-- 0017_wildcard_domain — โดเมนชนิด wildcard สำหรับใบรับรอง `*.example.com` (PLAN-V2 เฟส E7)
--
-- **ทำไมต้องเป็นชนิดใหม่ ไม่ใช่โดเมนธรรมดาที่ชื่อขึ้นต้นด้วย `*`:**
--
--   1. **ขอใบรับรองคนละวิธี** — wildcard ใช้ HTTP-01 ไม่ได้เลย ต้องพิสูจน์ด้วย DNS-01
--      (เขียน TXT `_acme-challenge`) ซึ่งแปลว่าโดเมนนั้นต้องมี zone อยู่บน BIND9 ของเครื่องนี้
--   2. **ลำดับ vhost ต่างกัน** — vhost ของ wildcard ต้องถูกอ่าน**ท้ายสุด** ไม่งั้นมันจะกลืน
--      คำขอของ subdomain ที่ระบุชื่อเต็มไว้แล้ว (ดู `Site::vhostFileName()`)
--   3. **ความหมายด้านความปลอดภัยต่างกัน** — `*.example.com` แปลว่า subdomain ที่ไม่มีใคร
--      จดทะเบียนไว้ก็เข้าเว็บนี้ได้ · ผู้ดูแลต้องเห็นความต่างนี้บนหน้าจอ ไม่ใช่เดาจากชื่อ
--
-- SQLite แก้ `CHECK` ในที่เดิมไม่ได้ ต้องสร้างตารางใหม่แล้วย้ายข้อมูล — ทำแบบเดียวกับ
-- migration 0010 · ข้อมูลเดิมย้ายครบทุกแถวเพราะโครงสร้างคอลัมน์ไม่เปลี่ยน

CREATE TABLE domains_new (
    id              INTEGER PRIMARY KEY,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    domain          TEXT    NOT NULL UNIQUE,
    type            TEXT    NOT NULL CHECK(type IN ('primary','subdomain','alias','redirect','wildcard')),
    redirect_target TEXT,
    redirect_code   INTEGER,
    created_at      INTEGER NOT NULL,
    zone_serial     INTEGER NOT NULL DEFAULT 0
);

INSERT INTO domains_new (id, site_id, domain, type, redirect_target, redirect_code, created_at, zone_serial)
     SELECT id, site_id, domain, type, redirect_target, redirect_code, created_at, zone_serial FROM domains;

DROP TABLE domains;
ALTER TABLE domains_new RENAME TO domains;

CREATE INDEX idx_domains_site ON domains(site_id);

-- ใบรับรอง wildcard ต้องแยกออกจากใบธรรมดาให้ชัด เพราะการต่ออายุใช้คนละวิธี:
-- ใบธรรมดาต่อได้ด้วย webroot เงียบ ๆ ส่วน wildcard ต้องเขียน TXT ลง zone ใหม่ทุกครั้ง
-- ซึ่งจะล้มเหลวถ้า `dns.enabled` ถูกปิดไปหลังจากออกใบครั้งแรก
ALTER TABLE certificates ADD COLUMN challenge TEXT NOT NULL DEFAULT 'http-01';
