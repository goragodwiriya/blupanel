-- 0005_merge_customers_into_users — ยุบ customers เข้า users ให้เหลือตารางเดียว
--
-- ทำไมต้องยุบ
-- ------------
-- 0004 เชื่อม customers.user_id → users.id แบบ 1:1 ไปแล้ว แต่ยังเหลือของซ้ำสองที่:
--   password_hash  อยู่ทั้งสองตาราง — เขียนพลาดที่ใดที่หนึ่ง = "เปลี่ยนรหัสแล้วรหัสเก่ายังใช้ได้"
--   status         คนละชุดค่า (users: active/disabled · customers: active/suspended/expired)
--                  ล็อกอินต้องตรวจสองรอบ และในฐานข้อมูลจริงก็ขัดกันเองอยู่แล้ว
--   username, display_name, created_at, updated_at
--
-- และที่แย่กว่านั้น: การมี "ผู้ใช้ที่ไม่มีแถวลูกค้า" เป็นไปได้ (webadmin ที่สร้างจาก CLI/API)
-- ซึ่งทำให้ QuotaChecker::checkOwnerCanCreate() หาแถวลูกค้าไม่เจอแล้วปล่อยผ่านทุกโควตา
-- พอโควตาย้ายมาอยู่บน users แถวโควตาจะมีอยู่กับทุกคนเสมอ — ช่องโหว่นี้หายไปโดยโครงสร้าง
-- ไม่ใช่หายเพราะเพิ่ม if
--
-- ทำไมไม่ rebuild ตาราง users
-- ----------------------------
-- migration ทุกไฟล์ถูกรันใน transaction ที่ PRAGMA foreign_keys = ON และ SQLite
-- ห้ามปิด foreign_keys กลาง transaction · การ DROP TABLE users จะยิง ON DELETE ของลูกทุกตัว
-- (sites.owner_user_id → SET NULL, totp_recovery_codes → CASCADE) = ข้อมูลหาย
-- ทดสอบยืนยันแล้วว่าเกิดขึ้นจริงแม้ตั้ง legacy_alter_table
-- จึงใช้ ALTER TABLE ADD COLUMN ล้วน ซึ่งไม่แตะ foreign key เลย

-- ---------------------------------------------------------------------------
-- 1. users รับหน้าที่ของ customers
-- ---------------------------------------------------------------------------

ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT '';

-- สถานะสองแกนที่ตั้งใจให้แยกจากกัน และอยู่บนแถวเดียวกันจึงขัดกันเองไม่ได้:
--   status         = สิทธิ์เข้าระบบ    (แอดมินแบนคนโกง → disabled)
--   service_status = สถานะบริการโฮสติ้ง (หมดอายุ → expired: เว็บดับ แต่ยังล็อกอินมาต่ออายุได้)
-- ของเดิมยัดสองความหมายนี้ลงคอลัมน์เดียวคนละตาราง เลยกลายเป็นว่าลูกค้าหมดอายุ
-- ล็อกอินเข้ามาจ่ายเงินไม่ได้
ALTER TABLE users ADD COLUMN service_status TEXT NOT NULL DEFAULT 'active'
    CHECK(service_status IN ('active','suspended','expired'));

-- บัญชี Linux ของผู้ใช้ — NULL = ยังไม่มีเว็บ จึงยังไม่ต้องมีบัญชีในระบบปฏิบัติการ
-- (superadmin/sysadmin ดูแลเซิร์ฟเวอร์ ไม่ได้โฮสต์เว็บ จะไม่มีวันได้ค่านี้)
-- ตั้งแต่ M3 เป็นต้นไป หนึ่งผู้ใช้ = หนึ่ง uid = หนึ่งบ้าน = หลายเว็บ
ALTER TABLE users ADD COLUMN system_user TEXT;
ALTER TABLE users ADD COLUMN uid INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN gid INTEGER NOT NULL DEFAULT 0;

ALTER TABLE users ADD COLUMN quota_domains    INTEGER NOT NULL DEFAULT 10;
ALTER TABLE users ADD COLUMN quota_subdomains INTEGER NOT NULL DEFAULT 20;
ALTER TABLE users ADD COLUMN quota_aliases    INTEGER NOT NULL DEFAULT 50;
ALTER TABLE users ADD COLUMN quota_emails     INTEGER NOT NULL DEFAULT 100;
ALTER TABLE users ADD COLUMN quota_databases  INTEGER NOT NULL DEFAULT 10;
ALTER TABLE users ADD COLUMN quota_ftp_users  INTEGER NOT NULL DEFAULT 5;

