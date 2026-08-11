-- 0003_scheduled_jobs — ตาราง scheduled jobs สำหรับ recurring system tasks
-- แยกจาก jobs (one-time job queue) และ cron_jobs (per-site cron entries)
-- scheduled_jobs ใช้สำหรับ recurring system-level tasks เช่น expiry check

CREATE TABLE scheduled_jobs (
    id            INTEGER PRIMARY KEY,
    name          TEXT    NOT NULL UNIQUE,
    capability    TEXT    NOT NULL,
    args_json     TEXT    NOT NULL DEFAULT '{}',
    schedule      TEXT    NOT NULL,  -- cron expression (e.g. "0 3 * * *")
    enabled       INTEGER NOT NULL DEFAULT 1,
    last_run_at   INTEGER,
    last_status   TEXT,              -- 'ok' | 'error' | null
    last_error    TEXT,
    created_at    INTEGER NOT NULL,
    updated_at    INTEGER NOT NULL
);
CREATE INDEX idx_scheduled_jobs_enabled ON scheduled_jobs(enabled);

-- Insert ExpiryCheck scheduled job — ตรวจสอบวันหมดอายุลูกค้าทุกวันเวลา 03:00
-- ส่งการแจ้งเตือน 30, 7, 1 วันก่อนหมดอายุ และเปลี่ยนสถานะเป็น 'expired' เมื่อหมดอายุ
INSERT INTO scheduled_jobs (name, capability, args_json, schedule, enabled, created_at, updated_at)
VALUES (
    'expiry.check',
    'expiry.check',
    '{}',
    '0 3 * * *',
    1,
    strftime('%s', 'now'),
    strftime('%s', 'now')
);
