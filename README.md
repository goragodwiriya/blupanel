# BluPanel — PHP Server Control Panel

แผงควบคุมโฮสติ้งสำหรับเซิร์ฟเวอร์ Linux ที่ใช้รันเว็บ PHP เป็นหลัก — จัดการเว็บไซต์ โดเมน DNS
ฐานข้อมูล ใบรับรอง SSL ผู้ใช้ การสำรองข้อมูล และบริการของระบบ จากหน้าจอเดียวที่เป็นภาษาไทย

ติดตั้งด้วยคำสั่งเดียวบนเครื่องเปล่า แล้วได้ระบบที่พร้อมสร้างเว็บไซต์พร้อม HTTPS ได้ทันที

```bash
git clone https://github.com/goragodwiriya/blupanel.git
cd blupanel
sudo ./install.sh
```

รหัสผ่านชั่วคราวของผู้ดูแลจะแสดง **ครั้งเดียว** ตอนจบ · เปิดหน้าจัดการที่
`https://<ไอพีของเครื่อง>:8443` แล้วระบบจะบังคับเปลี่ยนรหัสผ่านตั้งแต่เข้าครั้งแรก

---

## ทำอะไรได้บ้าง

| ด้าน | ความสามารถ |
|---|---|
| เว็บไซต์ | สร้าง/ลบ/พักการใช้งาน · Apache, nginx หรือ nginx+Apache (ได้ `.htaccess` บน nginx) · PHP หลายเวอร์ชันต่อเว็บ · Domain Pointer |
| โดเมนและ DNS | โดเมนหลัก/ย่อย/alias/wildcard · เขียน zone เข้า BIND9 จริงพร้อมตรวจ `named-checkzone` ก่อนใช้ |
| SSL | Let's Encrypt (webroot และ DNS-01 สำหรับ wildcard) · self-signed · ต่ออายุอัตโนมัติ |
| ฐานข้อมูล | MariaDB — สร้างฐาน/ผู้ใช้/สิทธิ์ · เข้า phpMyAdmin ต่อจาก panel ได้โดยไม่ต้องพิมพ์รหัส |
| ผู้ใช้โฮสติ้ง | หนึ่งบัญชี = หนึ่ง uid = หนึ่งบ้าน · โควตาโดเมน/ฐานข้อมูล/อีเมล/พื้นที่ดิสก์ · SFTP แบบ chroot |
| สำรองข้อมูล | ตั้งเวลาอัตโนมัติ · ส่งออกนอกเครื่องผ่าน local/sftp/rsync/S3 · กู้คืนพร้อมตรวจ checksum |
| เฝ้าระวัง | กราฟย้อนหลัง CPU/RAM/ดิสก์ · เกณฑ์แจ้งเตือน · ส่งออกทาง Email/Telegram/Webhook |
| ความปลอดภัย | Security Center · rate limit รายเว็บผ่าน fail2ban · firewall · audit log แบบ hash-chain |
| ระบบ | บริการ · งานตามเวลา · ตัวจัดการไฟล์ · ตั้งค่า SSH · อัปเดตที่ตรวจลายเซ็นก่อนติดตั้ง |

---

## สถาปัตยกรรมโดยย่อ

**แยกสองชั้นเสมอ** — ชั้นเว็บรันด้วยผู้ใช้ `phpcp-web` ที่ล็อกอินไม่ได้และไม่มีสิทธิ์แตะระบบ ·
งานที่ต้องใช้ root ทุกอย่างส่งผ่าน unix socket ไปให้ `phpcp-agentd` ซึ่งรับเฉพาะ **capability
ที่ลงทะเบียนไว้** ไม่ใช่คำสั่งอิสระ · ชั้นเว็บจึงถูกยึดไม่ได้เท่ากับยึดเครื่อง

**ไม่แตะ config ของระบบ** — panel มี config tree ของตัวเองที่ `/etc/phpcp` พร้อม Apache กับ
PHP-FPM instance แยกของตัวเอง ไม่เขียนอะไรลง `/etc/apache2` หรือ `/etc/php` เลยแม้แต่ไฟล์เดียว
ผู้ดูแลจึงยังจัดการเว็บเซิร์ฟเวอร์ของระบบด้วยมือได้ตามปกติ

**ไม่มี dependency ภายนอกให้ดูแล** — ไม่ใช้ Composer, ไม่ดึงอะไรจาก CDN · เก็บ Now.js dist
เข้า repo พร้อม `SHA256SUMS` ที่ `phpcp doctor` ตรวจให้ทุกครั้ง