-- โควตาดิสก์ย้ายจากรายเว็บมาเป็นรายบัญชี — ตรงกับที่ขายจริง ("แพ็กเกจ 10 GB")
-- และตรงกับ project quota ของ XFS/ext4 ที่นับตาม uid ในเฟส E2
ALTER TABLE users ADD COLUMN disk_quota_mb INTEGER;
ALTER TABLE users ADD COLUMN disk_used_mb  INTEGER NOT NULL DEFAULT 0;

ALTER TABLE users ADD COLUMN expiry_at INTEGER;

-- NULL ซ้ำกันได้หลายแถวตามหลัก SQL — ผู้ใช้ที่ยังไม่มีบัญชีระบบจึงอยู่ร่วมกันได้
CREATE UNIQUE INDEX idx_users_system_user ON users(system_user);
CREATE INDEX idx_users_service_status ON users(service_status);
CREATE INDEX idx_users_expiry ON users(expiry_at);

-- ---------------------------------------------------------------------------
-- 2. ย้ายข้อมูลลูกค้าขึ้นมาบนแถว users ของตัวเอง
-- ---------------------------------------------------------------------------

UPDATE users SET
    email            = COALESCE((SELECT c.email            FROM customers c WHERE c.user_id = users.id), ''),
    quota_domains    = COALESCE((SELECT c.quota_domains    FROM customers c WHERE c.user_id = users.id), quota_domains),
    quota_subdomains = COALESCE((SELECT c.quota_subdomains FROM customers c WHERE c.user_id = users.id), quota_subdomains),
    quota_aliases    = COALESCE((SELECT c.quota_aliases    FROM customers c WHERE c.user_id = users.id), quota_aliases),
    quota_emails     = COALESCE((SELECT c.quota_emails     FROM customers c WHERE c.user_id = users.id), quota_emails),
    quota_databases  = COALESCE((SELECT c.quota_databases  FROM customers c WHERE c.user_id = users.id), quota_databases),
    quota_ftp_users  = COALESCE((SELECT c.quota_ftp_users  FROM customers c WHERE c.user_id = users.id), quota_ftp_users),
    expiry_at        = (SELECT c.expiry_at FROM customers c WHERE c.user_id = users.id),
    service_status   = COALESCE((SELECT c.status FROM customers c WHERE c.user_id = users.id), 'active')
WHERE EXISTS (SELECT 1 FROM customers c WHERE c.user_id = users.id);

-- ผู้ดูแลเซิร์ฟเวอร์ไม่ได้ซื้อโฮสติ้ง — ให้ไม่จำกัดไปเลย จะได้ไม่มีวันติดโควตาตัวเอง
UPDATE users SET
    quota_domains = -1, quota_subdomains = -1, quota_aliases = -1,
    quota_emails  = -1, quota_databases  = -1, quota_ftp_users = -1
WHERE role IN ('superadmin','sysadmin');

-- ---------------------------------------------------------------------------
-- 3. เจ้าของเว็บ — ยุบ customer_sites เข้า sites.owner_user_id
-- ---------------------------------------------------------------------------
-- ก่อนหน้านี้มีความจริงสองชุดที่ขัดกันจริง ๆ ในฐานข้อมูล: customer_sites บอกว่าเว็บเป็น
-- ของลูกค้ารายหนึ่ง แต่ sites.owner_user_id เป็น NULL ทุกแถว · ที่ถูกคือ customer_sites
-- เพราะเป็นตัวที่โค้ดเขียนจริงตอนผูกเว็บให้ลูกค้า

UPDATE sites SET owner_user_id = (
    SELECT c.user_id
    FROM customer_sites cs
    JOIN customers c ON c.id = cs.customer_id
    WHERE cs.site_id = sites.id AND c.user_id IS NOT NULL
    ORDER BY cs.customer_id
    LIMIT 1
)
WHERE owner_user_id IS NULL
  AND EXISTS (SELECT 1 FROM customer_sites cs WHERE cs.site_id = sites.id);

