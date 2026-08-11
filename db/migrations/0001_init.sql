-- 0001_init — โครงสร้างพื้นฐานตาม ARCHITECTURE §7
-- หมายเหตุ: เก็บเวลาเป็น unix timestamp (INTEGER) ทั้งระบบ เพื่อให้เปรียบเทียบและ index ได้เร็ว

CREATE TABLE users (
    id              INTEGER PRIMARY KEY,
    username        TEXT    NOT NULL UNIQUE,
    display_name    TEXT    NOT NULL DEFAULT '',
    password_hash   TEXT    NOT NULL,
    role            TEXT    NOT NULL CHECK(role IN ('superadmin','sysadmin','webadmin')),
    totp_secret     TEXT,
    totp_enabled    INTEGER NOT NULL DEFAULT 0,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    INTEGER,
    last_login_at   INTEGER,
    last_login_ip   TEXT,
    created_at      INTEGER NOT NULL,
    updated_at      INTEGER NOT NULL
);

-- recovery code ของ 2FA เก็บเป็น hash ใช้ได้ครั้งเดียว
CREATE TABLE totp_recovery_codes (
    id       INTEGER PRIMARY KEY,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT   NOT NULL,
    used_at  INTEGER
);
CREATE INDEX idx_recovery_user ON totp_recovery_codes(user_id);

