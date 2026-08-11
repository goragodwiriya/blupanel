-- ปลายทางของไฟล์สำรองที่อยู่นอกเครื่อง — PLAN-V2 เฟส E1
--
-- ปัญหาที่แก้: `BackupManager` เขียนไฟล์สำรองลง /var/lib/phpcp/backups ที่เดียว ซึ่งอยู่
-- บนดิสก์ก้อนเดียวกับข้อมูลจริง · ดิสก์พังครั้งเดียวเสียทั้งของจริงและไฟล์สำรองพร้อมกัน
-- แปลว่าที่ผ่านมาระบบมี "ไฟล์สำรอง" ที่กันสถานการณ์ร้ายแรงที่สุดไม่ได้เลย
--
-- **ทำไมต้องเป็นตาราง ไม่ใช่ค่าตั้งในไฟล์ config:** ปลายทางมีได้หลายที่พร้อมกัน
-- (เครื่องสำรองในออฟฟิศ + NAS + เครื่องเช่าที่ผู้ให้บริการอื่น) และแต่ละที่มีนโยบาย
-- เก็บย้อนหลังไม่เท่ากัน · การเพิ่มปลายทางใหม่ต้องทำจากหน้าเว็บได้โดยไม่ต้องแก้ไฟล์
-- แล้วรีสตาร์ตบริการ

CREATE TABLE backup_destinations (
    id          INTEGER PRIMARY KEY,
    name        TEXT    NOT NULL UNIQUE,

    -- local  = อีกเส้นทางบนเครื่องนี้ (จุดเมานต์ของ NAS/USB) — ยังนับว่านอกดิสก์ก้อนเดิม
    -- sftp   = คัดลอกผ่าน OpenSSH ไปเครื่องอื่น
    -- rsync  = rsync over ssh · ส่งเฉพาะส่วนต่าง เหมาะกับไฟล์ใหญ่ที่ส่งทุกวัน
    driver      TEXT    NOT NULL CHECK(driver IN ('local','sftp','rsync')),

    -- ค่าตั้งที่ไม่ใช่ความลับ (host, port, user, path) — เก็บเป็น JSON เพราะแต่ละ
    -- driver ต้องการฟิลด์ไม่เหมือนกัน และคอลัมน์ว่าง ๆ ต่อ driver จะรกกว่าโดยไม่ได้อะไร
    config_json TEXT    NOT NULL DEFAULT '{}',

    -- ความลับ (รหัสผ่าน / private key) เข้ารหัสด้วย Secret เหมือน TOTP secret
    -- ผู้ที่ได้ panel.db ไปอย่างเดียวจึงเข้าปลายทางสำรองไม่ได้ · NULL = ไม่ต้องใช้ความลับ
    -- (local หรือ sftp ที่ใช้กุญแจของระบบซึ่งอยู่นอกฐานข้อมูล)
    secret_enc  TEXT,

    -- นโยบายเก็บย้อนหลัง · 0 = ไม่จำกัด · ใช้ร่วมกันได้ทั้งสองเงื่อนไข
    -- (เก็บอย่างน้อย N ชุดล่าสุดเสมอ แม้จะเกินจำนวนวันไปแล้ว)
    retention_days  INTEGER NOT NULL DEFAULT 30,
    retention_count INTEGER NOT NULL DEFAULT 7,

    enabled     INTEGER NOT NULL DEFAULT 1,

    -- ผลของการติดต่อครั้งล่าสุด — ปลายทางที่ล้มเงียบ ๆ อันตรายพอ ๆ กับไม่มีปลายทาง
    -- เพราะผู้ดูแลจะเชื่อว่ามีไฟล์สำรองอยู่นอกเครื่องทั้งที่ไม่มี
    last_ok_at  INTEGER,
    last_error  TEXT,

    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
);

-- ไฟล์สำรองหนึ่งไฟล์รู้ว่าตัวเองถูกส่งออกไปที่ไหนแล้วบ้าง
--
-- `offsite_status` แยก "ยังไม่ได้ส่ง" ออกจาก "ส่งแล้วล้มเหลว" โดยตั้งใจ — สองอย่างนี้
-- ต้องขึ้นหน้าจอคนละแบบ และตัวเก็บกวาดต้องไม่ลบไฟล์ที่ยังส่งออกไม่สำเร็จ
ALTER TABLE backups ADD COLUMN destination_id  INTEGER REFERENCES backup_destinations(id) ON DELETE SET NULL;
ALTER TABLE backups ADD COLUMN remote_path     TEXT;
ALTER TABLE backups ADD COLUMN offsite_status  TEXT NOT NULL DEFAULT 'none';
ALTER TABLE backups ADD COLUMN offsite_at      INTEGER;
ALTER TABLE backups ADD COLUMN offsite_error   TEXT;

-- ตัวเก็บกวาดค้นด้วยสองคอลัมน์นี้ทุกครั้งที่ทำงาน
CREATE INDEX idx_backups_destination ON backups(destination_id, created_at);
CREATE INDEX idx_backups_offsite     ON backups(offsite_status);
