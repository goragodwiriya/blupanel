-- 0004_customer_user_link — เชื่อม customers เข้ากับ users
--
-- ก่อนหน้านี้ customers และ users เป็นคนละระบบ:
--   users = คนที่ล็อกอิน panel ได้ (superadmin, sysadmin, webadmin)
--   customers = ลูกค้าที่ซื้อ hosting (มี username/password ของตัวเอง แต่ล็อกอิน panel ไม่ได้)
--
-- ตอนนี้รวมเป็นระบบเดียว:
--   ลูกค้า = webadmin ที่ล็อกอิน panel ได้
--   สร้าง customers พร้อมกับ users (role=webadmin) ไปพร้อมกัน
--   sites.owner_user_id ชี้ไปที่ users.id (เดิมชี้ไป customers.id ซึ่งผิด)

ALTER TABLE customers ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX idx_customers_user ON customers(user_id);
