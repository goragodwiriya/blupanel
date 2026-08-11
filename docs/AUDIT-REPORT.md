# รายงานตรวจสอบโปรเจกต์ phpcp — API ทั้งหมด, ความพร้อมใช้งานจริง, และความเป็นไปได้ในการแยก Frontend/API

> ตรวจสอบเมื่อ: 2026-08-05 · ตรวจจาก**ซอร์สโค้ดจริง**ในเครื่อง (239 ไฟล์ PHP ใน `src/`) ไม่ใช่จากเอกสารออกแบบเพียงอย่างเดียว
> ยืนยันด้วยการรันจริง: `php tests/run.php` → **ผ่าน 209 · ไม่ผ่าน 0 · ยืนยัน 1,308 ครั้ง** (35 ms) — เอกสาร `docs/ROADMAP.md` เขียนไว้ที่ 201/201 ซึ่งเป็นเลขเก่ากว่า แปลว่าเอกสารตามหลังโค้ดจริงเล็กน้อยแต่ไม่ได้กล่าวเกินจริง
> เอกสารนี้เป็นรายงานเพิ่มเติมจาก [ARCHITECTURE.md](ARCHITECTURE.md) / [SECURITY.md](SECURITY.md) / [ROADMAP.md](ROADMAP.md) ที่มีอยู่แล้ว — ไม่ได้แทนที่ แต่ตรวจทานของจริงเทียบกับที่เอกสารเดิมอ้าง แล้วตอบ 3 คำถามที่ถูกถามตรง ๆ

---

## สารบัญ