-- session เก็บเฉพาะ hash ของ id ไม่เก็บตัวจริง — ยึด DB ไปแล้วสวมรอย session ไม่ได้
CREATE TABLE sessions (
    id_hash    TEXT    PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip         TEXT    NOT NULL,
    ua_hash    TEXT    NOT NULL,
    pending_2fa INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    rotated_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX idx_sessions_user ON sessions(user_id);
CREATE INDEX idx_sessions_exp  ON sessions(expires_at);

-- token bucket ของ rate limit เก็บใน DB เพื่อให้ทน FPM หลาย worker
CREATE TABLE rate_limits (
    bucket      TEXT    PRIMARY KEY,
    tokens      REAL    NOT NULL,
    updated_at  INTEGER NOT NULL
);

CREATE TABLE sites (
    id             INTEGER PRIMARY KEY,
    name           TEXT    NOT NULL,
    primary_domain TEXT    NOT NULL UNIQUE,
    system_user    TEXT    NOT NULL UNIQUE,
    uid            INTEGER NOT NULL DEFAULT 0,
    gid            INTEGER NOT NULL DEFAULT 0,
    docroot        TEXT    NOT NULL,
    php_version    TEXT    NOT NULL,
    ssl_mode       TEXT    NOT NULL DEFAULT 'off'    CHECK(ssl_mode IN ('off','on','forced')),
    status         TEXT    NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
    disk_quota_mb  INTEGER,
    disk_used_mb   INTEGER NOT NULL DEFAULT 0,
    owner_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    docroot_override TEXT NOT NULL DEFAULT '',
    created_at     INTEGER NOT NULL,
    updated_at     INTEGER NOT NULL
);
CREATE INDEX idx_sites_owner ON sites(owner_user_id);
CREATE INDEX idx_sites_php   ON sites(php_version);

CREATE TABLE domains (
    id              INTEGER PRIMARY KEY,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    domain          TEXT    NOT NULL UNIQUE,
    type            TEXT    NOT NULL CHECK(type IN ('primary','subdomain','alias','redirect')),
    redirect_target TEXT,
    redirect_code   INTEGER,
    created_at      INTEGER NOT NULL
);
CREATE INDEX idx_domains_site ON domains(site_id);

CREATE TABLE dns_records (
    id        INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    type      TEXT    NOT NULL CHECK(type IN ('A','AAAA','CNAME','MX','TXT','CAA')),
    name      TEXT    NOT NULL,
    value     TEXT    NOT NULL,
    ttl       INTEGER NOT NULL DEFAULT 3600,
    priority  INTEGER
);
CREATE INDEX idx_dns_domain ON dns_records(domain_id);

CREATE TABLE certificates (
    id            INTEGER PRIMARY KEY,
    domain        TEXT    NOT NULL UNIQUE,
    issuer        TEXT    NOT NULL,
    status        TEXT    NOT NULL CHECK(status IN ('valid','expiring','expired','error','pending')),
    not_before    INTEGER,
    not_after     INTEGER,
    auto_renew    INTEGER NOT NULL DEFAULT 1,
    last_renew_at INTEGER,
    last_error    TEXT
);

CREATE TABLE databases_ (
    id         INTEGER PRIMARY KEY,
    db_name    TEXT    NOT NULL UNIQUE,
    site_id    INTEGER REFERENCES sites(id) ON DELETE SET NULL,
    charset    TEXT    NOT NULL DEFAULT 'utf8mb4',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE db_users (
    id       INTEGER PRIMARY KEY,
    username TEXT NOT NULL,
    host     TEXT NOT NULL DEFAULT 'localhost',
    UNIQUE(username, host)
);

CREATE TABLE db_grants (
    db_id      INTEGER NOT NULL REFERENCES databases_(id) ON DELETE CASCADE,
    db_user_id INTEGER NOT NULL REFERENCES db_users(id) ON DELETE CASCADE,
    privileges TEXT    NOT NULL CHECK(privileges IN ('readonly','readwrite','full')),
    PRIMARY KEY(db_id, db_user_id)
);

CREATE TABLE cron_jobs (
    id             INTEGER PRIMARY KEY,
    site_id        INTEGER REFERENCES sites(id) ON DELETE CASCADE,
    name           TEXT    NOT NULL,
    schedule       TEXT    NOT NULL,
    command        TEXT    NOT NULL,
    enabled        INTEGER NOT NULL DEFAULT 1,
    last_run_at    INTEGER,
    last_exit_code INTEGER,
    created_at     INTEGER NOT NULL
);

CREATE TABLE backups (
    id         INTEGER PRIMARY KEY,
    name       TEXT    NOT NULL,
    type       TEXT    NOT NULL CHECK(type IN ('site','database','config','full')),
    site_id    INTEGER REFERENCES sites(id) ON DELETE SET NULL,
    path       TEXT    NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    checksum   TEXT,
    status     TEXT    NOT NULL CHECK(status IN ('running','ok','failed')),
    created_at INTEGER NOT NULL
);

CREATE TABLE jobs (
    id            INTEGER PRIMARY KEY,
    capability    TEXT    NOT NULL,
    args_json     TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'queued'
                  CHECK(status IN ('queued','running','done','failed','cancelled')),
    progress      INTEGER NOT NULL DEFAULT 0,
    label         TEXT    NOT NULL DEFAULT '',
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    output        TEXT,
    error         TEXT,
    created_at    INTEGER NOT NULL,
    started_at    INTEGER,
    finished_at   INTEGER
);
CREATE INDEX idx_jobs_status ON jobs(status, created_at);

-- audit log แบบ hash chain: แก้ย้อนหลังแล้ว chain ขาด ตรวจด้วย `phpcp doctor`
CREATE TABLE audit_log (
    id            INTEGER PRIMARY KEY,
    ts            INTEGER NOT NULL,
    actor_user_id INTEGER,
    actor_name    TEXT    NOT NULL DEFAULT '',
    actor_ip      TEXT    NOT NULL DEFAULT '',
    request_id    TEXT    NOT NULL DEFAULT '',
    action        TEXT    NOT NULL,
    target        TEXT    NOT NULL DEFAULT '',
    result        TEXT    NOT NULL CHECK(result IN ('ok','denied','error')),
    detail_json   TEXT    NOT NULL DEFAULT '{}',
    prev_hash     TEXT    NOT NULL,
    hash          TEXT    NOT NULL
);
CREATE INDEX idx_audit_ts    ON audit_log(ts DESC);
CREATE INDEX idx_audit_actor ON audit_log(actor_user_id, ts DESC);

-- ค่าตั้งที่แก้ผ่าน UI ได้ (ต่างจาก config.php ที่แก้ด้วยมือ)
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);

-- 0002_rollback — รายการเปลี่ยนแปลงที่รอการยืนยัน (ARCHITECTURE §5.4)
--
-- ใช้กับค่าที่เปลี่ยนแล้วอาจตัดการเชื่อมต่อของผู้ที่กำลังแก้เอง (SSH, firewall)
-- ถ้าไม่กดยืนยันภายในเวลา ระบบจะคืนค่าเดิมให้อัตโนมัติ

CREATE TABLE pending_rollbacks (
    id            INTEGER PRIMARY KEY,
    action        TEXT    NOT NULL,
    description   TEXT    NOT NULL DEFAULT '',
    -- เนื้อหาไฟล์เดิมทั้งหมดที่ต้องคืน เก็บเป็น JSON เพราะหนึ่งการเปลี่ยนแปลง
    -- อาจแตะหลายไฟล์ และต้องคืนพร้อมกันทั้งชุด
    payload_json  TEXT    NOT NULL,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at    INTEGER NOT NULL,
    expires_at    INTEGER NOT NULL
);

CREATE INDEX idx_rollback_expires ON pending_rollbacks(expires_at);
