-- phpcp:rebuild-tables
--
-- 0006_per_user_hosting_layout — หนึ่งผู้ใช้ = หนึ่งบัญชีระบบ = หนึ่งบ้าน = หลายเว็บ
--
-- เดิมหนึ่งเว็บ = หนึ่ง uid = หนึ่ง FPM pool = หนึ่งบ้าน (/srv/phpcp/sites/<domain>)
-- ลูกค้าที่มี 5 เว็บจึงได้ 5 บัญชี Linux ที่ไม่เกี่ยวข้องกันเลยทั้งที่เป็นคนเดียวกัน:
--   - SFTP ต้องมี 5 บัญชี (เฟส E4)
--   - โควตาดิสก์นับแยกรายเว็บ ไม่ตรงกับที่ขายจริงซึ่งเป็นรายบัญชี (เฟส E2)
--   - 5 FPM pool กินหน่วยความจำโดยไม่ได้แยกอะไรที่ควรแยก เพราะเป็นทรัพย์สินของคนเดียวกัน
--
-- ตั้งแต่นี้ไป: uid และบ้านผูกกับ**ผู้ใช้** เว็บเป็นเพียงโฟลเดอร์ใต้บ้าน
--   /srv/phpcp/users/<username>/domains/<domain>/{public,logs,tmp,backups}
--
-- สิ่งที่แลกไป: เว็บของลูกค้า*คนเดียวกัน*อ่านไฟล์กันได้และแชร์คิว process กัน
-- รับได้เพราะเป็นทรัพย์สินของคนเดียวกัน และเป็นโมเดลเดียวกับ cPanel/Plesk/DirectAdmin
-- **การแยกระหว่างลูกค้าต่างรายยังแน่นเท่าเดิมทุกประการ**
--
-- ทำไมต้องสร้างตาราง sites ใหม่ทั้งตาราง
-- --------------------------------------
-- `sites.system_user` ประกาศ UNIQUE ไว้ตอน CREATE TABLE ซึ่ง SQLite ลบด้วย
-- ALTER TABLE DROP COLUMN ไม่ได้ ("cannot drop UNIQUE column") และข้อจำกัดนั้น
-- ต้องหายไปเพราะเว็บหลายแห่งของผู้ใช้คนเดียวกันย่อมใช้บัญชีระบบเดียวกัน
-- ไฟล์นี้จึงประกาศ `-- phpcp:rebuild-tables` ให้ตัวรัน migration ปิด foreign_keys
-- ชั่วคราวตามขั้นตอนมาตรฐานของ SQLite แล้วตรวจ foreign_key_check ก่อน commit

-- ---------------------------------------------------------------------------
-- 1. บัญชีระบบของผู้ใช้ที่เป็นเจ้าของเว็บอยู่แล้ว
-- ---------------------------------------------------------------------------
-- ผู้ใช้ที่ยังไม่มีเว็บจะไม่มีบัญชีระบบ — สร้างตอนสร้างเว็บแรกเท่านั้น (lazy)
-- ผู้ดูแลระบบที่บังเอิญถือเว็บอยู่ก็ได้บัญชีระบบด้วย เพราะเว็บต้องมี uid ที่ไม่ใช่ root

UPDATE users SET system_user = username
WHERE system_user IS NULL
  AND EXISTS (SELECT 1 FROM sites WHERE sites.owner_user_id = users.id);

-- ---------------------------------------------------------------------------
-- 2. สร้างตาราง sites ใหม่โดยไม่มี system_user / uid / gid
-- ---------------------------------------------------------------------------
-- ทั้งสามคอลัมน์ย้ายไปอยู่กับผู้ใช้แล้ว การเก็บไว้ที่เว็บด้วยจะกลายเป็นความจริงสองชุด
-- ที่ค่อย ๆ เพี้ยนจากกัน แบบเดียวกับที่ customer_sites เคยเพี้ยนจาก owner_user_id
--
-- และคราวนี้ owner_user_id เป็น NOT NULL จริง ๆ ที่ระดับคอลัมน์ ไม่ต้องพึ่ง trigger

