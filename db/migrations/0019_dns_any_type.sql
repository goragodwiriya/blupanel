-- 0019_dns_any_type — เปิดให้เก็บเรกคอร์ด DNS ได้ทุกชนิด ไม่ใช่แค่หกชนิดที่เลือกไว้ล่วงหน้า
--
-- **ทำไมต้องเปิด:** รายการปิดที่มี A/AAAA/CNAME/MX/TXT/CAA ครอบงานจริงไม่ครบ และสองกรณี
-- ที่ตกหล่นเจอทุกวันในงานโฮสติ้ง:
--
--   · **SRV** — Microsoft 365, Teams, SIP และ Minecraft ใช้ทั้งหมด
--   · **NS** — การมอบ subdomain ให้ DNS เครื่องอื่นดูแล (delegation)
--
-- และรายการปิดจะตกหล่นต่อไปเรื่อย ๆ ตราบใดที่ยังปิดอยู่ — TLSA (DANE), SSHFP, DS
-- (DNSSEC), HTTPS/SVCB (มาตรฐานใหม่ที่เบราว์เซอร์เริ่มใช้แล้ว), NAPTR, PTR · ทุกครั้งที่
-- มีคนเจอชนิดที่ขาดไป เขาต้องรอ migration ใหม่ ซึ่งเป็นราคาที่แพงเกินไปสำหรับ "พิมพ์
-- ข้อความสามคำลงไฟล์ที่ BIND อ่านอยู่แล้ว"
--
-- **แล้วอะไรกันความผิดพลาดแทน CHECK:**
--
--   1. `DnsRecord::assertType()` — รูปแบบของชื่อชนิดต้องถูกต้อง (ตัวอักษรใหญ่/ตัวเลข
--      หรือรูปแบบ TYPE65535 ตาม RFC 3597) กันการยัดข้อความอื่นลงช่องนี้
--   2. `DnsRecord::assertRdata()` — ค่าห้ามมีอักขระควบคุมหรือขึ้นบรรทัดใหม่ · **นี่คือ
--      ด่านที่สำคัญที่สุด** เพราะค่าถูกเขียนลงไฟล์ที่ BIND อ่าน การขึ้นบรรทัดใหม่ได้
--      แปลว่าแทรกเรกคอร์ดหรือคำสั่ง `$INCLUDE` เพิ่มเองได้
--   3. **`named-checkzone` ตัวจริง** — เป็นตัวตัดสินสุดท้ายว่าไฟล์ใช้ได้จริงไหม ก่อน
--      BIND จะโหลด · แม่นกว่ารายการชนิดที่เราจะเขียนเองได้เสมอ และเป็นตัวเดียวกับที่
--      BIND ใช้ตอนโหลดจริง (หลักการเดียวกับไฟล์ตั้งค่าของเว็บเซิร์ฟเวอร์ในโปรเจกต์นี้)
--
-- ชนิดที่ระบบ "รู้จัก" ยังมีการตรวจค่าเฉพาะทางเหมือนเดิมทุกข้อ (A ต้องเป็น IPv4, CNAME
-- ห้ามเป็น IP ฯลฯ) — การเปิดรายการไม่ได้ทำให้การตรวจที่มีอยู่หย่อนลงแม้แต่ข้อเดียว
--
-- SQLite แก้ `CHECK` ในที่เดิมไม่ได้ ต้องสร้างตารางใหม่แล้วย้ายข้อมูล — แบบเดียวกับ 0017

CREATE TABLE dns_records_new (
    id        INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    type      TEXT    NOT NULL,
    name      TEXT    NOT NULL,
    value     TEXT    NOT NULL,
    ttl       INTEGER NOT NULL DEFAULT 3600,
    priority  INTEGER
);

INSERT INTO dns_records_new (id, domain_id, type, name, value, ttl, priority)
     SELECT id, domain_id, type, name, value, ttl, priority FROM dns_records;

DROP TABLE dns_records;
ALTER TABLE dns_records_new RENAME TO dns_records;

CREATE INDEX idx_dns_domain ON dns_records(domain_id);
