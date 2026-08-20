-- 0027_php_settings — ค่า PHP ที่ปรับได้จริง แยกตามบัญชี
--
-- **ปัญหาที่แก้:** ค่าอย่าง `upload_max_filesize` เปลี่ยนไม่ได้เลยจากหน้าเว็บ ทั้งระบบมี
-- ค่าเดียวที่ฝังอยู่ใน `templates/fpm/pool.conf.tpl` · ลูกค้าที่ต้องอัปโหลดไฟล์ใหญ่กว่า
-- 64M จึงต้องให้ผู้ดูแลไปแก้เทมเพลตในรีโป ซึ่งเปลี่ยนให้**ทุกคนบนเครื่อง**พร้อมกัน
-- และหายไปทุกครั้งที่อัปเดต
--
-- **สิ่งที่หลอกตายิ่งกว่า:** `Site` มีพร็อพเพอร์ตี้ `memoryLimitMb`/`uploadLimitMb`/
-- `maxChildren` มาตั้งแต่ต้น หน้าตาเหมือนค่าที่ตั้งได้รายเว็บ แต่ `Site::fromRow()`
-- ไม่เคยอ่านค่าเหล่านี้จากคอลัมน์ไหนเลย (ไม่มีคอลัมน์ให้อ่านด้วยซ้ำ) — ทุกเว็บบนเครื่อง
-- จึงได้ค่าเริ่มต้นเหมือนกันหมดเสมอ โดยที่โค้ดอ่านแล้วดูเหมือนตั้งค่าได้
--
-- **ทำไมเก็บที่ `users` ไม่ใช่ `sites`:** ตั้งแต่ migration 0006 หนึ่ง pool = หนึ่งบัญชี ×
-- หนึ่งเวอร์ชัน PHP · เว็บทุกแห่งของบัญชีเดียวกันที่ใช้เวอร์ชันเดียวกันใช้ pool เดียวกัน
-- เก็บค่ารายเว็บจึงเป็นคำสัญญาที่ทำไม่ได้ — เว็บที่สร้างทีหลังจะเขียนทับค่าของเว็บก่อนหน้า
-- โดยไม่มีอะไรเตือน ซึ่งแย่กว่าไม่ให้ตั้งเลย
--
-- **ค่าเริ่มต้นตรงกับของเดิมทุกตัว** — เครื่องที่อัปเดตขึ้นมาต้องได้พฤติกรรมเดิมเป๊ะ
-- จนกว่าผู้ดูแลจะเปลี่ยนเอง · ค่าใหม่ที่ไม่เคยมีในเทมเพลตเดิม (max_input_vars,
-- max_file_uploads, session.gc_maxlifetime, date.timezone) ใช้ค่าปริยายของ PHP เอง
-- ยกเว้น max_input_vars ที่ตั้ง 3000 เพราะค่า 1000 ของ PHP คือสาเหตุคลาสสิกที่ฟอร์ม
-- ใหญ่ ๆ (เมนู WordPress, ตารางสิทธิ์) บันทึกแล้วข้อมูลหายเงียบ ๆ ไม่มี error ให้เห็น

ALTER TABLE users ADD COLUMN php_memory_limit_mb    INTEGER NOT NULL DEFAULT 256;
ALTER TABLE users ADD COLUMN php_upload_max_mb      INTEGER NOT NULL DEFAULT 64;
ALTER TABLE users ADD COLUMN php_post_max_mb        INTEGER NOT NULL DEFAULT 64;
ALTER TABLE users ADD COLUMN php_max_execution_time INTEGER NOT NULL DEFAULT 120;
ALTER TABLE users ADD COLUMN php_max_input_time     INTEGER NOT NULL DEFAULT 120;
ALTER TABLE users ADD COLUMN php_max_input_vars     INTEGER NOT NULL DEFAULT 3000;
ALTER TABLE users ADD COLUMN php_max_file_uploads   INTEGER NOT NULL DEFAULT 20;
ALTER TABLE users ADD COLUMN php_session_lifetime   INTEGER NOT NULL DEFAULT 1440;
ALTER TABLE users ADD COLUMN php_display_errors     INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN php_allow_url_fopen    INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN php_timezone           TEXT    NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN php_max_children       INTEGER NOT NULL DEFAULT 5;
