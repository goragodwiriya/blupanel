-- เมลโฮสติ้ง — PLAN-MAIL เฟส M1
--
-- กล่องจดหมายเป็น virtual mailbox ไม่ใช่ผู้ใช้ระบบ · เจ้าของกล่องคือเจ้าของโดเมน
-- ซึ่งอนุมานจาก domains.site_id → sites.owner_user_id ที่มีอยู่แล้ว ไม่มีคอลัมน์
-- เจ้าของซ้ำอีกชุดให้ต้องดูแลให้ตรงกัน

-- เปิดเมลเป็นรายโดเมน ไม่ใช่เปิดทั้งเครื่อง — โดเมนที่ไม่ได้ใช้เมลต้องไม่มีอะไร
-- โผล่ใน virtual maps ของ Postfix เลย
ALTER TABLE domains ADD COLUMN mail_enabled INTEGER NOT NULL DEFAULT 0;

CREATE TABLE mailboxes (
    id            INTEGER PRIMARY KEY,
    domain_id     INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    -- ส่วนหน้า @ เก็บแยกจากโดเมน เพื่อให้เปลี่ยนชื่อโดเมนแล้วกล่องตามไปเองได้
    local_part    TEXT    NOT NULL,
    -- แฮชของ Dovecot เท่านั้น ({ARGON2ID}...) — ไม่มีที่ไหนในระบบเก็บรหัสจริง
    password_hash TEXT    NOT NULL,
    quota_mb      INTEGER NOT NULL DEFAULT 1024,
    enabled       INTEGER NOT NULL DEFAULT 1,
    created_at    INTEGER NOT NULL,
    UNIQUE (domain_id, local_part)
);

CREATE INDEX idx_mailboxes_domain ON mailboxes(domain_id);

CREATE TABLE mail_aliases (
    id          INTEGER PRIMARY KEY,
    domain_id   INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    -- ว่าง = catch-all ของโดเมนนี้ (รับทุกชื่อที่ไม่ตรงกับกล่องหรือ alias อื่น)
    source      TEXT    NOT NULL DEFAULT '',
    -- ปลายทางคั่นด้วยจุลภาคได้หลายที่ ตามรูปแบบของ Postfix เอง
    destination TEXT    NOT NULL,
    created_at  INTEGER NOT NULL,
    UNIQUE (domain_id, source)
);

CREATE INDEX idx_mail_aliases_domain ON mail_aliases(domain_id);