**ทุกการเขียนไฟล์ตั้งค่าผ่าน `ConfigTransaction`** — เขียนแล้วตรวจ (`apache2 -t`,
`named-checkzone`, `sshd -t`) ก่อน reload เสมอ ไม่ผ่านคือย้อนกลับอัตโนมัติ

รายละเอียดเต็มอยู่ที่ [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

---

## ความต้องการของระบบ

- Debian 12+ / Ubuntu 22.04+ / Linux Mint 21+ (ทดสอบอัตโนมัติทุก push บน Debian 12, Ubuntu 22.04 และ 24.04)
- PHP 8.2 ขึ้นไป — Ubuntu 22.04 ต้องเพิ่ม `ppa:ondrej/php` ซึ่งตัวติดตั้งจัดการให้เอง
- RAM 1 GB · ดิสก์ว่าง 10 GB · สิทธิ์ root

ตัวติดตั้งลงและตั้งค่าให้ครบทั้ง Apache, nginx, MariaDB, BIND9, Postfix, certbot, fail2ban,
ufw, phpMyAdmin, logrotate แล้วปิดเป็นรายข้อได้ถ้าเครื่องมีของพวกนี้อยู่แล้ว —
ดู `./install.sh --help` หรือ [docs/INSTALL.md](docs/INSTALL.md)

---

## คำสั่งที่ใช้บ่อย

```bash
phpcp status                   # สถานะระบบโดยรวม
phpcp doctor                   # ตรวจสิทธิ์ ไฟล์ตั้งค่า scheduler และ checksum ของ Now.js
phpcp user:list                # รายชื่อผู้ใช้
phpcp-scheduler --list         # งานตามเวลาและผลรอบล่าสุด
phpcp audit:verify             # ตรวจความต่อเนื่องของ hash-chain ใน audit log
```

## สำหรับนักพัฒนา

```bash
./install.sh --mode=sandbox --portable   # ติดตั้งในโฟลเดอร์โปรเจกต์ ไม่ต้องใช้ root ไม่แตะระบบจริง
php tests/run.php                        # ชุดทดสอบทั้งหมด (ไม่ต้องใช้ PHPUnit)
php tests/run.php RbacMatrix             # เฉพาะบางกลุ่ม

# ทดสอบ "ติดตั้งบนเครื่องเปล่า" จริง ๆ — ถ้าตัวติดตั้งพัง build จะล้ม
docker build -f docker/Dockerfile.install-test --build-arg BASE=debian:12 -t phpcp:it .
docker run --rm phpcp:it bash /usr/local/src/phpcp/docker/acceptance.sh
```

`tests/run.php` เป็นตัวรันขนาดเล็กที่เขียนเอง ทุกเทสต์รันในโหมด dryrun/sandbox ได้จึงไม่ต้องใช้ root

---

## ความปลอดภัย

อ่าน [docs/SECURITY.md](docs/SECURITY.md) ให้จบก่อนเปิดใช้งานจริง — มี threat model, ชั้นการป้องกัน
ตามชนิดผู้โจมตี และ **รายการตรวจก่อนขึ้น production** ที่ต้องทำให้ครบ (2FA, เปลี่ยนใบรับรองจาก
self-signed, ปิด SSH root login, ตั้ง audit log แบบ append-only ฯลฯ)

พบช่องโหว่กรุณาแจ้งเป็นการส่วนตัวก่อนเปิดเผยสาธารณะ

---

## เอกสาร

| ไฟล์ | เนื้อหา |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | ติดตั้ง อัปเดต ถอนการติดตั้ง แก้ปัญหาที่พบบ่อย |
| [docs/USER-GUIDE.md](docs/USER-GUIDE.md) | คู่มือใช้งานหน้าจอ |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | โครงสร้างภายใน ชั้น agent โหมดการทำงาน |
| [docs/SECURITY.md](docs/SECURITY.md) | threat model และรายการตรวจก่อน production |
| [docs/openapi.yaml](docs/openapi.yaml) | สเปก REST API v2 |
| [docs/PLAN-V2.md](docs/PLAN-V2.md) | แผนงานและสถานะของแต่ละเฟส |

## สัญญาอนุญาต

[MIT](LICENSE) — Copyright (c) 2026 Goragod Wiriya