-- เว็บที่ไม่เคยผูกกับลูกค้าคนไหนเลย (เว็บของผู้ดูแลเอง) ยกให้ superadmin คนแรก
-- ไม่ปล่อยทิ้งไว้เป็น NULL เพราะ trigger ข้างล่างจะไม่ยอมให้มีเว็บไร้เจ้าของอีกต่อไป
UPDATE sites SET owner_user_id = (SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1)
WHERE owner_user_id IS NULL;

-- เว็บต้องมีเจ้าของเสมอ — บังคับที่ชั้นฐานข้อมูล ไม่ใช่แค่หวังว่าโค้ดจะจำ
-- (ใช้ trigger เพราะเปลี่ยนคอลัมน์เป็น NOT NULL ต้อง rebuild ตาราง ซึ่งจะ cascade ลบ
--  domains/databases_/cron_jobs ที่อ้าง sites อยู่ทิ้งทั้งหมด)
CREATE TRIGGER trg_sites_owner_required_insert
BEFORE INSERT ON sites
WHEN NEW.owner_user_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'เว็บไซต์ต้องระบุเจ้าของ (owner_user_id)');
END;

CREATE TRIGGER trg_sites_owner_required_update
BEFORE UPDATE OF owner_user_id ON sites
WHEN NEW.owner_user_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'เว็บไซต์ต้องระบุเจ้าของ (owner_user_id)');
END;

-- ลบผู้ใช้ที่ยังเป็นเจ้าของเว็บอยู่ไม่ได้
--
-- ถ้าไม่มี trigger ตัวนี้ การลบผู้ใช้จะไปเข้าเงื่อนไข ON DELETE SET NULL ของ
-- sites.owner_user_id แล้วชนกับ trigger ข้างบน ได้ข้อความว่า "เว็บไซต์ต้องระบุเจ้าของ"
-- ซึ่งไม่มีใครเดาออกว่าเกิดจากการลบผู้ใช้ · ที่สำคัญกว่าคือมันเป็นกฎที่ถูกต้องอยู่แล้ว:
-- เว็บของลูกค้าที่ถูกลบต้องถูกรื้อถอนหรือย้ายเจ้าของอย่างตั้งใจ ไม่ใช่กลายเป็นเว็บไร้เจ้าของ
-- ที่ยังรันอยู่บนเครื่องต่อไปเงียบ ๆ
CREATE TRIGGER trg_users_delete_requires_no_sites
BEFORE DELETE ON users
WHEN EXISTS (SELECT 1 FROM sites WHERE owner_user_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'ลบผู้ใช้ไม่ได้เพราะยังเป็นเจ้าของเว็บไซต์อยู่ — ต้องลบเว็บหรือย้ายเจ้าของก่อน');
END;

-- ---------------------------------------------------------------------------
-- 4. expiry_notifications ชี้มาที่ users แทน
-- ---------------------------------------------------------------------------
-- ตารางนี้ไม่มีลูก จึงสร้างใหม่ได้ปลอดภัย (ต่างจาก users/sites)

CREATE TABLE expiry_notifications_new (
    id            INTEGER PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    days_before   INTEGER NOT NULL,
    notified_at   INTEGER NOT NULL,
    UNIQUE(user_id, days_before)
);

INSERT INTO expiry_notifications_new (id, user_id, days_before, notified_at)
SELECT n.id, c.user_id, n.days_before, n.notified_at
FROM expiry_notifications n
JOIN customers c ON c.id = n.customer_id
WHERE c.user_id IS NOT NULL;

DROP TABLE expiry_notifications;
ALTER TABLE expiry_notifications_new RENAME TO expiry_notifications;
CREATE INDEX idx_expiry_notif_user ON expiry_notifications(user_id);

-- ---------------------------------------------------------------------------
-- 5. ปลดระวางตารางเดิม
-- ---------------------------------------------------------------------------
-- ทั้งสองตารางเป็น "ลูก" ในความสัมพันธ์ (customers.user_id → users.id,
-- customer_sites → customers/sites) การ DROP จึงไม่ยิง ON DELETE ใส่ users หรือ sites

DROP TABLE customer_sites;
DROP TABLE customers;
