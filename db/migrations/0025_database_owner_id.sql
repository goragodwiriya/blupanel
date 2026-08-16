-- 0025_database_owner_id — databases_ ต้องรู้เจ้าของโดยตรง ไม่ใช่ผ่าน site เท่านั้น
--
-- **ปัญหาที่แก้:** `databases_` มีแค่ `site_id` ที่เป็น NULL ได้ (ฟอร์มสร้างฐานข้อมูล
-- เปิดให้เลือก "ไม่ผูกกับเว็บไซต์" ตรง ๆ) แต่ทุกจุดที่ต้องเช็คว่าฐานข้อมูลนี้เป็นของ
-- ใคร (DbList สำหรับหน้ารายการ, DbCapability::assertOwnership สำหรับลบ/เปลี่ยนรหัสผ่าน)
-- หาเจ้าของผ่าน `JOIN sites` เท่านั้น — แถวที่ site_id เป็น NULL จึงหาย ไม่ใช่ในหน้า
-- รายการเท่านั้น แต่ลบหรือเปลี่ยนรหัสผ่านก็ทำไม่ได้เลยเพราะ assertOwnership คืน 0
-- แถวเสมอ (เกิดจริงกับผู้ใช้ "bluprint" 2026-08-16 — ปุ่ม "เปิด phpMyAdmin" ใช้ได้
-- เพราะเช็คสิทธิ์ผ่าน db_accounts/MariaDB grant คนละทางกับ databases_ เอง)
--
-- ความพยายามแก้ก่อนหน้านี้ (ยังอยู่ใน DbList.php ตอนนี้) ต่อ EXISTS ผ่าน
-- db_grants → db_users → db_accounts ไม่ได้ผลจริง เพราะ db_users ที่ DbCreate
-- บันทึกไว้เป็นผู้ใช้ MariaDB เฉพาะของฐานข้อมูลนั้น (`<name>_user`) ไม่ใช่ชื่อบัญชี
-- เจ้าของ (`db_accounts.mysql_user`) — ทั้งสองไม่มีวันตรงกัน
--
-- **ทางแก้ที่ถูกจุด:** เก็บเจ้าของไว้ที่ตัว `databases_` โดยตรง เหมือนที่ `sites`
-- ทำอยู่แล้ว แทนที่จะพยายามคำนวณย้อนกลับทุกครั้งที่ถาม
--
-- **การเติมค่าย้อนหลัง:** แถวที่ผูกเว็บไซต์อยู่แล้วก็อบเจ้าของจาก sites.owner_user_id
-- มาตรง ๆ · แถวที่ไม่ผูกเว็บไซต์ไม่มีข้อมูลนั้นให้ก็อบ แต่ชื่อฐานข้อมูลเองมีคำตอบอยู่แล้ว
-- (`DbAccountRepository::qualify()` เติมคำนำหน้า `<mysql_user>_` ให้เสมอตอนสร้าง)
-- ใช้กติกาเดียวกับที่ `DbDrop::ownerAccountFor()` ใช้อยู่แล้วสำหรับหาโฟลเดอร์สำรอง —
-- คำนำหน้าที่ยาวที่สุดที่ตรงชนะ กัน "alice" กับ "alice_shop" ตีกันเอง

ALTER TABLE databases_ ADD COLUMN owner_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

UPDATE databases_
SET owner_user_id = (SELECT owner_user_id FROM sites WHERE sites.id = databases_.site_id)
WHERE site_id IS NOT NULL;

UPDATE databases_
SET owner_user_id = (
    SELECT u.id FROM users u
    WHERE u.role = 'webadmin' AND u.system_user IS NOT NULL
      AND substr(databases_.db_name, 1, length(u.system_user) + 1) = u.system_user || '_'
    ORDER BY length(u.system_user) DESC
    LIMIT 1
)
WHERE site_id IS NULL AND owner_user_id IS NULL;
