-- phpcp:rebuild-tables
--
-- 0010_s3_backup_destination — เพิ่มชนิดปลายทาง 's3' ให้ backup_destinations (PLAN-V2 เฟส E1)
--
-- `driver` ประกาศ CHECK(driver IN ('local','sftp','rsync')) ไว้ตอน CREATE TABLE ซึ่ง
-- SQLite แก้ CHECK ที่ผูกกับคอลัมน์ด้วย ALTER TABLE ตรง ๆ ไม่ได้ ต้องสร้างตารางใหม่
-- ตามขั้นตอนมาตรฐานเดียวกับ 0006 (ดูคอมเมนต์ที่นั่นสำหรับรายละเอียดว่าทำไมต้องปิด
-- foreign_keys ชั่วคราว) — ตารางนี้ไม่มีลูกอื่นนอกจาก backups.destination_id ซึ่งอ้างด้วย
-- id ที่ยังคงเดิมทุกแถว จึงไม่มีความสัมพันธ์ที่ขาดหลังสร้างใหม่

CREATE TABLE backup_destinations_new (
    id          INTEGER PRIMARY KEY,
    name        TEXT    NOT NULL UNIQUE,
    driver      TEXT    NOT NULL CHECK(driver IN ('local','sftp','rsync','s3')),
    config_json TEXT    NOT NULL DEFAULT '{}',
    secret_enc  TEXT,
    retention_days  INTEGER NOT NULL DEFAULT 30,
    retention_count INTEGER NOT NULL DEFAULT 7,
    enabled     INTEGER NOT NULL DEFAULT 1,
    last_ok_at  INTEGER,
    last_error  TEXT,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
);

INSERT INTO backup_destinations_new SELECT * FROM backup_destinations;

DROP TABLE backup_destinations;
ALTER TABLE backup_destinations_new RENAME TO backup_destinations;
