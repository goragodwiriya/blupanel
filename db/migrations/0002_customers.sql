-- 0002_customers — เพิ่มตารางสำหรับลูกค้า (customer) และการจัดการโควตา
-- ระบบผู้ใช้สำหรับ panel (superadmin, sysadmin, webadmin) ยังคงอยู่ใน users ตารางเดิม
-- ลูกค้า (customer) คือผู้ที่ซื้อบริการ web hosting ซึ่งจะมี owner_user_id ในตาราง sites

-- ตารางลูกค้า (customer) — แยกจาก users เพราะมีลักษณะการใช้งานต่างกัน
CREATE TABLE customers (
    id               INTEGER PRIMARY KEY,
    username         TEXT    NOT NULL UNIQUE,
    password_hash    TEXT    NOT NULL,
    display_name     TEXT    NOT NULL DEFAULT '',
    email            TEXT    NOT NULL,
    status           TEXT    NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','expired')),
    quota_domains    INTEGER NOT NULL DEFAULT 10,
    quota_subdomains INTEGER NOT NULL DEFAULT 20,
    quota_aliases    INTEGER NOT NULL DEFAULT 50,
    quota_emails     INTEGER NOT NULL DEFAULT 100,
    quota_databases  INTEGER NOT NULL DEFAULT 10,
    quota_ftp_users  INTEGER NOT NULL DEFAULT 5,
    expiry_at        INTEGER,  -- unix timestamp, ถ้าเป็น null = ไม่มีหมดอายุ
    created_at       INTEGER NOT NULL,
    updated_at       INTEGER NOT NULL
);
CREATE INDEX idx_customers_status ON customers(status);
CREATE INDEX idx_customers_expiry ON customers(expiry_at);

-- ตารางเชื่อมSites ของลูกค้า ( Many-to-Many )
CREATE TABLE customer_sites (
    customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    PRIMARY KEY(customer_id, site_id)
);
CREATE INDEX idx_customer_sites_customer ON customer_sites(customer_id);
CREATE INDEX idx_customer_sites_site ON customer_sites(site_id);

-- ตารางแจ้งเตือนวันหมดอายุ — ป้องกันไม่ให้แจ้งซ้ำในวันเดียวกัน
CREATE TABLE expiry_notifications (
    id            INTEGER PRIMARY KEY,
    customer_id   INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    days_before   INTEGER NOT NULL,
    notified_at   INTEGER,
    created_at    INTEGER NOT NULL,
    UNIQUE(customer_id, days_before)
);
CREATE INDEX idx_expiry_notif_customer ON expiry_notifications(customer_id);

-- ฟังก์ชันตรรกะ:
-- 1. ลูกค้าแต่ละคนมี quota สำหรับ resource ต่างๆ (domain, subdomain, alias, email, database, ftp)
-- 2. เมื่อสร้าง site/domain/email/db/ftp ต้องเช็คว่า current usage < quota
-- 3. ถ้า expiry_at ผ่านไปแล้ว = status เปลี่ยนเป็น 'expired' อัตโนมัติ
-- 4. แจ้งเตือนล่วงหน้า 30, 7, 1 วันก่อนหมดอายุ (เก็บ notified_at เพื่อกันแจ้งซ้ำ)