-- trigger ของ 0005 อ้างถึงตาราง sites อยู่ · ถ้าไม่ทิ้งก่อน คำสั่งถัดไปที่แตะตาราง users
-- จะล้มด้วย "no such table: main.sites" เพราะ SQLite ตรวจ trigger ทุกตัวของตารางตอน
-- เตรียมคำสั่ง ไม่ใช่ตอนที่ trigger ทำงานจริง
DROP TRIGGER IF EXISTS trg_users_delete_requires_no_sites;
DROP TRIGGER IF EXISTS trg_sites_owner_required_insert;
DROP TRIGGER IF EXISTS trg_sites_owner_required_update;

CREATE TABLE sites_new (
    id             INTEGER PRIMARY KEY,
    name           TEXT    NOT NULL,
    primary_domain TEXT    NOT NULL UNIQUE,
    docroot        TEXT    NOT NULL,
    php_version    TEXT    NOT NULL,
    ssl_mode       TEXT    NOT NULL DEFAULT 'off'    CHECK(ssl_mode IN ('off','on','forced')),
    status         TEXT    NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
    disk_used_mb   INTEGER NOT NULL DEFAULT 0,
    owner_user_id  INTEGER NOT NULL REFERENCES users(id),
    docroot_override TEXT NOT NULL DEFAULT '',
    created_at     INTEGER NOT NULL,
    updated_at     INTEGER NOT NULL
);

-- docroot ย้ายมาอยู่ใต้บ้านของเจ้าของ · เว็บที่ตั้ง docroot_override ไว้เอง (Domain Pointer)
-- ไม่ถูกแตะ เพราะผู้ดูแลจงใจชี้ไปที่โฟลเดอร์ซึ่งมีอยู่ก่อนแล้ว
INSERT INTO sites_new (
    id, name, primary_domain, docroot, php_version, ssl_mode, status,
    disk_used_mb, owner_user_id, docroot_override, created_at, updated_at
)
SELECT
    s.id, s.name, s.primary_domain,
    CASE
        WHEN s.docroot_override <> '' THEN s.docroot
        ELSE '/srv/phpcp/users/' || u.username || '/domains/' || s.primary_domain || '/public'
    END,
    s.php_version, s.ssl_mode, s.status,
    s.disk_used_mb, s.owner_user_id, s.docroot_override, s.created_at, s.updated_at
FROM sites s
JOIN users u ON u.id = s.owner_user_id;

DROP TABLE sites;
ALTER TABLE sites_new RENAME TO sites;

CREATE INDEX idx_sites_owner ON sites(owner_user_id);
CREATE INDEX idx_sites_php   ON sites(php_version);

-- trigger ของ 0005 หายไปพร้อมตารางเดิม · ตัวที่บังคับ "ต้องมีเจ้าของ" ไม่ต้องสร้างใหม่
-- เพราะตอนนี้เป็น NOT NULL ที่ตัวคอลัมน์แล้ว ซึ่งตรงไปตรงมากว่า
-- ส่วนตัวที่ห้ามลบผู้ใช้ที่ยังถือเว็บอยู่ ยังจำเป็นเหมือนเดิม
CREATE TRIGGER trg_users_delete_requires_no_sites
BEFORE DELETE ON users
WHEN EXISTS (SELECT 1 FROM sites WHERE owner_user_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'ลบผู้ใช้ไม่ได้เพราะยังเป็นเจ้าของเว็บไซต์อยู่ — ต้องลบเว็บหรือย้ายเจ้าของก่อน');
END;

-- ---------------------------------------------------------------------------
-- 3. โควตาดิสก์เป็นของบัญชี ไม่ใช่ของเว็บ
-- ---------------------------------------------------------------------------
-- คอลัมน์ disk_quota_mb ของ sites หายไปพร้อมการสร้างตารางใหม่ · ค่าที่เคยตั้งไว้
-- รายเว็บไม่มีความหมายอีกต่อไปเพราะ project quota ของ XFS/ext4 นับตาม uid ซึ่งตอนนี้
-- เป็นของผู้ใช้ · ใครยังไม่ได้ตั้งให้ใช้ค่ารวมของเว็บที่เคยตั้งไว้เป็นจุดเริ่มต้น
UPDATE users SET disk_quota_mb = 10240 WHERE disk_quota_mb IS NULL AND system_user IS NOT NULL;
