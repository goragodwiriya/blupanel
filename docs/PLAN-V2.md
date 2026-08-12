# แผนงาน v2 — phpcp: Web Hosting Control Panel ฉบับสมบูรณ์

> **สถานะเอกสาร:** แผนงานหลักฉบับใหม่ · เขียนเมื่อ 2026-08-05
> **เอกสารนี้แทนที่:** ส่วน "แผนการทำเป็นเฟส" ใน [ROADMAP.md](ROADMAP.md) (เฟส 0–6 เสร็จแล้ว ให้ถือเป็นบันทึกประวัติ)
> **เอกสารประกอบ:** [AUDIT-REPORT.md](AUDIT-REPORT.md) (ผลตรวจสอบสถานะจริง) · [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md)
>
> **เป้าหมาย:** ระบบ Control Panel สำหรับ PHP Web Hosting ที่ **ติดตั้งได้ด้วยคำสั่งเดียว บริหารเซิร์ฟเวอร์ได้ครบทุกด้าน ปลอดภัยระดับใช้งานจริงกับลูกค้าที่จ่ายเงิน และโค้ดอ่านง่ายดูแลต่อได้**

---

## สารบัญ

- [0. บริบทสำหรับผู้เริ่มงานต่อ](#0-บริบทสำหรับผู้เริ่มงานต่อ)
- [1. นิยาม "สมบูรณ์" — เป้าหมายปลายทาง](#1-นิยาม-สมบูรณ์--เป้าหมายปลายทาง)
- [2. การตัดสินใจเชิงสถาปัตยกรรม (ตัดสินแล้ว)](#2-การตัดสินใจเชิงสถาปัตยกรรม-ตัดสินแล้ว)
- [3. สถาปัตยกรรมเป้าหมาย](#3-สถาปัตยกรรมเป้าหมาย)
- [4. สัญญา REST API — สเปกที่ต้อง implement](#4-สัญญา-rest-api--สเปกที่ต้อง-implement)
- [5. แผนงานเป็นเฟส](#5-แผนงานเป็นเฟส)
- [6. รายละเอียดงานรายเฟส](#6-รายละเอียดงานรายเฟส)
- [7. เกณฑ์รับงานและการทดสอบ](#7-เกณฑ์รับงานและการทดสอบ)
- [8. ความเสี่ยงและการจัดการ](#8-ความเสี่ยงและการจัดการ)
- [9. บันทึกส่งมอบสำหรับ session ใหม่](#9-บันทึกส่งมอบสำหรับ-session-ใหม่)

---

## 0. บริบทสำหรับผู้เริ่มงานต่อ

### 0.1 ระบบนี้คืออะไร

`phpcp` คือ Control Panel บริหาร Web Hosting บน Linux เขียนด้วย PHP 8.4 ล้วน **ไม่มี framework ไม่มี Composer** สถาปัตยกรรมแยกสิทธิ์ 3 ชั้นอย่างเข้มงวด:

```
ชั้น 1  Web UI (user: phpcp-web)  — ไม่มีสิทธิ์ root, ปิด exec/proc_open ที่ตัวเอง
   │    unix socket 0660 (JSON 1 บรรทัด/request)
ชั้น 2  phpcp-agentd (root)       — จุดเดียวที่มีสิทธิ์สูง, capability แบบ typed 63 ตัว
   │                                ไม่มีที่ไหนรับ shell string, proc_open(argv[]) เท่านั้น
ชั้น 3  OS: systemd/Apache/Nginx/PHP-FPM/MariaDB/ufw/certbot/postfix/BIND9
```

### 0.2 สถานะจริงที่ยืนยันแล้ว (ตรวจจากโค้ด ไม่ใช่จากเอกสาร)

| รายการ | ค่า | หมายเหตุ |
|---|---|---|
| ไฟล์ PHP ใน `src/` | 239 | |
| HTTP routes | 99 | `src/Kernel/Routes.php` |
| Agent capability | 63 ตัวใช้งานจริง (71 ไฟล์) | 8 ไฟล์เป็นคลาสฐาน · +2 จากเฟส A1 (disk.usage, cert.sync) |
| ผลทดสอบ | **303 ผ่าน / 0 ไม่ผ่าน / 2,272 assertion** | รันจริง: `php tests/run.php` (209 เคสเดิม + 13 scheduler + 8 ลูกค้า + 71 contract ของ REST API + 2 fuzz) |
| ไฟล์ view | 28 ไฟล์ ~5,291 บรรทัด | จะถูกปลดระวางในแผนนี้ |

**จุดแข็งที่ต้องรักษาไว้:** งานความปลอดภัยเป็นของจริงทั้งหมด ตรวจสอบยืนยันได้จากโค้ด — Argon2id (64MB/4/2), TOTP เขียนเองตาม RFC 6238, audit log แบบ hash-chain ที่มี `verifyChain()` จริง, CSRF ผูก session, PathGuard สองชั้น (lexical + post-privilege-drop realpath), SelfProtection, RBAC ตรวจสองชั้น (middleware + agent) **ชั้น Agent/Capability คือทรัพย์สินที่มีค่าที่สุดของโปรเจกต์ แผนนี้ไม่แตะมันเลย**

### 0.3 ทำไมต้องมีแผนใหม่

จาก [AUDIT-REPORT.md](AUDIT-REPORT.md) พบ 2 กลุ่มปัญหา:

1. **โค้ดฝั่งเว็บปนกันจนดูแลยาก** — Controller 17 จาก 22 ตัวผสม "คำนวณผล" กับ "เรนเดอร์ HTML" ไว้ในฟังก์ชันเดียว, ส่งผลลัพธ์ผ่าน query string (`?ok=...&pw=...`), ไม่มี schema กลางของทรัพยากร, JS เขียนเองอีก 7 ไฟล์ที่ต้องดูแลเอง
2. **ช่องว่างเชิงฟีเจอร์ที่กันไม่ให้ใช้งานจริงกับลูกค้าที่จ่ายเงินได้** — ไม่มี scheduler เลย, backup ไม่มี offsite, disk quota ไม่บังคับจริง, DNS ไม่ได้เชื่อม BIND9, ไม่มี FTP, ไม่มี WAF

---

## 1. นิยาม "สมบูรณ์" — เป้าหมายปลายทาง

ระบบจะถือว่า "สมบูรณ์" เมื่อผ่านเกณฑ์ทุกข้อนี้:

### 1.1 ด้านการติดตั้ง
- [ ] ติดตั้งบน VM เปล่าด้วยคำสั่งเดียว จบใน < 15 นาที บน Debian 12 / Ubuntu 22.04 / 24.04
- [ ] ติดตั้งแล้วได้ระบบที่พร้อมรับลูกค้าทันที ไม่ต้องแก้ config ด้วยมือเพิ่ม
- [ ] `phpcp doctor` ผ่านทุกข้อหลังติดตั้งเสร็จ
- [ ] อัปเดตระบบผ่าน `phpcp self-update` โดยตรวจลายเซ็นก่อนเสมอ

### 1.2 ด้านการบริหารเซิร์ฟเวอร์
- [ ] จัดการ service ทั้งหมดได้ (start/stop/restart/reload) พร้อมแสดงผลกระทบจริง
- [ ] Firewall + SSH แก้ได้พร้อม auto-rollback **ที่ทำงานเองได้จริงแม้ผู้ใช้หลุดการเชื่อมต่อ**
- [ ] Log viewer ครบทุกแหล่ง พร้อม tail สด
- [ ] Metrics สด + **เก็บประวัติย้อนหลังดูแนวโน้มได้**
- [ ] Security Center ให้คะแนนจากสภาพจริง พร้อมปุ่มแก้ที่ทำงานได้จริง
- [ ] แจ้งเตือนได้**หลายช่องทาง** (Telegram + Email + Webhook)

### 1.3 ด้านการบริหารโฮสติ้ง
- [ ] สร้าง/ระงับ/ลบเว็บไซต์ พร้อมแยกสิทธิ์ต่อเว็บสมบูรณ์ (1 เว็บ = 1 uid + 1 FPM pool)
- [ ] เปลี่ยน PHP version ต่อเว็บได้อิสระ
- [ ] SSL: Let's Encrypt + self-signed + นำเข้าเอง + **wildcard** + ต่ออายุอัตโนมัติ
- [ ] ฐานข้อมูล: สร้าง/ลบ/สิทธิ์/import/export
- [ ] ตัวจัดการไฟล์ครบ + **FTP/SFTP accounts**
- [ ] DNS: **เชื่อม BIND9 จริง** ไม่ใช่แค่ส่งออก zone file
- [ ] Cron ต่อเว็บไซต์
- [ ] Backup: เว็บ/DB/config + **ปลายทางนอกเครื่อง (S3/SFTP/rsync)** + ตั้งเวลา + restore ตรวจ checksum

### 1.4 ด้านการขายจริง (multi-tenant)
- [ ] บัญชีลูกค้า + โควตาทรัพยากรที่**บังคับใช้จริงทุกชนิดรวมถึงพื้นที่ดิสก์**
- [ ] วันหมดอายุ + แจ้งเตือนล่วงหน้า + ระงับอัตโนมัติเมื่อหมดอายุ
- [ ] ลูกค้าล็อกอินเข้ามาจัดการเว็บตัวเองได้ โดยไม่เห็นส่วน Server เลย
- [ ] **WAF / rate limit ระดับเว็บไซต์** กันเว็บลูกค้ารายเดียวทำให้ทั้งเครื่องล่ม

### 1.5 ด้านคุณภาพโค้ด
- [ ] **API เป็น REST/JSON ล้วน ไม่มี HTML ปนแม้แต่บรรทัดเดียว**
- [ ] Frontend แยกสมบูรณ์ เปลี่ยน/เขียนใหม่ได้โดยไม่แตะ backend
- [ ] มีสเปก API เป็นเอกสาร (OpenAPI) ที่ตรงกับโค้ดจริง
- [ ] ทดสอบอัตโนมัติครอบคลุม รันได้โดยไม่ต้อง root
- [ ] รองรับหลายภาษา (ไทย/อังกฤษ)

---

## 2. การตัดสินใจเชิงสถาปัตยกรรม (ตัดสินแล้ว)

| # | การตัดสินใจ | เหตุผล |
|---|---|---|
| **N1** | **API เป็น REST/JSON ล้วน** ไม่มี HTML ไม่มี redirect-flash | ตัดต้นตอ "โค้ดปนกัน" ที่ทำให้ดูแลยาก · ไม่ต้องมี branch `wantsJson()` ในทุก method |
| **N2** | **Frontend ใช้ Now.js เป็น SPA แยกต่างหาก** | ค่าเริ่มต้นของ `HttpClient` ตรงกับ phpcp เป๊ะ (`credentials: same-origin`, `X-CSRF-Token`, `meta[name=csrf-token]`) · มี Router/Table/Graph/Form/Modal/Toast/i18n ครบ เลิกดูแล JS เอง 7 ไฟล์ |
| **N3** | **ไม่ใช้ Kotchasan** | Now.js เป็น client-side ล้วน ไม่ผูกกับ Kotchasan · ถ้าลากมาจะกลายเป็น PHP framework 2 ตัวซ้อนกัน แย่กว่าปัญหาเดิม |
| **N4** | **ชั้น Agent/Capability ไม่แตะเลย** | เป็นส่วนที่ยากและอันตรายที่สุด ผ่านการทดสอบ 303 เคสแล้ว · การเปลี่ยน frontend ไม่ควรมีความเสี่ยงถึงชั้นนี้ |
| **N5** | **Serve จาก origin+พอร์ตเดียวกัน (8443)** — `/` = SPA static, `/api/v2/*` = REST | ไม่ต้องมี CORS เลย ซึ่งเป็นทางที่ปลอดภัยที่สุดสำหรับ panel ที่คุม root · cookie session เดิมใช้ได้ทันที |
| **N6** | **คง cookie session + CSRF เดิม ไม่ใช้ TokenService ของ Now.js** | `TokenService` เก็บ token ใน cookie ที่ JS อ่านได้ (ไม่ HttpOnly) = ถอยหลังจากของเดิมที่มี HttpOnly + SameSite=Strict + ผูก IP/UA |
| **N7** | **`config.allowEval = false` เสมอ** (ค่าเริ่มต้นของ Now.js) | ทำให้ `new Function` 2 จุดใน core ไม่ถูกเรียก → CSP ไม่ต้องเปิด `unsafe-eval` |
| **N8** | **commit `Now/dist/*` เข้า repo พร้อม checksum** ไม่ดึงจาก CDN | รักษาหลักการ supply-chain เดิม (ไม่มี CDN, ตรวจสอบได้, ติดตั้งบนเครื่องไม่มีเน็ตได้) |
| **N9** | **ยอมรับว่างบน้ำหนักเปลี่ยน** จาก <120 KB เป็น ~300 KB (gzip) | แลกกับการเลิกดูแล JS เอง · panel มีผู้ใช้ไม่กี่คน cache ได้ · **ต้องแก้ ARCHITECTURE §9.2 และ D6 ให้ตรงความจริง ห้ามปล่อยให้เอกสารขัดกับโค้ด** |
| **N10** | **เพิ่ม scheduler เป็นองค์ประกอบหลักของระบบ** | ปัจจุบัน**ไม่มี scheduler เลย** ทำให้ auto-rollback/expiry check/disk usage ไม่ทำงานเอง — เป็นช่องโหว่เชิงความปลอดภัย ไม่ใช่แค่ฟีเจอร์ขาด |

### 2.1 สิ่งที่ยังคง **ไม่** ทำ (ระบุชัดเพื่อกันขอบเขตบาน)

- 🔄 **Mail hosting เต็มรูปแบบ** — **คำตัดสินนี้ถูกกลับแล้ว (2026-08-12)** ย้ายไปเป็นแผนของตัวเองที่ [PLAN-MAIL.md](PLAN-MAIL.md) · เหตุผลเดิม (ต้องดูแลชื่อเสียง IP · SPF/DKIM/DMARC/rDNS ไม่ครบแล้วเมลเข้าถังขยะ · relay ที่ตั้งผิดกระทบ*ทุกเว็บไซต์*บนเครื่อง) ไม่ได้หายไป แต่ย้ายจาก "เหตุผลที่ไม่ทำ" มาเป็นข้อกำหนดของระบบ: หน้าตรวจความพร้อมที่บอกตรง ๆ ว่าอะไรยังไม่พร้อม และเทสต์ open relay ที่ยิงจริงทุกครั้ง
- ❌ **Billing / invoice / payment gateway** — ต่อระบบภายนอก
- ❌ **Multi-server / cluster** — 1 ติดตั้ง = 1 เซิร์ฟเวอร์
- ⏸ **RHEL/Alma/Rocky** — เลื่อนไปเฟส F (ทางเลือก) ไม่ใช่เงื่อนไขของคำว่า "สมบูรณ์"

---

## 3. สถาปัตยกรรมเป้าหมาย

### 3.1 ภาพรวม

```
เบราว์เซอร์ ── HTTPS พอร์ต 8443 (origin เดียว ไม่มี CORS) ──┐
                                                            │
┌───────────────────────────────────────────────────────────▼──────────┐
│ phpcp-web (Apache instance ของ panel เอง)                             │
│                                                                       │
│  GET /app, /app/* → public/index.php → SpaController → ส่งไฟล์ shell   │
│  GET /assets/*    → ไฟล์นิ่งของ SPA + Now/dist (Apache ส่งเอง)         │
│  *   /api/v2/*    → public/index.php → REST Controller (JSON ล้วน)    │
│                                                                       │
│  URL ของหน้าจอ (/app/*) ต้องไม่ตรงกับไดเรกทอรีจริง ไม่งั้น mod_dir      │
│  จะเข้ามาก่อนแล้ว FallbackResource ไม่ทำงาน — ดู SpaController          │
└───────────────────────────────────────┬───────────────────────────────┘
                                        │
                    ┌───────────────────▼────────────────────┐
                    │ src/Http/  (ชั้นบางมาก)                 │
                    │  Kernel + Middleware 7 ตัว (ของเดิม)    │
                    │  ApiController → Resource → JSON        │
                    └───────────────────┬────────────────────┘
                                        │
              ┌─────────────────────────┴─────────────────────┐
              ▼                                               ▼
   ┌─────────────────────┐                      ┌──────────────────────────┐
   │ src/Domain/*        │                      │ src/Agent/Client.php     │
   │ Repository (SQLite) │                      │   ↓ unix socket (JSON)   │
   │ ── ของเดิม ไม่แตะ ──  │                      │ phpcp-agentd (root)      │
   └─────────────────────┘                      │ Capability 61+ ตัว        │
                                                │ ── ของเดิม ไม่แตะ ──       │
                                                └──────────────────────────┘

   ┌──────────────────────────────────────────────────────────────┐
   │ phpcp-scheduler.timer (ใหม่ — เฟส A)                          │
   │  ทุก 1 นาที → อ่าน scheduled_jobs → dispatch capability       │
   │  rollback.run · expiry.check · disk.usage · cert.sync ...     │
   └──────────────────────────────────────────────────────────────┘
```

### 3.2 โครงสร้างไฟล์เป้าหมาย

```
webServer/
├── bin/
│   ├── phpcp                    CLI (เดิม)
│   ├── phpcp-agentd             daemon ชั้น 2 (เดิม ไม่แตะ)
│   └── phpcp-scheduler          ★ ใหม่ — ตัวรัน scheduled_jobs
│
├── public/
│   ├── index.php                front controller — /api/v2/*, /app/* และ static
│   ├── favicon.svg
│   └── assets/spa/              ★ Now.js SPA — **ไม่ใช่ public/app/** (ดู §3.1)
│       ├── index.html           หน้าเดียว (shell) — ทุก src/href ต้องเป็นเส้นทางสัมบูรณ์
│       ├── vendor/now/          now.core/table/graph + icons.css + ฟอนต์ + SHA256SUMS
│       ├── js/
│       │   ├── main.js          Now.init({allowEval:false}) + ตารางเส้นทาง
│       │   ├── api.js           แปลง envelope v2 ↔ Now.js สองทาง + 401/419
│       │   ├── auth.js          session bootstrap + ด่านหน้าทุกเส้นทาง
│       │   ├── formatters.js    window.formatters (ไปป์ใน data-text) + ตัวจัดรูปแบบเซลล์
│       │   ├── ui.js            sidebar/topbar/แถบโหมด + action `emit`/`apiRefresh`
│       │   └── pages.js         เฉพาะที่ประกาศไม่ได้: เซสชัน · SSE · คะแนนความปลอดภัย
│       ├── templates/*.html     21 หน้า — ประกาศล้วน (data-component/data-form/data-table)
│       ├── css/app.css          ธีมทับ Now.js
│       └── lang/{th,en}.json    i18n (en.json ว่างโดยตั้งใจ — ดู lang/README.md)
│
├── src/
│   ├── Kernel/                  เดิม (Router/Request/Response/Middleware)
│   ├── Http/                    ★ ใหม่ — แทนที่ src/Controller ทั้งหมด
│   │   ├── ApiController.php    คลาสฐาน: ok/created/noContent/fail/paginate
│   │   ├── ApiProblem.php       รูปแบบ error มาตรฐาน
│   │   ├── Resource/            ★ schema ของทรัพยากร (SiteResource, ...)
│   │   └── V2/                  Controller รายทรัพยากร (JSON ล้วน)
│   ├── Domain/                  เดิม (Repository)
│   ├── Agent/                   เดิม — ไม่แตะ
│   ├── Driver/                  เดิม + ที่เพิ่มในเฟส E
│   ├── Security/                เดิม
│   └── Support/                 เดิม
│
├── views/                       ✂ ลบแล้ว 2026-08-08 (เฟส D)
├── docs/
│   ├── PLAN-V2.md               เอกสารนี้
│   └── openapi.yaml             สเปก REST ที่เครื่องอ่านได้ — **ไม่ทำ API.md แยก**
│                                เพราะสเปกสองฉบับที่ต้องซิงก์กันเองจะเพี้ยนแน่นอน
│                                และมีเทสต์บังคับสองทางว่า openapi ตรงกับโค้ดอยู่แล้ว
└── tests/
    ├── security/                เดิม 232 เคส (+ tests/api อีก 71)
    └── api/                     ★ ทดสอบ contract ของ REST
```

---

## 4. สัญญา REST API — สเปกที่ต้อง implement

> ส่วนนี้คือ**สเปกบังคับ** ทุก endpoint ต้องทำตามนี้ทั้งหมด ไม่มีข้อยกเว้น

### 4.1 หลักการ

- Base path: **`/api/v2`** (ขึ้นเวอร์ชันเมื่อมี breaking change เท่านั้น)
- ตอบ `Content-Type: application/json; charset=utf-8` เสมอ **ไม่มี HTML ในทุกกรณี รวมถึงหน้า error**
- ใช้ HTTP verb ตามความหมายจริง: `GET` อ่าน · `POST` สร้าง/สั่งงาน · `PATCH` แก้บางส่วน · `PUT` แทนที่ทั้งชุด · `DELETE` ลบ
- ชื่อทรัพยากรเป็น**พหูพจน์ ตัวพิมพ์เล็ก คั่นด้วย `-`** เช่น `/sites`, `/php-versions`, `/cron-jobs`
- คำสั่งที่ไม่ใช่ CRUD ให้เป็น sub-resource ที่เป็นคำนาม ไม่ใช่กริยา — เช่น `POST /sites/{id}/suspension` (ไม่ใช่ `/sites/{id}/suspend`)

### 4.2 รูปแบบการตอบ

**สำเร็จ**
```json
{
  "ok": true,
  "data": { ... },
  "meta": { "page": 1, "per_page": 50, "total": 128, "total_pages": 3 }
}
```
`meta` ใส่เฉพาะเมื่อมีการแบ่งหน้า · `data` เป็น object สำหรับรายการเดี่ยว, array สำหรับรายการหลายตัว

**ล้มเหลว** — ต่างจากของเดิมที่ `error` เป็นสตริงเปล่า ๆ ให้ใช้โครงสร้างที่เครื่องอ่านได้:
```json
{
  "ok": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "ชื่อโดเมนไม่ถูกต้อง",
    "fields": { "domain": "รูปแบบโดเมนไม่ถูกต้องตาม RFC 1123" }
  }
}
```

**รหัส error ที่ใช้ได้ (enum ตายตัว):**
`VALIDATION_ERROR` · `UNAUTHENTICATED` · `TWO_FACTOR_REQUIRED` · `PASSWORD_CHANGE_REQUIRED` · `FORBIDDEN` · `NOT_FOUND` · `CONFLICT` · `CSRF_INVALID` · `RATE_LIMITED` · `QUOTA_EXCEEDED` · `PROTECTED_RESOURCE` · `AGENT_UNAVAILABLE` · `EXECUTION_FAILED` · `INTERNAL_ERROR`

### 4.3 HTTP status code

| Code | ใช้เมื่อ |
|---|---|
| `200` | สำเร็จ มีข้อมูลตอบกลับ |
| `201` | สร้างสำเร็จ (แนบ `Location` header) |
| `202` | รับงานเข้าคิวแล้ว (งานยาว เช่น backup) |
| `204` | สำเร็จ ไม่มีข้อมูลตอบกลับ (เช่น DELETE) |
| `400` | คำขอผิดรูปแบบ (JSON พัง) |
| `401` | `UNAUTHENTICATED` / `TWO_FACTOR_REQUIRED` |
| `403` | `FORBIDDEN` / `PROTECTED_RESOURCE` / `PASSWORD_CHANGE_REQUIRED` |
| `404` | `NOT_FOUND` |
| `409` | `CONFLICT` (เช่นโดเมนซ้ำ) |
| `419` | `CSRF_INVALID` |
| `422` | `VALIDATION_ERROR` / `QUOTA_EXCEEDED` |
| `429` | `RATE_LIMITED` (แนบ `Retry-After`) |
| `500` | `INTERNAL_ERROR` |
| `503` | `AGENT_UNAVAILABLE` |

### 4.4 การยืนยันตัวตน — จุดที่ต้องเพิ่มใหม่

**ปัญหาปัจจุบัน:** SPA ไม่มีหน้า HTML ให้ขูด `<meta name="csrf-token">` — ระบบเดิมไม่มี endpoint ใดคืน CSRF token เลย **ต้องเพิ่ม**

```
GET /api/v2/session
  ไม่ต้องล็อกอินก็เรียกได้ — ใช้ bootstrap ตอนเปิดแอป
  200 {"ok":true,"data":{
        "authenticated": false,
        "csrf_token": "…",           ← SPA เก็บไว้ใส่ header ทุก request ที่เปลี่ยนข้อมูล
        "mode": "production|sandbox|dryrun",
        "agent_available": true
      }}

  เมื่อล็อกอินแล้ว:
  200 {"ok":true,"data":{
        "authenticated": true,
        "csrf_token": "…",
        "user": {"id":1,"username":"admin","display_name":"…","role":"superadmin"},
        "permissions": ["site.view","site.create", …],   ← SPA ใช้ซ่อน/แสดงเมนู
        "must_change_password": false,
        "mode": "production", "agent_available": true
      }}

POST   /api/v2/session          {username, password}  → 200 หรือ 401 TWO_FACTOR_REQUIRED
POST   /api/v2/session/2fa      {code}                → 200
DELETE /api/v2/session                                → 204 (logout)
```

**กติกาบังคับ:**
- คุกกี้ session เดิม (`phpcp_sid` / `__Host-phpcp_sid`) HttpOnly + SameSite=Strict — **ห้ามย้าย token ไปเก็บใน JS**
- ทุก request ที่เปลี่ยนข้อมูลต้องส่ง header `X-CSRF-Token`
- เมื่อ session หมุน server ส่ง token ใหม่กลับทาง response header `X-CSRF-Token` (Now.js `HttpClient` อ่านอัตโนมัติอยู่แล้ว)
- `permissions` ที่ส่งให้ SPA ใช้เพื่อ **UX เท่านั้น** — การบังคับสิทธิ์จริงยังอยู่ที่ middleware + agent เหมือนเดิม ห้ามลดชั้นใดชั้นหนึ่ง

### 4.5 การแบ่งหน้า / ค้นหา / เรียง

```
GET /api/v2/sites?page=1&per_page=50&q=example&sort=-created_at&status=active
```
- `per_page` เพดาน 200 · ค่าเริ่มต้น 50
- `sort` นำหน้าด้วย `-` = จากมากไปน้อย
- ตัวกรองใช้ชื่อฟิลด์ตรง ๆ

### 4.6 ตารางแปลง route เดิม → REST ใหม่

> 99 routes เดิม → ~70 REST endpoints (ลดลงเพราะรวม verb เข้ากับ HTTP method)

| ทรัพยากร | REST ใหม่ | แทน route เดิม |
|---|---|---|
| **Session** | `GET/POST/DELETE /session`, `POST /session/2fa` | `/login`, `/login/2fa`, `/logout` |
| **Me** | `GET /me`, `PATCH /me/password` | `/account/password` |
| **Dashboard** | `GET /dashboard` | `GET /` |
| **Sites** | `GET/POST /sites` · `GET/PATCH/DELETE /sites/{id}` | `/sites` ×4 |
| | `PUT /sites/{id}/php-version` | `/sites/{id}/php` |
| | `GET/POST /sites/{id}/domains` · `DELETE /sites/{id}/domains/{domain}` · `PUT /sites/{id}/domains` | `/sites/{id}/domains*` ×3 |
| | `PUT /sites/{id}/suspension` (`{suspended:bool}`) | `/suspend`, `/resume` |
| | `POST /sites/{id}/owner-reset` | `/reset-owner` |
| **Domains** | `GET/POST /domains` · `DELETE /domains/{id}` | `/domains*` |
| | `GET/POST /domains/{id}/dns-records` · `DELETE /dns-records/{id}` | `/domains/{id}/records`, `/records/{id}/delete` |
| | `GET /domains/{id}/zone-file` | `/domains/{id}/zone` |
| **Certificates** | `GET /certificates` · `POST /certificates` (issue) · `POST /certificates/{siteId}/renewal` · `PUT /certificates/{siteId}/mode` · `DELETE /certificates/{siteId}` | `/ssl/*` ×5 |
| **PHP** | `GET /php-versions` | `/php` |
| **Databases** | `GET/POST /databases` · `DELETE /databases/{name}` · `POST /database-users/{user}/password` | `/databases*` ×4 |
| **Files** | `GET /files` (list) · `GET /files/content` · `PUT /files/content` · `GET /files/download` · `POST /files/upload` · `POST /files/directories` · `POST /files/move` · `POST /files/copy` · `DELETE /files` · `PUT /files/permissions` · `POST /files/archives` · `POST /files/extractions` | `/files/*` ×12 |
| **Cron** | `GET/POST /cron-jobs` · `PATCH/DELETE /cron-jobs/{id}` | `/cron*` ×4 |
| **Backups** | `GET/POST /backups` · `DELETE /backups/{id}` · `POST /backups/{id}/restoration` | `/backups*` ×4 |
| **Services** | `GET /services` · `POST /services/{unit}/actions` (`{action}`) | `/server/services*` |
| **Firewall** | `GET /firewall` · `POST /firewall/rules` · `DELETE /firewall/rules/{n}` · `PUT /firewall/enabled` | `/server/firewall/*` ×4 |
| **SSH** | `GET/PATCH /ssh-config` | `/server/ssh` ×2 |
| **Rollbacks** | `GET /rollbacks` · `POST /rollbacks/{id}/confirmation` · `POST /rollbacks/{id}/execution` | `/ssh|firewall/{id}/confirm|rollback` ×4 |
| **Logs** | `GET /logs/sources` · `GET /logs` | `/server/logs`, `/api/logs` |
| **Users** | `GET/POST /users` · `PATCH/DELETE /users/{id}` · `POST /users/{id}/password-reset` · `DELETE /users/{id}/two-factor` | `/server/users*` ×6 |
| **Customers** | `GET/POST /customers` · `GET/PATCH/DELETE /customers/{id}` · `PUT /customers/{id}/quota` · `POST /customers/{id}/password-reset` · `POST/DELETE /customers/{id}/sites` | `/customers*` ×9 |
| **Settings** | `GET/PATCH /settings` · `POST /settings/notification-test` · `POST /settings/mail-config` · `POST /settings/mail-test` | `/server/settings*` ×4 |
| **Security** | `GET /security/scan` | `/server/security` |
| **Metrics** | `GET /metrics` · `GET /metrics/stream` (SSE) · `GET /metrics/history` ★ | `/api/metrics`, `/api/stream/metrics` |
| **System** | `GET /system/info` · `GET /system/health` | `/server`, `/api/health` |

★ = ของใหม่ที่เพิ่มในเฟส E

---

## 5. แผนงานเป็นเฟส

### 5.1 ภาพรวมและลำดับ

```
┌── สายหลัก (ต้องเรียงลำดับ) ───────────────────────────────────────┐
│ A: ซ่อมรากฐาน  →  B: REST API v2  →  C: Now.js SPA  →  D: ปลดระวาง HTML │
└───────────────────────────────────────────────────────────────────┘

┌── สายขนาน (ทำพร้อมกันได้ เพราะเป็นงานฝั่ง agent เป็นหลัก) ──────────┐
│ E1 backup offsite · E2 disk quota · E3 DNS/BIND9 · E4 FTP          │
│ E5 WAF · E6 monitoring · E7 wildcard SSL                            │
└─────────────────────────────────────────────────────────────────────┘

               F: ขยายแพลตฟอร์ม (ทางเลือก — ไม่ใช่เงื่อนไข "สมบูรณ์")
```

**เหตุผลของลำดับนี้:** ฟีเจอร์ใหม่ที่มี UI ต้องรอเฟส C เพราะถ้าทำบน HTML เดิมก่อนจะต้อง**เขียนสองรอบ** แต่ฟีเจอร์ในสาย E ส่วนใหญ่เป็นงานฝั่ง agent/driver ที่มี UI น้อยมาก จึงทำขนานไปได้โดยไม่เสียงานซ้ำ

**ข้อยกเว้นสำคัญ:** ถ้าจะ deploy ใช้งานจริงกับลูกค้า**ก่อน**จบเฟส D ต้องทำ **E1 (backup offsite) และ E2 (disk quota) ให้เสร็จก่อน deploy** เพราะเป็นความเสี่ยงข้อมูลสูญหายและ noisy-neighbor ที่เกิดได้ทันทีที่มีลูกค้าจริง

### 5.2 ตารางสรุปเฟส

| เฟส | ชื่อ | ผลลัพธ์ | ความเสี่ยง | ขึ้นกับ |
|---|---|---|---|---|
| **A** | ~~ซ่อมรากฐานที่ขาด~~ ✅ **เสร็จทั้งเฟส 2026-08-05** | scheduler ทำงาน · auto-rollback ทำงานจริง · customer เดินผ่าน agent · ลบ UI ที่หลอก · เอกสารตรงโค้ด | ต่ำ | — |
| **B** | ~~REST API v2~~ ✅ **B1-B4 เสร็จแล้ว 2026-08-05** | API JSON ล้วนครบทุกทรัพยากร (94 endpoint) + OpenAPI + contract test | ต่ำ (เพิ่มล้วน ไม่แตะของเดิม) | A |
| **M** | ~~ยุบ users/customers เป็นตารางเดียว~~ 🔄 **M1–M5 เสร็จแล้ว 2026-08-06** | หนึ่งผู้ใช้ = หนึ่งบัญชี = หนึ่งบ้าน = หนึ่ง uid · `/api/v2/users` เส้นทางเดียว | กลาง (แตะสคีมาและ agent) | B |
| **C** | ~~Now.js SPA~~ ✅ **เสร็จทั้งเฟส · ตัวจัดการไฟล์พิสูจน์บนเครื่องจริงแล้ว 2026-08-11** | โครง SPA + 21 หน้า + i18n th/en + ตัวจัดการไฟล์เขียนได้ครบ 13 คำสั่ง | กลาง (งานเยอะ) | **M** |
| **D** | ปลดระวาง HTML | ลบ views/ + Controller เดิม + View.php | กลาง (ทำลายของเดิม) | C |
| **E** | เติมฟีเจอร์ให้สมบูรณ์ 🔄 **E1 เกือบครบ (ขาด S3/sftp/rsync live test) · E2 ทำเฉพาะทาง fallback (ยังไม่มี OS project quota) · E3 ✅ พิสูจน์กับ BIND9 จริงแล้ว 2026-08-10 (dig ตอบจริง) · E4 ✅ พิสูจน์ล็อกอิน SFTP จริงแล้ว 2026-08-10 · E5 🔄 rate limit ด้วย fail2ban ✅ 2026-08-11 (ModSecurity ยังไม่ทำ) · E7 🔄 โค้ดครบ 2026-08-11 แต่ยังไม่เคยออกใบจริง · E6 ✅ 2026-08-10 ครบทั้ง metrics ย้อนหลัง · อีเมล/webhook · เกณฑ์เตือน · เฝ้าดู agent ผ่าน systemd OnFailure** | ครบตามนิยามหัวข้อ 1 | กลาง-สูง (แตะ OS จริง) | ขนานได้ |
| **F** | ขยายแพลตฟอร์ม (ทางเลือก) | RHEL / mail เต็ม / multi-server | สูง | — |

---

## 6. รายละเอียดงานรายเฟส

### เฟส A — ซ่อมรากฐานที่ขาด

> **ทำก่อนอื่นเสมอ** งานเล็กแต่กระทบความปลอดภัยและความถูกต้องของระบบ

#### A1. Scheduler — องค์ประกอบที่หายไปทั้งตัว ★ สำคัญสุด — ✅ **เสร็จแล้ว (2026-08-05)**

> **สิ่งที่ทำจริงและจุดที่ต่างจากแผน** (อ่านก่อนทำเฟสถัดไปที่พึ่ง scheduler: E1/E2/E6)
>
> | ไฟล์ | หน้าที่ |
> |---|---|
> | `bin/phpcp-scheduler` | ตัวรัน — `--list` / `--run=<ชื่องาน>` / `--quiet` · flock กันรอบซ้อน |
> | `src/Domain/Scheduler.php` | ตัดสินว่างานไหนถึงเวลา แล้วสั่งผ่าน closure ที่ฉีดเข้ามา (เทสต์ได้โดยไม่ต้องมี agent) |
> | `src/Domain/ScheduledJobRepository.php` | อ่าน/เขียนตาราง + `DEFAULTS` + `installDefaults()` |
> | `src/Domain/CronSchedule.php` | เพิ่ม `matches()` / `isDue()` (เดิมมีแค่ validate/describe) |
> | `src/Agent/Capability/DiskUsage.php` · `CertSync.php` | capability ใหม่ 2 ตัว |
> | `templates/panel/phpcp-scheduler.{service,timer}.tpl` | unit + timer (`OnCalendar=*:0/1`, `Persistent=true`) |
>
> **ตัดสินใจระหว่างทางที่ต่างจากที่เขียนไว้ในแผน:**
>
> 1. **scheduler รันด้วยสิทธิ์ `phpcp-web` ไม่ใช่ root** — มันเป็นแค่ผู้กดปุ่มตามเวลา
>    ทุกอย่างเดินผ่าน agent เหมือนคำสั่งจากหน้าเว็บ ไม่มีเส้นทางที่สองที่ข้ามชั้นที่ 2
> 2. **`disk.usage` และ `cert.sync` เป็น capability แบบอ่านอย่างเดียว** ไม่ใช่ mutating —
>    มันไม่เปลี่ยนอะไรบนเครื่อง เขียนแค่ค่าที่วัดได้ลงตารางของ panel เอง
>    ผลพลอยได้คือไม่เติม audit log ทุก 15 นาทีตลอดไป และได้ Executor จริงในโหมด dryrun
> 3. **`rollback.run` มีด่านถามก่อนสั่ง** (`Scheduler::hasWork()`) — ถ้าไม่มีรายการหมดเวลา
>    จะข้ามโดยไม่สั่ง capability เลย เพราะการสั่งทุกนาทีจะเติม audit log ~2,880 แถว/วัน
>    ที่เกือบทั้งหมดคือ "ตรวจแล้วไม่มีอะไร" · เงื่อนไขที่ใช้ตัดสินเป็นคิวรีเดียวกับ
>    `RollbackGuard::expired()` และถ้าถามไม่ได้จะถือว่า "มีงาน" เสมอ (ผิดทางที่ปลอดภัยกว่า)
> 4. **`isDue()` ไล่เก็บรอบที่พลาด** ย้อนหลังได้ไม่เกิน 24 ชม. — เครื่องที่ดับตอนตี 3
>    ต้องได้รันงานรายวันเมื่อกลับมา ไม่ใช่ข้ามไปทั้งวัน
> 5. **งานตั้งต้นอยู่ในโค้ด (`ScheduledJobRepository::DEFAULTS`) ไม่ใช่ไฟล์ migration** —
>    เครื่องที่ติดตั้งไปแล้วผ่าน migration เก่าไปหมดแล้ว งานที่เพิ่มทีหลังจะไม่มีวันไปถึง
>    `phpcp setup` และ `phpcp db:migrate` เรียก `installDefaults()` ให้ทุกครั้ง
> 6. **แก้บั๊กที่เจอระหว่างทาง: `Db::value()`/`Db::first()` ไม่ปิด cursor** ทำให้เหลือ
>    read transaction ค้างบนการเชื่อมต่อ พอ agent (คนละโปรเซส) ถือ write lock อยู่
>    การเขียนครั้งถัดไปจะได้ `SQLITE_BUSY` **ทันที** โดยไม่รอตาม `busy_timeout`
>    อาการคือ "database is locked" แบบสุ่มเฉพาะตอนสองโปรเซสทำงานพร้อมกัน
>
> **ยังไม่ได้ทำ:** ข้อ 7 ของรายการข้างล่าง (หน้า Settings แสดงสถานะ scheduled jobs) รอเฟส C
> ระหว่างนี้ดูผ่าน `phpcp status`, `phpcp doctor` และ `phpcp-scheduler --list` ได้ครบ

**ปัญหาที่ยืนยันแล้ว:** ตาราง `scheduled_jobs` มีอยู่ (migration `0003`) พร้อมข้อมูล `expiry.check` ที่ตั้งเวลา `0 3 * * *` ไว้แล้ว **แต่ไม่มีโค้ดใดใน `src/` หรือ `bin/` อ่านตารางนี้เลย** และ `install.sh` ติดตั้ง systemd unit แค่ 3 ตัว (`phpcp-agentd`, `phpcp-fpm`, `phpcp-web`) ไม่มี timer ใด ๆ

**ผลกระทบที่เกิดจริงตอนนี้:**
- ❗ **auto-rollback ของ SSH/firewall ไม่ทำงานเองเลย** — `rollback.run` ถูกเรียกเฉพาะตอนมีคนเปิดหน้าเว็บนั้น ถ้าผู้ดูแลเปลี่ยนพอร์ต SSH ผิดแล้วหลุดการเชื่อมต่อ (ซึ่งเป็น**สถานการณ์ที่กลไกนี้ถูกออกแบบมาเพื่อรับมือโดยเฉพาะ**) จะไม่มีอะไรคืนค่าให้ → ล็อกตัวเองออกจากเครื่องถาวร นี่คือ**ช่องโหว่ ไม่ใช่ฟีเจอร์ขาด**
- วันหมดอายุลูกค้าไม่ถูกตรวจ ไม่มีการแจ้งเตือน ไม่มีการระงับอัตโนมัติ
- `sites.disk_used_mb` ไม่เคยถูกคำนวณ (ARCHITECTURE §14 ระบุว่าควรคำนวณทุก 15 นาที)
- สถานะใบรับรอง SSL ในฐานข้อมูลไม่ถูก sync กับของจริง

**สิ่งที่ต้องทำ:**
1. `bin/phpcp-scheduler` — อ่าน `scheduled_jobs` ที่ `enabled=1`, เทียบ cron expression กับเวลาปัจจุบัน (ใช้ `Domain/CronSchedule.php` ที่มีอยู่แล้ว), dispatch capability ผ่าน `Agent\Client`, บันทึก `last_run_at`/`last_status`/`last_error`
2. `templates/panel/phpcp-scheduler.service.tpl` + `.timer.tpl` (`OnCalendar=*:0/1` = ทุกนาที) — ตั้ง `NoNewPrivileges`, `ProtectSystem` เหมือน unit อื่น
3. แก้ `install.sh` ให้ติดตั้งและ enable timer ตัวนี้ (เป็น unit ที่ 4)
4. เพิ่ม `phpcp-scheduler` เข้า `SelfProtection::UNITS` — panel ต้องหยุด scheduler ตัวเองผ่าน UI ไม่ได้
5. เพิ่ม scheduled job ตั้งต้น: `rollback.run` (ทุกนาที), `expiry.check` (มีแล้ว), `disk.usage` (ทุก 15 นาที — ต้องสร้าง capability ใหม่), `cert.sync` (ทุกวัน)
6. `phpcp doctor` ต้องเตือนถ้า timer ไม่ทำงานหรือ `last_run_at` ค้างเกิน 5 นาที
7. หน้า Settings แสดงสถานะ scheduled jobs (เฟส C)

**เกณฑ์รับงาน:** ตั้งค่าเปลี่ยนพอร์ต SSH ใน container จริง → ปิดเบราว์เซอร์ทิ้งไว้ ไม่แตะอะไรเลย → เมื่อครบเวลา window ค่าต้องถูกคืนเป็นเดิมโดยอัตโนมัติ และมี audit log บันทึกว่า rollback แล้ว

#### A2. เดินสาย capability ที่ถูกทิ้งร้าง — ✅ **เสร็จแล้ว (2026-08-05)**

`customer.create`, `customer.quota_update`, `customer.site_attach` ลงทะเบียนครบสมบูรณ์แต่**ไม่มีใครเรียก** — `CustomerController` และ CLI เขียนตรรกะเดียวกันซ้ำแล้วเข้าถึง `CustomerRepository` ตรง ๆ ข้าม `Agent\Client` ไปเลย ผลคือ**เส้นทางนี้ไม่ได้ audit ผ่าน `Dispatcher` เหมือนคำสั่งอื่นทั้งระบบ**

**ตัดสินใจ:** เดินสายให้ Controller เรียกผ่าน Agent (ได้ validate + audit + notify ฟรี และสม่ำเสมอกับสถาปัตยกรรม) แล้วลบตรรกะที่ซ้ำใน Controller ออก

> **สิ่งที่ทำจริง:** `CustomerController::create/updateQuota/attachSite` และ CLI `customer:create`/`customer:quota`
> เรียกผ่าน agent ทั้งหมดแล้ว · ลบตรรกะซ้ำออก ~150 บรรทัด · เพิ่ม `CustomerCapability`
> เป็นฐานร่วม (กฎโควตา + โหลดลูกค้า + ตรวจโควตาตอนเชื่อมเว็บ)
>
> **สิ่งที่พบว่าพังจริงตอนเดินสาย — capability ทั้งสามไม่เคยถูกเรียก จึงไม่มีใครรู้:**
>
> 1. **`customer.quota_update` ตายทันทีที่ถูกเรียก** — ส่ง `null` เป็นค่าเริ่มต้นเข้า
>    `Validator::optionalInt()` ที่รับเฉพาะ `int` → TypeError · แก้โดยเพิ่ม `Validator::nullableInt()`
>    ซึ่งเฟส B จะได้ใช้ต่อกับทุก endpoint แบบ PATCH
> 2. **`customer.create` ตั้งโควตา "ไม่จำกัด" (-1) ไม่ได้** — ตรวจว่า "ต้องเป็นจำนวนเต็มบวก"
>    ขัดกับทั้งระบบที่ใช้ -1 = ไม่จำกัด · ตอนนี้ทุกเส้นทางใช้ `CustomerRepository::assertQuotaValue()` ร่วมกัน
> 3. **`InvalidArgumentException` จาก repository หลุดออกไปเป็น `internal_error`** — ผู้ใช้ที่กรอก
>    อีเมลผิดจะเห็น "เกิดข้อผิดพลาดภายในระบบ" · แปลงเป็น `ValidationError` แล้ว
> 4. **`customer.site_attach` ไม่ตรวจโควตา** — ตรรกะนั้นอยู่แค่ใน Controller ถ้าเดินสายตรง ๆ
>    จะกลายเป็นการ**ลด**การป้องกัน · ย้ายเข้ามาอยู่ที่ capability แล้ว (ที่ถูกต้องตามสถาปัตยกรรม)
> 5. **audit log เก็บค่าดิบทั้งหมดของทุกคำสั่ง** — พอ `customer.create` เดินผ่าน Dispatcher
>    รหัสผ่านลูกค้าจึงถูกฝังลง hash-chain ที่ลบไม่ได้ · เป็นปัญหาที่มีอยู่ก่อนแล้วด้วย
>    (`db.user_password`, `notify.telegram.token`, และ `file.write` ที่สำเนาเนื้อหาไฟล์ทั้งไฟล์)
>    · เพิ่ม `Dispatcher::redact()` ล้างค่าที่ชื่อมีคำว่า password/secret/token/passphrase/…
>    และตัดค่าที่ยาวเกิน 200 ไบต์เหลือแค่ขนาด

#### A3. ลบ UI ที่สื่อสารเกินจริง — ✅ **เสร็จแล้ว (2026-08-05)**

`quota_ftp_users` ปรากฏเป็นฟิลด์ให้กรอกในหน้าลูกค้า แต่**ไม่มีระบบ FTP อยู่จริงเลยทั้ง repo** — ซ่อนฟิลด์ออกจนกว่าเฟส E4 จะเสร็จ (หรือทำ E4 ก่อน) · เช่นเดียวกับ `quota_emails` ที่ `QuotaChecker` ฮาร์ดโค้ด `$emails = 0` ไว้

> **สิ่งที่ทำจริง:** ถอดช่องกรอกโควตา FTP ออกจากทั้งฟอร์มสร้างลูกค้าและฟอร์มแก้โควตาใน
> `views/page/customers.php` พร้อมหมายเหตุอธิบายว่าทำไมและจะกลับมาเมื่อไร (เฟส E4)
> · **ไม่แตะคอลัมน์ในฐานข้อมูลและไม่แตะ CLI** — ค่าที่ลูกค้าเดิมมีอยู่ยังอยู่ครบ
> พอ E4 เสร็จก็เอาช่องกลับมาได้โดยไม่ต้อง migrate อะไร
> · โควตาอีเมลไม่เคยมีช่องกรอกในหน้าเว็บอยู่แล้ว จึงไม่มีอะไรต้องถอด

#### A4. ปรับเอกสารให้ตรงความจริง — ✅ **เสร็จแล้ว (2026-08-05)**

แก้ `ARCHITECTURE.md` §9.2 (งบน้ำหนัก) และ D6 (ไม่มี build step) ให้สอดคล้องกับการตัดสินใจ N8/N9 — **ห้ามปล่อยให้เอกสารขัดกับโค้ด** เพราะเอกสารที่โกหกเรื่องหนึ่งทำให้ไม่มีใครเชื่อเรื่องอื่นอีกเลย

> **สิ่งที่ทำจริง:** §9.2 เปลี่ยนเป็นตารางสองคอลัมน์ (UI แบบ HTML ของเดิม / SPA เป้าหมายเฟส C)
> และถอดคำว่า "บังคับใน CI" ออกเพราะไม่เคยมี CI ตัวไหนตรวจค่านั้นจริง ·
> D6 ระบุเพิ่มว่าไลบรารีภายนอกที่ commit dist เข้า repo ไม่ขัดกับ "ไม่มี build step"
> · §5.2 / §13 / §14 อัปเดตไปแล้วในเฟส A1 (unit ที่ 4-5 และตัวคำนวณ disk usage)

---

### เฟส B — REST API v2 (JSON ล้วน)

> เพิ่มล้วน ไม่แตะของเดิม → HTML UI ยังใช้งานได้ตลอดเฟสนี้ ความเสี่ยงต่ำมาก

> **สถานะ: B1, B2, B3.1–B3.5 เสร็จแล้ว (2026-08-05) — ทรัพยากรครบทุกตัวตาม §4.6 · เหลือ B4 (OpenAPI ครบแล้ว, เทสต์ครบแล้ว) → เข้าเฟส C ได้**
>
> | ไฟล์ | หน้าที่ |
> |---|---|
> | `src/Http/ApiProblem.php` | enum รหัสข้อผิดพลาด 16 ตัว + status ที่ผูกตายตัว + ตัวแปลงจาก `AgentException` |
> | `src/Http/ApiController.php` | ฐาน: `ok/created/accepted/noContent/paginate/problem` + `pagination()/sort()` |
> | `src/Http/Resource/{Resource,UserResource}.php` | schema ที่เลือกฟิลด์ทีละตัวและคืนค่าดิบ |
> | `src/Http/V2/{Session,Me}Controller.php` | `/session`, `/session/2fa`, `/me`, `/me/password` |
> | `docs/openapi.yaml` | สเปกที่เขียนคู่กับโค้ด (ครอบเฉพาะที่ทำเสร็จแล้ว) |
> | `tests/api/` | เทสต์ contract 17 เคส ยิงผ่าน HttpKernel จริงพร้อม middleware ครบ |
>
> **สิ่งที่ต้องรู้ก่อนทำ B3:**
>
> 1. **`Request::payload()/payloadString()`** อ่านได้ทั้ง JSON body และ form-encoded —
>    controller ตัวใหม่ต้องใช้ตัวนี้ ไม่ใช่ `input()` ไม่งั้น `PATCH` ที่ส่ง JSON มาจะได้ค่าว่าง
>    (PHP เติม `$_POST` ให้เฉพาะ form-encoded เท่านั้น)
> 2. **ไม่ต้อง `try/catch` รอบการเรียก agent** — ปล่อยให้ `AgentException` ลอยขึ้นไป
>    `HttpKernel` แปลงเป็นรหัสที่ถูกต้องและบันทึก log ให้เอง (`ValidationError`→422,
>    `PermissionDenied`→403, `TransportError`→503, `ExecutionFailed`→500)
> 3. **เส้นทางใหม่ทุกเส้นต้องมี permission** ไม่งั้นเทสต์ `RbacMatrixTest` จะล้ม —
>    เส้นทางสาธารณะต้องขึ้นทะเบียนในเทสต์นั้นพร้อมเหตุผล
> 4. **`Csrf::SCOPE`** เป็นแหล่งความจริงเดียวของชื่อผูก token · ถ้า controller เปลี่ยน
>    `sessionId` เองต้องเรียก `Ctx::refreshCsrfToken()` ไม่งั้นคำตอบจะพา token ที่ใช้ไม่ได้กลับไป
> 5. **ลำดับ middleware ทำให้ CSRF ถูกตรวจก่อน 405** — `PUT` ใส่เส้นทางที่มีอยู่แต่ไม่มี token
>    จะได้ 419 ไม่ใช่ 405 · เป็นลำดับที่ถูกต้องแล้ว (ตัดคำขอที่ไม่มี token ก่อนแตะ logic ใด ๆ)
>
> **ยังไม่ได้ทำใน B2:** endpoint สำหรับ *ตั้งค่า* 2FA (สร้าง secret/QR/รหัสสำรอง) —
> ของเดิมยังไม่มีหน้านั้นเหมือนกัน จึงยกไปรวมกับ B3.5 (`users`) ที่เป็นเจ้าของเรื่องนี้
>
> ---
>
> **B3.1 (sites · domains · dns-records · php-versions) — เสร็จแล้ว**
>
> | ไฟล์ | หน้าที่ |
> |---|---|
> | `src/Http/V2/HostingController.php` | ฐานร่วม: `scopeOwner()` / `mayAccessSite()` / `findSite()` |
> | `src/Http/V2/SitesController.php` | 12 endpoint ของเว็บไซต์ รวมโดเมนของแต่ละเว็บ |
> | `src/Http/V2/DomainsController.php` | โดเมนทั้งระบบ + DNS record + zone file |
> | `src/Http/V2/PhpVersionsController.php` | `GET /php-versions` |
> | `src/Http/Resource/{Site,Domain,DnsRecord}Resource.php` | schema ที่คืนค่าดิบ |
> | `src/Domain/DnsRecord.php` | **กฎของ DNS record ที่หน้าเว็บเดิมกับ API ใช้ร่วมกัน** |
> | `tests/api/{SitesApiTest,OpenApiSpecTest}.php` | 14 เคส (สัญญา + กันสเปกล้าหลังโค้ด) |
>
> **สิ่งที่ตัดสินใจและสิ่งที่พบ:**
>
> 1. **`PATCH /sites/{id}` รองรับเฉพาะ `php_version`** — เป็นฟิลด์เดียวของเว็บไซต์ที่มี
>    capability รองรับจริง · การเปลี่ยนชื่อ/docroot ต้องรอ capability ของมันเอง
>    ชั้น API จะไม่เขียนฐานข้อมูลตรง ๆ เพราะนั่นคือการข้ามชั้นที่ 2 และทำให้ไม่มีร่องรอยใน audit
> 2. **เว็บของคนอื่นตอบ 404 ไม่ใช่ 403** — 403 ยืนยันว่า "มีอยู่จริงแต่ไม่ใช่ของคุณ"
>    ซึ่งใช้ไล่เดา id เพื่อสำรวจว่ามีเว็บอะไรบนเครื่องได้
> 3. **`zone-file` คืน JSON ที่มี `content`** ไม่ใช่ไฟล์แนบ — สัญญา "ทุก endpoint ตอบ JSON"
>    ไม่มีข้อยกเว้น · ฝั่ง SPA สร้างไฟล์ให้ดาวน์โหลดจากข้อความนั้นเองได้
> 4. **ย้ายกฎ DNS ไป `Domain\DnsRecord`** แล้วให้ `DomainController` เดิมเรียกตัวเดียวกัน —
>    ไม่งั้นจะมีวันที่หน้าเว็บยอมรับค่าที่ API ปฏิเสธ
> 5. **แก้บั๊กที่เทสต์จับได้: CNAME ที่เป็น IP ผ่านมาตลอด** — ตัวตรวจเดิมใช้
>    `/^[a-z0-9.-]+\.?$/i` ซึ่ง `203.0.113.10` ผ่านฉลุยเพราะมีแต่ตัวเลขกับจุด
>    คอมเมนต์บอกว่ากันไว้แล้วแต่โค้ดไม่ได้กัน · ตอนนี้ปฏิเสธพร้อมบอกทางแก้ (ให้ใช้ A/AAAA)
>    **เป็นบั๊กของหน้าเว็บเดิมด้วย และแก้ให้ทั้งสองทางพร้อมกันเพราะใช้โค้ดชุดเดียวกันแล้ว**
> 6. **`OpenApiSpecTest` กันสเปกล้าหลังโค้ด** — เพิ่ม endpoint แล้วลืมเขียนสเปก = เทสต์แดง
>    และในทางกลับกัน สเปกที่สัญญาเส้นทางซึ่งไม่มีในโค้ดก็แดงเช่นกัน
>
> ---
>
> **B3.2 (certificates · databases · cron-jobs · backups) — เสร็จแล้ว**
>
> | ไฟล์ | หน้าที่ |
> |---|---|
> | `src/Http/V2/CertificatesController.php` | 5 endpoint · อ้างด้วย **site id** เพราะ 1 เว็บ = 1 ใบเสมอ |
> | `src/Http/V2/DatabasesController.php` | 4 endpoint · อ้างด้วย **ชื่อฐานข้อมูล** เพราะชื่อคือสิ่งที่ MariaDB รู้จัก |
> | `src/Http/V2/CronJobsController.php` | 4 endpoint · ทุกการแก้ไขจบด้วย `cron.sync` + ย้อนกลับเมื่อล้ม |
> | `src/Http/V2/BackupsController.php` | 4 endpoint · `POST /backups/{id}/restoration` แยกสิทธิ์ต่างหาก |
> | `src/Domain/CronJobRepository.php` | **`applyThenSync()` ที่หน้าเว็บเดิมกับ API ใช้ร่วมกัน** |
> | `src/Http/Resource/{CronJob,Backup}Resource.php` | schema ที่คืนค่าดิบ |
> | `tests/api/HostingApiTest.php` | 11 เคส เน้นการย้อนกลับของ cron และการกันไฟล์สำรองระดับระบบ |
>
> **สิ่งที่ตัดสินใจ:**
>
> 1. **`cron-jobs` ต้องย้อนฐานข้อมูลกลับเมื่อเขียนไฟล์ไม่สำเร็จ** — ตรรกะนี้เดิมอยู่ใน
>    controller ของหน้าเว็บอย่างเดียว · ย้ายไป `CronJobRepository::applyThenSync()` แล้ว
>    ทั้งสองทางจึงได้การรับประกันเดียวกัน · เทสต์บังคับครบทั้งสามเส้นทาง (สร้าง/แก้/ลบ)
>    โดยอาศัยข้อเท็จจริงที่ว่าเทสต์รันโดยไม่มี agent — `cron.sync` จึงล้มเสมอ ซึ่งเป็น
>    สถานการณ์ที่ต้องพิสูจน์พอดี
> 2. **`schedule` ส่งทั้งค่าดิบและ `schedule_label`** — ฟอร์มแก้ไขต้องใช้ค่าดิบ
>    ส่วนหน้ารายการอยากได้คำอธิบาย · เป็นข้อยกเว้นเดียวของกฎ "คืนค่าดิบเท่านั้น"
>    ที่จงใจ เพราะการแปล cron expression ซ้ำที่ฝั่ง JS คือการเขียนตรรกะเดียวกันสองภาษา
> 3. **ไฟล์สำรองระดับระบบ (`type=config`) ลูกค้ามองไม่เห็นเลย** — JOIN กับ sites
>    ทำให้แถวที่ `site_id` เป็น null หลุดออกจากผลลัพธ์ของ webadmin โดยอัตโนมัติ
> 4. **การกู้คืนใช้สิทธิ์ `backup.restore` ที่แยกจาก `backup.manage`** — sysadmin
>    ดูรายการได้แต่กู้ไม่ได้ · webadmin สร้าง/ลบของตัวเองได้แต่กู้ไม่ได้
>    (เทสต์ยืนยันว่าถูกปฏิเสธคนละชั้นและได้รหัสต่างกันอย่างถูกต้อง: 403 ที่ middleware
>    กับ 404 ที่ controller)
> 5. **รหัสผ่านฐานข้อมูลอยู่ใน body ของคำตอบ ไม่ใช่ query string** — ของเดิม redirect
>    พร้อม `?pw=...` ซึ่งไปโผล่ใน access log ของเว็บเซิร์ฟเวอร์และในประวัติเบราว์เซอร์
>
> ---
>
> **B3.3 (files) — เสร็จแล้ว · 13 endpoint**
>
> `src/Http/V2/FilesController.php` · `tests/api/FilesApiTest.php` (8 เคส)
>
> 1. **เพิ่ม `GET /files/roots` ที่ไม่มีในตาราง §4.6** — UI เดิมได้รายการขอบเขตมาพร้อม
>    หน้า HTML แต่ SPA ไม่มีหน้าให้แนบมาด้วย จึงต้องมีทางขอแยก · ผลลัพธ์**ไม่มีเส้นทางจริง
>    บนเครื่อง** เพราะฝั่งหน้าเว็บไม่มีอะไรต้องทำกับค่านั้น
> 2. **ทุก endpoint อ้าง `root` + `path` สัมพัทธ์ ไม่มีเส้นทางสัมบูรณ์ปรากฏใน API เลย** —
>    นี่คือสิ่งที่ทำให้ traversal ออกนอกขอบเขตเป็นไปไม่ได้ตั้งแต่*รูปแบบ*ของ API
>    ไม่ใช่แค่จากการตรวจค่า · PathGuard ในฝั่ง agent ยังตรวจซ้ำอีกสองชั้นเหมือนเดิม
> 3. **`GET /files/download` เป็นข้อยกเว้นเดียวของกฎ "ทุก endpoint ตอบ JSON"** —
>    base64 ใน JSON ทำให้ไฟล์โต 33% และบังคับให้ client ถอดรหัสทั้งไฟล์ในหน่วยความจำ
>    ซึ่งใช้ไม่ได้จริงกับไฟล์หลายสิบเมกะไบต์ · ระบุเหตุผลไว้ในสเปกแล้ว และ**กรณี error
>    ยังตอบ JSON ตามปกติ** · header `nosniff` + octet-stream ยังอยู่ครบ (กันไฟล์ .html
>    ของผู้ใช้กลายเป็น stored XSS ในโดเมนของ panel)
> 4. **อัปโหลดรับสองแบบ**: multipart `files[]` (เบราว์เซอร์) และ JSON `name` +
>    `content_base64` (curl/สคริปต์) — แบบที่สองจำเป็นตามเกณฑ์รับงานเฟส B
> 5. **แปลงแฟล็ก boolean ที่ชั้น API ไม่ใช่ที่ capability** — capability ชุดไฟล์รับแฟล็ก
>    เป็นสตริง (มรดกจากฟอร์ม HTML) ส่วน SPA ส่ง true/false · การแปลงอยู่ที่
>    `FilesController::flag()` เพราะ capability เป็นชั้นที่แผนกำหนดว่าห้ามแตะ
> 6. **`copy` แยกเส้นทางจาก `move`** ทั้งที่ใช้ capability เดียวกัน — สองอย่างนี้เป็นคนละ
>    การกระทำในสายตาผู้ใช้ และการซ่อนความต่างไว้ในแฟล็กทำให้อ่าน log ย้อนหลังแล้วแยกไม่ออก
>
> **สิ่งที่การทดสอบจริงด้วย curl เปิดเผย:** `destination` ของ `/files/extractions` เป็น
> **ชื่อโฟลเดอร์** ไม่ใช่เส้นทาง (capability ปฏิเสธค่าที่มี `/`) — แก้คำอธิบายในสเปกแล้ว
>
> ---
>
> **B3.4 (ฝั่ง server) — เสร็จแล้ว · 18 endpoint**
>
> `Services` · `Firewall` · `SshConfig` · `Rollbacks` · `Logs` · `Security` · `Metrics` · `System`
> (`src/Http/V2/`) พร้อม `tests/api/ServerApiTest.php` (9 เคส)
>
> 1. **`webadmin` ต้องได้ 403 ทุก endpoint — ตรวจทีละเส้นทาง ไม่ใช่เชื่อว่าตารางสิทธิ์ถูก**
>    (เกณฑ์รับงาน B4 ข้อ 3) · เทสต์ยังใช้ประโยชน์จากการที่ agent ไม่ทำงานในเทสต์:
>    `sysadmin` ต้องได้ **503** (ผ่านสิทธิ์แล้วไปติดที่ agent) ไม่ใช่ 403 — ความต่าง
>    ของสองรหัสนี้พิสูจน์ว่าสิทธิ์ผูกกับเส้นทางถูกต้อง ไม่ใช่บังเอิญล้มเหมือนกันหมด
> 2. **`GET /system/health` ไม่เรียก agent โดยเจตนา** — มันมีไว้ตอบว่า "ติดต่อ agent
>    ได้ไหม" ถ้าตัวมันเองต้องพึ่ง agent จะล้มพร้อมกันแล้วตอบไม่ได้เลยในจังหวะที่ต้องการ
>    ที่สุด · ใช้ `dashboard.view` เพื่อให้ลูกค้าที่กดปุ่มแล้วไม่มีอะไรเกิดขึ้นเห็นสาเหตุได้
> 3. **`firewall` และ `ssh-config` แนบ `pending_rollback` กลับไปทุกครั้ง** (id, คำอธิบาย,
>    `remaining_seconds`) — ไม่งั้น SPA จะไม่รู้ว่าต้องขึ้นตัวนับถอยหลัง แล้วผู้ใช้จะเสีย
>    การตั้งค่าที่เพิ่งทำไปเงียบ ๆ เมื่อครบเวลาโดยไม่เข้าใจว่าเพราะอะไร
> 4. **`rollbacks/{id}/execution` บีบให้หมดอายุแล้วเดินเส้นทางเดียวกับ scheduler** —
>    ไม่มีตรรกะคืนค่าชุดที่สองในระบบให้ต้องดูแลและทดสอบ
> 5. **คำสั่งบริการที่ไม่รู้จักถูกปฏิเสธที่ชั้นเว็บ** — ชื่อ capability ถูกประกอบจากค่าที่
>    ผู้ใช้ส่งมา (`service.` . `$action`) ซึ่งเป็นรูปแบบที่ต้องไม่ปล่อยให้หลุดออกไปเลย
> 6. **`metrics/stream` เป็นข้อยกเว้น JSON ข้อที่สอง** — ใช้ `StreamController` เดิมซ้ำ
>    ไม่เขียน SSE ใหม่ (โค้ดนั้นจัดการ output buffer, ตรวจว่าเบราว์เซอร์ยังอยู่, และปิดเอง
>    ที่ 30 นาทีไว้แล้ว) · เมื่อเฟส D ลบ controller เดิม ให้**ย้ายไฟล์**มา ไม่ใช่คัดลอกโค้ด
> 7. **`logs` และ `files` ใช้หลักการเดียวกัน: ผู้ใช้ส่ง "คีย์" ไม่ใช่ "เส้นทาง"** และ
>    ผลลัพธ์ไม่มีเส้นทางไฟล์จริงติดออกไป — API นี้จึงอ่านไฟล์ใดก็ได้บนเครื่องไม่ได้
>
> ---
>
> **B3.5 (users · customers · settings) — เสร็จแล้ว · 18 endpoint → ครบทุกทรัพยากรของเฟส B**
>
> `UsersController` · `CustomersController` · `SettingsController` (`src/Http/V2/`)
> พร้อม `tests/api/AccountsApiTest.php` (11 เคส)
>
> **การตัดสินใจที่ค้างไว้ตั้งแต่ B3.4 — `users` ไม่เดินผ่าน agent:**
> บัญชีผู้ใช้ panel เป็นสถานะภายในของ panel ล้วน ๆ ไม่แตะระบบปฏิบัติการเลย (ไม่สร้าง
> system user ไม่แตะไฟล์ ไม่สั่งบริการ) · ชั้น agent มีไว้คั่นสิ่งที่ต้องใช้สิทธิ์สูง
> การเพิ่ม capability `user.*` จึงเป็นการขยายพื้นที่ผิวของชั้นที่อันตรายที่สุดในระบบ
> เพื่อสิ่งที่ไม่เคยออกไปนอกฐานข้อมูลของ panel — ขัดกับ N4 · **แบบแผนเดียวกับ `dns_records`**
> ที่แผนก็ให้อยู่ที่ชั้นเว็บจนกว่าจะมี BIND9 จริงในเฟส E3
> ราคาที่จ่ายคือต้องเขียน audit เองทุกจุด ซึ่งมีเทสต์เฝ้าว่าครบทุกเมธอด
>
> **สิ่งที่ตรึงไว้ด้วยเทสต์:**
> 1. **กฎกันล็อกตัวเองออกจากระบบ** — แก้บทบาท/ระงับ/ลบบัญชีตัวเองไม่ได้ (403)
>    และต้องเหลือผู้ดูแลระบบที่ใช้งานได้อย่างน้อยหนึ่งบัญชี · ระหว่างเขียนเทสต์พบว่า
>    กฎข้อหลัง**แทบไม่มีทางไปถึงผ่าน API** เพราะคนที่ลดบทบาทผู้ดูแลได้ต้องเป็น superadmin
>    และถ้าเป้าหมายคือ "คนสุดท้าย" ก็แปลว่าเป็นตัวเอง ซึ่งถูกกันที่ด่านแรกแล้ว —
>    จึงตรวจที่ repository โดยตรงแทน และตรวจว่า controller เรียกกฎครบทั้งสามทาง
> 2. **`customers` เดินผ่าน capability ของ A2 เท่านั้น** — มีเทสต์ทั้งแบบอ่านโค้ด
>    และแบบยิงจริง (agent ไม่ทำงาน = ต้องได้ 503 และต้องไม่มีลูกค้าถูกสร้างขึ้น)
> 3. **ค่าลับใน settings ถูกปิดบังก่อนออกจาก capability** และค่าว่างต้องยังว่าง —
>    หน้าจอต้องแยกออกว่า "ยังไม่ได้ตั้ง" กับ "ตั้งไว้แล้วแต่ซ่อนอยู่"
>
> ---
>
> **บั๊กที่พบตอนทำ B3.5 — 4 เส้นทางของ B3.2/B3.5 ตายสนิทโดยไม่มีใครรู้**
>
> `Router::compile()` แปลงเฉพาะ `{[a-z_]+}` — เส้นทางที่เขียน `{siteId}` แบบ camelCase
> จึงไม่ถูกแปลงเป็น regex และ **ไม่มีวันจับคู่กับคำขอใด ๆ เลย**
> (`/certificates/{siteId}` ×3 และ `/customers/{id}/sites/{siteId}`)
>
> ที่น่ากลัวกว่าตัวบั๊กคือ **เทสต์ contract เดิมยังเขียวทั้งหมด** เพราะ 404 ก็เป็น JSON
> ที่ถูกรูปแบบเหมือนกัน — เทสต์ตรวจ "รูปร่างของคำตอบ" แต่ไม่ได้ตรวจว่า "เส้นทางมีอยู่จริง"
>
> แก้เป็น `{site_id}` ทั้งหมด และเพิ่มเทสต์ถาวรใน `OpenApiSpecTest` ที่ลองจับคู่ทุกเส้นทาง
> ที่มีพารามิเตอร์กับ URL ตัวอย่างจริง — เส้นทางที่จับคู่ไม่ได้จะทำให้เทสต์แดงทันที

#### B1. วางรากฐาน
1. `src/Http/ApiController.php` — คลาสฐาน: `ok()`, `created()`, `accepted()`, `noContent()`, `paginate()`, `fail()`
2. `src/Http/ApiProblem.php` — รูปแบบ error ตาม §4.2 พร้อม enum รหัส error
3. แก้ `HttpKernel::handleException()` ให้แปลง `AgentException` แต่ละชนิดเป็นรหัส error ที่ถูกต้อง (`ValidationError`→422, `PermissionDenied`→403, `ProtectedResource`→403, `TransportError`→503, `ExecutionFailed`→500)
4. แก้ middleware ทั้ง 7 ตัวให้ตอบตามรูปแบบ error ใหม่เมื่อ path ขึ้นต้น `/api/v2/`
5. `src/Http/Resource/` — คลาสแปลง array จาก Repository เป็น JSON schema ที่เสถียร **จุดสำคัญ: ต้องคืนค่าดิบ (bytes, unix timestamp) ให้ frontend จัดรูปแบบเอง ไม่ใช่ส่งสตริงที่ format แล้วแบบที่ view เดิมทำ**

#### B2. Session + bootstrap (ทำก่อนอื่น เพราะทุกอย่างขึ้นกับมัน)
`GET/POST/DELETE /session`, `POST /session/2fa`, `GET /me`, `PATCH /me/password` ตาม §4.4

#### B3. ทรัพยากรที่เหลือ — แบ่งเป็น 5 ชุด
| ชุด | ทรัพยากร | หมายเหตุ |
|---|---|---|
| B3.1 | sites, php-versions, domains, dns-records | แกนหลักของ hosting |
| B3.2 | certificates, databases, cron-jobs, backups | |
| B3.3 | files | ซับซ้อนสุด 12 endpoint · upload ต้องรองรับ chunk |
| B3.4 | services, firewall, ssh-config, rollbacks, logs, security, metrics, system | ฝั่ง server |
| B3.5 | users, customers, settings | |

#### B4. เอกสารและทดสอบ
1. `docs/openapi.yaml` — เขียนคู่กับโค้ด ไม่ใช่ตามหลัง
2. `tests/api/` — ทดสอบ contract: ทุก endpoint ต้องคืน `Content-Type: application/json` เสมอ **แม้ในกรณี error ทุกชนิด** (401/403/404/419/422/429/500/503) · ห้ามมี HTML หลุดออกมาแม้แต่ไบต์เดียว
3. ทดสอบ RBAC ซ้ำบน API ใหม่: `webadmin` ต้องได้ 403 ทุก endpoint ของ SERVER

**เกณฑ์รับงาน:** เรียกได้ครบทุกทรัพยากรด้วย `curl` โดยไม่ต้องเปิดเบราว์เซอร์เลย · `tests/run.php` ยังผ่าน 303/303 เท่าเดิม (ไม่มี regression) · ชุดทดสอบ API ใหม่ผ่านทั้งหมด

---

### เฟส M — ยุบ users/customers เป็นตารางเดียว

> **ต้องทำก่อนเฟส C** — SPA จะสร้างหน้าจอตามรูปข้อมูลที่มีอยู่ ถ้ายุบทีหลังต้องรื้อหน้าจอ
> API และเทสต์พร้อมกัน

**ที่มา:** `customers` กับ `users` เป็น 1:1 อยู่แล้วตั้งแต่ migration 0004 แต่ยังมีของซ้ำสองที่:
`password_hash` (เขียนพลาดที่ใดที่หนึ่ง = "เปลี่ยนรหัสแล้วรหัสเก่ายังใช้ได้"), `status` คนละชุดค่า
ที่ในฐานข้อมูลจริงก็ขัดกันเองอยู่, และ `customer_sites` กับ `sites.owner_user_id` เป็นความจริง
สองชุดที่ไม่ตรงกันจริง ๆ · ที่แย่ที่สุดคือ "ผู้ใช้ที่ไม่มีแถวลูกค้า" เป็นไปได้ ทำให้ผ่านทุกโควตา

#### M1. ยุบสคีมาและชั้นโดเมน ✅ **เสร็จแล้ว 2026-08-06**

- `db/migrations/0005_merge_customers_into_users.sql` — โควตา/อีเมล/วันหมดอายุ/`service_status`
  ย้ายขึ้นมาบน `users` · `customer_sites` ยุบเข้า `sites.owner_user_id` · ทิ้ง `customers`
- **สองแกนสถานะที่ตั้งใจให้ต่างกันได้:** `status` คุมสิทธิ์ล็อกอิน · `service_status` คุมบริการโฮสติ้ง
  (อยู่แถวเดียวกันจึงขัดกันเองไม่ได้) — ตอนนี้ทั้งคู่ยังปิดกั้นการล็อกอินเหมือนเดิมเป๊ะ ๆ
  การเปิดให้ผู้ใช้ที่หมดอายุล็อกอินเข้ามาต่ออายุต้องมี session แบบจำกัดสิทธิ์ก่อน (ดู M8)
- **เว็บทุกแห่งต้องมีเจ้าของเสมอ** — บังคับด้วย trigger สองตัว รวมถึงห้ามลบผู้ใช้ที่ยังถือเว็บอยู่
  (เว็บไร้เจ้าของ = เว็บที่ยังรันอยู่แต่ไม่มีใครรับผิดชอบและไม่ถูกนับเข้าโควตาใคร)
- `Quota` คลาสใหม่ = แหล่งความจริงเดียวของกฎโควตา (เดิมกระจายอยู่ 3 ที่)
- ยุบ `/api/v2/customers` เข้า `/api/v2/users` — **สิทธิ์ระดับ route เป็น `customer.manage`
  แต่การแตะบัญชีผู้ดูแลต้องมี `user.manage` เพิ่ม** (`UsersController::assertMayManage()`)
  ถ้าลดด่านนี้ออก sysadmin จะรีเซ็ตรหัสผ่าน superadmin ได้ทันที — มีเทสต์เฝ้าทุกเมธอด
- ชั้น agent กันซ้ำอีกชั้นด้วย `CustomerCapability::loadHostingAccount()` ที่รับเฉพาะ `role=webadmin`

**บั๊กที่พบและแก้ระหว่างทาง:** `QuotaChecker::canCreate()` อ่านคีย์พหูพจน์แต่ผู้เรียกทุกตัวส่ง
เอกพจน์ → ลูกค้าที่มีโควตา 10 โดเมนสร้างเว็บไม่ได้แม้แต่เว็บเดียว และ webadmin ที่ไม่มีแถวลูกค้า
ผ่านทุกโควตาแบบไม่จำกัด · ทั้งคู่ไม่มีเทสต์จับมาก่อน (`tests/security/QuotaTest.php` ใหม่ 6 เคส)

#### M2. เส้นทางไฟล์และชื่อบัญชีระบบ ✅ **เสร็จแล้ว 2026-08-06**

- `db/migrations/0006_per_user_hosting_layout.sql` — สร้างตาราง `sites` ใหม่โดยไม่มี
  `system_user`/`uid`/`gid` (ย้ายไปอยู่กับผู้ใช้) และ `owner_user_id` เป็น `NOT NULL` จริง
- `/srv/phpcp/users/<username>/domains/<domain>/{public,logs,tmp,backups}` แทน `/srv/phpcp/sites/<domain>/`
- ชั้นบน `/srv/phpcp/users/` เป็น `0711` — เดินผ่านได้แต่ `ls` ไม่ได้ ลูกค้าจึงไม่รู้ว่ามีใครอยู่บ้าง ·
  `tmp` และ `.ssh` เป็น `0700` เพราะ session ของ PHP อยู่ใน tmp (อ่านได้ = สวมสิทธิ์ได้)
- uid = ชื่อผู้ใช้ · กฎชื่อเข้มขึ้นสามชั้น: `^[a-z][a-z0-9_-]{2,31}$` + `RESERVED_USERNAMES` +
  **`getent passwd` ที่ฝั่ง agent ซึ่งเป็นด่านที่เชื่อถือได้จริง** — ตัดสินจาก home directory
  ใน `/etc/passwd` ว่าบัญชีนั้นเป็นของ panel เองหรือของคนอื่น ถ้าเป็นของคนอื่นต้องหยุด
  ก่อนเรียก `useradd` ไม่งั้นเราจะ `chown -R` ไฟล์ลูกค้าไปให้ uid ของคนอื่น
- บัญชี Linux สร้างแบบ lazy — ตอนสร้างเว็บแรก ไม่ใช่ตอนสร้างผู้ใช้ (ผู้ดูแลจึงไม่มี uid)
- `install.sh` รับ `--users-dir=` และตั้ง `0711` ให้ · `Paths::usersDir()` + ค่าตั้ง `sites.users_dir`
- **ตัวรันmigration รองรับการสร้างตารางใหม่แล้ว** — ไฟล์ที่มี `-- phpcp:rebuild-tables`
  จะถูกรันโดยปิด `foreign_keys` ชั่วคราวตามขั้นตอนมาตรฐานของ SQLite แล้วตรวจ
  `foreign_key_check` ก่อน commit · จำเป็นเพราะ `ALTER TABLE DROP COLUMN` ลบคอลัมน์
  ที่ประกาศ UNIQUE ไว้ตอน CREATE ไม่ได้ และ `DROP TABLE` แบบเปิด FK จะไปยิง
  `ON DELETE CASCADE` ของตารางลูกจนข้อมูลหายจริง

#### M3. FPM pool ต่อ (ผู้ใช้ × เวอร์ชัน PHP) ✅ **เสร็จแล้ว 2026-08-06**

- ลูกค้าที่มี 5 เว็บบน PHP 8.4 ใช้ pool เดียว (เดิม 5 pool) · `open_basedir` = บ้านของผู้ใช้
- **สิ่งที่แลกไป:** เว็บของลูกค้า*คนเดียวกัน*อ่านไฟล์กันได้และแชร์คิว process กัน —
  รับได้เพราะเป็นทรัพย์สินของคนเดียวกัน และเป็นโมเดลเดียวกับ cPanel/Plesk/DirectAdmin
  **การแยกระหว่างลูกค้าต่างรายยังแน่นเท่าเดิมทุกประการ**
- `AccountProvisioner` แยกออกจาก `SiteProvisioner` · vhost ชี้ path และ socket ใหม่
- **กับดักสามข้อของ pool ที่ใช้ร่วมกัน ซึ่งแก้ไว้แล้วและมีเทสต์เฝ้า:**
  - ลบเว็บ → ห้ามลบไฟล์ pool ถ้าเจ้าของยังมีเว็บอื่นใช้เวอร์ชันนั้น (เว็บพี่น้องจะดับทันที)
  - เปลี่ยนเวอร์ชัน PHP ของเว็บเดียว → ห้ามลบ pool เวอร์ชันเดิมด้วยเหตุผลเดียวกัน
  - `open_basedir` ต้องเป็น**สหภาพ**ของบ้านกับ Domain Pointer ของทุกเว็บที่ใช้ pool นั้น
    ไม่ใช่ของเว็บที่กำลังแก้อยู่เท่านั้น
- ลบเว็บสุดท้ายของผู้ใช้ = คืนบัญชีระบบและล้าง `users.system_user`

#### M4. ขอบเขตตัวจัดการไฟล์และ SFTP ✅ **เสร็จไปพร้อม M2**

- `FileRoots` join ตาราง users เพื่ออ่านบ้านของเจ้าของ · ขอบเขต "เว็บไซต์ทั้งหมด"
  ของผู้ดูแลชี้ไป `usersDir()` แล้ว
- เว็บที่เจ้าของยังไม่มีบัญชีระบบจะไม่แสดงในตัวจัดการไฟล์ — โฟลเดอร์ยังไม่มีอยู่จริง
- ปูทางให้ E4: SFTP หนึ่งบัญชีต่อลูกค้า (เดิมต้องหนึ่งบัญชีต่อเว็บ) · `.ssh/authorized_keys`
  ถูกสร้างไว้แล้วที่ `0700` โดย `AccountProvisioner`

#### M5. phpMyAdmin SSO ✅ **เสร็จแล้ว 2026-08-06**

- `db/migrations/0007_db_accounts.sql` — ตาราง `db_accounts(user_id, mysql_user, password_enc)`
  รหัสเข้ารหัสด้วย sodium secretbox ด้วยคีย์เดียวกับที่เก็บ TOTP secret (อยู่ใน
  `/etc/phpcp/config.php` ซึ่งแยกไฟล์จาก `panel.db` — ได้ไฟล์ฐานข้อมูลไปอย่างเดียวถอดไม่ออก)
- capability ใหม่ 2 ตัว: `db.account_credentials` (คืนความลับ) และ `db.account_rotate`
- **ทั้งคู่ไม่รับ argument ใด ๆ เลย** — อ่านผู้ใช้จาก `$context->actor->userId` เท่านั้น
  จึงไม่มีทางขอรหัสของคนอื่นได้แม้จะแก้ payload ที่ส่งมา · ด่านแบบ "ไม่มีอะไรให้ส่ง"
  แข็งแรงกว่าด่านแบบ "ตรวจสิ่งที่ส่งมาให้ดี" เพราะอย่างหลังมีวันลืม
- `DbAccountRepository` **ไม่มีเมธอดถอดรหัสเลย** — การถอดอยู่ที่ชั้น agent จุดเดียว
  ซึ่งเป็นชั้นที่ถือคีย์อยู่แล้ว (มีเทสต์เฝ้าว่าไฟล์นั้นไม่รู้จักคลาส `Secret` ด้วยซ้ำ)
- `POST /phpmyadmin/sso` + CSRF token — **เป็นการล็อกอิน ไม่ใช่ลิงก์** ถ้าเป็น GET
  เว็บใดก็ตามที่ผู้ใช้เปิดอยู่จะฝัง `<img src="…/sso">` แล้วทำให้เบราว์เซอร์เหยื่อ
  สร้าง session ของ phpMyAdmin ขึ้นมาเงียบ ๆ ได้
- `install.sh` ตั้ง `auth_type = signon` + `SignonSession = phpcp_pma_signon` ให้อัตโนมัติ
- `db.create` เติมคำนำหน้า `<username>_` ให้ฐานข้อมูลของลูกค้าและ `GRANT` ให้บัญชีนั้น
  **ตอนสร้าง** ไม่ใช่ตอนเปิด phpMyAdmin — ไม่งั้นผู้ใช้ที่เพิ่งสร้างฐานข้อมูลจะเปิดไปแล้ว
  ไม่เห็นของที่เพิ่งสร้าง ซึ่งดูเหมือนระบบพัง · ผู้ใช้เฉพาะของฐานข้อมูล (รหัสแสดงครั้งเดียว)
  ยังมีอยู่เหมือนเดิม เพราะแอปของลูกค้าควรต่อด้วยบัญชีที่จำกัดสิทธิ์เฉพาะฐานข้อมูลตัวเอง

**สิ่งที่ยอมรับไปแล้วอย่างเป็นทางการ:** ระบบเก็บรหัสผ่าน MariaDB แบบถอดกลับได้
ซึ่งเดิมไม่เคยเก็บเลย · เหตุผลที่ยังคุ้ม: agent รันด้วย root และเข้า MariaDB ผ่าน
unix_socket ได้เต็มที่อยู่แล้ว การเก็บรหัสนี้จึงไม่ได้เพิ่ม "สิ่งที่ผู้บุกรุกที่ยึด agent
ได้แล้วทำได้" แม้แต่อย่างเดียว แต่แลกมาด้วยการที่ลูกค้าเลิกจดรหัสฐานข้อมูลใส่ไฟล์ text
· `db.account_rotate` หมุนรหัสได้ทุกเมื่อโดยผู้ใช้ไม่รู้สึกอะไร เพราะไม่เคยเห็นรหัสนั้นอยู่แล้ว

#### M6. โควตาดิสก์ต่อบัญชี

- `users.disk_quota_mb` (ย้ายจากรายเว็บ) ตรงกับที่ขายจริงและตรงกับ project quota ที่นับตาม uid
- รวมงานกับ E2 ได้เลย

#### M7. ปรับ SPA/REST ให้ตรงรูปใหม่

- `/api/v2/users` เป็นทรัพยากรเดียว — `?role=webadmin` คือ "รายการลูกค้า"
- ปลดระวางหน้า `/customers` เดิมพร้อมเฟส D

#### M8. (ทางเลือก) session แบบจำกัดสิทธิ์สำหรับบัญชีหมดอายุ

ให้ล็อกอินเข้ามาดูสถานะและต่ออายุได้ แต่แก้ไขทรัพยากรโฮสติ้งไม่ได้ — ต้องทำก่อนจึงจะเปิดให้
`service_status != active` ล็อกอินได้ ไม่งั้นจะเป็นการเพิ่มสิทธิ์ให้บัญชีที่ถูกระงับ

#### M-live. บั๊กที่พบจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-07)

เทสต์ในโปรเจกต์ผ่าน 333/333 ตลอดเวลาที่บั๊กสี่ตัวนี้อยู่ — ทุกตัวถูกพบจากการกดใช้งานจริง
บนเครื่องที่ติดตั้งแล้วเท่านั้น · **บทเรียนร่วมของทั้งสี่ตัวคือตัวจำลองในเทสต์ไม่เหมือนของจริง
ในจุดเล็ก ๆ แล้วทำให้เทสต์ทั้งชุดไร้ความหมายในเส้นทางนั้น**

| บั๊ก | อาการ | ทำไมเทสต์ไม่จับ | กันซ้ำด้วย |
|---|---|---|---|
| `Request::header('Content-Type')` อ่าน `HTTP_CONTENT_TYPE` อย่างเดียว | **REST API v2 ทั้ง 94 endpoint อ่าน JSON body ไม่ได้เลย** ทุกคำขอได้ payload ว่างแบบเงียบ ๆ | `Request::make()` เซ็ต `HTTP_CONTENT_TYPE` ให้เอง ทั้งที่ PHP-FPM ส่งมาเป็น `CONTENT_TYPE` ตาม RFC 3875 | `RequestBodyTest` + `make()` วาง header แบบเดียวกับ CGI แล้ว |
| query ที่ป้อน `SiteResource` ไม่ join `users` | docroot ออกมาเป็น `/srv/phpcp/users//domains/…` | fixture ในเทสต์ใส่ `owner_username` มาให้เอง | `find()` join เจ้าของเสมอ · `Site::fromRow()` โยนทิ้งแทนที่จะสร้างเส้นทางพิการ · assertion ห้ามมี `//` |
| โควตาอ่านจากตัวเลขที่เก็บไว้ ไม่ใช่จากบทบาท | ลูกค้าที่ถูกเลื่อนเป็นผู้ดูแลติดโควตาเดิมค้าง แล้วแก้ไม่ได้เพราะเส้นทางจัดการรับเฉพาะ webadmin | ไม่มีเทสต์ที่เปลี่ยนบทบาทแล้วตรวจโควตาซ้ำ | เทสต์ "บัญชีผู้ดูแลต้องไม่ถูกจำกัดโควตา แม้เคยเป็นลูกค้ามาก่อน" |
| ชื่อ capability `db.account.credentials` มีจุดสองตัว | ลงทะเบียนได้ ทดสอบผ่าน แต่เรียกผ่าน socket ไม่ได้ (Protocol ยอมให้มีจุดเดียว) | ไม่มีเทสต์ที่ตรวจว่าทะเบียนกับ Protocol เห็นตรงกัน | เปลี่ยนเป็น `db.account_credentials` + เทสต์ตรวจชื่อทุกตัวกับ regex ของ Protocol |
| pool ของ panel ไม่ตั้ง `session.save_path` | `POST /phpmyadmin/sso` ตอบ 500 เพราะค่าปริยาย `/var/lib/php/sessions` อยู่นอก `open_basedir` | panel ไม่เคยใช้ session ของ PHP มาก่อน สะพาน SSO เป็นตัวแรก | ตั้ง `session.save_path = {{TMP_DIR}}` ในเทมเพลต + จับ `session_start()` ที่ล้มแล้วบอกสาเหตุแทน 500 |

**ผลการทดสอบจริงหลังแก้ครบ** (เครื่องที่มีเว็บใช้งานอยู่ 6 แห่ง):
- สร้างเว็บที่สองของผู้ใช้เดิม → **ไฟล์ pool เท่าเดิม บัญชี Linux เท่าเดิม** `open_basedir` ครอบทั้งสองเว็บ
- ลบเว็บนั้น → **pool และบัญชียังอยู่ เว็บพี่น้องไม่ดับ** (กับดักหลักของ M3)
- เว็บเดิมทั้ง 6 แห่งตอบ 200 เหมือนก่อนทดสอบทุกแห่ง
- `POST /phpmyadmin/sso` → 303 → เข้า phpMyAdmin ได้โดยไม่ต้องพิมพ์รหัส เห็นเฉพาะฐานข้อมูลที่ GRANT ให้

#### M-smoke. `bin/phpcp-smoke` — ยิงทุก endpoint ใส่เซิร์ฟเวอร์จริง ✅ **เสร็จแล้ว 2026-08-07**

อ่านรายการ route จาก `Routes::build()` (แหล่งเดียวกับ `OpenApiSpecTest` จึงตกหล่นไม่ได้)
แล้วยิงจริงผ่าน TLS + Apache + PHP-FPM + middleware + session — ชั้นที่เทสต์ในโปรเจกต์
ไม่เคยแตะ และเป็นที่ที่บั๊กทั้งหมดของรอบนี้ซ่อนอยู่

**ไม่แตะข้อมูลจริงเลย:** เส้นทางที่มีพารามิเตอร์ใช้ id ที่ไม่มีอยู่ (ตอบ 404) · เส้นทางที่
เปลี่ยนแปลงข้อมูลและไม่มีพารามิเตอร์ส่ง body ว่าง (ตอบ 422) · เส้นทางที่ทำลาย session
ของตัวเองถูกข้าม โดยประกาศเหตุผลไว้ในโค้ดให้เห็นว่าตรงไหนคือจุดบอด

**สิ่งที่ตรวจต่อคำขอ:** ไม่มี 5xx · ตอบ JSON จริง · มีฟิลด์ `ok` · รหัสข้อผิดพลาดอยู่ใน
`ApiProblem` · ข้อความผิดพลาดไม่ว่าง

**บทเรียนระหว่างสร้าง:** เวอร์ชันแรกรายงาน 429 ว่า "ผ่าน" เพราะรูปแบบคำตอบถูกต้อง
ทั้งที่คำขอไม่เคยไปถึงตัวควบคุมเลย — **ผลบวกลวงในตัวตรวจอันตรายกว่าไม่มีตัวตรวจ**
แก้เป็นรอตาม `Retry-After` แล้วลองใหม่ ถ้ายังโดนอีกให้นับเป็น "ยังไม่ได้ตรวจ" และคืน exit code 1

**บั๊กที่ตัวนี้เจอทันทีในการรันครั้งแรก:** `mod_proxy_fcgi` บัฟเฟอร์คำตอบทั้งก้อนเป็น
ค่าเริ่มต้น ทำให้ `GET /api/v2/metrics/stream` (SSE) ไม่ส่งแม้แต่ header ออกมาเลยจนกว่า
สตรีมจะจบใน 30 นาที — **หน้าจอที่รอค่าสดค้างเปล่าโดยไม่มี error ให้เห็น** ·
แก้ด้วย `ProxySet flushpackets=on` ในเทมเพลต httpd (ต้องรัน `install.sh` ซ้ำจึงจะมีผล)

#### M-audit. `tools/security-audit.sh` — ตรวจความปลอดภัยจากภายนอก ✅ **2026-08-07**

ย้ายจากรูทโปรเจกต์มาไว้ที่ `tools/` ซึ่ง `install.sh` ไม่คัดลอก — เครื่องมือที่เรียก `nmap`
และทำ HTTP probe จึงไม่เคยไปอยู่บนเครื่องที่ให้บริการลูกค้า · `InstallScopeTest` ตรึงกฎนี้ไว้
เพราะรายชื่อโฟลเดอร์ที่ติดตั้งเป็นบรรทัดเดียวใน shell script ที่ใครก็เพิ่มได้

**หมวดใหม่ที่เพิ่ม (พิสูจน์คำสัญญาใน SECURITY.md §2.2–2.3 จากภายนอก):**
คุณสมบัติคุกกี้ session (`Secure`/`HttpOnly`/`SameSite=Strict`/คำนำหน้า `__Host-`) ·
CSRF ปฏิเสธ POST ที่ไม่มี token · rate limit ของการล็อกอินทำงานจริง ·
`/phpmyadmin/` ไม่แสดงฟอร์มล็อกอินให้คนนอก

**สามบทเรียนเรื่องคุณภาพของตัวตรวจเอง** — ทั้งหมดเจอจากการรันจริงกับตัวเอง:

1. **การตรวจที่ไม่มีวันได้ทำงาน อันตรายกว่าไม่มีการตรวจ** — คุกกี้ session ออกตอน
   ล็อกอินสำเร็จเท่านั้น การตรวจสามข้อจึงไม่เคยทำงานเลยจนกว่าจะรับ `--user`/`--password-file`
   · ถ้าไม่ได้บัญชีมา ต้องรายงานว่า "ยังไม่ได้ตรวจ" (MEDIUM) ไม่ใช่เงียบไปเฉย ๆ
2. **ผลบวกลวงทำลายความน่าเชื่อถือพอ ๆ กับผลลบลวง** — การตรวจ CSRF ยิงด้วย cookie jar
   ที่ล็อกอินแล้ว ทำให้ middleware พาออกจากหน้าล็อกอินด้วย 303 แล้วถูกอ่านเป็น
   "ไม่มีการป้องกัน CSRF" · ต้องใช้ jar สะอาดเสมอ
3. **"ตรวจไม่ได้" ต้องแยกจาก "ไม่ผ่าน"** — 429 จากตัวจำกัดอัตราเคยถูกรายงานเป็น
   CRITICAL ทั้งที่คำขอไม่เคยไปถึงด่าน CSRF เลย · เป็นบั๊กชนิดเดียวกับที่ `phpcp-smoke`
   เคยรายงาน 429 ว่า "ผ่าน" — คนละทิศแต่รากเดียวกัน

`--auth-checks` ไม่เปิดโดยอัตโนมัติเพราะการทดสอบ rate limit กินโควตาล็อกอินของ IP นั้น
ไปหลายนาที · `--json-out=P` ส่งออก **JSON ไม่ใช่ HTML** โดยตั้งใจ — รายงานฝังเนื้อหา
ที่ได้จากเป้าหมายที่สแกน ถ้า panel เสิร์ฟ HTML ก้อนนั้นจาก origin ตัวเอง บั๊ก escaping
ในอนาคตแม้จุดเดียวจะกลายเป็น XSS ในโดเมนของ panel

**รันครบทุกส่วนแล้ว 2026-08-11** (ก่อนหน้านั้น `--auth-checks` กับ `--aggressive`
ไม่เคยถูกเปิดเลยสักครั้ง จึงมีสามด่านที่ไม่เคยถูกตรวจ): Critical 0 · High 3 · Medium 0
โดย High ทั้งสามไม่ใช่ช่องโหว่ของ panel — พอร์ต MariaDB/PostgreSQL ของเครื่องพัฒนา
เปิดอยู่ และใบรับรอง self-signed ซึ่งอยู่ในเช็กลิสต์ก่อน production อยู่แล้ว
· ส่วนที่เพิ่งได้ตรวจครั้งแรกและผ่านหมด: คุกกี้ session ครบทั้ง Secure/HttpOnly/
SameSite=Strict/`__Host-` · CSRF ปฏิเสธ POST ที่ไม่มี token (419) · rate limit
ตอนล็อกอินทำงานจริง · phpMyAdmin ไม่แสดงฟอร์มให้คนนอก · PUT/DELETE/PATCH ถูกปฏิเสธ

~~**ยังไม่ได้ทำ (รอเฟส C):**~~ ✅ **เสร็จแล้ว 2026-08-08** — `GET /api/v2/security/audit`
อ่าน `/var/lib/phpcp/security-audit.json` (ปลายทางของ `--json-out`) แล้วส่ง `results`
เป็น `data` ส่วน `target`/`scan_time`/`summary` เป็น `meta` ตาม §4.2 · หน้า Security ของ
SPA เขียนทุกช่องด้วย `textContent` ไม่ใช่ `innerHTML` — เนื้อหามาจากเป้าหมายที่ถูกสแกน
จึงห้ามแตะ HTML parser ของเบราว์เซอร์เลย

**เกณฑ์ "เก่า" ตัดสินที่เซิร์ฟเวอร์ ไม่ใช่ที่หน้าจอ** (`meta.stale` + `meta.max_age_seconds`)
ถ้าสองที่คิดเองคนละแบบ ผู้ใช้จะเห็นคำเตือนไม่ตรงกับที่ระบบเชื่อ · ยังไม่เคยรันตัวสแกน
ตอบ **404 ไม่ใช่ `data: []`** เพราะ "ยังไม่มีข้อมูล" กับ "ตรวจแล้วไม่พบอะไร" อ่านต่างกัน
คนละเรื่อง — ตารางว่างแปลว่า "ปลอดภัยดี" ซึ่งเป็นคำตอบที่ผิดที่สุดที่จะให้เมื่อยังไม่ได้ตรวจ

**ไม่มีปุ่มสั่งสแกนบนหน้าเว็บโดยตั้งใจ** — ตัวสแกนยิง nmap และทดสอบ rate limit กินเวลา
เป็นนาที และปุ่มแบบนั้นทำให้ panel กลายเป็นเครื่องมือสแกนเป้าหมายอื่นได้ด้วย

**เกณฑ์รับงานของเฟส M:** `tests/run.php` ผ่าน 100% ทุกขั้น · ค้นทั้ง repo ไม่พบคำว่า
`customer_sites`, `CustomerRepository`, หรือการแปลง `customer_id ↔ user_id` อีก ·
ลูกค้าหนึ่งรายที่มี 3 เว็บต้องมี 1 uid, 1 บ้าน, และ pool เท่าจำนวนเวอร์ชัน PHP ที่ใช้จริง

---

### เฟส C — Now.js SPA — ✅ **เสร็จทั้งเฟส · ใช้งานจริงบน 8443 มาตลอดตั้งแต่ 2026-08-08**

> **อ่านก่อนทำเฟส D** — สิ่งที่ทำจริง จุดที่ต่างจากแผน และสิ่งที่ยังค้าง
>
> | ไฟล์ | หน้าที่ |
> |---|---|
> | `public/assets/spa/index.html` | shell หน้าเดียว · โหลด vendor + js ของเราตามลำดับที่มีเหตุผลกำกับในไฟล์ |
> | `public/assets/spa/vendor/now/` | `now.core/table/graph` + `icons.css` + ฟอนต์ icomoon + `SHA256SUMS` |
> | `public/assets/spa/js/api.js` | **หัวใจของเฟสนี้** — แปลง envelope และชื่อพารามิเตอร์สองทาง |
> | `public/assets/spa/js/auth.js` | สถานะเซสชัน + ด่านหน้าทุกเส้นทางของ router |
> | `public/assets/spa/js/ui.js` | sidebar · topbar · แถบเตือนโหมด · `confirm()` สามระดับ |
> | `public/assets/spa/js/pages.js` | สคริปต์ประจำหน้า (`data-script`) + ตัวจัดรูปแบบเซลล์ |
> | `public/assets/spa/js/main.js` | `Now.init` + ตารางเส้นทาง 21 เส้น · แยก `ROUTE_BASE` กับ `ASSET_BASE` |
> | `public/assets/spa/templates/*.html` | 21 เทมเพลต |
> | `public/assets/spa/lang/th.json` | 182 คีย์ — ข้อความ UI ทั้งหมดของ SPA อยู่ที่นี่ |
> | `src/Http/SpaController.php` | ส่งไฟล์ shell ให้ `/app` และ `/app/{page}` |
> | `src/Http/V2/DashboardController.php` | `GET /api/v2/dashboard` (§4.6 มีในสเปกแต่ยังไม่มีในโค้ด) |
> | `src/Http/V2/ScheduledJobsController.php` | `GET /api/v2/scheduled-jobs` (หนี้ที่เฟส A1 ข้อ 7 ฝากไว้) |
>
> **ตัดสินใจระหว่างทางที่ต่างจากที่เขียนไว้ในแผน:**
>
> 1. **ไม่ใช้ `AuthManager` ของ Now.js เลย** — แผนข้อ C1.4 เขียนว่า "ตั้ง `AuthGuard`
>    ตาม permissions" แต่ `AuthManager` ออกแบบมาสำหรับ token ที่ JS ถืออยู่: มันเก็บผู้ใช้
>    ลง localStorage ต่ออายุ token เอง และคาดหวังคำตอบรูป `{success, token, user}`
>    · phpcp ไม่มี token ให้ JS เลย (N6) การยัดเข้ามาจะได้สำเนาสถานะชุดที่สองที่ไม่มีวัน
>    ตรงกับของจริง · แทนด้วย `RouterManager.beforeEach()` + `GET /session` ซึ่งสั้นกว่า
>    และลำดับการตัดสินตรงกับ `Middleware\Authenticate` ฝั่ง PHP เป๊ะ
> 2. **สองฝั่งพูดคนละสำเนียง และต้องแปลที่จุดเดียว** — Now.js ส่ง `pageSize`/`search`/
>    `sort="ชื่อ asc"` และรอคำตอบรูป `{success, data, meta:{pageSize,totalPages}}`
>    ส่วน §4.2/§4.5 กำหนด `per_page`/`q`/`sort="-ชื่อ"` และ `{ok, data, meta:{per_page,total_pages}}`
>    · **ไม่แก้ทั้งสองฝั่ง**: แก้ PHP ให้รับสองชื่อจะได้สองชื่อต่อหนึ่งความหมายตลอดไป
>    และ OpenAPI จะเลิกเป็นความจริง · แก้ไฟล์ dist จะทำให้ SHA256SUMS ไร้ความหมาย
>    · จึงแปลใน `js/api.js` ที่จุดต่อสามจุดซึ่งระบุเหตุผลไว้ครบในไฟล์
> 3. **SPA อยู่ที่ `/app` และเสิร์ฟผ่าน PHP ไม่ใช่ Apache** — router โหมด history ทำให้
>    `/app/sites` ไม่มีไฟล์อยู่จริง กดรีเฟรชแล้วจะได้ 404 · ผลพลอยได้คือ shell ได้ header
>    ความปลอดภัยชุดเดียวกับทุกคำตอบของ panel (CSP/HSTS/X-Frame-Options) ซึ่งไฟล์ที่
>    Apache ส่งเองจะไม่ได้ · เฟส D ข้อ 3 วางแผนให้ทำแบบนี้อยู่แล้ว
> 4. **ทุก `src`/`href` ใน shell ต้องเป็นเส้นทางสัมบูรณ์** — ไฟล์เดียวถูกส่งจากหลาย URL
>    เส้นทางสัมพัทธ์จะกลายเป็น `/app/app/...` ทันทีที่รีเฟรชบนหน้าย่อย · `<base href>`
>    ใช้แก้ไม่ได้เพราะ CSP ตั้ง `base-uri 'none'` · มีเทสต์เฝ้าข้อนี้แล้ว
> 5. **`data-i18n` ต้องมีคีย์เขียนกำกับเสมอ (`data-i18n="Domain"`)** ห้ามปล่อยว่าง —
>    `TableManager` ฉีด `<span class="col-resizer">` เข้าไปใน `<th>` ก่อน i18n ทำงาน
>    ทำให้โหมดเดาคีย์จากเนื้อในพัง อาการคือหัวตารางไม่ถูกแปลทั้งที่ป้ายอื่นในหน้าเดียวกันแปลปกติ
> 6. **`data-formatter` แทน `data-template` สำหรับค่าที่ผู้ใช้ควบคุมได้** — ตรวจโค้ดแล้วพบว่า
>    `data-template` **มี** การ escape จริง (`TableManager.escapeCellValue`) แต่ formatter
>    เติมค่าด้วย `textContent` ซึ่งปลอดภัยโดยโครงสร้าง ไม่ต้องพึ่งว่าใครจะ escape ให้ครบทุกจุด
> 7. **`GET /api/v2/dashboard` เป็นเส้นทางเดียวของ v2 ที่ตอบ 200 แม้ agent ล่ม** —
>    ตัวเลขจากฐานข้อมูลของ panel ยังถูกต้อง และหน้าแรกต้องเปิดได้ในจังหวะที่ผู้ดูแล
>    ต้องการรู้ที่สุดว่าเกิดอะไรขึ้น · มีเทสต์ตรึงไว้ ห้ามแก้ให้ล้มตาม agent
 8. **URL ของหน้าจอต้องไม่ตรงกับไดเรกทอรีจริงบนดิสก์** — ไฟล์นิ่งของ SPA จึงอยู่ที่
>    `public/assets/spa/` ทั้งที่หน้าจออยู่ที่ `/app/*`
>
>    เดิมวางไฟล์ไว้ที่ `public/app/` แล้ว `/app/` ได้ **404 ของ Apache เอง** —
>    `FallbackResource` **ข้าม URL ที่ชี้ไปยังไฟล์หรือไดเรกทอรีที่มีอยู่จริง** พอมีไดเรกทอรี
>    `public/app/` อยู่ `mod_dir` จึงเข้ามาก่อน: ตอบ 301 จาก `/app` ไป `/app/` (DirectorySlash
>    เปิดเป็นค่าเริ่มต้น) แล้วมองหา DirectoryIndex ในนั้น ไม่เจอ จบที่ 404 **โดยที่ PHP
>    ไม่เคยเห็นคำขอนั้นเลย** — สังเกตได้จากคำตอบที่ไม่มี header `X-Request-Id`
>
>    **`php -S` ไม่ทำแบบนี้** บั๊กจึงไม่โผล่ตอนทดสอบเลย เห็นเฉพาะบนพอร์ต 8443 —
>    ตัวอย่างตรง ๆ ของ §9.4 ข้อ 2 · และรอบแรกผมสรุปสาเหตุผิดด้วย (เดาว่าคำขอถึง PHP แล้ว
>    ตกที่ตารางเส้นทาง) การเพิ่มเส้นทางฝั่ง PHP จึงไม่ช่วยอะไรเลย
>
>    ทางแก้ที่**ไม่เลือก** สองทางและเหตุผล:
>    - เติม `index.html` ใน `DirectoryIndex` ของ `httpd.conf.tpl` — Apache จะส่ง shell เอง
>      โดยไม่ผ่าน `SecurityHeaders` แปลว่าหน้าที่คุม root ทั้งเครื่องจะไม่มี CSP กำกับ
>    - `.htaccess` — ใช้ไม่ได้เลย `AllowOverride None` ทั้งสองบล็อกใน `httpd.conf.tpl`
>      (ตั้งใจ: Apache ไม่ต้องไล่อ่าน .htaccess ทุกไดเรกทอรี และไม่มี config ซ่อนอยู่นอกไฟล์เดียว)
>
>    ทางที่เลือกไม่ต้องแตะ config ของ Apache เลย และได้ผลพลอยได้คือไฟล์ SPA ไปอยู่ใต้
>    `/assets/` ซึ่งเป็นที่ของไฟล์นิ่งอยู่แล้ว (`SecurityHeaders` ยกเว้น `no-store` ให้ prefix นี้)
>    · `js/main.js` แยก `ROUTE_BASE` (`/app`) กับ `ASSET_BASE` (`/assets/spa`) ออกจากกันชัดเจน
> 9. **คลาส `sidemenu-panel` คือสิ่งที่ทำให้เมนูเป็นแผงซ้าย** ไม่ใช่ `sidebar` · ขาดไปแล้ว
>    เมนูจะไหลตามเนื้อหาปกติ กินความกว้างทั้งจอ และดันเนื้อหาลงไปข้างล่างทั้งหมด
>    · `.main-content { margin-left: var(--menu-width) }` ที่ `@media (min-width: 769px)`
>    ต้องเขียนเอง core ไม่ได้ทำให้ และจุดตัดต้องเป็น 769px ให้ตรงกับ Now.js เป๊ะ
 10. **`MenuManager.createMenu()` ข้ามเมนูที่ยังไม่มี `<ul>`** — ComponentManager แทรก
>    `<nav class="sidemenu">` เข้า DOM ก่อนที่ `mounted()` จะสร้างรายการ MutationObserver
>    ของ MenuManager จึงเห็นเมนูเปล่าแล้วข้ามไป และไม่มีอะไรกระตุ้นให้ลองใหม่อีก
>    · อาการคือ **ปุ่มยุบเมนูกดแล้วไม่มีอะไรเกิดขึ้น** (ตัวฟังคลิกเป็น delegation ที่ระดับ
>    document จึงทำงานปกติ แต่มันวนหาเมนูใน `state.menus` ที่ว่างเปล่า)
>    · ทางแก้: เรียก `MenuManager.createMenu(nav)` เองหลังใส่ `<ul>` เสร็จ
> 11. **`updateActiveMenu()` เทียบเส้นทางแบบไม่มี base กับ `href` ที่มี base** —
>    มันตัด `/app` ออกจากเส้นทางปัจจุบันแล้วเทียบกับ `href` ตรง ๆ ซึ่งเราตั้งเป็น
>    `/app/services` เพื่อให้เปิดแท็บใหม่ได้ · สองค่านี้ไม่มีวันตรงกัน จึงทำเครื่องหมาย
>    รายการที่เปิดอยู่เองจาก `data-route` (ที่เก็บชื่อเส้นทางแบบไม่มี base ไว้แล้ว)
> 12. **`data-field` ซ้ำกันสองคอลัมน์ทำให้คอลัมน์แรกหายไปเงียบ ๆ** — TableManager
>    ทำดัชนีคอลัมน์ด้วยชื่อฟิลด์ · คอลัมน์ปุ่มต้องผูกกับฟิลด์ที่ไม่ได้แสดงที่อื่น
>    แล้วอ่านค่าที่ต้องใช้จาก `row` ที่ formatter ได้รับอยู่แล้ว · มีเทสต์เฝ้าแล้ว
> 13. **ชื่อคลาสไอคอนที่ไม่มีอยู่จริงจะหายไปเงียบ ๆ** ไม่มี error ใด ๆ · ชุด icomoon
>    ไม่มี `icon-globe`/`icon-server`/`icon-terminal`/`icon-bin`/`icon-loop` ที่เดาว่าน่าจะมี
>    · มีเทสต์เฝ้าแล้ว: ทุกคลาส `icon-*` ในเทมเพลตและ JS ต้องมีอยู่ใน `icons.css`
> 14. **บัญชีที่ไม่มีอีเมลแก้ชื่อที่แสดงไม่ได้เลย** (บั๊กเดิมของระบบ ไม่ใช่ของเฟส C) —
>    `UserRepository::updateProfile()` ตรวจรูปแบบอีเมลกับค่าว่างด้วย และ
>    `UsersController::update()` ส่งอีเมลเดิมกลับเข้ามาเป็นค่าตั้งต้นเมื่อผู้เรียกไม่ได้ส่งมา
>    · บัญชีที่สร้างจาก `phpcp user:create` ไม่มีอีเมล (`admin` เองก็ไม่มี) จึงได้ 422
>    "อีเมลไม่ถูกต้อง" ทั้งที่ไม่ได้แตะช่องอีเมลเลย · **โผล่ตอนมีฟอร์มแก้ไขให้กดจริง**
>    ซึ่ง UI เดิมไม่มี · แก้ให้อีเมลว่างได้ (= ไม่มีอีเมล) และมีเทสต์เฝ้าแล้ว
> 15. **แก้บั๊กที่เจอระหว่างทาง:** `DashboardController::counts()` ตอนแรกกรอง
>    `certificates` ด้วย `site_id` ซึ่ง **ไม่มีอยู่ในตารางนั้น** (มันผูกกับชื่อโดเมน)
>    ทำให้หน้าแรกของลูกค้าได้ 500 ทั้งหน้า · เทสต์ที่เขียนคู่กันจับได้ทันที
>
> **สิ่งที่ยังไม่ได้ทำ / ยังพิสูจน์ไม่ครบ — อ่านก่อนเริ่มเฟส D:**
>
> | รายการ | สถานะ |
> |---|---|
> | ทดสอบบน `https://127.0.0.1:8443` ของจริง | 🔄 ซิงก์แล้วสองรอบ เจอบั๊กสามตัวที่ `php -S` และเทสต์ในโปรเซสมองไม่เห็นเลย (mod_dir · `sidemenu-panel` · **transaction แบบ DEFERRED**) แก้ครบแล้ว · **ต้องซิงก์ซ้ำแล้วดูด้วยตาอีกรอบ** |
> | หน้าจอทั้ง 16 หน้าเรนเดอร์และดึงข้อมูลได้ | ✅ พิสูจน์บน Chrome headless ผ่าน `php -S` + agent ในโหมด sandbox |
> | ตัวจัดการไฟล์ | ✅ **ครบทั้ง 12 endpoint แล้ว** — เดินโฟลเดอร์ · เส้นทางนำทาง · ขึ้นบน · สร้างโฟลเดอร์ · อัปโหลด · ดาวน์โหลด · ลบ · แก้ไขไฟล์ข้อความ · ย้าย/เปลี่ยนชื่อ · เปลี่ยนสิทธิ์ · บีบอัด · แตกไฟล์ · พิสูจน์บนดิสก์จริงทุกตัว **ยกเว้นเปลี่ยนสิทธิ์** ที่ยืนยันผลไม่ได้บนเครื่องพัฒนา (NTFS บังคับ 777 ทุกไฟล์) — คำขอสำเร็จแต่ต้องดูผลจริงบนเซิร์ฟเวอร์ ext4 |
> | หน้ารายละเอียดเว็บไซต์ 8 แท็บ | ✅ **ครบทั้ง 8 เรื่องแล้ว** (ภาพรวม · เวอร์ชัน PHP · โดเมน · ระงับ/ลบ · SSL · ฐานข้อมูล · cron · สำรองข้อมูล) · สี่เรื่องหลังกรองที่ฝั่งเซิร์ฟเวอร์ด้วย `site_id` ไม่ใช่ดึงมาทั้งหมดแล้วกรองบนหน้าจอ — เพิ่มตัวกรอง `site_id` ให้ `/certificates` และ `/databases` ตาม §4.5 |
> | **สร้าง** ผู้ใช้ · ฐานข้อมูล · cron · สำรองข้อมูล | ✅ **เสร็จแล้ว** — แผงฟอร์มพับเก็บได้ในแต่ละหน้า ใช้ FormManager + `data-reload-table` · พิสูจน์ในเบราว์เซอร์แล้วว่ากดบันทึกแล้วแถวขึ้นในตารางจริง |
> | **ลบ** ผู้ใช้ · ฐานข้อมูล · cron + รีเซ็ตรหัสผ่าน + เปิด/ปิดงาน cron | ✅ **เสร็จแล้ว** — ปุ่มในแถวตาราง ใช้ `apiRefresh` พร้อมกล่องยืนยัน |
> | **แก้ไขผู้ใช้** (ชื่อ · อีเมล · บทบาท · สถานะบริการ · โควตา) | ✅ **เสร็จแล้ว** — หน้า `/user?id=N` · เดิม route นี้ชี้ไปเทมเพลตที่ไม่มีอยู่จริง (กดแล้วได้หน้า "ไม่พบหน้าที่ต้องการ") |
> | **แก้ไข cron** (ชื่อ · ตารางเวลา · คำสั่ง · เปิด/ปิด) | ✅ **เสร็จแล้ว** — หน้า `/cron-job?id=N` · เพิ่ม `GET /api/v2/cron-jobs/{id}` ให้ด้วย เดิมมีแต่ `PATCH` แก้ทรัพยากรที่อ่านตัวเดียวไม่ได้ |
> | ยืนยัน/คืนค่า rollback ของ SSH กับ firewall | ✅ **เสร็จแล้ว** — คอมโพเนนต์ `rollback-bar` อยู่ทุกหน้า ถามสถานะทุก 15 วินาที เดินตัวเลขเองทุกวินาที พร้อมปุ่มยืนยันและคืนค่าทันที · พิสูจน์ในเบราว์เซอร์แล้วว่าตัวเลขเดินจริงและติดตามข้ามหน้า |
> | หน้า Security อ่านผล `tools/security-audit.sh` | ✅ **เสร็จแล้ว** — `GET /api/v2/security/audit` + ส่วน "ผลตรวจจากภายนอก" ในหน้า Security · เตือนเมื่อเกิน 30 วันโดยตัดสินที่เซิร์ฟเวอร์ · **ยังไม่ได้ดูด้วยตาบน 8443** (รอซิงก์) |
> | `GET /metrics/history` | ❌ ต้องรอเฟส E6 ที่สร้างตารางเก็บค่าย้อนหลัง |
>
> ### บั๊กชุดที่เฟสนี้ขุดเจอ — ทั้งหมดมาจาก "SPA ยิงคำขอพร้อมกัน"
>
> UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า · SPA ยิง 2–6 ก้อนพร้อมกันต่อหนึ่งหน้า
> สมมติฐาน "คำขอมาทีละอัน" ที่ฝังอยู่ในโค้ดมาตั้งแต่เฟส 0 จึงพังพร้อมกันสามจุด
>
> #### 1. `เด้งไปหน้า login เอง` — การหมุน session id ชนกันเอง
>
> **อาการที่ผู้ใช้รายงาน:** "คลิกไปเรื่อย ๆ สักพักจะเด้งไปหน้า login เอง"
>
> **ต้นตอ:** `SessionStore::rotate()` คืน id ใหม่**เสมอ** โดยไม่ดูว่า
> `UPDATE ... WHERE id_hash = :old` โดนแถวไหนหรือไม่ · ทุกคำขอที่ยิงพร้อมกันเห็นว่า
> ถึงเวลาหมุนพร้อมกัน ตัวแรกหมุน X→Y สำเร็จ ที่เหลือ UPDATE ไม่โดนแถวไหนแต่ยังคืน
> id ที่สุ่มใหม่ของตัวเองออกไปตั้งเป็นคุกกี้ · คำตอบที่ถึงเบราว์เซอร์ทีหลังชนะ
> เบราว์เซอร์จึงถือ id ที่**ไม่มีอยู่ในฐานข้อมูล** แล้วถูกเด้งออกทุกรอบการหมุน (15 นาที)
>
> **พิสูจน์:** ตั้ง `panel.session_rotate = 1` แล้วยิงพร้อมกัน 4 คำขอ → รอบที่สอง
> session ตายถาวร (401 ทุกคำขอหลังจากนั้น)
>
> **แก้:** migration `0008` เพิ่ม `prev_id_hash`/`prev_until` · `rotate()` ทำใน
> transaction เดียวและคืน null เมื่อ UPDATE ไม่โดนแถวไหน · `load()` ยอมรับ id เดิม
> ในช่วงผ่อนผัน 30 วินาที เพื่อให้คำขอที่ออกไปก่อนหน้าเสี้ยววินาทีไม่ถูกตีเป็นหมดอายุ
> · `destroy()`/`touch()` จับ `prev_id_hash` ด้วย — ไม่งั้น "ออกจากระบบแล้วแต่ไม่ได้ออกจริง"
> · หลังแก้: 25 รอบ × 6 คำขอพร้อมกัน ไม่มี 401 เลย (ที่เหลือคือ 429 ของ rate limit ซึ่งถูกต้อง)
>
> #### 2. `database is locked` ทุกคำขอที่ยิงพร้อมกัน
>
> ตอนแรกสรุปว่าเป็นเรื่อง NTFS/FUSE ของเครื่องพัฒนา · **ผิด** — ยิงพร้อมกัน 8 คำขอ
> ไปที่ `https://127.0.0.1:8443/api/v2/session` ของเซิร์ฟเวอร์จริงบน ext4 **ล้ม 7 จาก 8
> เหมือนกันเป๊ะ**
>
> **ต้นตอ:** `Db::transaction()` ใช้ `PDO::beginTransaction()` ซึ่งเป็น `BEGIN DEFERRED`
> — ยังไม่จองล็อกอะไรเลย พอคำสั่งแรกในบล็อกเป็นการ**อ่าน** การเชื่อมต่อจะได้ล็อกอ่าน
> แล้วเมื่อจะ**เขียน**ต้องยกระดับล็อก ถ้าตอนนั้นมีการเชื่อมต่ออื่นถือล็อกเขียนอยู่
> SQLite คืน `SQLITE_BUSY` **ทันทีโดยไม่รอตาม `busy_timeout` เลย** เพราะการรอคือ deadlock
>
> `RateLimiter::allow()` และ `AuditLog::write()` ใช้ transaction และทำงาน**ทุกคำขอ**
> ทั้งคู่อ่านก่อนเขียน · แปลว่า **ทุก endpoint ของทั้งระบบ**ล้มเมื่อมีคำขอพร้อมกัน
>
> **ทำไมผ่านมา 6 เฟสโดยไม่มีใครเจอ:** UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า ·
> SPA ยิงหลายก้อนพร้อมกันต่อหนึ่งหน้า (เช่น `/app/server` เปิด SSE ค้างไว้พร้อมกับ
> `GET /system/info`) อาการจึงโผล่ทันทีที่เปิดหน้าแรก
>
> **แก้แล้ว:** `Db::transaction()` ใช้ `exec('BEGIN IMMEDIATE')` จองล็อกเขียนตั้งแต่ต้น
> การรอจึงเกิดที่จุดเดียวที่รอได้ และ `busy_timeout` ทำงานตามที่ตั้งไว้จริง ·
> รองรับการซ้อนด้วย (บล็อกชั้นในใช้ transaction ของชั้นนอก)
> · **ก่อนแก้ 1/8 ผ่าน · หลังแก้ 24/24 ผ่าน** · มีเทสต์เฝ้าที่
> `tests/security/DbConcurrencyTest.php` ซึ่งใช้ **4 โปรเซสจริง** เพราะการเชื่อมต่อ
> สองตัวในโปรเซสเดียวกันไม่เกิดการแย่งล็อกแบบเดียวกัน จึงพิสูจน์อะไรไม่ได้
>
> **บทเรียน:** อย่าโทษ filesystem ก่อนพิสูจน์ — การเทียบ ext4 กับ FUSE ใช้เวลาคำสั่งเดียว
> และเป็นตัวชี้ขาดว่าเป็นบั๊กโค้ดหรือสภาพแวดล้อม

#### C1. โครงพื้นฐาน
1. วาง `Now/dist/*` เข้า `public/app/vendor/now/` พร้อมไฟล์ `SHA256SUMS` และขั้นตอนตรวจสอบใน `phpcp doctor`
2. `public/app/index.html` — shell หน้าเดียว, โหลด core + table + graph, `Now.init({allowEval:false, ...})`
3. `js/api.js` — ห่อ `HttpClient`: `baseURL='/api/v2'`, จัดการ error → toast, จัดการ 401 → เด้งหน้า login, 419 → ขอ token ใหม่แล้วลองใหม่ 1 ครั้ง
4. `js/auth.js` — bootstrap จาก `GET /session`, ตั้ง `AuthGuard` ตาม `permissions`
5. Router + layout (sidebar 2 กลุ่ม HOSTING/SERVER ตาม PROMPT.md) + แถบเตือนโหมด sandbox/dryrun ที่ปิดไม่ได้
6. `lang/th.json` + `lang/en.json` — **ย้ายข้อความไทยทั้งหมดออกจากโค้ด PHP มาไว้ที่นี่**

#### C2–C3. หน้าจอทั้งหมด (17 หน้า)
- **Hosting:** แดชบอร์ด · เว็บไซต์ (list + detail 8 แท็บ) · โดเมน+DNS · SSL · PHP · ฐานข้อมูล · ตัวจัดการไฟล์ · Cron · Backup · ลูกค้า
- **Server:** ภาพรวม · Services · ความปลอดภัย · Firewall · SSH · Logs · ผู้ใช้งาน · ตั้งค่า

**ใช้ของ Now.js แทนการเขียนเอง:** `TableManager` (ตารางค้นหา/กรอง/เรียง/เลือกหลายรายการ) · `GraphComponent` (กราฟ metrics) · `Modal`/`DialogManager` (confirm 3 ระดับตาม SECURITY §4) · `NotificationManager` (toast) · `FormManager` + ElementFactory (ฟอร์มทั้งหมด)

**ข้อควรระวังด้านความปลอดภัย:** core มี `innerHTML` 135 จุด — ข้อมูลที่ผู้ใช้ควบคุมได้ (ชื่อไฟล์ ชื่อโดเมน เนื้อหา log ข้อความ error จาก OS) **ต้องผูกแบบ text เท่านั้น ห้ามใช้ html binding** ให้ตั้งเป็นกฎใน code review และเขียนทดสอบ XSS ครอบ 3 จุดเสี่ยงสุด (file manager, log viewer, ชื่อโดเมน)

#### C4. SSE
`/api/v2/metrics/stream` ใช้ `EventSource` ตรง ๆ ไม่ต้องผ่าน Now.js — ปิด stream อัตโนมัติที่ 30 นาทีเหมือนเดิม

**เกณฑ์รับงาน:** ใช้งานได้ครบทุกหน้าเทียบเท่า HTML UI เดิม · สลับภาษาไทย/อังกฤษได้ · CSP ไม่มี `unsafe-inline`/`unsafe-eval` และไม่มี error ใน console · `webadmin` ไม่เห็นเมนู SERVER เลย

#### C5. ตัวจัดการไฟล์ — ✅ **พิสูจน์บนเซิร์ฟเวอร์จริงแล้ว 2026-08-11**

> รายการ "ที่ยังเหลือ" เดิมของเฟสนี้เขียนว่า *"เหลือฟอร์มสร้าง/แก้บางหน้าและตัวจัดการไฟล์
> แบบเขียน"* — ตรวจโค้ดแล้วพบว่า**เสร็จไปตั้งแต่ตอนนั้นแล้ว** แต่ไม่มีใครกลับมาอัปเดต
> บรรทัดนี้ · บันทึกไว้เป็นตัวอย่างว่ารายการค้างในเอกสารต้องตรวจกับโค้ดก่อนเชื่อเสมอ

**ครบทั้งสามชั้น:** 15 capability (`file.*`) · 16 endpoint ใต้ `/api/v2/files/*` ·
13 ปุ่มบนหน้าจอ (`mkdir` `touch` `upload` `rename` `copy` `cut` `paste` `delete`
`chmod` `zip` `unzip` `download` `info`) พร้อมตัวแก้ไขไฟล์ คลิปบอร์ดข้ามโฟลเดอร์
ลากวาง และคีย์ลัด

**ผลการทดสอบจริงผ่าน REST ด้วยเซสชันจริง (13/13):** สร้างโฟลเดอร์ · สร้างไฟล์ใหม่ ·
อ่านกลับได้เนื้อหาเดิม (ไม่ใช่แค่ตอบ 200) · บันทึกทับแล้วเนื้อหาเปลี่ยนจริง · chmod ·
คัดลอก · เปลี่ยนชื่อ · zip · unzip · รายการไฟล์เห็นของที่สร้าง · ลบแล้วหายจริงจากดิสก์

**พฤติกรรมที่ยืนยันว่าถูกต้องตามเจตนา:** `PUT /files/content` **เขียนทับไฟล์ที่มีอยู่
เท่านั้น** โดยปริยาย — พิมพ์ชื่อไฟล์ผิดได้ 422 ไม่ใช่ไฟล์เปล่าโผล่ในที่ที่ไม่ตั้งใจ ·
ต้องส่ง `create: true` ถึงจะสร้างใหม่ได้ ซึ่งเป็นสิ่งที่ปุ่ม "ไฟล์ใหม่" ทำอยู่แล้ว

**ที่ยังไม่ได้ทดสอบ:** อัปโหลดไฟล์จริงผ่านฟอร์ม (multipart) และการลากวาง — ทดสอบผ่าน
REST ล้วนไม่ครอบคลุมสองอย่างนี้ ต้องกดจากเบราว์เซอร์จริง

---

### เฟส D — ปลดระวาง HTML เดิม — ✅ **เสร็จแล้ว 2026-08-08**

> เฟสเดียวที่ทำลายของเดิม · แผนเดิมเขียนว่า "ทำเมื่อ SPA พิสูจน์ตัวเองในการใช้งานจริง
> แล้วเท่านั้น" — เจ้าของระบบตัดสินใจทำทันทีหลังเฟส C ด้วยเหตุผลว่า **ระบบยังไม่ขึ้น
> ใช้จริง** จึงไม่มีผู้ใช้ที่ต้องมีช่วงเปลี่ยนผ่านให้ · ข้อควรระวังเดิมยังจริงอยู่สำหรับ
> เครื่องที่มีคนใช้แล้ว

**สิ่งที่ลบจริง**

| ของที่ลบ | จำนวน |
|---|---|
| `views/` | 28 ไฟล์ |
| `src/Controller/{Hosting,Server,Api}/` + `AuthController` + `DashboardController` | 22 ไฟล์ |
| `src/Kernel/View.php` · `src/Kernel/Navigation.php` | 2 ไฟล์ |
| `public/assets/{js,icons,images}/` + `css/app.css` เดิม | 6 ไฟล์ |
| เส้นทาง UI แบบ HTML | 92 เส้นทาง |
| API v1 (`/api/metrics`, `/api/files`, `/api/stream/metrics` …) | 8 เส้นทาง |

**สิ่งที่เหลือฝั่ง PHP:** `/` (เด้งไป `/app/`) · `/app*` สี่รูป (shell ไฟล์เดียว) ·
`/api/v2/*` 94 เส้นทาง

**สามอย่างที่ต้องย้ายก่อนลบได้ ไม่ใช่ลบทิ้งเฉย ๆ**

1. **SSE ของ metrics** — `Controller\Api\StreamController` ถูกเรียกต่อจาก
   `Http\V2\MetricsController::stream()` · ย้ายโค้ดเข้าไปในตัว v2 ตามที่คอมเมนต์เดิม
   สั่งไว้ ไม่ใช่คัดลอก
2. **phpMyAdmin SSO** — เป็นสิ่งเดียวที่ไม่มีคู่ใน v2 เลย · เขียนใหม่เป็น
   `POST /api/v2/phpmyadmin/session` ที่คืน URL ปลายทางแทนการ redirect (SPA ยิงด้วย
   `fetch` จึงตาม 302 เองไม่ได้อย่างมีประโยชน์) · ยังเป็น POST เพราะเป็น "การล็อกอิน"
   ไม่ใช่การอ่านข้อมูล และรหัสผ่านยังไม่เคยออกไปที่หน้าเว็บเลย
3. **หน้าข้อผิดพลาด 419/429/403/404/500** — ยังต้องมีเพราะผู้ใช้พิมพ์ URL เองได้ และ
   ตัวจำกัดอัตราตัดสินใจก่อน router รู้ว่าเส้นทางคืออะไร · รวบเป็น `Http\ErrorPage`
   ที่ประกอบ HTML ด้วยมือ + `assets/css/error.css` ที่**ไม่พึ่ง bundle ของ SPA เลย** —
   หน้าที่ถูกส่งตอนอย่างอื่นล้มไปแล้วต้องไม่พึ่งสิ่งที่อาจล้มไปด้วย

**สิ่งที่แผนเดิมสั่งแต่ไม่ได้ทำ (ตั้งใจ)**

- ~~ลบ `Request::wantsJson()`~~ — ยังใช้อยู่ใน `Authenticate` กับ `CsrfProtection`
  สำหรับแยกคำขอที่มาจากเบราว์เซอร์ตรง ๆ ออกจากคำขอของ SPA
- ~~ปรับ `httpd.conf.tpl` ให้ serve static พร้อม cache header~~ — ยังไม่ทำ เป็นเรื่อง
  ประสิทธิภาพล้วน ๆ ไม่ใช่เงื่อนไขของการปลดระวาง

**สิ่งที่เจอตอนลบ** — `css/app.css` ของ SPA อ้าง `/assets/images/logo.svg` ซึ่งอยู่
**นอก**โฟลเดอร์ของ SPA · พอลบชุดของเก่าทิ้ง โลโก้หายไปโดยที่หน้ายังเรนเดอร์ครบและ
เทสต์ยังเขียว เห็นแค่ใน console ของเบราว์เซอร์ · ย้ายไฟล์เข้า `spa/images/` แล้วเพิ่ม
เทสต์ที่ไล่ทุก URL `/assets/**` ในไฟล์ของ SPA ไปดูว่ามีอยู่จริงบนดิสก์ไหม

**เกณฑ์รับงาน:** ✅ ค้นทั้ง repo ไม่พบการ echo HTML จากฝั่ง PHP นอกจาก `ErrorPage`
ตัวเดียว · ✅ `tests/run.php` 363/363 · ✅ `phpcp-smoke` ผ่านทุกเส้นทาง ·
✅ เดินทุกหน้าในเบราว์เซอร์จริงไม่มี warning/error

---

### เฟส E — เติมฟีเจอร์ให้สมบูรณ์ (ทำขนานได้)

#### E1. Backup ปลายทางนอกเครื่อง ★ ความเสี่ยงสูงสุดในระบบตอนนี้ — 🔄 **2026-08-09**
**ปัญหา:** `BackupManager` ฮาร์ดโค้ด `/var/lib/phpcp/backups` — ดิสก์พังครั้งเดียวเสียทั้งข้อมูลจริงและไฟล์สำรองพร้อมกัน
**ทำ:** เพิ่ม destination driver (local / SFTP / rsync / S3-compatible) · เก็บ credential แบบเข้ารหัส (sodium secretbox แบบเดียวกับ TOTP secret) · ตั้งเวลาสำรองอัตโนมัติผ่าน scheduler จากเฟส A · นโยบายเก็บย้อนหลัง (retention) · **ทดสอบ restore จากปลายทางนอกจริงอย่างน้อย 1 ครั้ง**

##### สิ่งที่ทำแล้ว

| ส่วน | ไฟล์ |
|---|---|
| ตาราง `backup_destinations` + คอลัมน์ `offsite_*` ของ `backups` | `db/migrations/0009_backup_destinations.sql` |
| เพิ่ม `s3` เข้า CHECK ของ `driver` (ต้องสร้างตารางใหม่ทั้งตาราง — ดูคอมเมนต์ในไฟล์) | `db/migrations/0010_s3_backup_destination.sql` |
| ชั้นปลายทาง | `src/Driver/Backup/{Destination,LocalDestination,SshDestination,SftpDestination,RsyncDestination,S3Destination,DestinationFactory}.php` |
| ที่เก็บข้อมูล (ความลับเข้ารหัส) | `src/Domain/BackupDestinationRepository.php` |
| capability | `backup.push` · `backup.prune` · `backup.destination_test` |
| REST | `/api/v2/backup-destinations` (CRUD + `verification`) · `POST /api/v2/backups/{id}/offsite-copy` |
| หน้าจอ | ส่วน "ปลายทางนอกเครื่อง" + คอลัมน์ "สำเนานอกเครื่อง" + ปุ่มส่งออกรายแถว ในหน้า Backups · ฟอร์มเพิ่มปลายทางมีช่อง bucket/region/access key/endpoint/path-style ของ s3 เพิ่มแล้ว |
| งานตามเวลา | `backup.prune` ทุกวันตี 5 ใน `ScheduledJobRepository::DEFAULTS` |
| เทสต์ | `tests/security/BackupOffsiteTest.php` (11 ข้อ) · `tests/security/S3BackupDestinationTest.php` (8 ข้อ — ดูหมายเหตุเรื่อง S3 ด้านล่าง) |

##### สามข้อที่ตัดสินใจต่างจากที่เขียนไว้ในแผน

1. **ยืนยันตัวตนด้วยกุญแจอย่างเดียว ไม่รองรับรหัสผ่าน** — `Executor::exec()` ไม่รับตัวแปร
   แวดล้อม และรหัสผ่านที่ส่งทางอาร์กิวเมนต์อ่านได้จาก `/proc/<pid>/cmdline` โดยผู้ใช้อื่น
   บนเครื่องเดียวกัน · เลือกไม่ขยาย interface เพื่อรองรับวิธีที่อ่อนกว่าอยู่แล้ว ·
   กุญแจยังถอนคืนได้ทีละใบซึ่งรหัสผ่านทำไม่ได้
2. **`StrictHostKeyChecking=yes` เสมอ** — ปิดมันคือยอมให้ไฟล์สำรองทั้งระบบถูกดักกลางทาง
   โดยไม่มีอะไรฟ้อง · ราคาคือต้องใส่ host key ตอนตั้งค่า ซึ่งข้อความ error บอกคำสั่ง
   `ssh-keyscan` ที่ต้องรันให้เลย
3. **`local` ยังนับเป็นปลายทางที่มีประโยชน์** เมื่อชี้ไปยังจุดเมานต์ของ NAS หรือดิสก์
   ก้อนที่สอง · `test()` จึงตอบกลับมาด้วยว่าปลายทางอยู่บนอุปกรณ์เดียวกับต้นทางหรือเปล่า
   (`same_device`) เพื่อให้เห็นความจริงข้อนี้ตั้งแต่ตอนตั้งค่า ไม่ใช่ตอนดิสก์พัง

##### สิทธิ์ใหม่: `backup.offsite`

`backup.manage` เป็นสิทธิ์ **หมวด Hosting ที่ผู้ดูแลเว็บไซต์มีอยู่แล้ว** (สร้าง/ลบไฟล์
สำรองของเว็บตัวเอง) · ตอนแรกผมผูกเส้นทางปลายทางกับตารางเวลาไว้กับสิทธิ์นี้ ซึ่งแปลว่า
**ผู้ดูแลเว็บไซต์เพิ่มและลบปลายทางของทั้งเครื่องได้** — เทสต์ "webadmin ต้องได้ 403 ทุก
endpoint ของหมวด SERVER" จับได้ทันทีที่เพิ่มเส้นทางใหม่เข้าไปในรายการ

แยกเป็น `backup.offsite` (superadmin + sysadmin) เพราะปลายทางหนึ่งที่รับไฟล์สำรองของ
ลูกค้าทุกราย และตารางเวลารันในนามของ "ระบบ" · ปุ่มลบไฟล์สำรองยังใช้ `backup.manage`
ตามเดิม ผู้ดูแลเว็บไซต์จึงยังจัดการไฟล์ของตัวเองได้ครบ

##### บั๊กที่ขุดเจอระหว่างทาง

- **`static $seen` ในเมธอดของตัวเก็บกวาด** — agent เป็นโปรเซสที่รันค้างเป็นเดือน ตัวนับ
  จึงสะสมข้ามการเรียก แล้วรอบที่สองเป็นต้นไปเห็นว่าทุกกลุ่มครบโควตาตั้งแต่แถวแรก
  **แล้วลบไฟล์ที่ต้องเก็บไว้** · แก้เป็นตัวนับต่อการเรียก + เทสต์เรียกซ้ำสองรอบตรึงไว้
- **`BackupManager` ฮาร์ดโค้ดไดเรกทอรีสำรอง** (ตัวที่แผนข้อนี้ระบุเอง) — ทำให้ติดตั้ง
  แบบ portable ใช้ไม่ได้และเทสต์ต้องมีสิทธิ์ root · เปลี่ยนเป็นรับผ่าน constructor จาก
  `Paths::backups()` แล้วไล่แก้ผู้เรียกครบทั้งห้าที่
- **`data-row-actions` ของตารางไฟล์สำรองไม่มีตัวรับเลย** — ปุ่มกู้คืนกับลบขึ้นมาแต่กด
  แล้วไม่เกิดอะไร · เปลี่ยนเป็นคอลัมน์ที่ใช้ `actionButton` แบบเดียวกับตารางอื่นทั้งหมด
- **ตารางฝั่งหน้าจอข้ามตัวจัดรูปเมื่อค่าเป็น null** — ทำให้ช่อง "รันล่าสุด" ว่างเปล่า
  แทนที่จะขึ้นป้าย "ยังไม่เคยรัน" · ใช้ `0` แทน `null` ตามแบบแผนเดิมของ API นี้
  (`expires_at: 0`) · เป็นกับดักตัวเดียวกับที่ทำให้สามคอลัมน์ของหน้าใบรับรองว่างมาตลอด

##### ที่ยังเหลือ

- ~~**S3-compatible** ยังไม่ได้ทำ~~ 🔄 **เขียนแล้ว 2026-08-09** (`S3Destination` — เซ็น
  AWS SigV4 เองทั้งชุดด้วย `hash_hmac`/`ext-curl` เพราะไม่มี Composer เหมือนที่แผนบอกไว้
  ใช้แบบเดียวกับ `Updater::fetch()`/`TelegramNotifier` คือคุย HTTPS ตรงไม่ผ่าน `Executor`
  เพราะเป็น API ภายนอก ไม่ใช่คำสั่งที่ต้องผ่าน sandbox) · สตรีมอัปโหลด/ดาวน์โหลดเข้า-ออก
  ดิสก์ตรง ๆ ไม่โหลดทั้งไฟล์ขึ้นหน่วยความจำ · ยืนยันความครบถ้วนด้วย ETag→MD5 เมื่อทำได้
  ถอยไปเทียบขนาดเมื่อไม่ได้ (มัลติพาร์ต/เข้ารหัสฝั่งเซิร์ฟเวอร์) แบบเดียวกับที่ sftp ทำกับ
  sha256 · **`tests/security/S3BackupDestinationTest.php` ตรวจแค่ความถูกต้องของอัลกอริทึม
  เซ็น (canonical request/string-to-sign/Authorization header ตรงสเปก, ไวต่อทุกอินพุตที่
  ควรมีผล) ผ่าน reflection ล้วน ๆ — ยังไม่เคยยิงไปเซิร์ฟเวอร์ S3 จริงสักครั้งเพราะเครื่อง
  พัฒนาไม่มีบัญชีให้ทดสอบ ต้องยิงจริงอย่างน้อยหนึ่งครั้ง (`verify()`/ปุ่ม "Test" ในหน้า
  Backups กับ MinIO หรือ AWS S3 จริง) ก่อนเชื่อว่าใช้งานได้ — เหมือนที่ sftp/rsync ยังติด
  ค้างข้อเดียวกันอยู่ด้านล่าง**
- ~~**ตั้งเวลาสำรองอัตโนมัติ**~~ ✅ เสร็จแล้ว — `/api/v2/backup-schedules` + ส่วน
  "สำรองอัตโนมัติ" ในหน้า Backups · `backup.create` รับ `destination_id` ได้แล้ว จึง
  **สร้างและส่งออกในคำสั่งเดียว** ซึ่งจำเป็นเพราะ scheduler เรียก capability ได้ทีละตัว
  ต่อหนึ่งงาน · พิสูจน์แล้วด้วย `phpcp-scheduler --once` จริง: สร้างไฟล์ → ส่งออก →
  `offsite_status = ok` → ไฟล์อยู่ที่ปลายทางจริง

  งานเหล่านี้เก็บใน `scheduled_jobs` เดิมโดยใช้คำนำหน้าชื่อ `backup.auto.` และ
  **แก้ได้เฉพาะแถวที่มีคำนำหน้านั้น** — ส่ง id ของ `rollback.run` มาได้ 404 ·
  `capability` ถูกตรึงที่ `backup.create` เสมอ ผู้เรียกเลือกไม่ได้
- **ทดสอบ restore จากปลายทางนอกจริง** — ทำกับ driver `local` ครบวงจรแล้ว (ส่งออก ·
  ดึงกลับ · เทียบ checksum · ลบ) แต่ **`sftp`/`rsync`/`s3` ยังไม่เคยยิงไปเครื่องจริงสักครั้ง**
  เพราะเครื่องพัฒนามีเครื่องเดียวและไม่มีบัญชี S3 · ข้อนี้ยังไม่ถือว่าผ่านเกณฑ์รับงานของ E1

#### E2. บังคับโควตาพื้นที่ดิสก์จริง — 🔄 **2026-08-09**
**ปัญหา:** คอลัมน์ `disk_quota_mb` มีในฐานข้อมูลแต่ไม่มีโค้ดใดอ่านหรือบังคับใช้ — ลูกค้ารายเดียวเติมดิสก์เต็มได้ กระทบทุกเว็บบนเครื่อง
**ทำ:** filesystem quota จริง (XFS/ext4 project quota) เป็นทางหลัก · ถ้า filesystem ไม่รองรับให้ fallback เป็นการคำนวณด้วย scheduler + ระงับการเขียนเมื่อเกิน (แจ้งเตือนที่ 80%/90%/100%) · **ต้อง fail-closed แบบเดียวกับ `sites.shared_owner` คือถ้าบังคับไม่ได้ต้องบอกให้ชัด ไม่ใช่เงียบ ๆ ปล่อยผ่าน**

##### สิ่งที่ทำแล้ว (ทาง fallback เท่านั้น — อ่านหัวข้อ "ทางหลักที่ยังไม่ได้ทำ" ด้านล่างก่อนเชื่อว่าเฟสนี้บังคับใช้ได้จริงระดับ OS)

| ส่วน | ไฟล์ |
|---|---|
| แก้บั๊กเดิม: `disk.usage` (จากเฟส A/ARCHITECTURE §14) query `sites.disk_quota_mb` ซึ่งหายไปตั้งแต่ migration 0006 — SQL error ทุกครั้งที่ scheduler เรียกมาตลอด ไม่มีใครเห็นเพราะไม่มีเทสต์ยิง `run()` จริง | `src/Agent/Capability/DiskUsage.php` |
| ตาราง `disk_quota_state` (ระดับแจ้งเตือนล่าสุดต่อบัญชี) | `db/migrations/0011_disk_quota_state.sql` |
| วัดพื้นที่ดิสก์**ต่อบัญชี** (ทั้งบ้าน ไม่ใช่รายเว็บ) + แจ้งเตือน 80/90/100% | `src/Agent/Capability/DiskQuotaCheck.php` (`quota.disk_check`, ทุกชั่วโมง) |
| ด่านบังคับ: ปฏิเสธการสร้างทรัพยากรใหม่เมื่อดิสก์เต็ม | `src/Domain/QuotaChecker.php` (`diskQuotaExceeded()`) |
| แก้/ตั้งโควตาดิสก์ผ่าน API ได้แล้ว (เดิมไม่มีทางตั้งเลยแม้แต่ตอนสร้างบัญชี) | `CustomerCreate`, `CustomerQuotaUpdate`, `UsersController::create/setQuota` |
| หมวดแจ้งเตือนใหม่ `quota` (Telegram) | `Notifier`, `SettingsRepository`, `settings.html` |
| หน้าจอ | ช่อง "Disk space (MB)" ในฟอร์มสร้าง/แก้โควตาผู้ใช้ + แถวแสดงผลแบบป้ายสีใน `user.html` |
| เทสต์ | `tests/security/DiskQuotaTest.php` (14 ข้อ รวมเทสต์ `du` จริงบนดิสก์จริงผ่าน `RealExecutor`) |

##### ทางหลักที่ยังไม่ได้ทำ — XFS/ext4 project quota จริงระดับ OS

**ตัดสินใจไม่เขียนโค้ด `xfs_quota`/`setquota` ในเซสชันนี้** เหตุผลต่างจากทุก driver อื่นที่เขียนไปก่อนหน้า (S3, sftp, rsync) ตรงที่ตัวนั้น ๆ ยัง**พิสูจน์ความถูกต้องภายในได้บางส่วน**โดยไม่ต้องมีเครื่องจริง (เช่น SigV4 ตรวจด้วย reflection ได้ว่าประกอบสตริงถูกสเปก) แต่คำสั่ง quota ของ OS ไม่มีทางพิสูจน์อะไรได้เลยนอกจากรันบนเครื่องที่มี XFS/ext4 project quota เปิดอยู่จริง — เครื่องพัฒนานี้ไม่ใช่เครื่องแบบนั้น (อาการ NTFS/FUSE ที่บังคับ 777 ทุกไฟล์ซึ่งเจอมาแล้วหลายเฟส เป็นสัญญาณว่าไม่ใช่ ext4/XFS จริงด้วยซ้ำ) เขียนโค้ดที่ตรวจสอบตัวเองไม่ได้แม้แต่นิดเดียวแล้วบอกว่า "เสร็จแล้ว" อันตรายกว่าไม่เขียนเลย เพราะจะมีความมั่นใจปลอมอยู่ในโค้ดที่ไม่เคยรันจริง

**สิ่งที่ทำแทน (ทาง fallback) บังคับใช้ได้แค่ไหนจริง ๆ:**
- ✅ บล็อก **การสร้างทรัพยากรใหม่ผ่าน panel** (เว็บไซต์/โดเมน/ฐานข้อมูล) เมื่อดิสก์เต็ม — ทดสอบแล้วจริง
- ❌ **ไม่บล็อกการเขียนไฟล์ที่มีอยู่แล้ว** — โค้ดของลูกค้าเอง (WordPress อัปโหลดรูป, ปลั๊กอินสร้าง cache ฯลฯ) เขียนต่อได้เรื่อย ๆ แม้เกินโควตาไปแล้ว เพราะไม่มีอะไรอยู่ระดับ syscall กันไว้ ซึ่งเป็นเหตุผลเดียวกับที่แผนระบุว่า project quota ต้องเป็น "ทางหลัก" ไม่ใช่ทาง fallback
- ข้อความแจ้งเตือนและ error ที่คืนจาก `QuotaChecker::diskQuotaExceeded()` เขียนให้สื่อแค่ "สร้างของใหม่ไม่ได้" โดยตั้งใจ ไม่พูดว่า "เขียนไฟล์ไม่ได้แล้ว" เพื่อไม่ให้ผู้ดูแลเข้าใจผิดว่ามีการบังคับใช้ที่ระดับ filesystem ทั้งที่ยังไม่มี

**งานที่เหลือสำหรับทางหลัก (ต้องทำบนเครื่องที่มี XFS หรือ ext4 + `prjquota` จริง ไม่ใช่เครื่องนี้):**
1. ตรวจจับว่า filesystem รองรับ project quota จริงหรือไม่ ด้วยการทดสอบจริง (เขียน+ตั้ง limit+อ่านกลับบน project id ทดสอบ) ตามแบบเดียวกับ `SiteProvisioner::assertOwnershipUnsupported()` — **ห้ามเดาจากชนิด filesystem**
2. Driver ผูก project id เข้ากับ uid ของแต่ละบัญชี (XFS: `xfs_quota -x -c 'project -s'` · ext4: `chattr +P -p <id>` + `quotaon`) แล้วตั้ง `bhard`/`bsoft` จาก `users.disk_quota_mb`
3. เรียก driver นี้จาก `quota.disk_check` แทน/คู่กับ `du` (ถ้ามี project quota แล้ว อ่านค่าใช้งานจาก `repquota`/`xfs_quota report` แม่นยำและเร็วกว่า `du` เดินทั้งต้นไม้ไฟล์มาก)
4. ทดสอบจริงว่า process ของบัญชีที่เกินโควตาเขียนไฟล์แล้วได้ `EDQUOT` จริง (นี่คือเกณฑ์รับงานตัวจริงของ E2 ที่ยังไม่ผ่าน)
5. ถ้า filesystem ไม่รองรับ (กรณี NTFS/FUSE ของเครื่องพัฒนาเอง หรือ ext4 ที่ไม่เปิด quota ตอน mount) ต้องมี endpoint/หน้าจอบอกสถานะนี้อย่างชัดเจน ไม่ใช่แค่เงียบไปเฉย ๆ — ยังไม่ได้ทำจุดนี้ด้วยเพราะยังไม่มี detection code เลย

#### E3. DNS — เชื่อม BIND9 จริง — ✅ **2026-08-09**
**ปัญหา:** `install.sh` ติดตั้ง BIND9 ให้ แต่ไม่มีโค้ดใดเขียน zone file หรือสั่ง `rndc reload` — ผู้ดูแลเข้าใจผิดได้ง่ายว่า "มี DNS server ใช้งานได้แล้ว"
**ทำ:** capability `dns.zone_write` / `dns.reload` · เขียน zone จาก `dns_records` ผ่าน `ConfigTransaction` (rollback ได้) · `named-checkzone` ก่อน reload ทุกครั้ง (แบบเดียวกับ `apache2ctl -t`) · จัดการ SOA serial อัตโนมัติ · **หรือถ้าตัดสินใจไม่ทำ ต้องเอา BIND9 ออกจาก `install.sh` และเขียนในเอกสารให้ชัดว่าเป็นแค่ตัวส่งออก zone file**

##### ทำไมเฟสนี้ต่างจาก E1 (S3) และ E2 (project quota) — มี oracle จริงให้ตรวจ

เครื่องพัฒนานี้มี BIND9 ตัวจริงติดตั้งและรันอยู่ (`systemctl is-active named` → active) ต่างจาก
S3 (ไม่มีบัญชีให้ทดสอบ) และ XFS/ext4 project quota (เครื่องนี้ไม่ใช่ filesystem ที่รองรับ)
จึงทดสอบ**รูปแบบ zone file จริงกับ `named-checkzone` ตัวจริง**ได้ทันทีโดยไม่ต้องมีสิทธิ์
พิเศษเลย (เป็นแค่ตัวตรวจไวยากรณ์ ไม่แตะ state ของ `named`) — ต่างจากการเขียนไฟล์เข้า
`/etc/bind/` จริงกับ `rndc reload` จริงซึ่งต้องมี root ที่เครื่องนี้ไม่มี (`sudo` ต้องใส่รหัสผ่าน)

##### สิ่งที่ทำแล้ว

| ส่วน | ไฟล์ |
|---|---|
| ค่าตั้ง `dns.enabled`/`zone_dir`/`named_conf_local`/`nameservers`/`soa_email` (ปิดไว้เป็นค่าเริ่มต้นเสมอ แบบเดียวกับ `sites.shared_owner`) | `src/Kernel/Config.php` |
| คอลัมน์ `domains.zone_serial` (เก็บ serial ล่าสุดที่ส่งออกจริง กันย้อนกลับ) | `db/migrations/0012_dns_zone_serial.sql` |
| zone file แบบสมบูรณ์ (SOA+NS+TTL, escape SOA email, FQDN ให้ CNAME/MX อัตโนมัติ) แยกจาก `toZoneFile()` เดิม (export ให้ผู้ใช้ไปวางที่ DNS provider ภายนอก ยังใช้ได้เหมือนเดิม) | `src/Domain/DnsRecord.php::toAuthoritativeZoneFile()` |
| เขียน zone + `named.conf.local` ผ่าน `ConfigTransaction` · ตรวจด้วย `named-checkzone`+`named-checkconf` · `rndc reload` | `src/Driver/Dns/BindZoneManager.php` |
| capability `dns.zone_write` (ต่อโดเมน — สิทธิ์ `domain.manage` เดียวกับที่ webadmin แก้ DNS ของตัวเองอยู่แล้ว ตรวจความเป็นเจ้าของซ้ำที่ชั้น agent) · `dns.reload` (ทั้งเครื่อง — สิทธิ์ใหม่ `dns.manage` เฉพาะ superadmin/sysadmin แบบเดียวกับ `backup.offsite`) | `src/Agent/Capability/{DnsZoneWrite,DnsReload}.php` |
| `POST /domains/{id}/dns-records` และ `DELETE /dns-records/{id}` เรียก `dns.zone_write` ให้อัตโนมัติหลังบันทึก DB — ล้มได้โดยไม่ทำให้คำขอเดิมล้มเหลว (`dns_synced`/`dns_message` ในคำตอบ) | `src/Http/V2/DomainsController.php` |
| `POST /api/v2/dns/reload` — ปุ่ม "Resync all zones" เห็นเฉพาะ `permissions['dns.manage']` | `Routes.php`, `templates/domains.html` |
| แก้บั๊กที่เจอระหว่างทาง: `domain.html` ผูกกล่องแสดง zone file ไว้กับคีย์ `zone` ทั้งที่ API ตอบ `content` — กล่องว่างเปล่ามาตลอดโดยไม่มี error ให้เห็น | `templates/domain.html` |
| เทสต์ 17 ข้อ — **6 ข้อยิง `named-checkzone` ตัวจริง** (ทุกชนิดเรกคอร์ด, zone เปล่า, อีเมล SOA มีจุด, หลายเนมเซิร์ฟเวอร์, FQDN อัตโนมัติ) ที่เหลือผ่าน `DryRunExecutor` · +2 ข้อจากบั๊กที่เจอบนเซิร์ฟเวอร์จริง | `tests/security/DnsZoneTest.php` |

##### ✅ ผ่านเกณฑ์รับงานจริงแล้ว (2026-08-10)

ทดสอบบน `https://127.0.0.1:8443` ของจริงหลังผู้ใช้เปิด `dns.enabled` — **ผ่านข้อ
"สร้าง DNS record ใน panel → dig ได้คำตอบจาก BIND9 บนเครื่องนี้จริง" ของ §7.2 แล้ว**

| ขั้น | ผล |
|---|---|
| เพิ่ม A record `digtest.bbl.test → 203.0.113.77` ผ่าน REST | `dns_synced: true` · serial 2026081003 |
| `dig @127.0.0.1 digtest.bbl.test A +short` | ตอบ `203.0.113.77` |
| `dig @127.0.0.1 bbl.test SOA` | `status: NOERROR` + flag **`aa`** (authoritative จริง ไม่ใช่ forward) |
| ไฟล์บนดิสก์ | `/etc/bind/zones/bbl.test.zone` (`root:bind`, 0644) + `named.conf.local` ถูกเขียนทับตามแบบที่ออกแบบ |
| ลบ record ผ่าน REST → `dig` ซ้ำ | ไม่มีคำตอบแล้ว — วงจรครบทั้งสร้างและลบ |
| serial เดินจริงทุกครั้งที่แก้ | 2026081000 → 01 → 02 → 03 ไม่ซ้ำไม่ย้อน |

**ข้อแม้:** `dig` ยิงจาก `127.0.0.1` คือ*ในเครื่องเดียวกัน* ยังไม่ได้พิสูจน์จากเครื่องอื่น
ผ่านอินเทอร์เน็ตจริง (ต้องมีโดเมนจดจริง + NS ชี้มาที่เครื่องนี้ + พอร์ต 53 เปิดออกนอก)
ข้อความในเกณฑ์ §7.2 ที่เขียนว่า "จากภายนอก" จึงยังไม่ผ่านตามตัวอักษร

##### บั๊กสองตัวที่เทสต์ 426 ข้อจับไม่ได้ แต่เจอทันทีที่ยิงเซิร์ฟเวอร์จริง

1. **เส้นทางของ `named-checkzone`/`named-checkconf` ผิด** — ฮาร์ดโค้ด `/usr/sbin/` ทั้งที่
   Debian/Ubuntu วางไว้ที่ `/usr/bin/` (แพ็กเกจ `bind9-utils`) ทุกการซิงก์จึงล้มด้วย
   *"ไม่พบคำสั่งหรือรันไม่ได้"*
   · **ทำไมเทสต์ไม่จับ:** `DryRunExecutor` ไม่รันคำสั่งจริง ส่วนเทสต์ที่ยิง `named-checkzone`
   จริงเรียกผ่าน **PATH** ไม่ได้เรียกผ่านค่าคงที่ในโค้ด — ตรวจถูกคนละอย่างกับที่ production ใช้
   · **แก้:** ไล่หาจากรายการ path แบบเดียวกับที่ `MariaDbManager` ทำกับ `mariadb`/`mysql`
   (รองรับทั้ง Debian และ RHEL) + ข้อความบอกให้ `apt install` ถ้าไม่เจอ
   · เทสต์ใหม่ตรึงว่าค่าคงที่ต้องชี้ไปยังไฟล์ที่มีอยู่จริงบนเครื่องที่รันเทสต์
2. **`dns.reload` รายงานสำเร็จทั้งที่ล้มทุกโดเมน** — คืน `pushed: true` + แถบเขียว "สำเร็จ"
   ทั้งที่ซิงก์ได้ 0 จาก 1 โดเมน คือการล้มเงียบแบบเดียวกับที่เฟส E1 เตือนไว้เองว่า
   "อันตรายพอ ๆ กับไม่มีระบบเลย"
   · **ทำไมเทสต์ไม่จับ:** ไม่มีเทสต์ที่ทำให้*ทุก*โดเมนล้มพร้อมกัน — ทดสอบแต่ทางที่สำเร็จ
   · **แก้:** ล้มหมด → โยน `ExecutionFailed` · ล้มบางส่วน → แถบ**เหลือง** ไม่ใช่เขียว

**บทเรียนที่ใช้ได้กับเฟสอื่น:** เทสต์ที่เรียกโปรแกรมภายนอกผ่าน PATH ไม่ได้พิสูจน์ว่า
โค้ดจริงเรียก path ถูก
· ✅ **ปิดช่องนี้ทั้งโปรเจกต์แล้ว 2026-08-10** — `tests/security/BinaryPathTest.php` อ่านค่าคงที่
จากคลาสจริงผ่าน reflection (ไม่คัดลอกรายการมาไว้ในเทสต์ ไม่งั้นเทสต์จะเขียวตอนโค้ดเปลี่ยนไปผิด)
แล้วตรวจครบทั้ง 9 คลาส: `DiskUsage`/`DiskQuotaCheck`(du) · `BackupManager`(tar) ·
`SftpDestination`/`RsyncDestination`(sftp/rsync/ssh) · `UfwDriver` · `CertbotManager`(certbot/openssl) ·
`MailManager`(postfix/postmap) · `MariaDbManager`(คู่ที่มี fallback)
· **จุดสำคัญของการออกแบบ: แยก "path ผิด" ออกจาก "ไม่ได้ติดตั้งโปรแกรมนี้"** — ถ้าเจอชื่อโปรแกรม
ใน `PATH` แต่ path ที่โค้ดใช้ไม่มีไฟล์ = บั๊ก (แดง) · ถ้าไม่เจอทั้งสองที่ = เครื่องนี้ไม่ได้ติดตั้ง
(ข้าม) จึงไม่ false-positive บน CI ที่ไม่มี certbot/BIND9
· มีเทสต์คู่กันบังคับว่าทุก path ต้องเป็น absolute — เรียกด้วยชื่อล้วนแล้วให้ระบบหาใน `PATH`
เปิดช่องยัดโปรแกรมปลอมเพื่อยกระดับสิทธิ์เป็น root ได้ (agent รันเป็น root)
· ตรวจแล้วว่า `php-fpm<version>` และ `service` ที่เหลือถูกต้อง (ตัวแรกต่อเวอร์ชันเข้าไป
ตัวหลังมี `file_exists()` เลือกให้อยู่แล้ว)

##### ข้อจำกัดที่ต้องรู้ก่อนเปิด `dns.enabled` จริง

1. **`named.conf.local` กลายเป็นไฟล์ที่ phpcp จัดการทั้งไฟล์** ไม่ใช่แค่เติม stanza — เขียนทับใหม่
   จากรายชื่อโดเมนที่มี `zone_serial > 0` ทุกครั้งที่มีโดเมนใหม่ **ถ้าเครื่องนั้นเคยมี zone ที่ตั้ง
   เองด้วยมือมาก่อน จะหายไปจาก `named.conf.local` ทันทีที่เปิด `dns.enabled`** ต้องเตือนผู้ดูแล
   ก่อนเปิดใช้งานเสมอ (ยังไม่มีการตรวจ/เตือนอัตโนมัติในโค้ด — เป็นสิ่งที่ควรทำเพิ่ม)
2. **ไม่สร้าง glue record อัตโนมัติ** — ถ้า `dns.nameservers` ชี้เป็นชื่อที่อยู่ในโซนเดียวกับที่
   กำลังสร้าง (เช่น `ns1.example.com` เป็น NS ของ zone `example.com` เอง) `named-checkzone`
   จะเตือน "has no address records" (ไม่ถึงกับปฏิเสธ) ผู้ดูแลต้องเพิ่มเรกคอร์ด A ของ nameserver
   นั้นเป็น DNS record ปกติเอง — พิสูจน์ปัญหานี้ไว้แล้วตอนพัฒนา (ดูหมายเหตุในคอมเมนต์ของ
   `BindZoneManager`)
3. **ยังไม่มีการลบ zone อัตโนมัติเมื่อลบโดเมน** — `site.remove_domain`/`domains destroy` ไม่ได้
   เรียก `dns.zone_write` หรือลบ stanza ออกจาก `named.conf.local` เลย โดเมนที่ถูกลบใน panel
   จะยังมี zone ค้างอยู่ใน BIND9 จนกว่าจะมีคนเรียก `dns.reload` (ซึ่งจะลบให้เองเพราะ regenerate
   จากฐานข้อมูลใหม่ทั้งหมด) — ควรเรียก `dns.reload` เองหลังลบโดเมนไปพลาง ๆ จนกว่าจะเชื่อมจุดนี้
4. ~~**`rndc reload`/เขียนไฟล์เข้า `/etc/bind/` จริงยังไม่เคยพิสูจน์**~~ ✅ **พิสูจน์แล้ว 2026-08-10**
   — `phpcp-agentd` รันเป็น root อยู่แล้วบนเครื่องนี้ จึงเขียน `/etc/bind/zones/` และสั่ง
   `rndc reload` ได้จริง · ดูตาราง "ผ่านเกณฑ์รับงานจริงแล้ว" ด้านบน

#### E4. FTP/SFTP accounts — ✅ **เสร็จและพิสูจน์บนเซิร์ฟเวอร์จริงแล้ว 2026-08-10**
**ทำ:** SFTP ผ่าน OpenSSH (chroot ต่อเว็บไซต์) เป็นทางหลัก — ไม่ต้องติดตั้ง daemon เพิ่มและปลอดภัยกว่า FTP · capability `ftp.user_create/delete/password` · เชื่อมกับ `quota_ftp_users` ที่มีอยู่แล้ว · ปลด A3 ที่ซ่อนฟิลด์ไว้

##### เปลี่ยนขอบเขตจากแผนเดิม: **หนึ่งบัญชีโฮสติ้ง = หนึ่ง SFTP login** (ไม่ใช่ chroot ต่อเว็บ)

แผนเดิมเขียนตอนที่หน่วยของการแยกสิทธิ์ยังเป็น "เว็บไซต์" · **migration 0006 เปลี่ยนเป็น
"ผู้ใช้" ไปแล้ว** (หนึ่งผู้ใช้ = หนึ่ง uid = หนึ่งบ้าน = หลายเว็บ) การสร้างบัญชี SFTP แยก
ต่อเว็บจึงไม่ได้แยกอะไรเพิ่มเลย เพราะทุกเว็บของผู้ใช้คนเดียวกันใช้ uid เดียวกันอยู่แล้ว —
เข้าถึงไฟล์กันได้ผ่าน uid นั้นไม่ว่าจะ chroot ยังไง

**ทำไมไม่ทำหลายบัญชีต่อผู้ใช้ (ตาม `quota_ftp_users` ตามตัวอักษร):** ไฟล์เว็บเป็น
`<user>:www-data` เพื่อให้เว็บเซิร์ฟเวอร์เดินผ่านไดเรกทอรี 0750 ได้ (ไม่งั้นไฟล์สแตติกตอบ
403 หมด รวมถึงไฟล์ตรวจสอบของ Let's Encrypt) · การให้บัญชี SFTP ตัวที่สองเขียนไฟล์เว็บได้
ต้องเปลี่ยนกลุ่มของไฟล์เป็น `<user>:<user>` แล้ว www-data เข้าไม่ได้อีกต่อไป — ต้องรื้อ
โมเดลสิทธิ์ไฟล์ทั้งระบบ ซึ่งขัด §7.1 ข้อ 2 "ห้ามลดชั้นความปลอดภัยใด ๆ ที่มีอยู่"
· `quota_ftp_users` จึงตีความใหม่เป็น**สวิตช์**: `0` = แพ็กเกจไม่รวม · `-1`/`>0` = เปิดได้
(ไม่ลบคอลัมน์เพราะยังบอกสิทธิ์ตามแพ็กเกจได้ตรงตัว)

##### สิ่งที่ทำแล้ว

| ส่วน | ไฟล์ |
|---|---|
| คอลัมน์ `users.sftp_enabled` + `sftp_enabled_at` | `db/migrations/0013_sftp_access.sql` |
| **บ้านชั้นบนสุดเป็น `root:<user>` 0750** — เงื่อนไขบังคับของ `ChrootDirectory` | `src/Driver/AccountProvisioner.php::lockHomeRoot()` |
| เขียน `sshd_config.d/phpcp-sftp.conf` ผ่าน `ConfigTransaction` + `sshd -t` · กลุ่ม `phpcp-sftp` · `chpasswd` ทาง stdin | `src/Driver/Ssh/SftpAccessManager.php` |
| capability `sftp.enable` (ใช้เปลี่ยนรหัสผ่านด้วย) · `sftp.disable` | `src/Agent/Capability/Sftp{Enable,Disable}.php` |
| REST `PUT`/`DELETE /api/v2/users/{id}/sftp` + `sftp_enabled`/`sftp_available` ใน `UserResource` | `Routes.php`, `UsersController.php`, `UserResource.php` |
| หน้าจอ: ส่วน "SFTP access" ในหน้าผู้ใช้ — แยก "แพ็กเกจไม่รวม" ออกจาก "ยังไม่ได้เปิด" | `templates/user.html` |
| เทสต์ 13 ข้อ (รวม 2 ข้อจากบั๊กที่เจอบนเซิร์ฟเวอร์จริง) | `tests/security/SftpAccessTest.php` |

##### บั๊กที่เจอทันทีที่ยิงเซิร์ฟเวอร์จริง (2026-08-10)

**`sshd -t` ล้มด้วย "Missing privilege separation directory: /run/sshd"** เพราะเครื่องนี้
ไม่ได้เปิดบริการ ssh ไว้ (`systemctl is-active ssh` → inactive) และ `/run/sshd` ถูกสร้างโดย
systemd ตอน start เท่านั้น (`RuntimeDirectory=sshd`)

- **สิ่งที่ทำงานถูกต้องอยู่แล้ว:** `ConfigTransaction` คืนไฟล์เดิมอัตโนมัติ — ไม่มีไฟล์ค้าง
  ใน `sshd_config.d/` และ sshd ไม่ได้ถูกแตะเลย (ตรวจแล้วว่าไดเรกทอรีว่างเปล่าหลังล้ม)
- **สิ่งที่ผิด:** ผู้ดูแลเห็นข้อความ "การตั้งค่าที่สร้างขึ้นไม่ผ่านการตรวจสอบ" ซึ่งชี้ไปที่
  ไฟล์ config ที่ phpcp เพิ่งเขียน ทั้งที่ไฟล์นั้นถูกต้องทุกบรรทัด — ไล่หาผิดที่แน่นอน
- **แก้:** (1) `assertSshdRunning()` ตรวจก่อนแตะไฟล์ใด ๆ พร้อมบอกคำสั่ง
  `systemctl enable --now ssh` ที่ต้องรัน (2) `explainSshdTest()` แปลข้อความดิบของ
  `sshd -t` ให้ชี้ไปที่ต้นตอจริงเป็นตาข่ายสำรอง
- **บทเรียนเรื่องเทสต์:** เทสต์แรกที่เขียนใช้ `DryRunExecutor` ซึ่ง**คืน exit 0 ให้ทุกคำสั่ง**
  `ServiceProbe` จึงสรุปว่าทุกบริการทำงานอยู่เสมอ แยกกรณีนี้ไม่ออกเลย · ต้องเขียน
  `SshdStateExecutor` ที่คุมผลของ `systemctl` ได้จริง — เป็นข้อจำกัดชนิดเดียวกับที่ทำให้
  บั๊ก path ของ E3 หลุดไปได้ (ตัวจำลองที่ "สำเร็จเสมอ" ปิดบังกรณีล้มเหลวทั้งหมด)

##### สามด่านที่กันไม่ให้ SFTP กลายเป็น shell access

1. `ForceCommand internal-sftp` — ทำได้แค่รับส่งไฟล์ ต่อให้ขอ shell ก็ไม่ได้
   (`internal-sftp` จำเป็นสำหรับ chroot เพราะ `sftp-server` แบบไฟล์อยู่นอก chroot จึงเรียกไม่ถึง)
2. shell ของบัญชียังเป็น `/usr/sbin/nologin` ตามเดิม — ด่านสำรองถ้า `Match` block หายไป
3. `ChrootDirectory` + ตัด forwarding ทุกชนิด (`AllowTcpForwarding`/`AllowAgentForwarding`/
   `AllowStreamLocalForwarding`/`PermitTunnel`/`X11Forwarding` = no) กัน SFTP กลายเป็นทางออก
   สู่เครือข่ายภายใน

##### กับดักที่แก้ไว้แล้วและมีเทสต์เฝ้า

- **`Match` มี scope ไหลข้ามไฟล์** — ทุก directive ที่ตามหลัง `Match` อยู่ใน scope นั้น
  ไปเรื่อย ๆ รวมถึงบรรทัดใน `sshd_config` หลักที่ include ทีหลัง · ไฟล์จึงจบด้วย `Match all`
  เสมอ ไม่งั้นค่าตั้ง sshd ของทั้งเครื่องกลายเป็นของกลุ่ม SFTP โดยไม่มีใครรู้
  (เทสต์ตรึงไว้ · พิสูจน์แล้วว่าลบบรรทัดออกแล้วเทสต์แดงจริง)
- **ไม่แตะ `sshd_config` หลักเลย** — ไฟล์นั้นคือสิ่งที่พังแล้วเข้าเครื่องไม่ได้อีก
  (`SshManager` จึงยอมให้แก้แค่ 5 คีย์) · ไฟล์แยกลบทิ้งก็คืนสภาพทันที · ถ้าเครื่องไม่มี
  บรรทัด `Include` จะบอกให้ชัดพร้อมคำสั่งที่ต้องเพิ่ม แทนที่จะเขียนไฟล์ทิ้งไว้เฉย ๆ ให้
  ผู้ดูแลเห็นว่า "สำเร็จ" แต่ล็อกอินไม่ได้จริง
- **รหัสผ่านส่งทาง stdin ของ `chpasswd`** ไม่ใช่อาร์กิวเมนต์ — `/proc/<pid>/cmdline`
  ผู้ใช้อื่นบนเครื่องอ่านได้ (เหตุผลเดียวกับที่ `SshDestination` ไม่รองรับ password auth)
  · มีเทสต์ตรวจว่ารหัสผ่านไม่โผล่ในคำสั่งใดเลย

##### ✅ พิสูจน์บนเซิร์ฟเวอร์จริงแล้ว (2026-08-10)

| สิ่งที่ทดสอบ | ผล |
|---|---|
| เปิด SFTP ผ่าน REST แล้วล็อกอินด้วย `sftp` จริง | `Connected` · `pwd` → `/bbl` (เข้าบ้านตัวเองอัตโนมัติจาก `-d /%u`) · `ls` → `domains logs tmp` |
| `ssh` เข้ามาเป็น shell | ถูกปฏิเสธ: *"This service allows sftp connections only"* (ข้อความของ `ForceCommand internal-sftp`) |
| `cd ..` แล้ว `ls /` (ราก chroot) | **Permission denied** — 0711 ทำให้มองไม่เห็นด้วยซ้ำว่ามีลูกค้ารายอื่นบนเครื่อง |
| `cd /etc` · `get /etc/passwd` | *No such file or directory* — chroot ตัดขาดจากระบบไฟล์จริงสมบูรณ์ |
| `cd /gcms` · `cd /acc` (บ้านลูกค้ารายอื่น) | เข้าไม่ได้ |
| **เว็บ `bbl.test` หลังเปิด SFTP** | **HTTP 200** — ไม่กระทบการเสิร์ฟเลย |
| สิทธิ์บ้านหลังทั้งหมด | ยังเป็น `bbl:www-data 0750` ตามเดิม (ไม่ถูกแตะ) |
| ปิด SFTP | ถูกเอาออกจากกลุ่ม `phpcp-sftp` จริง (`groups=988(bbl)`) |

**ข้อแม้เรื่องการเข้าบ้านลูกค้ารายอื่น:** ที่ทดสอบได้จริงคือบัญชี `gcms`/`acc` ซึ่ง**ยังไม่มี
บ้านอยู่จริง** (0 เว็บ = ยังไม่มีบัญชีระบบ) ผลลัพธ์ "No such file or directory" จึงยังไม่ได้
พิสูจน์ว่าสิทธิ์กันจริงถ้าบ้านนั้นมีอยู่ · สิ่งที่ยืนยันแล้วคือ `id -nG bbl` → `bbl` เท่านั้น
(**ไม่ได้อยู่ในกลุ่ม `www-data`**) ซึ่งเมื่อรวมกับบ้านที่เป็น `<owner>:www-data 0750` แปลว่า
ผู้ใช้รายอื่นตกเป็น "other" ที่ไม่มีสิทธิ์ใด ๆ ตามกฎ POSIX — ควรทดสอบซ้ำเมื่อมีลูกค้าสองราย
ที่มีเว็บจริงทั้งคู่

##### บั๊กสามตัวที่การทดสอบจริงจับได้ (เทสต์ในโปรเซสไม่เห็นเลยสักตัว)

1. **sshd ไม่ได้อ่าน config ใหม่** — โค้ดเดิมไม่สั่ง reload เพราะเข้าใจผิดว่า "การเชื่อมต่อ
   ใหม่อ่าน config ใหม่เอง" · sshd อ่าน config ตอน start แล้ว fork ตัวลูกจากภาพในหน่วยความจำ
   นั้น · **อาการชี้ไปคนละทางกับต้นตอ:** `ssh` ตอบ *"This account is currently not
   available"* (nologin) และ `sftp` ตอบ *"Received message too long"* (เจอ output ของ
   nologin แทน protocol) → เพิ่ม `systemctl reload ssh` (ไม่ใช่ `restart` เพื่อไม่ตัด
   เซสชันของผู้ดูแลที่อาจกำลัง ssh อยู่)
2. **early return ข้ามขั้นตอนที่จำเป็น** — `ensureConfig()` return ทันทีเมื่อไฟล์ตรงอยู่แล้ว
   ซึ่งเดิมข้าม reload ไปด้วย · แปลว่ากรณี "เขียนไฟล์สำเร็จแต่ reload ล้มรอบก่อน" **กดซ้ำ
   ไม่มีทางแก้ได้เลย** → ย้าย reload ออกมาให้ `enable()` เรียกเสมอ
3. **เรียกเมธอดที่ไม่มีอยู่** — `$this->binary()` เป็นของ `BindZoneManager` คัดลอกแนวคิดมา
   แต่ลืมเขียนเมธอด → แยกเป็น `Support\BinaryPath::resolve()` ที่ทั้งสอง driver ใช้ร่วมกัน

**ทั้งสามตัวอยู่ในชั้นที่ `DryRunExecutor` "สำเร็จเสมอ"** — รูปแบบเดียวกับบั๊ก path ของ E3
เป็นข้อจำกัดเชิงโครงสร้างของการทดสอบด้วยตัวจำลอง ไม่ใช่ความบกพร่องของเทสต์ชุดใดชุดหนึ่ง

##### ที่ยังเหลือ

- **บ้านของบัญชีที่ยังไม่มีเว็บเลยยังไม่มีอยู่จริง** — เปิด SFTP ไม่ได้ (มีข้อความบอกให้สร้าง
  เว็บก่อน) ซึ่งถูกต้องเพราะยังไม่มีไฟล์อะไรให้เข้าถึง
- **ยังไม่ได้ทดสอบว่าลูกค้าสองรายที่มีเว็บจริงทั้งคู่เข้าบ้านกันไม่ได้** — ดูข้อแม้ด้านบน
- **`Include` ของ `sshd_config`** ตรวจแล้วว่ามีบนเครื่องนี้ แต่ถ้าเครื่องไหนไม่มีจะได้ข้อความ
  บอกให้เพิ่มพร้อมบรรทัดที่ต้องใส่ (ยังไม่ได้ทดสอบเส้นทางนั้นจริงเพราะเครื่องนี้มีอยู่แล้ว)
- FTP จริง (vsftpd/proftpd) ไม่ได้ทำและไม่ควรทำ — SFTP ครอบคลุมกรณีใช้งานทั้งหมดโดยไม่ต้อง
  เปิดพอร์ตเพิ่มและไม่มีปัญหา passive port range

#### E5. WAF / rate limit ระดับเว็บไซต์ — 🔄 **rate limit ✅ พิสูจน์บนเครื่องจริงแล้ว 2026-08-11 · ModSecurity ยังไม่ทำ**
**ปัญหา:** เทมเพลต vhost ของลูกค้าไม่มี `limit_req` หรือ ModSecurity เลย — เว็บลูกค้าไม่มีการป้องกัน L7 DoS จาก panel
**ทำ (ขอบเขตเดิมของแผน):** เพิ่ม rate limit ต่อเว็บในเทมเพลต (nginx `limit_req` / Apache `mod_ratelimit`) เปิด/ปิดและปรับค่าได้ต่อเว็บ · ModSecurity + OWASP CRS เป็นตัวเลือกเสริม (เปิดเป็น detection-only ก่อนเสมอ เพราะ CRS มี false positive สูงมากกับ CMS ทั่วไป)

##### ⚠️ เปลี่ยนจากแผนเดิม: ไม่ใช้ `mod_ratelimit` เพราะมันไม่ได้ทำสิ่งที่แผนต้องการ

`mod_ratelimit` เป็น **output filter ที่จำกัดแบนด์วิดท์** (KB/s ต่อ connection) ไม่ได้จำกัด
จำนวนคำขอ — คนยิง 10,000 req/s ยังยิงได้ครบทุกคำขอ แค่ได้ข้อมูลกลับช้าลง · การใส่แล้ว
เขียนบนหน้าเว็บว่า "เปิด rate limit แล้ว" คือ**ความปลอดภัยหลอก** ซึ่งอันตรายกว่าไม่มีฟีเจอร์นี้

| ทางเลือก | ทำไมไม่เลือก |
|---|---|
| `mod_ratelimit` | จำกัดแบนด์วิดท์เท่านั้น ไม่ตอบโจทย์ที่แผนเขียนไว้เอง |
| `mod_evasive` | ต้องติดตั้งเพิ่ม · นับแยกต่อ child process (ไม่แชร์สถานะ) เกณฑ์จริงจึงกลายเป็นค่าที่ตั้ง × จำนวน child ซึ่งอธิบายให้ผู้ดูแลเข้าใจไม่ได้ |
| **fail2ban** ✅ | ทำงานอยู่บนเครื่องแล้ว · โค้ดชุดเดียวใช้ได้ทั้ง Apache และ nginx (อ่าน access log เหมือนกัน) · บล็อกที่ firewall ซึ่งถูกกว่าให้เว็บเซิร์ฟเวอร์รับคำขอ |

##### สิ่งที่ทำแล้ว

| ส่วน | ไฟล์ |
|---|---|
| ตาราง `site_rate_limits` — ปิดเป็นค่าเริ่มต้นเสมอ | `db/migrations/0016_site_rate_limit.sql` |
| สร้าง filter + jail แยกไฟล์ต่อเว็บ · ตรวจ config ก่อน commit · `reload` ไม่ใช่ `restart` | `src/Driver/Security/Fail2banManager.php` |
| capability สามตัว: `site.rate_limit_set` / `_status` / `_unban` | `src/Agent/Capability/SiteRateLimit*.php` |
| REST 4 เส้นทางใต้ `/sites/{id}/rate-limit` + openapi | `src/Http/V2/SitesController.php` |
| ฟอร์ม + ตาราง IP ที่ถูกแบน + ปุ่มปลดแบน + คำเตือนเหนือฟอร์ม | `templates/site.html` |
| ถอน jail ตอนลบเว็บ (ก่อนย้ายไฟล์) | `src/Agent/Capability/SiteDelete.php` |
| เทสต์ 14 ข้อ | `tests/security/RateLimitTest.php` |

##### ✅ พิสูจน์บนเซิร์ฟเวอร์จริงแล้ว (2026-08-11)

| สิ่งที่ทดสอบ | ผล |
|---|---|
| กดบันทึกจากหน้าเว็บ → สร้าง jail จริง | `Jail 'phpcp-bbl' started` · ค่าตรงกับที่ตั้ง (`maxRetry: 20 / findtime: 30 / banTime: 150`) |
| **อ่านไฟล์ log ไม่ใช่ systemd journal** | `uses pyinotify` + `Added logfile: /srv/phpcp/users/bbl/domains/bbl.test/logs/access.log` |
| `failregex` + `datepattern` ตรงกับ log จริงของ Apache | `fail2ban-regex`: **4479 lines, 4479 matched, 0 missed** · date template hits ครบ 4479 |
| localhost ไม่ถูกแบน | ยิง 30 ครั้งจาก `127.0.0.1` → `Currently failed: 0` |
| `ConfigTransaction` ย้อนไฟล์เมื่อการตรวจไม่ผ่าน | รอบที่คำสั่งตรวจผิด ไฟล์ถูกคืนค่าเดิมครบ ไม่มี config พังค้างบนดิสก์ |

##### บั๊กที่การทดสอบจริงจับได้ — ทั้งหมดเงียบสนิท

1. **`backend = systemd` ของ Debian ทำให้ `logpath` ถูกเมินทั้งบรรทัด** —
   `/etc/fail2ban/jail.d/defaults-debian.conf` ตั้งไว้ใน `[DEFAULT]` · jail จะขึ้นสถานะ
   active ทุกอย่างดูปกติ แต่ไม่นับคำขอสักรายการเดียวและไม่มีอะไรฟ้อง
   → ทุก jail ที่ระบบสร้างต้องบังคับ `backend = auto` เอง
2. **`fail2ban-client --test get <jail> failregex` ไม่ใช่ไวยากรณ์ที่มีอยู่จริง** —
   `--test` เป็นตัวเลือกของโปรแกรม ไม่ใช่ modifier ของ `get` · และ `get` ถาม
   **เซิร์ฟเวอร์ที่กำลังรัน** ซึ่งยังไม่รู้จัก jail ที่เพิ่งเขียนลงดิสก์ จึงล้มเหลวทุกครั้ง
   ไม่ว่าไฟล์จะถูกหรือผิด (`UnknownJailException`) → ใช้ `fail2ban-client -t` ที่อ่านไฟล์จากดิสก์
3. **`jail.conf` ของ Debian comment `ignoreip` ไว้** — localhost ไม่ได้ถูกยกเว้นโดยอัตโนมัติ
   ยิงจาก `127.0.0.1` แล้วโดนแบนจริง ซึ่งตัดขาดหน้า panel ที่ผู้ดูแลใช้เข้ามาแก้ปัญหาพอดี
   → ระบบเติม `127.0.0.1/8 ::1` ให้ทุก jail **เสมอ** ไม่ว่าผู้ใช้จะกรอกอะไร

##### ข้อแลกเปลี่ยนที่ต้องบอกผู้ใช้บนหน้าจอ (ทำแล้ว)

1. **การแบนมีผลทั้งเครื่อง ไม่ใช่เฉพาะเว็บนั้น** — fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost
   · IP ที่ยิงเว็บ A จนโดนแบนจะเข้าเว็บ B ของลูกค้าอีกรายไม่ได้ด้วย · เป็นเหตุผลที่
   ค่าเริ่มต้นต้องหลวมพอที่คนใช้งานปกติจะไม่มีวันชน (300 คำขอ/60 วินาที)
2. **ตอบสนองช้ากว่าโมดูลในตัว** — อ่าน log เป็นรอบ คำขอชุดแรกที่เกินเกณฑ์ผ่านไปถึงเว็บก่อนเสมอ

##### ที่ยังเหลือของ E5

- **ยังไม่เห็นการแบนเกิดขึ้นจริง** — พิสูจน์แล้วว่า filter จับ log ได้ 100% และ jail ทำงาน
  แต่ยังไม่ได้ยิงจาก IP ที่ไม่ใช่ localhost จนโดนแบนจริง (ต้องยิงจากเครื่องอื่นหรือ LAN IP)
- **ยังไม่ได้ทดสอบปุ่มปลดแบนบนหน้าเว็บ** — เส้นทาง `site.rate_limit_unban` ยังไม่เคยถูกเรียกจริง
- **ยังไม่ได้ทดสอบกับ nginx** — โค้ดชุดเดียวกันควรใช้ได้เพราะอ่าน access log เหมือนกัน
  แต่รูปแบบ log ของ nginx ต่างจาก Apache เล็กน้อย ต้องยืนยันด้วย `fail2ban-regex` เช่นกัน
- **ModSecurity + OWASP CRS** — ยังไม่ทำ · แผนระบุว่าเป็นตัวเลือกเสริมและต้องเปิดเป็น
  detection-only ก่อนเสมอ · ต้องมีหน้าจัดการ exception ต่อเว็บก่อนถึงจะใช้จริงได้
  ไม่งั้น false positive ของ CRS จะทำให้ CMS ทั่วไปใช้งานไม่ได้

#### E6. Monitoring และการแจ้งเตือน — ✅ **เสร็จและพิสูจน์บนเครื่องจริงแล้ว 2026-08-10** (เหลือยืนยันว่าอีเมลถึงกล่องจริง)
**ทำ (ขอบเขตเดิมของแผน):** เก็บ metrics ย้อนหลัง (ตาราง SQLite แบบ downsample) · ช่องทางแจ้งเตือนเพิ่ม: Email (ผ่าน Postfix ที่มีอยู่) + Webhook ทั่วไป · ตั้งเกณฑ์เตือนได้ (disk > 85%, RAM, load, service ล่ม, cert ใกล้หมดอายุ)

##### สิ่งที่ทำแล้ว: เก็บ metrics ย้อนหลัง + กราฟ

| ส่วน | ไฟล์ |
|---|---|
| ตาราง `metrics_history` — สามชั้น (นาที/24 ชม. · ชั่วโมง/30 วัน · วัน/365 วัน) รวม ~2,500 แถวคงที่ต่อเครื่อง | `db/migrations/0014_metrics_history.sql` |
| บันทึก · ยุบชั้น · เก็บกวาดตามอายุ | `src/Domain/MetricsHistoryRepository.php` |
| capability `metrics.record` (อ่านอย่างเดียว — ไม่เติม audit ทุกนาที เหตุผลเดียวกับ `disk.usage`) + งานตามเวลาทุกนาที | `src/Agent/Capability/MetricsRecord.php`, `ScheduledJobRepository::DEFAULTS` |
| `GET /api/v2/metrics/history?range=` — เลือกชั้นให้เองตามช่วงที่ขอ | `src/Http/V2/MetricsController.php` |
| กราฟในหน้าภาพรวมเซิร์ฟเวอร์ + ปุ่มเลือกช่วง 24h/7d/30d/1y | `templates/server.html`, `js/pages.js` |
| เทสต์ 12 ข้อ | `tests/security/MetricsHistoryTest.php` |

**เปลี่ยนจากแผนเดิม:** แผนเขียนไว้ว่า 1 นาที/24 ชม. → 5 นาที/7 วัน → 1 ชม./90 วัน · เปลี่ยนชั้นกลาง
เป็นรายชั่วโมงและขยายชั้นท้ายเป็นรายวัน/1 ปี เพราะคำถามที่ผู้ดูแลถามจริงมีสองแบบชัดเจน:
"เมื่อวานตอนนั้นเกิดอะไร" (ต้องละเอียดระดับนาที ภายใน 24 ชม.) กับ "เดือนนี้โตขึ้นเท่าไร"
(รายวันพอ) — ชั้น 5 นาทีไม่ได้ตอบคำถามไหนที่อีกสองชั้นไม่ตอบ

##### ✅ พิสูจน์บนเซิร์ฟเวอร์จริงแล้ว (2026-08-10)

| สิ่งที่ทดสอบ | ผล |
|---|---|
| งานตามเวลาเก็บข้อมูลจริง | มีจุดข้อมูลต่อเนื่องทุกนาทีบน `/var/lib/phpcp/panel.db` |
| `range=1h/24h` → ชั้น `minute` · `7d` → `hour` · `1y` → `day` | เลือกชั้นถูกทุกช่วง และ**การยุบชั้นเกิดขึ้นจริง** (มีแถว hour/day) |
| `range=bogus` | HTTP 422 |
| **กราฟวาดจริงบน Chrome headless** ด้วยข้อมูลจาก API จริง | SVG 214 shapes · `data-url` ทำงานโดยไม่ต้องเขียน JS แปลงข้อมูล |

##### บั๊กที่การทดสอบจริงจับได้

1. **SQLite เทียบ INTEGER กับ TEXT ผิดโดยไม่มี error** — PDO ผูกพารามิเตอร์เป็น TEXT
   โดยปริยาย และ SQLite จัดลำดับชนิดโดย INTEGER มาก่อน TEXT เสมอ · การเทียบผลลัพธ์ของ
   **expression** (ซึ่งไม่มี type affinity ของคอลัมน์มาช่วยแปลง) กับพารามิเตอร์จึงเป็นจริง
   เสมอ ทำให้ช่วงเวลาปัจจุบันที่ยังไม่ปิดถูกยุบก่อนเวลา → แก้ด้วย `CAST(:x AS INTEGER)`
   · **ตรวจแล้วว่าโค้ดเดิมทั้งระบบปลอดภัย** เพราะเทียบคอลัมน์ตรง ๆ (`expires_at < :t`)
   ซึ่ง type affinity ของคอลัมน์แปลงให้อัตโนมัติ — ปัญหาเกิดเฉพาะกับ expression
2. **`GraphComponent.init(el, opts)` ไม่ได้สร้าง instance** — เป็นการ initialize ทั้งระบบ
   (ตัวอย่างใน `now.js/ai/js/main.js` ใช้ผิด) · ที่ถูกคือประกาศ `data-component="graph"`
   แล้วให้ `Now.init({environment:'production'})` สแกนเอง
3. **`refresh()` ไม่อ่าน `data-url` ใหม่** — โหลดจาก `instance.options.url` ที่จำไว้ตอนสร้าง
   · การเปลี่ยน `dataset.url` แล้ว refresh ไม่มีผลเลย (พิสูจน์ในเบราว์เซอร์: ค่าไม่เปลี่ยน)
   → ต้องเขียนทับ `instance.options.url` ก่อนเรียก `refresh()`

##### สิ่งที่ทำแล้ว: ช่องทางแจ้งเตือน + เกณฑ์เตือน (2026-08-10)

| ส่วน | ไฟล์ |
|---|---|
| ช่องทางอีเมล (Postfix ผ่าน `sendmail` — เดินผ่าน Executor ตาม §4.4) | `src/Driver/Notify/EmailNotifier.php` |
| ช่องทาง webhook — ลงลายเซ็น HMAC-SHA256 (`X-Phpcp-Signature`) · บังคับ https ยกเว้น localhost · ไม่ตาม redirect | `src/Driver/Notify/WebhookNotifier.php` |
| `Notifier` กระจายไปทุกช่องทางที่เปิด (ไม่ใช่ช่องแรกที่สำเร็จ) + `activeChannels()` | `src/Domain/Notifier.php` |
| เกณฑ์เตือน + **กฎกันข้อความซ้ำ** (แจ้งตอนเข้าสถานะ · แจ้งทันทีเมื่อแย่ลง · เงียบระหว่างนั้น · เตือนซ้ำทุก 6 ชม.) | `src/Domain/AlertRules.php`, `db/migrations/0015_alert_state.sql` |
| capability `alert.check` ทุก 5 นาที — disk · RAM · load ต่อคอร์ · บริการสำคัญ · ใบรับรอง | `src/Agent/Capability/AlertCheck.php` |
| `GET /api/v2/alerts` — สถานะที่ยังค้าง (อ่านอย่างเดียว) | `src/Http/V2/AlertsController.php` |
| ปุ่มทดสอบแยกทีละช่องทาง + หน้าตั้งค่า + ตารางเกณฑ์ที่ค้าง | `src/Agent/Capability/NotifyTest.php`, `templates/settings.html` |
| **เฝ้าดู agent** — `OnFailure=` ของ systemd เรียกตัวส่งข้อความที่มีสิทธิ์พอ | `bin/phpcp-alert`, `templates/panel/phpcp-alert@.service.tpl` |
| เทสต์ 39 ข้อ | `tests/security/AlertRulesTest.php`, `NotifyChannelsTest.php`, `NotifyDeliveryTest.php`, `tests/api/AlertsApiTest.php` |

##### ✅ พิสูจน์บนเซิร์ฟเวอร์จริงแล้ว (2026-08-10)

| สิ่งที่ทดสอบ | ผล |
|---|---|
| หยุด Apache ผ่าน panel | ได้ Telegram จริง |
| `phpcp-alert test` | ได้ข้อความจริงทุกช่องทางที่ตั้งไว้ |
| kill `phpcp-agentd` ซ้ำจนชน `StartLimitBurst` | `OnFailure=` ทำงาน · ได้ข้อความ "บริการล้มเหลว" จริงในจังหวะที่ทุกช่องทางอื่นใช้ไม่ได้ |

##### ⚠️ บทเรียนสำคัญ: แต่ละโปรเซสส่งข้อความออกได้ไม่เท่ากัน

เขียน `AgentHealth` ให้ scheduler แจ้งเตือนตอน agent ตาย — ตรรกะถูกทุกอย่างแต่**เงียบสนิท**
เพราะ `phpcp-scheduler.service` ถูก hardening ไว้:

| ข้อจำกัด | ผล |
|---|---|
| `RestrictAddressFamilies=AF_UNIX` | เปิด TCP ไม่ได้ → Telegram/webhook ตายเงียบ |
| `NoNewPrivileges=yes` | ปิด setgid ของ `postdrop` → `mail_queue_enter: Permission denied` ใน journal |

**ไม่ผ่อน hardening** (§7.1 ข้อ 2) — ย้ายหน้าที่ส่งข้อความไปที่ `bin/phpcp-alert` ที่ systemd
เรียกผ่าน `OnFailure=` แทน · `AgentHealth` เหลือแค่บันทึกสถานะให้ `/api/v2/alerts` อ่าน
เพราะ `OnFailure=` ไม่ทำงานตอน `systemctl stop` (systemd ถือว่าเป็นการหยุดตั้งใจ)

##### บั๊กที่การทดสอบจริงจับได้ — ทั้งหมดเงียบสนิท ไม่มีอะไรฟ้อง

เทสต์ผ่าน 100% ตอนที่ทั้งห้าข้อนี้พังอยู่:

1. **`AlertCheck` กรองด้วยคีย์ `load` ที่ `ServiceProbe` ไม่เคยคืน** — เงื่อนไขไม่มีทางเป็นจริง
   · ระบบยิงแจ้งเตือนรวดเดียว 6 ข้อความเรื่อง php-fpm เวอร์ชันที่ไม่ได้ลงบนเครื่อง
2. **`probeFallback()` คืน `installed => true` ให้ unit ที่ systemd บอกว่าไม่มี** — มองหาคำว่า
   `unrecognized service` แต่ systemd ตอบ `could not be found` · เพิ่มด่านท้าย: ไม่มีไฟล์ unit
   **และ**คำสั่งล้มเหลว = คืน `null` (ไม่รู้) แทนการเดา
3. **`Dispatcher::notify()` ไม่ส่ง executor ให้ `Notifier`** — อีเมลถูกข้ามทุกครั้ง ผู้ดูแลที่
   ตั้งค่าครบถูกต้องไม่ได้รับอะไรเลยสักฉบับ
4. **`service.start` ไม่อยู่ในตาราง `NOTIFY`** — หยุดบริการแล้วดัง เปิดคืนแล้วเงียบ
   ทิ้งความกังวลค้างไว้
5. **เว็บเซิร์ฟเวอร์ถูกตัดสินทีละตัว** — nginx ที่ติดตั้งไว้แต่สตาร์ตไม่ขึ้นเพราะ Apache
   ถือพอร์ต 80 คือ**สภาพปกติ** ไม่ใช่เหตุปลุกใคร → เปลี่ยนเป็นตัดสินทั้งกลุ่ม
   (เตือนเมื่อไม่เหลือตัวไหนทำงานเลย) · php-fpm ยังแยกทีละเวอร์ชันเพราะแยกกันจริง

**บทเรียนเรื่องเทสต์:** เทสต์ที่ค้นคำในซอร์สโดนคอมเมนต์ทั้งสองทาง — ครั้งแรก**ผ่านทั้งที่บั๊ก
กลับมาแล้ว** (คำอยู่ในคอมเมนต์ที่เขียนอธิบายบั๊กนั้นเอง) ครั้งที่สอง**ล้มทั้งที่โค้ดถูก**
· วิธีพิสูจน์ว่าเทสต์กัดจริง: ถอด**เฉพาะโค้ด**โดยคงคอมเมนต์ไว้ ถ้ายังผ่านแปลว่าวัดคอมเมนต์อยู่
· ทางแก้คือทดสอบพฤติกรรมจริง (Executor ปลอมที่จำลอง output ของ systemd) หรือแยกคำสั่ง
ออกจากคอมเมนต์ก่อนอ่าน (`unitDirectives()` ใน `NotifyDeliveryTest.php`)

##### ที่ยังเหลือของ E6

- ~~**ยังไม่ยืนยันว่าอีเมลถึงกล่องจดหมายจริง**~~ ✅ **ยืนยันแล้ว 2026-08-11** — ผู้ดูแล
  ได้รับอีเมลแจ้งเตือนในกล่องจริง · ครบวงจรตั้งแต่ `phpcp-alert` → Postfix → ปลายทาง
- **webhook ยังไม่เคยยิงไปปลายทางจริง** — พิสูจน์แล้วแค่การประกอบลายเซ็นและการบังคับ https
- ยังไม่ได้ดูกราฟด้วยตาบนหน้าจริงที่ `https://127.0.0.1:8443/app/server` — พิสูจน์แล้วแค่ว่า
  คอมโพเนนต์วาด SVG ได้จากข้อมูลชุดเดียวกัน

#### E7. Wildcard SSL — 🔄 **โค้ดครบและเทสต์ผ่าน 2026-08-11 · ยังไม่เคยออกใบจริง**
**ทำ:** DNS-01 ผ่าน BIND9 ในเครื่อง (ทำได้สะอาดถ้า E3 เสร็จแล้ว — ไม่ต้องเก็บ credential ของผู้ให้บริการ DNS ภายนอกซึ่งเป็นปัญหาที่ ROADMAP เดิมติดค้างไว้) · `domains.type` เพิ่มค่า `wildcard` · ชื่อไฟล์ vhost ของ wildcard ต้องเรียงท้ายสุด (`phpcp-zz-wildcard-*.conf`) ไม่งั้น wildcard จะกลืน vhost ที่ระบุชื่อเต็ม · เขียนคำเตือนในเอกสารว่า wildcard = subdomain ที่ไม่มีใครจดก็เข้าเว็บนี้ได้

##### สิ่งที่ทำแล้ว

| ส่วน | ไฟล์ |
|---|---|
| `domains.type` เพิ่ม `wildcard` + `certificates.challenge` | `db/migrations/0017_wildcard_domain.sql` |
| เขียน/ลบ TXT `_acme-challenge` ผ่านกลไก zone เดิม แล้ว**รอจน `dig` เห็นจริง** | `src/Domain/AcmeDnsChallenge.php` |
| hook ที่ certbot เรียก (`auth` / `cleanup`) รับค่าจาก env ของ certbot | `bin/phpcp-acme-hook` |
| เลือก DNS-01 อัตโนมัติเมื่อมี `*.` ในรายการโดเมน · ใบธรรมดายังใช้ webroot | `src/Driver/Ssl/CertbotManager.php` |
| `Validator::wildcardDomain()` — แยกจาก `domain()` เพื่อกัน `*` หลุดไปถึงชื่อไฟล์ | `src/Support/Validator.php` |
| vhost ของเว็บที่รับ wildcard ได้คำนำหน้า `zz-wildcard-` ทั้ง Apache และ nginx | `src/Driver/WebServer/*Driver.php` |
| เพิ่มโดเมน `*.x` จากหน้าเว็บได้ พร้อมคำเตือนเหนือฟอร์ม | `SiteAddDomain.php`, `templates/site.html` |
| เทสต์ 9 ข้อ | `tests/security/WildcardSslTest.php` |

##### บั๊กที่เจอระหว่างทาง (แก้แล้ว)

**TXT ใน zone file ไม่เคยถูกใส่เครื่องหมายคำพูด** — เป็นบั๊กที่มีอยู่ก่อน E7 และกระทบ
SPF/DKIM ของทุกโดเมน ไม่ใช่แค่ ACME · ค่าที่มีช่องว่างอย่าง `v=spf1 include:... ~all`
ถูกเขียนดิบ ๆ ทำให้ BIND9 อ่านเป็นหลายสตริงแยกกันหรือปฏิเสธทั้ง zone · เดิมปล่อยให้ผู้ใช้
ใส่ `"` มาเอง ซึ่งเป็นกับดักสำหรับคนที่วางค่าตามที่ผู้ให้บริการเมลบอกมา → ตอนนี้ห่อให้
อัตโนมัติ (ไม่ห่อซ้อนถ้ามีอยู่แล้ว) และ escape `"` ที่อยู่กลางค่า

##### ⚠️ ยังไม่เคยออกใบจริงสักใบ

โค้ดครบและเทสต์ผ่าน แต่**ยังไม่ได้ยิงกับ Let's Encrypt จริง** — สิ่งที่ยังพิสูจน์ไม่ได้:

1. **certbot เรียก hook ได้จริงไหม** และส่ง `CERTBOT_DOMAIN`/`CERTBOT_VALIDATION` มาตามที่คาด
2. **การรอ 60 วินาทีพอไหม** — `waitUntilVisible()` ถามแค่ `@127.0.0.1` ซึ่งบอกได้แค่ว่า
   BIND9 ในเครื่องเสิร์ฟแล้ว ไม่ได้บอกว่า resolver ของ Let's Encrypt เห็นแล้ว
3. **โดเมนทดสอบบนเครื่องนี้เป็น `.test`** ซึ่ง Let's Encrypt ออกใบให้ไม่ได้ — ต้องใช้โดเมนจริง
   ที่ NS ชี้มาที่เครื่องนี้ · ทดสอบด้วย `--staging` ก่อนเสมอ (ไม่นับโควตา)
4. **การต่ออายุอัตโนมัติ** — timer ของ certbot จะเรียก hook เองตอน renew ซึ่งต้องการให้
   `dns.enabled` ยังเปิดอยู่และ zone ยังอยู่ · ถ้าใครปิด DNS ไปหลังออกใบ การต่ออายุจะล้มเงียบ ๆ
   จนกว่าใบจะหมดอายุ — **ยังไม่มีอะไรเตือนเรื่องนี้**

##### ที่ยังเหลือของ E7

- ออกใบจริงด้วย `--staging` กับโดเมนจริงที่ NS ชี้มาที่เครื่องนี้
- ยืนยันว่า Apache เลือก vhost ที่ระบุชื่อเต็มก่อน vhost ของ wildcard จริง (ตอนนี้กันไว้
  ด้วยลำดับชื่อไฟล์อย่างเดียว ซึ่งเป็นการป้องกันที่ถูกต้องแต่ยังไม่ได้ยืนยันพฤติกรรมจริง)
- เตือนเมื่อใบ wildcard ต่ออายุไม่ได้เพราะ `dns.enabled` ถูกปิด

---

### เฟส F — ขยายแพลตฟอร์ม (ทางเลือก)

| งาน | หมายเหตุ |
|---|---|
| F1. RHEL/Alma/Rocky | ต้องเพิ่ม branch `dnf` + SELinux + firewalld ทั่วทั้ง `install.sh` และ driver |
| F2. Mail hosting เต็ม | **แนะนำไม่ทำ** ตามเหตุผลใน §2.1 — ถ้าจะทำควรแยกเป็นผลิตภัณฑ์ต่างหาก |
| F3. Multi-server | agent อยู่คนละเครื่อง คุยผ่าน mTLS แทน unix socket — เปลี่ยนสมมติฐานหลักของสถาปัตยกรรม |

---

## 7. เกณฑ์รับงานและการทดสอบ

### 7.1 กฎที่ห้ามละเมิดตลอดทั้งแผน

1. **`tests/run.php` ต้องผ่าน 100% ทุกครั้งก่อน commit** — ปัจจุบัน 580/580 ห้ามลดลง
2. **ห้ามลดชั้นความปลอดภัยใด ๆ ที่มีอยู่** — RBAC ต้องตรวจ 2 ชั้นเหมือนเดิม, PathGuard, SelfProtection, audit hash-chain ต้องอยู่ครบ
   · ตั้งแต่เฟส M เพิ่มอีกข้อ: **`user.manage` คือด่านเดียวที่กัน sysadmin ไม่ให้แตะบัญชีผู้ดูแล**
   หลังยุบ users/customers เป็นตารางเดียว — ห้ามลดหรือข้าม `UsersController::assertMayManage()`
3. **ห้ามให้ชั้นเว็บเขียน `config.php`** — ใช้ `SettingsRepository` เท่านั้น (เหตุผล: `config.php` ถูก include ตอนบูต ช่องโหว่เดียวในหน้าตั้งค่า = รันโค้ดทันที)
4. **ทุก capability ใหม่ต้องมี test ของตัวเอง** และผ่าน `CapabilityFuzzTest` อัตโนมัติ
5. **ห้าม `Access-Control-Allow-Origin: *`** ในทุกกรณี
6. **งานที่แตะ OS จริงต้องทดสอบบน container จริง** (`docker/acceptance.sh`) ไม่ใช่แค่ sandbox

### 7.2 เกณฑ์รับงานรวมของทั้งแผน

```
[ ] ติดตั้งบน VM เปล่า 1 คำสั่ง → สร้างเว็บไซต์พร้อม SSL ใช้งานได้จริง (Debian 12/Ubuntu 22.04/24.04)
[ ] เปลี่ยนพอร์ต SSH แล้วไม่ยืนยัน + ปิดเบราว์เซอร์ → ค่าคืนเองอัตโนมัติ (พิสูจน์ scheduler)
[ ] สำรองข้อมูลไปปลายทางนอกเครื่อง → ลบข้อมูลในเครื่อง → กู้คืนจากปลายทางนอกสำเร็จ
[ ] ลูกค้าเกินโควตาดิสก์ → ถูกบังคับจริง ไม่ใช่แค่ขึ้นตัวเลขเตือน
[~] สร้าง DNS record ใน panel → dig ได้คำตอบจาก BIND9 บนเครื่องนี้จริง
    ✅ ผ่านแล้วเมื่อ dig จาก 127.0.0.1 (2026-08-10 · authoritative answer, สร้างและลบครบวงจร)
    ❌ ยังไม่ได้ทดสอบ "จากภายนอก" จริง — ต้องมีโดเมนจดจริง + NS ชี้มาที่เครื่องนี้ + พอร์ต 53 เปิดออกนอก
[ ] เว็บลูกค้าถูกยิง request ถี่ → ถูก rate limit โดยเว็บอื่นบนเครื่องเดียวกันไม่กระทบ
[ ] ค้นทั้ง repo ไม่พบการสร้าง HTML จากฝั่ง PHP
[ ] ทุก endpoint คืน JSON แม้ในกรณี error ทุกชนิด
[ ] สลับภาษา th/en ได้ทั้งระบบ
[ ] phpcp doctor ผ่านทุกข้อ รวมถึงตรวจ scheduler และ checksum ของ Now.js
[ ] tests/run.php ผ่าน 100%
```

---

## 8. ความเสี่ยงและการจัดการ

| ความเสี่ยง | ผลกระทบ | การจัดการ |
|---|---|---|
| เฟส C (SPA) บานปลาย 17 หน้าจอ | ล่าช้ามาก | ทำทีละหน้าให้จบจริง เริ่มจาก Services + Sites ที่ใช้บ่อยสุด · HTML เดิมยังใช้ได้ระหว่างทาง ไม่มีช่วง "ใช้ไม่ได้" |
| `innerHTML` ใน Now.js เปิดช่อง XSS | ยึด panel = ยึด root | กฎ text-binding เท่านั้นสำหรับข้อมูลที่ผู้ใช้ควบคุมได้ + test XSS 3 จุดเสี่ยง + CSP เข้มเป็นตาข่ายชั้นสอง |
| E2/E3/E5 แตะ OS จริง อาจทำเว็บลูกค้าล่ม | ลูกค้าเสียหาย | ทุกการเขียน config ผ่าน `ConfigTransaction` + ตรวจ (`named-checkzone`/`nginx -t`) ก่อน reload เสมอ ตามแบบที่ระบบทำอยู่แล้ว |
| ทำเฟส E ก่อน C แล้วต้องเขียน UI สองรอบ | เสียเวลาซ้ำ | E ที่มี UI มาก (E5, E6) ให้รอเฟส C · E1–E4 ทำก่อนได้เพราะ UI น้อย |
| deploy ใช้งานจริงก่อนแผนจบ | ข้อมูลลูกค้าสูญหาย | **บังคับ: ต้องจบ A1 + E1 + E2 ก่อน deploy ที่มีลูกค้าจริง** |
| เฟส M2–M3 ย้ายเส้นทางไฟล์และ uid ของเว็บที่มีอยู่ | เว็บลูกค้าล่มยกเครื่อง | **ไม่เขียนตัวย้าย** — เว็บที่มีอยู่ให้ลบแล้วสร้างใหม่ ซึ่งไม่แตะไฟล์เดิมเลยเพราะ `moveToTrash()` คืนค่าว่างเมื่อบ้านตามเลย์เอาต์ใหม่ยังไม่มีอยู่ · ของที่ต้องเก็บกวาดเองคือ pool ชื่อรายโดเมนและบัญชี `web_N` ที่ค้าง |
| เส้นทาง `/api/v2/users` เส้นเดียวใช้จัดการทั้งลูกค้าและผู้ดูแล | sysadmin ยกระดับเป็น superadmin | ด่าน `assertMayManage()` ที่ชั้นเว็บ + `loadHostingAccount()` ที่ชั้น agent + เทสต์ที่ยิงทุกเมธอดด้วยสิทธิ์ sysadmin |
| Now.js เป็น dependency ภายนอกที่ควบคุมไม่ได้ | supply chain | commit dist เข้า repo + SHA256SUMS + ตรวจใน `doctor` · ไม่ดึง CDN · ตรึงเวอร์ชัน อัปเดตเมื่อตรวจสอบแล้วเท่านั้น |

---

## 9. บันทึกส่งมอบสำหรับ session ใหม่

### 9.1 อ่านอะไรก่อน

1. **เอกสารนี้** (`docs/PLAN-V2.md`) — แผนและสเปก
2. `docs/AUDIT-REPORT.md` — สถานะจริงของระบบ ณ 2026-08-05 พร้อม API catalog ครบทุกตัว
3. `docs/ARCHITECTURE.md` §4–§6 — ชั้น Agent, การแยก service ของ panel, โหมดการทำงาน
4. `docs/SECURITY.md` — threat model (สำคัญมากก่อนแตะอะไรที่เกี่ยวกับสิทธิ์)

### 9.2 คำสั่งที่ใช้บ่อย

```bash
php tests/run.php                  # ทดสอบทั้งหมด — ต้องผ่าน 580/580 เสมอ
php bin/phpcp status               # ตรวจสุขภาพระบบ
php bin/phpcp doctor               # ตรวจ permission/config ผิดพลาด (รวมชีพจรของ scheduler)
php bin/phpcp-scheduler --list     # ดูงานตามเวลาและผลรอบล่าสุด
php bin/phpcp-scheduler --run=ชื่องาน  # บังคับรันงานเดียวทันที ใช้ตอนไล่ปัญหา
docker compose up -d               # สภาพแวดล้อมทดสอบแบบ production จริง
docker/acceptance.sh               # เกณฑ์รับงาน 17 ข้อบน container
```

### 9.3 สิ่งที่ต้องระวังเป็นพิเศษ

- **ห้ามแตะ `src/Agent/`** เว้นแต่กำลังเพิ่ม capability ใหม่ตามแผน — ชั้นนี้คือขอบเขตความปลอดภัยจริงของทั้งระบบ
- **capability ห้ามเรียก `$context->audit`** — `Dispatcher` เป็นเจ้าของการเขียน audit log แต่ผู้เดียว (`Context` ไม่มี `audit`/`request` ให้ใช้)
- **`owner_user_id` กับ `customer_id` เป็นคนละ ID space** — ใช้ `QuotaChecker::checkOwnerCanCreate()` อย่าเทียบตรง ๆ
- โหมด sandbox: ทุกเส้นทางต้องผ่าน `Executor::path()` มิฉะนั้นจะไปแตะระบบจริงของเครื่องนักพัฒนา (เคยเกิดมาแล้ว 3 ครั้งกับ MariaDB, ufw, และ `/etc/mysql/debian.cnf`)
- เครื่องนี้ไม่มีสิทธิ์รัน `sudo` แบบไม่ถามรหัสผ่าน — ถ้าต้องใช้ ให้ส่งคำสั่งให้ผู้ใช้รันเอง

### 9.4 งานชิ้นแรกที่ควรเริ่ม

~~**เฟส A**~~ ✅ **เสร็จทั้งเฟสแล้ว 2026-08-05** (A1 scheduler · A2 เดินสาย customer · A3 ซ่อน UI ที่หลอก · A4 เอกสาร)
ดูสรุปสิ่งที่ทำและจุดที่ต่างจากแผนในหัวข้อ [A1](#a1-scheduler--องค์ประกอบที่หายไปทั้งตัว--สำคัญสุด---เสร็จแล้ว-2026-08-05) ถึง A4

~~**งานถัดไปคือเฟส B — REST API v2**~~ **B1 (รากฐาน) และ B2 (เซสชัน) เสร็จแล้ว 2026-08-05**

~~**งานถัดไปคือ B3.5**~~ ✅ **เฟส B เสร็จทั้งเฟสแล้ว 2026-08-05**

**เกณฑ์รับงานเฟส B — ผ่านครบทุกข้อ:**

| เกณฑ์ | ผล |
|---|---|
| เรียกได้ครบทุกทรัพยากรด้วย `curl` โดยไม่ต้องเปิดเบราว์เซอร์ | ✅ ยิง GET ครบ 22 ทรัพยากร ได้ 200 + `application/json` ทุกตัว |
| `tests/run.php` ไม่มี regression | ✅ 303/303 ตอนจบเฟส B (ปัจจุบัน 337/337) |
| ทุก endpoint คืน JSON แม้ในกรณี error ทุกชนิด | ✅ ตรวจใน contract test ทุกชุด (401/403/404/405/409/419/422/429/500/503) |
| `webadmin` ได้ 403 ทุก endpoint ของหมวด SERVER | ✅ `ServerApiTest` ตรวจทีละเส้นทาง 15 เส้น |
| OpenAPI ตรงกับโค้ด | ✅ `OpenApiSpecTest` บังคับสองทาง + ตรวจว่าเส้นทางจับคู่ได้จริง |

~~**งานถัดไปคือเฟส C**~~ — **เฟส M แทรกก่อนและเสร็จแล้ว 2026-08-07** (M1–M5 + ตัวตรวจสองตัว)

~~**งานถัดไปคือเฟส C — Now.js SPA**~~ 🔄 **C1–C4 เสร็จแล้ว 2026-08-07**
ดูสรุปสิ่งที่ทำ จุดที่ต่างจากแผน และรายการที่ยังค้าง ในหัวข้อ [เฟส C](#เฟส-c--nowjs-spa)

**งานชิ้นแรกที่ต้องทำต่อ — เรียงตามลำดับ:**

1. **ซิงก์ working tree ไป `/usr/share/phpcp` แล้วเปิด `https://127.0.0.1:8443/app` ด้วยตา**
   นี่คือข้อ 2 ของ "สิ่งที่ต้องรู้ก่อนเริ่ม C" ที่ยังไม่ได้ทำ — เฟส M เจอบั๊ก 6 ตัวที่
   เทสต์มองไม่เห็นเลย ทุกตัวอยู่ในชั้นที่ตัวจำลองไม่ได้เดินผ่าน (PHP-FPM, Apache, session)
   · สิ่งที่ต้องดูเป็นอันดับแรกคือหน้า `/app/server` ที่เปิด SSE ค้างไว้พร้อมกับยิงคำขออื่น
2. **ปิดช่องที่ยังค้างของเฟส C** ตามตาราง "สิ่งที่ยังไม่ได้ทำ" ในหัวข้อเฟส C —
   เรียงตามความเสี่ยง: ตัวนับถอยหลัง rollback ของ SSH/firewall มาก่อน (ไม่มีแล้วผู้ดูแล
   จะเสียการตั้งค่าที่เพิ่งทำโดยไม่รู้ตัว) แล้วค่อยเป็นฟอร์มสร้าง/แก้ที่เหลือ
3. **เฟส D ห้ามเริ่มจนกว่าข้อ 1 และ 2 จะจบ** — D เป็นเฟสเดียวที่ทำลายของเดิม
   UI แบบ HTML คือทางถอยเดียวที่มีอยู่ตอนนี้

**สิ่งที่ต้องรู้ก่อนเริ่ม C — อ่านสามข้อนี้ให้จบก่อนเขียนบรรทัดแรก:**

1. **มีเซิร์ฟเวอร์จริงรันอยู่ที่ `https://127.0.0.1:8443`** ใช้ทดสอบได้ และ**ต้องใช้**
   · `/usr/share/phpcp` เป็นสำเนาของ working tree ที่ผู้ใช้ซิงก์ให้ (ตรวจด้วย `md5sum`
   เทียบสองฝั่งก่อนเชื่อว่าโค้ดใหม่ทำงานอยู่) · ฐานข้อมูลจริงคือ `/var/lib/phpcp/panel.db`
   ซึ่ง**อ่านไม่ได้** ต้องผ่าน HTTP หรือขอให้ผู้ใช้รันคำสั่งให้
2. **เทสต์ผ่าน 100% ไม่ได้แปลว่าใช้งานได้** — เฟส M เจอบั๊ก 6 ตัวที่เทสต์ 333 เคส
   มองไม่เห็นเลย ทุกตัวอยู่ในชั้นที่ตัวจำลองไม่ได้เดินผ่าน (PHP-FPM, Apache, session)
   · ตัวที่หนักที่สุดคือ **REST API v2 ทั้งชุดอ่าน JSON body ไม่ได้บนเซิร์ฟเวอร์จริง**
   ซึ่งจะทำให้ SPA ใช้งานไม่ได้เลยและหาสาเหตุยากมากถ้าไม่รู้ล่วงหน้า
3. **รัน `bin/phpcp-smoke` ทุกครั้งที่แตะชั้น API** — ยิงครบ 89 endpoint ผ่าน HTTP จริง
   · ตอนนี้ผ่าน 89/89 ถ้าเลขลดลงแปลว่าเพิ่งทำอะไรพัง
4. อ่าน **FRAMEWORK_GUIDE.md** ก่อนเริ่มทำงาน และปฏิบัติตามอย่างเคร่งครัด มีตัวอย่างที่ใช้งานได้จริงอยู่ในโปรเจ็ค สามารถนำมาใช้ได้ทันที เขียน Javascript เพิ่มเท่าที่จำเป็นเท่านั้น

**เครื่องมือตรวจสามตัวและมุมมองของแต่ละตัว:**

| เครื่องมือ | มุมมอง | ใช้เมื่อ |
|---|---|---|
| `php tests/run.php` | ในโปรเซส | ทุกครั้งก่อน commit — ต้อง 580/580 |
| `bin/phpcp-smoke --url=... --user=... --password-file=...` | HTTP + ล็อกอินแล้ว | หลังแตะ API, middleware, routing |
| `tools/security-audit.sh <url> --auth-checks --user=... --password-file=...` | HTTP จากภายนอก | หลังแตะ header, คุกกี้, TLS, CSRF, rate limit |

**สิ่งที่เฟส B ส่งมอบให้เฟส C ใช้ได้ทันที:**
- `GET /api/v2/session` เป็นจุด bootstrap — ให้ทั้งสถานะล็อกอิน, CSRF token, โหมด,
  สถานะ agent และรายการสิทธิ์สำหรับซ่อน/แสดงเมนู ในคำขอเดียว
- ทุกคำตอบแนบ `X-CSRF-Token` ล่าสุด — `HttpClient` ของ Now.js อ่านให้เองอยู่แล้ว
- รหัสข้อผิดพลาดเป็น enum ตายตัว: 401 → เด้งหน้าล็อกอิน · 419 → ขอ token ใหม่แล้วลองใหม่
  1 ครั้ง · 422 → ทาสีช่องกรอกจาก `error.fields` · 503 → ขึ้นแถบ "agent ไม่ทำงาน"
- `docs/openapi.yaml` เป็นสัญญาที่เชื่อได้ว่าตรงกับโค้ด (มีเทสต์บังคับ)

**สิ่งที่เฟส A ทิ้งไว้ให้เฟส B ใช้ต่อได้เลย:**
- `Validator::nullableInt()` — ค่าที่ "ไม่ส่งมา" ต่างจาก "ส่งมาเป็น 0" ซึ่งทุก endpoint แบบ `PATCH` ต้องใช้
- `Dispatcher::redact()` — ทำให้ข้อมูลลับไม่หลุดลง audit log ไม่ว่า capability ไหนจะรับ arg อะไร
- `CustomerCapability` — แบบอย่างของฐานร่วมที่รวมกฎ domain ไว้ที่เดียว

**เกณฑ์รับงานที่ยังต้องพิสูจน์บนเครื่องจริง (ทั้ง A1):** ทดสอบบน container ตาม §7.1 ข้อ 6 —
เปลี่ยนพอร์ต SSH แล้วไม่ยืนยัน ปิดเบราว์เซอร์ทิ้งไว้ ค่าต้องคืนเองเมื่อครบเวลา
(บนเครื่องพัฒนาโหมด sandbox พิสูจน์แล้วว่าเส้นทาง scheduler → agent → `rollback.run` → คืนค่า → audit ครบ
และเส้นทางลูกค้าพิสูจน์ครบทั้งจากหน้าเว็บจริงผ่าน HTTP และจาก CLI)

---

## 10. รอบงาน 2026-08-11 — เตรียมขึ้นเซิร์ฟเวอร์จริง

### 10.1 ของใหม่ที่ไม่ได้อยู่ในแผนเดิม

| งาน | เหตุผลที่ทำ |
|---|---|
| **โหมด `nginx-proxy`** — nginx ชั้นหน้า + Apache ชั้นหลังที่ 127.0.0.1:8080 | nginx ไม่รองรับ `.htaccess` และจะไม่รองรับ · เว็บที่ย้ายมาจากโฮสต์อื่นเกือบทั้งหมดพึ่งไฟล์นั้น · เป็น **ค่าเริ่มต้นของตัวติดตั้ง** แล้ว |
| nginx ตอบไฟล์ static เองโดยไม่ข้ามกฎ `.htaccess` | `HtaccessScan` แยก "กฎ rewrite ล้วน" (ปลอดภัย) ออกจาก "กฎควบคุมการเข้าถึง" · โฟลเดอร์ที่มีกฎถูกบังคับผ่าน Apache · nginx ตรวจซ้ำทุกคำขอ · งาน `webserver.rescan` รายชั่วโมงปิดช่องของไฟล์ที่รากเว็บ |
| เปลี่ยนเว็บเซิร์ฟเวอร์ + ตั้ง nameserver จากหน้า Settings | ค่าเหล่านี้เคยต้อง ssh เข้าไปแก้ไฟล์ ซึ่งขัดกับเหตุผลที่ control panel มีอยู่ · ย้ายมาอยู่ในตาราง `settings` โดย `config.php` ยังเป็นค่าเริ่มต้นของเครื่องเก่า |
| `phpcp sites:rebuild` | `config.example.php` อ้างถึงคำสั่งนี้มาตั้งแต่ต้นแต่ไม่เคยมีอยู่จริง |
| ตัวติดตั้งลง Postfix + logrotate + รัน `doctor` ให้เอง | สามอย่างนี้เคยเป็น "ผู้ดูแลต้องไปทำเอง" ซึ่งแปลว่าไม่มีใครทำ |
| README · LICENSE · CI สามดิสโทร | เตรียมขึ้น GitHub |

### 10.2 บั๊กที่เจอจากการใช้งานจริง ไม่ใช่จากเทสต์

ทั้งหมดนี้ผ่านชุดทดสอบ 500+ ข้อมาได้สบาย ๆ เพราะเทสต์ไม่ได้ยิงผ่านเบราว์เซอร์จริง:

- **ทุกคำตอบ API ที่สำเร็จถูกตีเป็น error** — `Api.unwrap()` ตรวจ `body.success` แต่
  `ApiController` ส่ง `{ok:true}` · กระทบล็อกอิน เปลี่ยนรหัสผ่าน rollback bar phpMyAdmin
  และปุ่มสำเนาปลายทางนอก · ตาราง/ฟอร์ม/กราฟไม่โดนเพราะใช้กลไกของเฟรมเวิร์กคนละทาง
- **การ์ดค่าใช้งานสดไม่เคยขยับ** — ดัก `message` แต่ SSE ส่ง `event: metrics`
  (เหตุการณ์ที่มีชื่อไม่เข้า listener ของ `message` ตามสเปก)
- **คอมโพเนนต์ไม่เคยถูก destroy ตอนเปลี่ยนหน้า** — `ComponentManager` คีย์ instance ด้วย
  element ที่เทมเพลตแทนที่ไปแล้ว · poller สะสมทุกครั้งที่เดินหน้า (แก้ที่เฟรมเวิร์ก)
- **ปุ่มในตารางไม่มีสถานะกำลังทำงาน** — คลาส `loading` ไปเกาะปุ่มลอย ๆ ที่ไม่อยู่ใน DOM
- **ติดตั้งบนเครื่องเปล่าไม่สำเร็จ** — ลืม `chown root:phpcp` ให้ `config.php`
- **session ถูกทำลายเมื่อ User-Agent เปลี่ยน** — ย่อจอทดสอบ responsive ใน DevTools
  แล้วถูกเด้งออก · เลิกผูกกับ UA และเลิกทำลาย session เมื่อ IP ไม่ตรง (ปฏิเสธคำขอพอ)
- **`nginx -t` เปิด listening socket จริง** — ไม่ใช่ตรวจแค่ไวยากรณ์ · agent ไม่มี
  `CAP_NET_BIND_SERVICE` จึงล้มทุกครั้ง และระหว่างสลับโหมด Apache ยังถือพอร์ตอยู่

**บทเรียนร่วมของทั้งเจ็ดข้อ:** ชุดทดสอบในโปรเซสยืนยันได้แค่ว่า "โค้ดทำสิ่งที่เขียนไว้"
ไม่ได้ยืนยันว่า "สองฝั่งพูดภาษาเดียวกัน" — ทุกข้อถูกจับได้ด้วยการยิงของจริงผ่านเบราว์เซอร์
หรือ curl เท่านั้น · เทสต์ที่เพิ่มเข้าไปหลังแก้จึงผูกสองฝั่งเข้าด้วยกันเสมอ
(เช่น `ApiEnvelopeTest` ตรึงว่า `ApiController` กับ `api.js` ใช้คีย์เดียวกัน)

### 10.3 สถานะ ณ สิ้นรอบ

```
[x] ชุดทดสอบ                      580/580
[x] ติดตั้งบนเครื่องเปล่า           27/27 (ubuntu 24.04 · ค่าเริ่มต้น nginx-proxy)
[x] เกณฑ์รับงานโหมด nginx-proxy    19/19
[x] เกณฑ์รับงานโหมด apache         22/22
[x] อีเมลแจ้งเตือนถึงกล่องจริง       ยืนยันแล้ว 2026-08-11
```

### 10.4 ที่เหลือ — ต้องทำบนเครื่องจริงเท่านั้น

เรียงตามลำดับที่ควรทำหลังติดตั้งบนเซิร์ฟเวอร์จริง:

1. **E1 — ปลายทางสำรองนอกเครื่อง** ยิง `sftp`/`rsync`/`s3` จริงอย่างน้อยอย่างละครั้ง
   แล้วกู้คืนกลับมาครบวงจร · เป็นเงื่อนไขบังคับก่อนรับลูกค้าจริงตาม §8
2. **E2 — project quota ระดับ OS** ต้องเป็นเครื่องที่ filesystem เป็น XFS หรือ ext4
   ที่เปิด `prjquota` · เกณฑ์รับงานคือ process ของบัญชีที่เกินโควตาต้องได้ `EDQUOT` จริง
3. **E5 — เห็นการแบนของ fail2ban เกิดขึ้นจริง** ต้องยิงจาก IP ที่ไม่ใช่ localhost
4. **E7 — ออกใบ wildcard จริงด้วย `--staging`** ต้องมีโดเมนจริงที่ NS ชี้มาที่เครื่องนั้น
5. **E3 — query DNS จากภายนอก** (เครื่องพัฒนาเดิมมี dnsmasq ถือพอร์ต 53 อยู่)
6. **เช็กลิสต์ก่อน production ใน `docs/SECURITY.md` §5** — 2FA, ใบรับรองจริง, ufw,
   ปิด SSH root login, `chattr +a` บน audit.log, mount `noexec,nosuid`, ทดสอบ restore