0. [สรุปสำหรับผู้บริหาร](#0-สรุปสำหรับผู้บริหาร)
1. [ภาพรวมสถาปัตยกรรมโดยย่อ](#1-ภาพรวมสถาปัตยกรรมโดยย่อ)
2. [คำถามที่ 1 — มี API อะไรบ้าง](#2-คำถามที่-1--มี-api-อะไรบ้าง)
3. [คำถามที่ 2 — โปรเจกต์นี้สมบูรณ์แค่ไหนสำหรับใช้งานจริง](#3-คำถามที่-2--โปรเจกต์นี้สมบูรณ์แค่ไหนสำหรับใช้งานจริง)
4. [คำถามที่ 3 — แยก Frontend ออกจาก API ได้ไหม](#4-คำถามที่-3--แยก-frontend-ออกจาก-api-ได้ไหม)
5. [ข้อเสนอแนะโดยรวม](#5-ข้อเสนอแนะโดยรวม)

---

## 0. สรุปสำหรับผู้บริหาร

**โปรเจกต์นี้คืออะไร:** `phpcp` เป็น Control Panel สำหรับบริหาร Web Hosting บน Linux (คล้าย Plesk แต่เบากว่า) เขียนด้วย PHP 8.4 ล้วน ไม่มี framework ไม่มี Composer ไม่มี npm สถาปัตยกรรมแบ่ง 3 ชั้นอย่างเข้มงวด: (1) เว็บ UI ที่ไม่มีสิทธิ์ root เลย (2) agent daemon รันเป็น root ตัวเดียวที่สื่อสารผ่าน unix socket ด้วยคำสั่งชนิด "capability" ที่ตรวจ schema ทุกตัว ไม่มีการส่ง shell string เด็ดขาด (3) ระบบปฏิบัติการจริง (Apache/Nginx, PHP-FPM, MariaDB, ufw, certbot, postfix, BIND9)

**คำถามที่ 1 (API มีอะไรบ้าง):** ระบบมี "API" อยู่ 2 ชั้นซ้อนกัน — (ก) **HTTP layer** 99 routes ส่วนใหญ่เป็นฟอร์ม POST ที่ตอบกลับด้วย HTML redirect (มี query string บอกผล) มีเพียงบางส่วน (`/api/*`, ตัวจัดการไฟล์, การสั่งงาน service) ที่ตอบ JSON จริง และ (ข) **Agent Capability layer** ภายใน 61 capability ที่ทำงานจริงกับระบบปฏิบัติการ (สร้างเว็บไซต์, restart service, ออก SSL, backup ฯลฯ) — นี่คือ "API" ที่มีวินัยด้านความปลอดภัยสูงสุดในระบบ รายละเอียดครบทั้งสองชั้นอยู่ในหัวข้อ 2

**คำถามที่ 2 (สมบูรณ์แค่ไหน):** ด้าน**วิศวกรรมความปลอดภัยทำได้ดีจริง**และตรวจสอบยืนยันได้ — Argon2id, TOTP แท้ (ไม่พึ่ง library), audit log แบบ hash-chain ที่ตรวจสอบได้จริง, CSRF, path-traversal guard, การแยกสิทธิ์ต่อเว็บไซต์ — ทดสอบผ่านจริง 209/209 ไม่ใช่ตัวเลขโฆษณา แต่ด้าน**ความสมบูรณ์เชิงธุรกิจโฮสติ้ง**ยังมีช่องว่างสำคัญที่ต้องรู้ก่อนใช้งานจริงกับลูกค้าที่จ่ายเงิน: **ไม่มี disk quota บังคับจริง**, **backup เก็บในเครื่องเดียวเท่านั้น (ไม่มี offsite)**, **DNS เป็นแค่ตัวส่งออก zone file ไม่ใช่ DNS server ที่ทำงานจริงแม้จะติดตั้ง BIND9 ให้**, **ไม่มี WAF/rate-limit ระดับเว็บไซต์ลูกค้า**, **ไม่มี FTP** (แต่ UI มีช่อง quota FTP ที่ไม่มีฟีเจอร์รองรับ — จุดนี้ควรแก้ก่อนอื่น), ไม่มี mailbox hosting/billing (แต่เอกสารระบุไว้ชัดว่าตั้งใจไม่ทำ), รองรับเฉพาะ Debian/Ubuntu, ไม่มี multi-server รายละเอียดเต็มพร้อมระดับความเสี่ยงอยู่ในหัวข้อ 3

**คำถามที่ 3 (แยก Frontend/API ได้ไหม):** **ได้ และความเสี่ยงต่ำกว่าที่คาด** เพราะส่วนที่ยากที่สุดของระบบ (การรันคำสั่งสิทธิ์สูงอย่างปลอดภัยผ่าน Agent/Capability) **แยกออกจาก HTML อยู่แล้วตั้งแต่ต้น** ไม่ต้องแตะเลย สิ่งที่ต้องทำเพิ่มคือชั้น Controller ใหม่ที่บาง ๆ คืน JSON แทน HTML/redirect ซึ่งมีต้นแบบที่ใช้งานจริงอยู่แล้วใน `FileController` และ `ServiceController` — งานส่วนใหญ่คือทำซ้ำแพทเทิร์นเดิมกับอีก ~15 controller ไม่ใช่ออกแบบใหม่ทั้งหมด แนะนำแนวทางเพิ่ม `/api/v2/*` ควบคู่ของเดิมแบบไม่ทำลายของเก่า แล้วค่อยตัดสินใจทีหลังว่าจะถอด HTML UI ออกหรือไม่ รายละเอียดแผนงานเป็นเฟสอยู่ในหัวข้อ 4

---

## 1. ภาพรวมสถาปัตยกรรมโดยย่อ

```
เบราว์เซอร์ (HTTPS)
   │
   ▼
┌─────────────────────────────────────────┐
│ ชั้น 1 — Web UI (user: phpcp-web)         │  ไม่มี exec/proc_open/shell_exec เลย
│ Router → Middleware 7 ตัว → Controller    │  แม้โดน RCE ก็สั่ง shell ไม่ได้
│ → View (PHP template) → HTML             │
└─────────────────────────────────────────┘
   │  unix socket 0660 root:phpcp (JSON 1 บรรทัดต่อ request)
   ▼
┌─────────────────────────────────────────┐
│ ชั้น 2 — phpcp-agentd (user: root)        │  จุดเดียวในระบบที่มีสิทธิ์สูง
│ CapabilityRegistry (allowlist ตายตัว)     │  ไม่มี capability แบบ generic
│ → validate() ตรวจ schema ทุก argument     │  → AuditLog (ก่อนทำ) → run()
│ → Executor: proc_open(argv[]) ไม่ผ่าน sh  │
└─────────────────────────────────────────┘
   │
   ▼
ชั้น 3 — systemd / Apache / Nginx / PHP-FPM / MariaDB / ufw / certbot / postfix / BIND9
```

ตัวเลขที่ตรวจยืนยันแล้วในเครื่องนี้:

| รายการ | จำนวน | วิธีตรวจ |
|---|---|---|
| ไฟล์ PHP ทั้งหมดใน `src/` | 239 | `find` |
| HTTP routes (`src/Kernel/Routes.php`) | 99 | `grep -c '$router->add'` |
| ไฟล์ Agent Capability | 69 ไฟล์ (61 capability ที่ลงทะเบียนจริง, 8 เป็นคลาสฐาน/ตัวช่วย) | อ่านโค้ด + เทียบกับ `CapabilityRegistry::defaults()` |
| ผลทดสอบ (`php tests/run.php`) | **209 ผ่าน / 0 ไม่ผ่าน / 1,308 assertion** | รันจริงบนเครื่องนี้ |
| โหมดการทำงาน | production / sandbox / dryrun | `Executor` 3 implementation |

---

## 2. คำถามที่ 1 — มี API อะไรบ้าง

ระบบนี้ไม่มี "API" แบบ REST เดี่ยว ๆ ให้เรียกจากภายนอกโดยตรง — สิ่งที่มีคือ **HTTP layer** (ที่ผู้ใช้ปลายทางคือเบราว์เซอร์เป็นหลัก) ซ้อนอยู่บน **Agent Capability layer** (internal privileged API ผ่าน unix socket) ทั้งสองชั้นมีรายละเอียดเต็มด้านล่าง

### 2.1 โครงสร้างการเรียกใช้ทั่วไป (HTTP Request Contract)

ถ้าต้องการเขียนสคริปต์/โปรแกรมภายนอกมาเรียก endpoint เหล่านี้ ต้องรู้กติกาต่อไปนี้ก่อน:

**Session cookie**
- ชื่อคุกกี้: `phpcp_sid` ปกติ หรือ `__Host-phpcp_sid` เมื่อเปิด `panel.cookie_secure` (deploy จริงผ่าน HTTPS)
- Attribute: `HttpOnly`, `SameSite=Strict`, `Secure` (เมื่อเปิด cookie_secure)
- คุกกี้หมุนรอบ (rotate) เป็นระยะและทันทีหลังล็อกอิน/เปลี่ยนรหัสผ่าน — ต้องเก็บค่า `Set-Cookie` ล่าสุดเสมอ ไม่ใช่ค่าจากตอนแรก
- **ไม่มี bearer token / API key ในระบบเลย** — ทางเข้าเดียวคือคุกกี้ที่ได้จาก `POST /login` (+ `POST /login/2fa` ถ้าเปิด TOTP)

**CSRF token** (`src/Security/Csrf.php`, `src/Middleware/CsrfProtection.php`)
- คำนวณจาก `HMAC-SHA256(session_id, secretKey)` — ผูกกับทั้ง session ไม่ใช่ผูกกับแต่ละฟอร์ม (เคยออกแบบให้ผูกกับ path ของฟอร์มด้วย แต่พบว่าใช้งานจริงไม่ได้เพราะฟอร์มจำนวนมากเรนเดอร์ที่หน้าหนึ่งแล้ว POST ไปอีกเส้นทาง จึงเปลี่ยนมาผูกกับ session อย่างเดียว — ดู ROADMAP.md เฟส 1)
- ส่งกลับมาได้ 2 ทาง: ฟิลด์ฟอร์ม `_token` หรือ header `X-CSRF-Token` (เช็คฟิลด์ก่อน header)
- อ่านค่าปัจจุบันได้จาก `<meta name="csrf-token">` หรือ `<input type="hidden" name="_token">` ในหน้า HTML ที่ล็อกอินแล้ว — **ไม่มี endpoint JSON ที่คืนค่า token เปล่า ๆ** ต้อง GET หน้า HTML ก่อนเสมอเพื่อขูด token
- ผิดพลาด → HTTP `419` พร้อม `{"ok":false,"error":"เซสชันหมดอายุ..."}`

**การตอบกลับ JSON vs HTML** (`Request::wantsJson()`)
ถือว่าเป็น JSON เมื่อ: มี header `Accept: application/json` หรือ `X-Requested-With: fetch` หรือ path ขึ้นต้นด้วย `/api/`
รูปแบบการตอบมี 4 แบบปนกันในระบบ:
1. **HTML เสมอ** — หน้ารายการ/รายละเอียดส่วนใหญ่ (`GET`)
2. **Redirect (303) เสมอ ไม่มี JSON เลย** — การเปลี่ยนแปลงส่วนใหญ่ใน Hosting/Server (Site, Domain, Ssl, Database, Cron, Backup, Firewall, Ssh, Settings, Customer, User) — ผลลัพธ์แนบมาทาง query string `?ok=...` / `?err=...` แม้จะส่ง header ขอ JSON ไปก็ตาม
3. **แยกจริงตาม `wantsJson()`** — เฉพาะ `FileController` (save/folder/move/delete/chmod/zip/unzip/upload) และ `ServiceController::action`
4. **`/api/*` — JSON เสมอ** (path prefix พอแล้ว) ยกเว้น `GET /api/stream/metrics` ที่เป็น Server-Sent Events

รูปแบบ envelope มาตรฐานเมื่อเป็น JSON:
```json
{"ok": true,  "data": { ... }}
{"ok": false, "error": "ข้อความภาษาไทยอธิบายสาเหตุ"}
```

**Rate limit / IDOR / secret**
- `/login` และ endpoint อื่นมี rate limit แบบ token bucket เก็บใน SQLite → เกินโควตาได้ `429` + `Retry-After`
- IDOR (สิทธิ์ข้ามเว็บไซต์ของ `webadmin`) ถูกตรวจ**สองชั้น**: ที่ controller (เช่น `SiteController::mayAccess`) และซ้ำอีกครั้งที่ Agent/Dispatcher — อย่าเชื่อว่าผ่านชั้นแรกแล้วปลอดภัย
- รหัสผ่านที่สุ่มใหม่ (สร้างผู้ใช้ DB, ผู้ใช้ panel, ลูกค้า) ส่งกลับทาง query string `?pw=...` ของ redirect **ครั้งเดียว** เท่านั้น ไม่ถูกเก็บซ้ำที่ไหน — สคริปต์อัตโนมัติต้องอ่านจาก `Location` header ของ request ที่สร้าง/รีเซ็ตทันที

### 2.2 ตาราง HTTP Endpoint ทั้งหมด (99 routes)

#### Auth (`AuthController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /login | query `next` | สาธารณะ | HTML | ฟอร์มล็อกอิน |
| POST /login | `username`,`password`,`next`,`_token` | สาธารณะ | Redirect → `/` หรือ `/login/2fa` หรือ `/account/password` | ตรวจรหัสผ่านแบบ constant-time (hash เปล่าแม้ user ไม่มีจริง กัน user enumeration) |
| GET /login/2fa | query `next` | สาธารณะ (เฉพาะช่วงรอ 2FA) | HTML | หน้ากรอกรหัส TOTP/recovery code |
| POST /login/2fa | `code`,`next`,`_token` | สาธารณะ (เฉพาะช่วงรอ 2FA) | Redirect | ยืนยัน TOTP หรือ recovery code ใช้ครั้งเดียว |
| POST /logout | `_token` | `dashboard.view` | Redirect → `/login` | ทำลาย session |
| GET /account/password | — | `dashboard.view` | HTML | ฟอร์มเปลี่ยนรหัสผ่าน (รวมกรณีถูกบังคับเปลี่ยน) |
| POST /account/password | `current_password`,`new_password`,`confirm_password`,`_token` | `dashboard.view` | Redirect `/` หรือ HTML 422 | ทำลาย session อื่นทั้งหมดของผู้ใช้เมื่อสำเร็จ |

#### Dashboard

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET / | — | `dashboard.view` | HTML | เรียก capability `system.metrics` + `service.status`, นับ sites/domains/databases/certs/backups จาก SQLite, audit log ล่าสุด 8 รายการ |

#### Hosting → เว็บไซต์ (`SiteController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /sites | query `ok`,`err` | `site.view` | HTML | `webadmin` เห็นเฉพาะเว็บของตัวเอง |
| POST /sites | `domain`,`name`,`php_version`,`aliases`,`docroot`,`pointer_root`,`owner_user_id`,`_token` | `site.create` | Redirect `/sites/{id}` | → capability `site.create` |
| GET /sites/{id} | query `tab` | `site.view` | HTML | 8 แท็บ: overview/domains/php/ssl/databases/files/logs/backup |
| POST /sites/{id}/php | `php_version`,`_token` | `site.edit` | Redirect | → `site.set_php` |
| POST /sites/{id}/domains | `domains`,`type`,`_token` | `domain.manage` | Redirect | → `site.set_domains` (แทนที่ทั้งชุด) |
| POST /sites/{id}/domains/add | `host`,`path`,`_token` | `domain.manage` | Redirect | → `site.add_domain` |
| POST /sites/{id}/domains/remove | `domain`,`_token` | `domain.manage` | Redirect | → `site.remove_domain` |
| POST /sites/{id}/reset-owner | `fix_permissions`,`_token` | `site.edit` | Redirect | → `site.reset_owner` (ทีละเว็บ ไม่มีปุ่ม "ซ่อมทั้งหมด") |
| POST /sites/{id}/suspend | `_token` | `site.suspend` | Redirect | → `site.suspend` (ตอบ 503 ไม่ใช่ 403) |
| POST /sites/{id}/resume | `_token` | `site.suspend` | Redirect | → `site.resume` |
| POST /sites/{id}/delete | `confirm_domain`,`_token` | `site.delete` | Redirect | ต้องพิมพ์ชื่อโดเมนซ้ำยืนยัน + ย้ายเข้าถังพักก่อนลบจริง |

#### Hosting → โดเมน (`DomainController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /domains | query `domain` | `domain.view` | HTML | รายการโดเมน + DNS records ของโดเมนที่เลือก |
| POST /domains/add | `site_id`,`host`,`path`,`_token` | `domain.manage` | Redirect | → `site.add_domain` |
| POST /domains/{id}/records | `type`(A/AAAA/CNAME/MX/TXT/CAA),`name`,`value`,`ttl`,`priority`,`_token` | `domain.manage` | Redirect | เขียน `dns_records` ตรง (ไม่ผ่าน agent) |
| POST /domains/{id}/remove | `_token` | `domain.manage` | Redirect | → `site.remove_domain` (ลบโดเมนหลักไม่ได้) |
| POST /records/{id}/delete | `_token` | `domain.manage` | Redirect | ลบ DNS record ตรง |
| GET /domains/{id}/zone | — | `domain.view` | ไฟล์ `text/plain` (`.zone`) | ส่งออก BIND-style zone file |

#### Hosting → SSL (`SslController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /ssl | — | `ssl.view` | HTML | → `ssl.list` |
| POST /ssl/{id}/issue | `method`(letsencrypt/self-signed),`email`,`staging`,`_token` | `ssl.manage` | Redirect | → `ssl.issue` (ไม่เปิด HTTPS ให้อัตโนมัติ) |
| POST /ssl/{id}/renew | `force`,`_token` | `ssl.manage` | Redirect | → `ssl.renew` |
| POST /ssl/{id}/mode | `mode`(off/on/forced),`_token` | `ssl.manage` | Redirect | → `ssl.set_mode` |
| POST /ssl/{id}/delete | `confirm_domain`,`_token` | `ssl.manage` | Redirect | → `ssl.delete` |

#### Hosting → PHP (`PhpController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /php | — | `php.view` | HTML | → `php.list` อ่านอย่างเดียว ปุ่มควบคุมโปรเซสอยู่หน้า Services เท่านั้นตามกฎ UX ของ PROMPT.md |

#### Hosting → ฐานข้อมูล (`DatabaseController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /databases | query `q`,`page` | `db.view` | HTML | → `db.list` (ผสาน SQLite panel กับ `SHOW DATABASES` จริง) |
| POST /databases | `name`,`username`,`host`,`privileges`,`site_id`,`charset`,`_token` | `db.manage` | Redirect `?ok&user&pw` | → `db.create` |
| POST /databases/drop | `name`,`confirm`,`drop_user`,`_token` | `db.manage` | Redirect | → `db.drop` (สำรองอัตโนมัติก่อนลบเสมอ) |
| POST /databases/password | `username`,`host`,`_token` | `db.manage` | Redirect `?ok&user&pw` | → `db.user_password` |

#### Hosting → ตัวจัดการไฟล์ (`FileController`) — ตัวเดียวที่มี JSON dual-mode ครบ

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /files | query `root`,`path`,`page` | `file.view` | HTML | → `file.list` |
| GET /files/download | query `root`,`path` | `file.view` | Raw bytes (`attachment`+`nosniff`) | → `file.download` |
| POST /files/save | `root`,`path`,`content`,`create`,`_token` | `file.manage` | **JSON ถ้า fetch / redirect ถ้าฟอร์ม** | → `file.write` (เขียนแบบ atomic) |
| POST /files/folder | `root`,`path`,`name`,`_token` | `file.manage` | JSON/redirect | → `file.mkdir` |
| POST /files/move | `root`,`path`,`items[]`,`destination`,`rename`,`copy`,`overwrite`,`_token` | `file.manage` | JSON/redirect | → `file.move` (ย้าย/เปลี่ยนชื่อ/คัดลอก) |
| POST /files/delete | `root`,`path`,`items[]`,`_token` | `file.manage` | JSON/redirect | → `file.delete` |
| POST /files/chmod | `root`,`path`,`mode`,`recursive`,`_token` | `file.manage` | JSON/redirect | → `file.chmod` (allowlist โหมดเท่านั้น) |
| POST /files/zip | `root`,`path`,`items[]`,`archive`,`_token` | `file.manage` | JSON/redirect | → `file.zip` |
| POST /files/unzip | `root`,`path`,`destination`,`_token` | `file.manage` | JSON/redirect | → `file.unzip` (กัน Zip Slip ใน `Executor::unzip()`) |
| POST /files/upload | `root`,`path`,multipart `files`,`overwrite`,`_token` | `file.manage` | `{"ok":true,"data":{"uploaded":[...]}}` | → `file.upload` ทีละไฟล์ (base64) |
| GET /api/files | query `root`,`path`,`page`,`per_page` | `file.view` | JSON เสมอ | → `file.list` (JS เรียกตอนเลื่อนหน้า) |
| GET /api/files/read | query `root`,`path` | `file.view` | JSON เสมอ | → `file.read` (เปิดไฟล์ในตัวแก้ไข) |
| GET /api/files/browse | query `root`,`path`,`page`,`per_page` | `file.view` | JSON เสมอ | ต้นไม้โฟลเดอร์ฝั่งซ้าย |

#### Hosting → งานอัตโนมัติ / สำรองข้อมูล (`CronController`, `BackupController`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /cron | — | `cron.view` | HTML | อ่าน `cron_jobs` ตรง |
| POST /cron | `id`,`site_id`,`schedule`,`name`,`command`,`enabled`,`_token` | `cron.manage` | Redirect | เขียน DB ตรง + → `cron.sync` (rollback DB ถ้า sync ล้ม) |
| POST /cron/{id}/toggle | `_token` | `cron.manage` | Redirect | สลับ enabled + `cron.sync` |
| POST /cron/{id}/delete | `_token` | `cron.manage` | Redirect | ลบแถว + `cron.sync` |
| GET /backups | — | `backup.view` | HTML | อ่าน `backups` ตรง |
| POST /backups | `type`,`site_id`,`database`,`note`,`_token` | `backup.manage` | Redirect | → `backup.create` |
| POST /backups/{id}/restore | `confirm`,`_token` | `backup.restore` | Redirect | → `backup.restore` (เฉพาะ type=site) |
| POST /backups/{id}/delete | `_token` | `backup.manage` | Redirect | → `backup.delete` |

#### Server (`Overview`, `Service`, `Security`, `Firewall`, `Ssh`, `Log`, `User`, `Settings`)

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /server | — | `server.view` | HTML | `system.info` + `system.metrics` + `service.status` |
| GET /server/services | — | `service.view` | HTML | → `service.status`, จัดกลุ่มตามชนิด, แสดงเว็บไซต์ที่พึ่งพา |
| POST /server/services/{unit}/{action} | `_token`; `action`∈{start,stop,restart,reload} | `service.control` | **JSON ถ้า fetch / redirect ถ้าฟอร์ม** | → `service.<action>` |
| GET /server/security | query `err` | `security.view` | HTML | → `security.scan` (คะแนน 0–100 พร้อมปุ่มแก้จริง) |
| GET /server/firewall | — | `firewall.view` | HTML | → `firewall.status` |
| POST /server/firewall/rules | `action`,`port`,`protocol`,`source`,`comment`,`window`,`_token` | `firewall.manage` | Redirect | → `firewall.rule_add` |
| POST /server/firewall/rules/{number}/delete | `expect`,`window`,`_token` | `firewall.manage` | Redirect | → `firewall.rule_delete` (มี auto-rollback) |
| POST /server/firewall/enable | `window`,`_token` | `firewall.manage` | Redirect | → `firewall.enable` (auto-rollback ถ้าไม่ยืนยัน) |
| POST /server/firewall/disable | `_token` | `firewall.manage` | Redirect | → `firewall.disable` |
| POST /server/firewall/{id}/confirm | `_token` | `firewall.manage` | Redirect | → `rollback.confirm` |
| POST /server/firewall/{id}/rollback | `_token` | `firewall.manage` | Redirect | → `rollback.run` |
| GET /server/ssh | — | `ssh.view` | HTML | → `ssh.config_get` |
| POST /server/ssh | `Port`,`PermitRootLogin`,`PasswordAuthentication`,`PubkeyAuthentication`,`PermitEmptyPasswords`,`window`,`_token` | `ssh.manage` | Redirect | → `ssh.config_set` (auto-rollback) |
| POST /server/ssh/{id}/confirm | `_token` | `ssh.manage` | Redirect | → `rollback.confirm` |
| POST /server/ssh/{id}/rollback | `_token` | `ssh.manage` | Redirect | → `rollback.run` |
| GET /server/logs | query `source`,`lines`,`q`,`level` | `log.view` | HTML | → `system.logs_tail` |
| GET /server/users | — | `user.view` | HTML | รายชื่อผู้ใช้ panel + จำนวน session ที่ active |
| POST /server/users | `username`,`role`,`display_name`,`_token` | `user.manage` | Redirect `?ok&pw` | สุ่มรหัสผ่าน 20 ตัว บังคับเปลี่ยนครั้งแรก |
| POST /server/users/{id}/role | `role`,`_token` | `user.manage` | Redirect | ห้ามเปลี่ยน role ตัวเอง / ห้ามลบ superadmin คนสุดท้าย |
| POST /server/users/{id}/status | `status`,`_token` | `user.manage` | Redirect | ปิดใช้งาน → ยกเลิก session ทั้งหมด |
| POST /server/users/{id}/password | `_token` | `user.manage` | Redirect `?ok&pw` | รีเซ็ตรหัสผ่านสุ่ม |
| POST /server/users/{id}/disable-2fa | `_token` | `user.manage` | Redirect | ล้าง TOTP secret |
| POST /server/users/{id}/delete | `_token` | `user.manage` | Redirect | ห้ามลบตัวเอง/superadmin คนสุดท้าย |
| GET /server/settings | query `err` | `settings.view` | HTML | → `settings.get` |
| POST /server/settings | ทุกคีย์ใน `SettingsRepository::KEYS` (notify.*, mail.*),`_token` | `settings.manage` | Redirect | → `settings.set` (ค่า secret ที่ไม่ได้แก้ ไม่ถูกเขียนทับ) |
| POST /server/settings/notify-test | `_token` | `settings.manage` | Redirect | → `notify.test` (Telegram) |
| POST /server/settings/mail-apply | `hostname`,`_token` | `settings.manage` | Redirect | → `mail.apply` (เขียน Postfix) |
| POST /server/settings/mail-test | `to`,`_token` | `settings.manage` | Redirect | → `mail.test` |

#### ลูกค้า (`CustomerController`) — สำหรับขาย hosting ให้ลูกค้าปลายทาง

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /customers | — | `customer.view` | HTML | รายชื่อลูกค้า + การใช้งาน/โควตา |
| POST /customers | `username`,`display_name`,`email`,`password`,quota 6 ตัว,`expiry_at`,`domain`(สร้างเว็บแรกพร้อมกัน),`php_version`,`_token` | `customer.manage` | Redirect `?ok&pw` | สร้างลูกค้า + ผู้ใช้ panel เชื่อมกัน; ถ้าใส่ domain จะเรียก `site.create` ด้วย |
| POST /customers/{id}/edit | `display_name`,`email`,`password`,`_token` | `customer.manage` | Redirect | แก้โปรไฟล์ตรง |
| POST /customers/{id}/quota | 6 ฟิลด์ quota_*,`_token` | `customer.manage` | Redirect | แก้ DB ตรง |
| POST /customers/{id}/expiry | `expiry_at`,`_token` | `customer.manage` | Redirect | แก้ DB ตรง |
| POST /customers/{id}/status | `status`(active/suspended/expired),`_token` | `customer.manage` | Redirect | แก้ DB ตรง |
| POST /customers/{id}/site-attach | `site_ids[]`,`_token` | `customer.manage` | Redirect (สรุปจำนวนที่เกินโควตา) | ตรวจโควตาต่อเว็บก่อนผูก |
| POST /customers/{id}/site-detach | `site_id`,`_token` | `customer.manage` | Redirect | ถอด owner |
| POST /customers/{id}/password | `password`,`_token` | `customer.manage` | Redirect `?ok&pw` | ยกเลิก session ผู้ใช้ที่ผูกอยู่ |
| POST /customers/{id}/delete | `_token` | `customer.manage` | Redirect | ปฏิเสธถ้ายังมีเว็บผูกอยู่ |

#### `/api/*` — JSON surface โดยตรง

| Method + Path | พารามิเตอร์ | Permission | รูปแบบตอบกลับ | คำอธิบาย |
|---|---|---|---|---|
| GET /api/metrics | — | `dashboard.view` | `{"ok":true,"data":{cpu,ram,disk,load,...}}` | → `system.metrics` |
| GET /api/services | query `units[]` | `service.view` | `{"ok":true,"data":[...]}` | → `service.status` |
| GET /api/health | — | `dashboard.view` | `{"ok":true,"data":{"agent":bool,"socket":...,"mode":...}}` | ไม่เรียก agent จริง แค่เช็คว่าต่อได้ไหม ใช้แสดงแบนเนอร์ "agent ล่ม" |
| GET /api/logs | query `source`,`lines`,`q`,`level` | `log.view` | JSON | → `system.logs_tail` |
| GET /api/stream/metrics | — | `dashboard.view` | **`text/event-stream` (SSE ไม่ใช่ JSON)** | poll `system.metrics` ทุก 2 วิ นานสุด 30 นาที |

### 2.3 ตัวอย่างการเรียกใช้จริง (curl)

**ล็อกอิน (ไม่มี 2FA):**
```bash
curl -s -c cookies.txt https://panel.example.com/login -o login.html
TOKEN=$(grep -oP 'name="_token" value="\K[^"]+' login.html | head -1)

curl -s -b cookies.txt -c cookies.txt -X POST https://panel.example.com/login \
  -d "username=admin" -d "password=xxxxx" -d "_token=${TOKEN}" -d "next=/" -i
# → 303 Location: /   (หรือ /login/2fa ถ้าเปิด TOTP)
```

หลังล็อกอินต้อง GET หน้าใดก็ได้ 1 ครั้งเพื่อขูด token ใหม่ที่ผูกกับ session ที่หมุนแล้ว:
```bash
curl -s -b cookies.txt https://panel.example.com/ -o home.html
TOKEN=$(grep -oP 'name="csrf-token" content="\K[^"]+' home.html | head -1)
```

**สร้างเว็บไซต์ใหม่:**
```bash
curl -s -b cookies.txt -X POST https://panel.example.com/sites \
  -d "domain=example.com" -d "php_version=8.4" -d "_token=${TOKEN}" -i
# → 303 Location: /sites/42?ok=... (สำเร็จ) หรือ /sites?err=... (เช่นโดเมนซ้ำ)
```

**Restart service (ตอบ JSON จริงเพราะส่ง header fetch):**
```bash
curl -s -b cookies.txt -X POST https://panel.example.com/server/services/nginx/restart \
  -H "Accept: application/json" -H "X-CSRF-Token: ${TOKEN}" -i
# → {"ok": true, "data": {"message": "รีสตาร์ท nginx แล้ว"}}
```

**อัปโหลดไฟล์ (multipart):**
```bash
curl -s -b cookies.txt -X POST https://panel.example.com/files/upload \
  -H "Accept: application/json" \
  -F "_token=${TOKEN}" -F "root=site-42-docroot" -F "path=uploads" \
  -F "overwrite=1" -F "files=@./photo.jpg"
# → {"ok": true, "data": {"uploaded": ["photo.jpg"]}}
```

### 2.4 ชั้น Capability (Agent layer) — "API ภายใน" ที่คุมสิทธิ์สูงทั้งหมด

นี่คือชั้นที่สำคัญที่สุดของระบบด้านความปลอดภัย — HTTP controller ทุกตัวที่ต้องทำอะไรกับระบบปฏิบัติการ (สร้างผู้ใช้ Linux, restart service, เขียน vhost, ออก SSL ฯลฯ) **ต้อง**เรียกผ่านชั้นนี้เท่านั้น ไม่มีทางลัด แต่ละ capability คือ 1 คลาส 1 ไฟล์ มี `validate()` ตรวจ schema ก่อนเสมอ แล้ว `run()` เป็นคนสั่งงานจริงผ่าน `Executor::exec(argv[])` (ไม่ผ่าน shell เด็ดขาด) พบทั้งหมด **61 capability ที่ลงทะเบียนใช้งานจริง** (จาก 69 ไฟล์ — ที่เหลือ 8 ไฟล์เป็นคลาสฐาน/ตัวช่วยที่ไม่มี `NAME` ของตัวเอง)

#### System

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `system.info` | — | ข้อมูลเครื่อง (hostname, OS, CPU, filesystem) | `GET /server` |
| `system.metrics` | — | CPU/RAM/Disk/Network/Load แบบสด อ่านจาก `/proc` ตรง ไม่ fork | `GET /`, `/server`, `/api/metrics`, `/api/stream/metrics` |

#### Service

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `service.status` | `services: list<string>` (≤32 รายการ, ผ่าน `SelfProtection::assertUnit`) | อ่านสถานะ systemd หลายตัวพร้อมกัน | `GET /server/services`, `/api/services` |
| `service.start`/`.stop`/`.restart`/`.reload` | `service: string` (≤64, allowlist `ServiceCatalog`) | สั่งงานบริการ | `POST /server/services/{unit}/{action}` |

#### Site / Hosting

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `site.create` | `domain`(regex RFC1123), `name?`, `php_version`(allowlist), `aliases?`(≤20), `docroot?`, `pointer_root?`, `owner_user_id?` | สร้างเว็บไซต์ครบ 6 ขั้น (useradd→pool→vhost→reload→DB→audit) แบบ transactional | `POST /sites`, `POST /customers` |
| `site.set_php` | `site_id`, `php_version` | เปลี่ยน PHP version ต่อเว็บ | `POST /sites/{id}/php` |
| `site.set_domains` | `site_id`, `domains: list`(≤50), `type` | แทนที่ชุดโดเมนย่อย/alias ทั้งหมด | `POST /sites/{id}/domains` |
| `site.add_domain` | `site_id`, `host`, `path?` | เพิ่มโดเมนย่อยทีละรายการ (สูงสุด 50/เว็บ) | `POST /sites/{id}/domains/add`, `/domains/add` |
| `site.remove_domain` | `site_id`, `domain` | ลบโดเมนย่อย/alias (ลบโดเมนหลักไม่ได้) | `POST /sites/{id}/domains/remove`, `/domains/{id}/remove` |
| `site.suspend`/`.resume` | `site_id` | ระงับ/เปิดเว็บ (ตอบ 503 ไม่ลบข้อมูล) | `POST /sites/{id}/suspend`,`/resume` |
| `site.delete` | `site_id`, `confirm_domain`(ต้องตรงชื่อโดเมน) | ย้ายเข้าถังพักก่อนลบจริง | `POST /sites/{id}/delete` |
| `site.reset_owner` | `site_id`, `fix_permissions` | `chown -R` กลับเป็นค่าที่ถูกต้อง ทีละเว็บ | `POST /sites/{id}/reset-owner` |

#### Php / Db

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `php.list` | — | รายการ PHP version + จำนวนเว็บที่ใช้ + flag EOL | `GET /php` |
| `db.list` | — | ผสาน DB ที่ panel รู้จักกับ `SHOW DATABASES` จริง | `GET /databases` |
| `db.create` | `name`, `username?`, `host`(enum), `privileges`, `site_id?`, `charset?` | สร้าง DB+user เฉพาะของมันเอง | `POST /databases` |
| `db.drop` | `name`, `confirm`(ต้องตรงชื่อ), `drop_user?` | สำรอง (mysqldump) อัตโนมัติก่อนลบเสมอ | `POST /databases/drop` |
| `db.user_password` | `username`, `host` | สุ่มรหัสผ่านใหม่ | `POST /databases/password` |

#### File (`file.view` สำหรับอ่าน, `file.manage` สำหรับเขียน — คำนวณจาก `isMutating()` อัตโนมัติ)

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `file.list` | `root`, `path?`, `page`, `per_page` | รายการไฟล์ (ไม่ลงลึก, แบ่งหน้า) | `GET /files`, `/api/files`, `/api/files/browse` |
| `file.read` | `root`, `path` | อ่านไฟล์ข้อความ (≤5MB, allowlist นามสกุล) | `GET /api/files/read` |
| `file.download` | `root`, `path` | ดาวน์โหลด (บังคับ `nosniff`+`attachment`) | `GET /files/download` |
| `file.write` | `root`, `path`, `content`(UTF-8, ≤5MB), `create?` | เขียนแบบ atomic (temp+rename) | `POST /files/save` |
| `file.mkdir` | `root`, `path`, `name` | สร้างโฟลเดอร์ (mode 0750 ตายตัว) | `POST /files/folder` |
| `file.move` | `root`, `items: list`(≤100), `destination`, `rename?`, `copy?`, `overwrite?` | ย้าย/เปลี่ยนชื่อ/คัดลอก | `POST /files/move` |
| `file.delete` | `root`, `items: list`(≤100) | ลบ (ห้ามลบ `public/logs/tmp/backups` ของเว็บ) | `POST /files/delete` |
| `file.chmod` | `root`, `path`, `mode`(allowlist: 0600/0640/0644/0700/0750/0755), `recursive?` | เปลี่ยนสิทธิ์ (ห้าม 0777/setuid/setgid) | `POST /files/chmod` |
| `file.zip` / `.unzip` | ดูตาราง HTTP ด้านบน | บีบ/แตกไฟล์ (กัน Zip Slip ใน Executor) | `POST /files/zip`,`/unzip` |
| `file.upload` | `root`, `path`, `name`, `content`(base64), `overwrite?` | รับไฟล์อัปโหลด (execute bit ไม่ถูกตั้งเด็ดขาด) | `POST /files/upload` |

#### Firewall / Ssh / Rollback

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `firewall.status` | — | อ่านกฎ ufw | `GET /server/firewall` |
| `firewall.rule_add` | `action`,`port`,`protocol`,`source`,`comment`,`window` | เพิ่มกฎ (deny มี auto-rollback) | `POST /server/firewall/rules` |
| `firewall.rule_delete` | `number`,`expect`(ลายเซ็นกฎ กัน race),`window` | ลบกฎ (ห้ามลบกฎพอร์ต panel/SSH) | `POST /server/firewall/rules/{n}/delete` |
| `firewall.enable`/`.disable` | `window?` | เปิด/ปิด firewall (enable มี auto-rollback, disable ไม่มีเพราะเปิดสิทธิ์ไม่ใช่ปิด) | `POST /server/firewall/enable`,`/disable` |
| `ssh.config_get` | — | อ่านค่าตั้ง SSH พร้อมคำเตือนความเสี่ยง | `GET /server/ssh` |
| `ssh.config_set` | คีย์ตาม `SshManager::keys()`,`window` | แก้ค่าตั้ง SSH ที่อันตรายที่สุดในระบบ — มี auto-rollback เสมอ | `POST /server/ssh` |
| `rollback.confirm` | `rollback_id` | ยืนยันว่ายังเข้าถึงได้ ยกเลิกการคืนค่า | `POST .../{id}/confirm` (ssh, firewall) |
| `rollback.run` | — | คืนค่าที่หมดเวลายืนยัน | `POST .../{id}/rollback` |

#### Ssl / Backup / Cron

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `ssl.list` | — | ใบรับรองทั้งหมด อ่านสดจากไฟล์ | `GET /ssl` |
| `ssl.issue` | `site_id`,`method`(letsencrypt/self-signed),`staging?`,`email?` | ขอใบรับรอง | `POST /ssl/{id}/issue` |
| `ssl.renew` | `site_id`,`force?` | ต่ออายุทันที | `POST /ssl/{id}/renew` |
| `ssl.set_mode` | `site_id`,`mode`(off/on/forced) | เปิด/ปิด/บังคับ HTTPS | `POST /ssl/{id}/mode` |
| `ssl.delete` | `site_id`,`confirm_domain` | ลบใบรับรอง (ปิด ssl_mode ก่อนเสมอ) | `POST /ssl/{id}/delete` |
| `backup.create` | `type`(site/database/config/full),`site_id?`,`database?`,`note?` | สร้างสำรองข้อมูล (config/full เฉพาะ superadmin/sysadmin) | `POST /backups` |
| `backup.restore` | `backup_id`,`confirm`(ตรงโดเมน) | กู้คืน (เฉพาะ type=site — database/config ปฏิเสธตรง ๆ) | `POST /backups/{id}/restore` |
| `backup.delete` | `backup_id` | ลบไฟล์สำรอง | `POST /backups/{id}/delete` |
| `cron.sync` | `site_id` | เขียน `/etc/cron.d/phpcp-<domain>` ใหม่ทั้งไฟล์ จากตาราง DB | `POST /cron`,`/cron/{id}/toggle`,`/delete` |

#### Settings / Notify / Mail / Security / Log

| Capability | Args | หน้าที่ | ผู้เรียก |
|---|---|---|---|
| `settings.get`/`.set` | ตามคีย์ `SettingsRepository::keys()` | อ่าน/เขียนค่าตั้งระบบ (มาสก์ secret ตอนอ่าน) | `GET/POST /server/settings` |
| `notify.test` | — | ทดสอบส่ง Telegram | `POST /server/settings/notify-test` |
| `mail.apply` | `hostname?` | เขียนค่าตั้ง Postfix (loopback-only เสมอ กันเป็น open relay) | `POST /server/settings/mail-apply` |
| `mail.test` | `to` | ส่งเมลทดสอบผ่าน sendmail จริง | `POST /server/settings/mail-test` |
| `security.scan` | — | ตรวจ 9 หมวด ให้คะแนน 0–100 อ่านสภาพจริงทุกครั้ง | `GET /server/security` |
| `system.logs_tail` | `source`(allowlist), `lines`,`search?`,`level?` | อ่านท้ายไฟล์ log (ไม่โหลดทั้งไฟล์) | `GET /server/logs`,`/api/logs` |

#### Customer / Expiry — **พบว่าไม่มีผู้เรียกใช้งาน**

| Capability | หมายเหตุ |
|---|---|
| `customer.create`, `customer.quota_update`, `customer.site_attach` | ลงทะเบียนสมบูรณ์ครบ validate/run แต่ `CustomerController.php` และ CLI เขียนตรรกะเดียวกันซ้ำเข้าถึง `CustomerRepository` ตรง ๆ โดยไม่ผ่าน `Agent\Client` เลย — ไม่ใช่บั๊กที่กระทบผู้ใช้ (ผลลัพธ์เหมือนกัน) แต่เป็น code path ที่ไม่มีวันถูกเรียกจริง |
| `expiry.check` | `Cli/Seeder.php` ใส่แถวตัวอย่างในตาราง `scheduled_jobs` อ้างถึง capability นี้ (ตั้งใจให้รันทุกวันตี 3) แต่**ไม่มีโค้ดส่วนไหนอ่านตาราง `scheduled_jobs` หรือเรียก dispatch capability ตามตารางนี้เลย** — แปลว่าฟีเจอร์ "ตรวจวันหมดอายุลูกค้าอัตโนมัติ" ยังไม่ถูกเดินสายจริง ต้องมีคนกดตรวจเอง หรือต้องเพิ่ม scheduler |

### 2.5 ข้อสังเกตสำคัญจากการตรวจ API

1. **`rollback.run` เอกสารในตัวโค้ดเองอ้างว่ามี cron เรียกทุกนาที แต่ไม่มีจริงในโปรเจกต์** — มีเฉพาะทาง trigger แบบ "โหลดหน้าแล้วเช็ค" (`SshController::rollback`, `FirewallController::rollback`) ไม่มี systemd timer หรือ CLI subcommand ใดเรียกมันแบบไม่มีคนคลิก ถ้าผู้ใช้ตั้งค่าเสี่ยง (เช่นเปลี่ยนพอร์ต SSH) แล้วไม่กลับมาเปิดหน้านั้นอีกเลย การ auto-rollback จะไม่เกิดขึ้นเองในโค้ดชุดนี้ — ควรตรวจสอบว่าตัวติดตั้งจริงมีการตั้ง cron ของระบบ (นอก repo) แยกไว้หรือไม่ ถ้าไม่มีควรเพิ่ม
2. **ทั้ง 3 capability ของ `customer.*` และ `expiry.check` ไม่มีผู้เรียกใช้จริง** (ดูตารางข้างบน) — ควรพิจารณาว่าจะลบทิ้ง (เพราะ Controller ทำเองอยู่แล้ว) หรือจะเชื่อมสายใหม่ให้ Controller/scheduler เรียกผ่านชั้นนี้แทน เพื่อความสม่ำเสมอของสถาปัตยกรรม (การผ่าน Agent ทำให้ได้ audit log + validate ฟรี ซึ่งตอนนี้ path ตรงของ `CustomerController` ไม่ได้ audit ผ่านกลไกเดียวกัน)
3. **`FileCapability::permission()` คำนวณจาก `isMutating()` ไม่ได้ประกาศตรง ๆ ทีละคลาส** — ถ้าจะ grep หา `'file.manage'` ในไฟล์ capability จะไม่เจอในคลาสลูกแต่ละตัว ต้องดูที่คลาสฐาน

---

## 3. คำถามที่ 2 — โปรเจกต์นี้สมบูรณ์แค่ไหนสำหรับใช้งานจริง

### 3.1 ผลการรันชุดทดสอบ (รันจริงบนเครื่องนี้)

```
$ php tests/run.php
...
ผ่าน 209 · ไม่ผ่าน 0 · ยืนยัน 1,308 ครั้ง · 35 ms
```

- 14 ไฟล์ทดสอบใน `tests/security/` ครอบคลุม: Capability fuzzing (ยิงค่าผิดรูปแบบเข้าทุก capability ที่ลงทะเบียนแบบอัตโนมัติ), Path traversal, RBAC matrix, Self-protection, Config write/rollback, Firewall, Nginx, SSL, Settings, Site paths, Updater signature verification
- สุ่มตรวจเนื้อหาการทดสอบแล้วพบว่าเป็น assertion จริงเทียบกับ exception type/ค่าที่คำนวณจริง ไม่ใช่ `assertTrue(true)` หลอก ๆ
- ตัวเลขในเอกสาร ROADMAP.md (201/201) เก่ากว่าตัวเลขจริงปัจจุบัน (209/209) เล็กน้อย — สอดคล้องกับพัฒนาการที่ยังดำเนินต่อ ไม่ใช่การกล่าวเกินจริง

**ไม่พบ TODO/FIXME/stub ที่แท้จริงใน `src/`** — สิ่งที่ grep เจอเป็น false positive ทั้งหมด (ชื่อตัวแปร `$todo` ในการจัดเรียง, ข้อความ "ยังไม่รองรับคำสั่งนี้" ใน simulator ของโหมด sandbox ซึ่งเป็นพฤติกรรมที่ตั้งใจ ไม่ใช่ของที่เขียนไม่เสร็จ)

### 3.2 ตรวจสอบระบบความปลอดภัยเทียบกับที่เอกสารอ้าง — ยืนยันว่ามีจริงทุกข้อ

| การควบคุม | ตรวจพบ | สรุป |
|---|---|---|
| Argon2id | `src/Security/Password.php` — `memory_cost=64MB, time_cost=4, threads=2` + บล็อกรหัสผ่านยอดนิยม 36 รายการ + ปฏิเสธรหัสที่มีชื่อผู้ใช้ปน | ✅ ของจริง ไม่ใช่ bcrypt ติดป้าย |
| TOTP (RFC 6238) | `src/Security/Totp.php` — เขียนเอง (HMAC-SHA1, dynamic truncation, base32) ไม่พึ่ง library | ✅ ถูกต้องตามสเปก |
| CSRF | `src/Security/Csrf.php` — HMAC ผูก session, `hash_equals` | ✅ ของจริง |
| Rate limit | `src/Security/RateLimiter.php` — token bucket ใน SQLite (ไม่ใช่ memory เดียว กันปัญหา multi-worker) | ✅ ของจริง |
| Audit log hash-chain | `src/Security/AuditLog.php` — `hash = sha256(prev_hash|ts|actor|...)`, มี `verifyChain()` ตรวจทั้งตาราง จริง | ✅ ยืนยันว่ามีแต่ `Agent\Dispatcher` เท่านั้นที่เขียนได้ (grep `->audit->write` ใน capability ทั้งหมด = 0 ผลลัพธ์ ตรงกับกฎที่บันทึกไว้ในความจำของระบบ) |
| SelfProtection | `src/Agent/SelfProtection.php` — บล็อก unit/path/user ของ panel เอง ด้วย `realpath()` เทียบหลัง resolve symlink | ✅ บังคับใช้จริงที่ชั้น capability ไม่ใช่แค่ซ่อนปุ่มที่ UI |
| PathGuard | `src/Support/PathGuard.php` — สองชั้น: lexical clean() + post-privilege-drop realpath() เทียบ prefix พร้อม `/` ท้าย (กัน bug `/srv/sites/example.com-evil`) | ✅ ของจริง |
| RBAC | `src/Security/Permissions.php` — 3 role × 37 permission จุดเดียว ตรวจ 2 ชั้น (middleware + agent) และ**ระบุตรง ๆ ในคอมเมนต์ของตัวเอง**ว่าถ้าเว็บชั้น 1 โดนยึดครบ ผู้โจมตีปลอมสิทธิ์ที่ส่งไป agent ได้ — ขอบเขตความปลอดภัยจริงอยู่ที่ capability validator | ✅ ของจริง และ**เปิดเผยข้อจำกัดของตัวเองอย่างตรงไปตรงมา** |

**ไม่พบช่องว่างระหว่างสิ่งที่ SECURITY.md อ้างกับโค้ดจริงในทั้ง 7 ข้อนี้** — งานด้านความปลอดภัยเป็นจุดแข็งที่สุดของโปรเจกต์

### 3.3 ตารางความสมบูรณ์ของฟีเจอร์ (สำหรับใช้งานจริงกับลูกค้าที่จ่ายเงิน)

| ฟีเจอร์ | สถานะ | หลักฐาน | สิ่งที่ต้องเพิ่มเพื่อใช้งานจริง |
|---|---|---|---|
| แยกสิทธิ์ต่อเว็บไซต์ (1 เว็บ=1 user Linux+FPM pool) | **สมบูรณ์** | ทดสอบ 16 เคสใน `SitePathsTest.php` | ใช้งานได้เลย |
| บัญชีลูกค้า + โควตาจำนวนทรัพยากร (โดเมน/subdomain/DB/cron) | **สมบูรณ์** | `CustomerRepository.php`, `QuotaChecker::canCreate()` ตรวจก่อนสร้างทุกครั้ง | ใช้งานได้เลย |
| **โควตาพื้นที่ดิสก์** | ❌ **ขาด** | คอลัมน์ `disk_quota_mb` มีใน schema แต่ไม่มีโค้ดที่ไหนอ่านหรือบังคับใช้เลย (`grep setquota/edquota/du` = ไม่เจอ) | ต้องทำ filesystem quota จริง (XFS/ext4 project quota) หรือ poll `du` เป็นระยะแล้วบังคับ |
| **โควตาอีเมล** | ❌ **ขาด (field ตาย)** | `QuotaChecker.php` ฮาร์ดโค้ด `$emails=0` พร้อมคอมเมนต์ "ยังไม่มีตารางนี้ใน schema" | ไม่มีโครงสร้างรองรับเลย |
| **บัญชี FTP** | ❌ **ขาด แต่ UI หลอกว่ามี** | grep vsftpd/proftpd = ไม่เจอทั้ง repo แต่ `quota_ftp_users` เป็นฟิลด์ในหน้า UI จริง | **จุดควรแก้ก่อนอื่น** — UI แสดงโควตาของฟีเจอร์ที่ไม่มีอยู่จริง ควรซ่อนฟิลด์นี้ออกจนกว่าจะทำจริง มิเช่นนั้นลูกค้าจะถูกขายฟีเจอร์ที่ไม่มี |
| Billing / ใบแจ้งหนี้ / payment gateway | ❌ **ขาด (ตามที่เอกสารระบุตรง ๆ)** | grep invoice/billing/payment/stripe = ไม่เจอ | ต้องต่อระบบภายนอกทั้งหมด |
| DNS | ⚠️ **บางส่วน — export zone file เท่านั้น** | `install.sh` ติดตั้ง BIND9 จริง แต่ไม่มีโค้ดใดเขียน zone ให้ BIND9 หรือสั่ง `rndc reload` — หน้า DNS เป็นแค่ที่เก็บ record แล้วส่งออกไฟล์ | ถ้าจะขายเป็น "มี DNS server" ต้องเขียนสายเชื่อม records→BIND9 zone จริง ไม่งั้นต้องสื่อสารกับลูกค้าให้ชัดว่าต้องเอา zone file ไปตั้งที่ผู้ให้บริการ DNS เอง |
| เมลขาออก (relay/local) | **สมบูรณ์ตามขอบเขตที่ตั้งใจ** | `MailManager.php` เขียน Postfix จริง, บังคับ loopback-only กันเป็น open relay, ตรวจแล้วบน container จริง | ใช้งานได้ตามที่ตั้งใจ (ไม่ใช่ mailbox hosting) |
| รับอีเมล/mailbox/webmail | ❌ **ขาด (ตั้งใจไม่ทำ ระบุในเอกสารชัดเจน)** | ไม่มี Dovecot/IMAP/webmail ใด ๆ | เป็นงานขนาดใหญ่พอ ๆ กับทั้งโปรเจกต์ ตามที่ ROADMAP.md ระบุเหตุผลไว้ (ความเสี่ยง reputation/open relay) |
| Backup | ⚠️ **เก็บในเครื่องเดียวเท่านั้น** | `BackupManager.php` — path ฮาร์ดโค้ด `/var/lib/phpcp/backups` ไม่มี S3/rsync ปลายทางนอกเลย | **ความเสี่ยงสูงสุดอันดับ 1** — ดิสก์พังครั้งเดียว = ข้อมูลลูกค้าทุกรายหายพร้อมสำรอง ต้องเพิ่มปลายทาง offsite |
| Multi-server / cluster | ❌ **ขาด (ตั้งใจ)** | ไม่มี concept node_id ที่ไหนเลย | ออกแบบมาเป็น 1 การติดตั้ง = 1 เซิร์ฟเวอร์ |
| รองรับ RHEL/CentOS/Alma/Rocky | ❌ **ขาด** | `install.sh` เช็ค distro แล้ว `die` ทันทีถ้าไม่ใช่ตระกูล Debian ในโหมด production | ต้องเพิ่ม branch `dnf`/SELinux/firewalld ทั้งชุด |
| Wildcard subdomain | ❌ **ขาด (มีแผนออกแบบไว้แล้วใน ROADMAP แต่ยังไม่ลงมือ)** | ยังไม่มีค่า `wildcard` ใน `domains.type` | ต้องแก้ template vhost + ตัดสินใจเรื่อง DNS-01 credential ก่อน (เอกสารระบุปัญหานี้ไว้ตรง ๆ แล้วว่ายังไม่มีคำตอบ) |
| IPv6 | ⚠️ **ใช้ได้เฉพาะ nginx driver** | เทมเพลต nginx มี `listen [::]:` ชัดเจน, เทมเพลต Apache ไม่มี directive IPv6 ชัดเจน | ถ้าใช้ Apache driver ต้องพึ่งพฤติกรรม default ของ OS |
| HTTP/2 | ⚠️ **เฉพาะ nginx** | `templates/nginx/vhost-ssl.conf.tpl` มี `ssl http2;` | Apache driver ไม่มี directive HTTP/2 |
| WAF / rate-limit ต่อเว็บไซต์ลูกค้า | ❌ **ขาด** | ไม่มี `limit_req`/ModSecurity ใดในเทมเพลต vhost ของลูกค้า (มีแค่ป้องกันตัว panel เอง) | เว็บลูกค้าทุกเว็บไม่มีการป้องกัน L7 DoS จาก panel — ต้องพึ่งโค้ดแอปเองทั้งหมด |
| Monitoring/แจ้งเตือน | ⚠️ **มีช่องทางเดียว (Telegram)** | `Notifier.php` ครอบคลุม 5 หมวดเหตุการณ์ ไม่มี email/Slack/webhook/Prometheus | พอสำหรับผู้ดูแลคนเดียว ไม่พอสำหรับทีมที่ต้อง on-call |
| หลายภาษา (i18n) | ❌ **ไทยล้วน ไม่มีโครงสร้างรองรับภาษาอื่น** | ไม่มีไฟล์ locale อื่น ไม่มี lang switcher | ต้องทำ i18n layer ใหม่ทั้งหมดถ้าจะขยายตลาด |
| SSL/Let's Encrypt | **สมบูรณ์** | `CertbotManager.php` ตรวจสอบวันหมดอายุจาก PEM จริงเอง ไม่พึ่ง bookkeeping ของ certbot | ใช้งานได้เลย |
| Firewall (ufw) | **สมบูรณ์** | ทดสอบ 10 เคสรวม self-lockout prevention | ใช้งานได้เลย |

### 3.4 ช่องว่างเรียงตามความเสี่ยง/ผลกระทบ ถ้านำไปใช้งานจริงวันนี้

1. **Backup เก็บในเครื่องเดียว ไม่มี offsite** — ดิสก์พังครั้งเดียวเสียทั้งข้อมูลจริงและสำรองพร้อมกัน กระทบทุกลูกค้าบนเครื่อง (ความเสี่ยงสูงสุด)
2. **ไม่มี disk quota บังคับจริง** — ลูกค้ารายเดียวเติมดิสก์เต็มได้ กระทบเว็บไซต์อื่นทุกเว็บบนเครื่องเดียวกัน (noisy neighbor) ทั้งที่ระบบขายว่ามี "โควตา"
3. **DNS ติดตั้ง BIND9 ให้แต่ panel ไม่ได้เชื่อมสายจริง** — เสี่ยงสร้างความเข้าใจผิดกับผู้ดูแลว่า "มี DNS server ใช้งานได้" ทั้งที่ยังต้องต่อสายเอง
4. **ไม่มี WAF/rate-limit ให้เว็บไซต์ลูกค้า** — เว็บที่โฮสต์ไม่มีการป้องกัน L7 DoS จาก panel เลย
5. **ฟิลด์โควตา FTP มีใน UI แต่ไม่มีฟีเจอร์ FTP จริง** — จุดเดียวที่ UI สื่อสารผิดจากความจริง ควรแก้ก่อนเปิดขาย
6. **1 เซิร์ฟเวอร์ ไม่มีแผนสำรองภัยพิบัติ** — ต่อเนื่องจากข้อ 1 ไม่มีทาง recover ถ้าเครื่องล่มทั้งเครื่อง
7. **ไม่รองรับ RHEL/CentOS** — จำกัดตลาดเหลือครึ่งเดียวของฐาน distro โฮสติ้งทั่วไป
8. **ไม่มีเมลบ็อกซ์/บิลลิ่ง** — ทั้งสองอย่างจงใจไม่ทำ (สมเหตุสมผล) แต่ต้องต่อระบบภายนอกก่อนขายจริงเชิงพาณิชย์
9. **UI ภาษาไทยล้วน ไม่มี i18n** — จำกัดฐานลูกค้า/ผู้ดูแลที่อ่านไทยได้เท่านั้น
10. **แจ้งเตือนช่องทางเดียว (Telegram) ไม่มีประวัติย้อนหลังเชิงกราฟ** — พอสำหรับผู้ดูแลคนเดียว ไม่พอสำหรับทีม on-call

### 3.5 สรุประดับความพร้อมใช้งานจริง

**ใช้ได้จริงบางส่วน ภายใต้ขอบเขตที่แคบ**: เซิร์ฟเวอร์ Debian/Ubuntu เดี่ยว ๆ โฮสต์เว็บ PHP ให้ลูกค้าที่ไม่ต้องการอีเมล/FTP/สำรองนอกเครื่อง/subdomain แบบ wildcard และมีระบบบิลลิ่งแยกต่างหากอยู่แล้ว **งานด้านวิศวกรรมความปลอดภัยเป็นจุดแข็งที่แท้จริงและตรวจสอบยืนยันได้ (ไม่ใช่แค่คำโฆษณาในเอกสาร)** — เป็นจุดขายหลักของโปรเจกต์นี้เมื่อเทียบกับ control panel อื่น ส่วนช่องว่างที่เหลือทั้งหมดเป็นเรื่อง**ขอบเขตฟีเจอร์เชิงธุรกิจโฮสติ้ง** ไม่ใช่ปัญหาคุณภาพโค้ด และส่วนใหญ่เอกสารของโปรเจกต์เองก็ระบุไว้ตรงไปตรงมาอยู่แล้วว่าตั้งใจไม่ทำ (ยกเว้นเรื่อง backup offsite, disk quota บังคับจริง, DNS ต่อสาย BIND9 จริง และฟิลด์ FTP หลอกใน UI ที่ควรแก้ก่อนใช้งานเชิงพาณิชย์จริง)

---

## 4. คำถามที่ 3 — แยก Frontend ออกจาก API ได้ไหม

**คำตอบสั้น: ได้ และความเสี่ยงต่ำกว่าที่คาด** เพราะส่วนที่ยากที่สุดของระบบ (การรันคำสั่งสิทธิ์สูงอย่างปลอดภัย) แยกจาก HTML อยู่แล้วตั้งแต่การออกแบบเริ่มต้น

### 4.1 สถาปัตยกรรมปัจจุบันสะอาดแบบ MVC แค่ไหน

**Kernel สะอาดจริง**: `src/Kernel/HttpKernel.php` รัน middleware pipeline คงที่ (`SecurityHeaders → RateLimit → Session → Authenticate → Csrf → Authorize → AuditContext`) ล้อมรอบ `new $controller()->{$action}($request)` — ตัว `Request`/`Response` เป็น value object ไม่ผูกกับ superglobals ไม่รู้จัก HTML เลยที่ระดับนี้

**แต่ตัว Controller action ยังไม่แยก "คำนวณผล" ออกจาก "เรนเดอร์ผล" อย่างสม่ำเสมอ**: หน้า `index`/`show` สร้าง array ข้อมูลปนกับ flag การแสดงผล (`canEdit`, `canDelete` ฯลฯ) แล้วส่งตรงเข้า `View::render()` ในฟังก์ชันเดียวกัน ส่วน action ที่เปลี่ยนแปลงข้อมูลเกือบทั้งหมด (17 จาก 22 controller) **`redirect()` เสมอ ไม่เคยเช็ค `wantsJson()` เลย** มีเพียง `FileController` และ `ServiceController` (2 จาก 22) ที่แยกจริงตาม `wantsJson()`

### 4.2 จุดแข็งที่เอื้อต่อการแยก API — นี่คือเหตุผลหลักที่ทำได้จริง

1. **ชั้น Agent/Capability (`src/Agent/*`) ปลอดจาก HTTP/HTML โดยสมบูรณ์อยู่แล้ว** — `Client::call()` คุยกันด้วย JSON ผ่าน unix socket ล้วน ๆ ไม่มี `Request`/`Response`/`View` แทรกอยู่เลยแม้แต่ไฟล์เดียว `Actor` มีแค่ scalar (`userId, username, role, ip, requestId`) นี่คือ "ส่วนที่ยาก" ของระบบทั้งหมด (RBAC-checked dispatch, audit log, dry-run mode) ที่**ไม่ต้องแก้อะไรเลย**ถ้าจะสร้าง frontend ใหม่
2. **Domain repository คืนค่าเป็น associative array ธรรมดา** ที่ serialize เป็น JSON ได้ทันที ไม่มีการผูกกับการเรนเดอร์
3. **Response envelope และ middleware ทุกชั้นตอบ JSON แบบเดียวกันอยู่แล้ว** (`{"ok":bool,"data"/"error":...}`) — ใช้ที่ `Authenticate`, `Authorize`, `Csrf`, `RateLimit` ทุกตัว ไม่ต้องออกแบบ convention ใหม่
4. **RBAC แยกจากการเรนเดอร์อยู่แล้ว 100%** — permission string เดียวกัน (`site.view`, `service.control` ฯลฯ) ถูกใช้ทั้งที่ middleware และที่ Agent Dispatcher เป็น source of truth เดียว ใช้ซ้ำได้เลยกับ API ใหม่
5. **มีต้นแบบ dual-mode (JSON/redirect) ที่ทำงานจริงอยู่แล้ว** ใน `FileController::respond()/reject()` — เป็นแพทเทิร์นที่ดี เพียงแต่ยังไม่ถูกยกขึ้นไปเป็นของกลางใน `Controller.php` (ถูกคิดค้นซ้ำ 2 ครั้งอิสระกันใน FileController กับ ServiceController)

### 4.3 จุดที่ต้องคลี่คลายก่อนทำจริง

1. **17 จาก 22 controller ต้องเพิ่ม branch ตอบ JSON** — งานทำซ้ำเชิงกลไก ไม่ใช่ปัญหาการออกแบบ (Routes.php มี 99 routes, ~65 เป็น POST)
2. **ข้อความผล/รหัสผ่านที่สุ่มใหม่ถูกส่งผ่าน query string** เช่น `/databases?ok=...&user=...&pw=...` — ต้องย้ายไปอยู่ใน response body แทน ซึ่งจริง ๆ **สะอาดกว่าของเดิม** แต่ต้องตัดสินใจออกแบบใหม่ ไม่ใช่ port ตรง ๆ
3. **หน้า index/show ผสม "ข้อมูล" กับ "flag สิทธิ์สำหรับเรนเดอร์ปุ่ม"** เช่น `canCreate`, `canEdit` — API ควรคืนทรัพยากรบวก `permissions` object แยก หรือให้ frontend คำนวณเองจาก endpoint `/api/me` — ต้องออกแบบทีละ resource ไม่ใช่ copy-paste ได้ทันที
4. **`View::chrome()` ฉีด nav/breadcrumb/CSRF/nonce เข้าไปทุกหน้า** — เป็นเรื่อง presentation ล้วน ไม่มีปัญหาอะไร แค่ยืนยันว่า HTML view เดิมใช้เป็น data contract ของ SPA ไม่ได้ตั้งแต่ต้น
5. **ไม่มี schema/DTO กลางสำหรับทรัพยากร เช่น "Site"** — วันนี้ view คาดหวัง key ของ array ตามธรรมเนียมที่ตกลงกันเองระหว่าง controller กับ template เท่านั้น (ดู `views/page/sites.php` เช่น `$site['disk_used_mb']`) ต้องออกแบบ response shape ที่เสถียรใหม่สำหรับ API
6. **`StreamController::metrics()` เขียน header/echo ตรง ข้าม `Response` object** — เป็นข้อยกเว้นที่ต้องรักษาไว้ ไม่ใช่บั๊ก (SSE ต้องทำแบบนี้)
7. **ไม่มี CORS เลยในระบบ** — ถ้า frontend ใหม่อยู่คนละ origin ต้องเพิ่ม CORS middleware ใหม่ทั้งหมด (ปัจจุบัน CSP `connect-src 'self'` และ COOP/CORP บล็อกการเรียกข้าม origin ไว้อยู่แล้วโดยเจตนา)
8. **CSRF ผูกกับคุกกี้ session เท่านั้น ไม่มี token/API key** — ถ้า client ใหม่เป็น mobile app หรืออยู่คนละ origin ต้องเพิ่มโหมด auth แบบ bearer token คู่ขนาน

### 4.4 สถาปัตยกรรมเป้าหมายที่แนะนำ

```
Frontend ใหม่ (SPA ใด ๆ)  ──HTTP/JSON──▶  Controller API ชั้นบาง (Api/V2/*)
                                              │
                                              ├─▶ Domain/*Repository (ของเดิม ไม่ต้องแก้)
                                              └─▶ Agent\Client → unix socket → Dispatcher →
                                                    Capability → Executor (ของเดิม ไม่ต้องแก้เลย)

UI HTML เดิม (ไม่แตะ) ──▶ Controller/* + views/* เดิม รันคู่ขนานกันไปได้
```

**หลักการออกแบบ:**
- ยก `FileController::respond()/reject()` ขึ้นเป็นเมธอดกลางใน `Controller.php` แล้วให้ controller API ใหม่ทุกตัว (มุ่งที่ `/api/v2/*`) ใช้ร่วมกัน — ไม่ต้อง redirect เลยเพราะเป็น JSON-only ตั้งแต่ route
- ใช้ permission string เดิมใน `Routes.php` ซ้ำได้ทันที ไม่ต้องเขียน RBAC ใหม่
- ใช้ `Domain/*Repository` และ `Agent\Client` เดิมทั้งหมด — controller ใหม่จะบางมาก (parse request → เรียก repository/agent → shape JSON → `ok()`)
- **กลยุทธ์ auth: ถ้า SPA ใหม่ served จาก origin เดียวกัน (reverse proxy `/` → SPA, `/api/*` → PHP) ไม่ต้องเพิ่มอะไรเลย** คุกกี้ + CSRF เดิมใช้ได้ทันที (นี่คือทางเลือกความเสี่ยงต่ำสุดและแนะนำเป็นค่าเริ่มต้น) ถ้าต้องข้าม origin จริง (เช่น mobile app แยกจริง) ค่อยเพิ่ม CORS middleware + โหมด API-key คู่ขนาน (แนะนำ opaque API key เก็บใน SQLite แบบเดียวกับตาราง `sessions` ที่มีอยู่แล้ว มากกว่า JWT เพราะสอดคล้องกับปรัชญา "ไม่มีอะไรซับซ้อนเกินจำเป็น" ของโปรเจกต์นี้)

### 4.5 แผนการย้ายระบบเป็นเฟส (ไม่ทำลายของเดิม)

| เฟส | ขอบเขต | ความพยายาม | ความเสี่ยง |
|---|---|---|---|
| **0 — ยกแพทเทิร์น dual-mode ขึ้นเป็นของกลาง** | ย้าย `respond()/reject()` เข้า `Controller.php`, ปรับ `ServiceController` ให้ใช้ร่วม | ต่ำ (ไม่กี่ชั่วโมง) | ต่ำ — refactor ล้วน พฤติกรรม HTML เดิมไม่เปลี่ยน |
| **1 — เปิด `/api/v2/*` คู่ขนาน เริ่มจาก Sites/Services/Databases** | Controller ใหม่ ~4-6 ไฟล์ ~10-15 routes ใช้ Domain+Agent เดิม | ต่ำ-กลาง (หลักวัน) | ต่ำ — เป็นการเพิ่มล้วน ไม่กระทบ UI เดิมเลย พิสูจน์แนวทางได้ก่อนลงทุนเยอะ |
| **2 — ขยาย `/api/v2/*` ครอบคลุมที่เหลือทั้งหมด** (Domains, SSL, Cron, Backup, Firewall, SSH, Settings, Users, Customers, Files, Auth) | ~15 ไฟล์ใหม่ ~50-60 routes | กลาง (งานส่วนใหญ่ของแผน แต่เป็นงานทำซ้ำแพทเทิร์นเดิม) | ต่ำ-กลาง — กลไกเดิมพิสูจน์แล้วจากเฟส 1 ความเสี่ยงหลักคือ response shape ไม่สม่ำเสมอถ้าไม่มี convention/lint คุม |
| **3 — ตัดสินใจเรื่อง CORS/token auth** (เฉพาะถ้าจำเป็น) | Middleware ใหม่ 2-3 ไฟล์ | ต่ำ-กลาง | **กลาง** — จุดเดียวที่พลาดแล้วเป็นรูรั่วความปลอดภัยจริง (เช่น echo origin ผิดใน CORS) ต้องรีวิวละเอียด ไม่ใช่แค่ทดสอบว่าใช้งานได้ |
| **4 — สร้าง Frontend ใหม่ต่อยอด `/api/v2/*`** | โปรเจกต์ frontend แยกทั้งหมด | สูง (สัปดาห์-เดือน แต่เป็นงาน frontend ล้วน) | ต่ำสำหรับฝั่ง backend (ไม่กระทบเลย) |
| **5 — (ทางเลือก) ถอด HTML UI เดิมออก** | ลบ `views/*` (28 ไฟล์ ~5,291 บรรทัด), routes ที่ไม่ใช่ API, `View.php` | กลาง | กลาง — เป็นเฟสเดียวที่ทำลายของเดิม ควรทำหลังสุดและเป็นทางเลือก ไม่ทำก็ได้ไม่มีต้นทุนแฝง |

### 4.6 สรุปข้อเสนอแนะ

**แนะนำเริ่มจากเฟส 0-1 ก่อน** เพื่อพิสูจน์แนวทางแบบมีความเสี่ยงต่ำที่สุด แล้วค่อยขยายเป็นเฟส 2 อย่างต่อเนื่อง ระบบ UI เดิมสามารถรันคู่ขนานไปได้ตลอด ไม่จำเป็นต้อง "รื้อทิ้งแล้วเขียนใหม่" — จุดแข็งที่สุดของสถาปัตยกรรมเดิมคือชั้น Agent/Capability ที่ออกแบบให้ปลอดจาก HTML มาตั้งแต่ต้นอยู่แล้ว ทำให้การแยก Frontend/API ในโปรเจกต์นี้เป็นงาน "เพิ่มชั้นบาง ๆ ด้านบน" มากกว่า "ผ่าตัดสถาปัตยกรรมใหม่" — ควรเลื่อนการถอด HTML UI เดิม (เฟส 5) ออกไปจนกว่า SPA ใหม่จะพิสูจน์ตัวเองในการใช้งานจริงแล้วเท่านั้น

---

## 5. ข้อเสนอแนะโดยรวม

**ถ้าจะใช้งานจริงกับลูกค้าที่จ่ายเงินเร็ว ๆ นี้** ให้จัดการ 4 เรื่องนี้ก่อนเปิดขายเป็นอันดับแรก:
1. เพิ่มปลายทาง backup แบบ offsite (S3/rsync ไปเครื่องอื่น) — ความเสี่ยงสูงสุดในระบบตอนนี้
2. บังคับ disk quota จริงระดับ filesystem ไม่ใช่แค่ตัวเลขที่เก็บไว้เฉย ๆ
3. ซ่อนฟิลด์โควตา FTP ออกจาก UI จนกว่าจะมีฟีเจอร์ FTP จริง (หรือรีบทำฟีเจอร์นี้)
4. ตัดสินใจและสื่อสารให้ชัดว่า DNS เป็นแค่ตัวส่งออก zone file ไม่ใช่ DNS server ที่ทำงานอัตโนมัติ (หรือลงทุนเชื่อมสายกับ BIND9 จริงถ้าต้องขายเป็นฟีเจอร์)

**ถ้าจะแยก Frontend/API** เริ่มจากเฟส 0-1 ในหัวข้อ 4.5 ก่อนโดยไม่ต้องตัดสินใจเรื่องถอด HTML UI เดิมตอนนี้ — ความเสี่ยงต่ำและพิสูจน์แนวทางได้เร็ว

**จุดแข็งที่ควรรักษาไว้และสื่อสารเป็นจุดขาย:** วิศวกรรมความปลอดภัยของโปรเจกต์นี้ (การแยกสิทธิ์ 3 ชั้น, capability แบบ typed ที่ไม่มีทางรับ shell string, audit log แบบ hash-chain ที่ตรวจสอบได้จริง, Argon2id/TOTP ของแท้, การทดสอบอัตโนมัติ 209 เคสที่รันผ่านจริง) อยู่ในระดับที่เหนือกว่า control panel โอเพนซอร์สทั่วไปจำนวนมาก และเป็นสิ่งที่ตรวจสอบยืนยันได้จริงจากซอร์สโค้ด ไม่ใช่แค่คำกล่าวอ้างในเอกสาร
