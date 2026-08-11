# PHP Server Control Panel — โครงสร้างไฟล์และแผนการทำงาน

> เอกสารคู่กับ [ARCHITECTURE.md](ARCHITECTURE.md) และ [SECURITY.md](SECURITY.md)

---

## 1. โครงสร้างไฟล์ในโปรเจกต์

```
webServer/
├── PROMPT.md                       ข้อกำหนด UI เดิม
├── index.html                      prototype เดิม — เก็บไว้อ้างอิง แล้วย้ายเป็น _reference/
├── install.sh                      ตัวติดตั้ง
├── docs/
│   ├── ARCHITECTURE.md
│   ├── SECURITY.md
│   └── ROADMAP.md
│
├── bin/
│   ├── phpcp                       CLI
│   └── phpcp-agentd                daemon ชั้นที่ 2
│
├── public/                         document root ของ panel (เปิดจากภายนอกได้เฉพาะที่นี่)
│   ├── index.php                   front controller เดียว
│   └── assets/
│       ├── css/app.css
│       ├── js/{core,toast,modal,table,metrics,fileman,logview}.js
│       ├── fonts/noto-sans-thai-{400,500,600}.woff2
│       └── icons/sprite.svg
│
├── src/
│   ├── Kernel/
│   │   ├── Bootstrap.php  Config.php  Router.php  Request.php  Response.php
│   │   └── Container.php           DI เล็ก ๆ เขียนเอง ไม่เกิน 80 บรรทัด
│   ├── Middleware/
│   │   └── SecurityHeaders · RateLimit · Session · Auth · Csrf · Rbac · AuditContext
│   ├── Controller/
│   │   ├── DashboardController.php
│   │   ├── Hosting/    Site · Domain · Ssl · Php · Database · File · Cron · Backup
│   │   ├── Server/     Overview · Service · Security · Firewall · Ssh · Log · User · Setting
│   │   └── Api/        Metrics(SSE) · Job · Action
│   ├── Domain/                     Entity + Repository ต่อ SQLite
│   ├── Agent/
│   │   ├── Client.php              ฝั่ง panel เรียก socket
│   │   ├── Server.php              ฝั่ง daemon
│   │   ├── CapabilityRegistry.php
│   │   ├── SelfProtection.php      §5.3 ของ ARCHITECTURE
│   │   ├── Executor/               Executor · RealExecutor · SandboxExecutor · DryRunExecutor
│   │   ├── Capability/             ~60 ไฟล์ 1 capability = 1 ไฟล์
│   │   └── Job/                    Runner + งานยาวแต่ละชนิด
│   ├── Driver/
│   │   ├── WebServer/  ApacheDriver.php · NginxDriver.php
│   │   ├── Php/        FpmManager.php
│   │   ├── Db/         MariaDbManager.php
│   │   ├── Ssl/        CertbotManager.php · SelfSignedManager.php
│   │   └── Firewall/   UfwDriver.php · NftDriver.php
│   ├── Support/                    Str · Path · Bytes · Time · Validator · ThaiFormat
│   └── Security/                   Password · Totp · Csrf · AuditLog · SecurityScore
│
├── views/                          เทมเพลต PHP ล้วน ภาษาไทยทั้งหมด
│   ├── layout/  base.php · sidebar.php · header.php · breadcrumb.php · modeBanner.php
│   ├── component/ card · table · badge · modal · confirm · toast · chart · empty · pagination
│   └── page/    ตามหน้าใน ARCHITECTURE §3.3
│
├── templates/                      เทมเพลต config ที่ agent ใช้ generate
│   ├── apache/{vhost,vhost-ssl}.conf.tpl
│   ├── nginx/{vhost,vhost-ssl}.conf.tpl
│   ├── fpm/pool.conf.tpl
│   └── panel/{httpd.conf,fpm.conf,systemd/*.service}.tpl
│
├── db/migrations/                  0001_init.sql, 0002_....sql
├── lang/th.php                     ข้อความไทยทั้งหมดรวมที่เดียว
└── tests/
    ├── unit/  integration/  security/
    └── fixtures/sandbox-seed.php   ข้อมูลตัวอย่างไทย
```

**หมายเหตุ:** `lang/th.php` รวมข้อความไว้ที่เดียวแม้ v1 จะมีภาษาเดียว — ต้นทุนตอนนี้เกือบศูนย์ แต่ทำให้แก้คำผิดทั้งระบบได้จากไฟล์เดียว และเพิ่มภาษาอังกฤษทีหลังได้โดยไม่ต้องรื้อเทมเพลต

---

## 2. แผนการทำเป็นเฟส

ทุกเฟสจบแล้วต้อง **ใช้งานได้จริงและ demo ได้** ไม่ใช่ครึ่ง ๆ กลาง ๆ

### เฟส 0 — โครงและกลไกความปลอดภัย (รากฐาน)

> ห้ามข้ามหรือทำทีหลัง — ทุกอย่างหลังจากนี้วางอยู่บนเฟสนี้

- Kernel: Bootstrap, Config, Router, Container, Request/Response
- Middleware ครบ 7 ตัว
- SQLite migration + repository พื้นฐาน
- `Executor` interface + 3 implementation (Real / Sandbox / DryRun)
- `phpcp-agentd` + protocol + `CapabilityRegistry` + `SelfProtection`
- capability ชุดแรก: `system.metrics`, `service.status`
- Auth + session + CSRF + Argon2id + TOTP
- Audit log แบบ hash chain
- layout, sidebar, header, breadcrumb, modeBanner + `app.css` + design token
- `install.sh` เวอร์ชันแรก + systemd 3 units
- `phpcp` CLI: `status`, `doctor`, `mode:*`, `sandbox:*`, `user:passwd`

**เกณฑ์รับงาน:** ติดตั้งบนเครื่องนี้ด้วย `--mode=sandbox` แล้วล็อกอินได้ เห็นแดชบอร์ดเปล่าที่มีแถบเตือนโหมดทดสอบ · `phpcp doctor` ผ่าน · ทดสอบความปลอดภัยชุด CapabilityFuzz และ SelfProtection ผ่าน

> **สถานะ: เสร็จแล้ว** — 76 ไฟล์ PHP ~8,900 บรรทัด + views/CSS/JS อีก ~1,200
> ทดสอบความปลอดภัยผ่าน 25/25 · โหลดหน้าแรกรวม 10 KB (gzip) จากงบ 120 KB
>
> จุดที่ต่างจากแผนเดิมและเหตุผล:
> - ใช้ `src/Kernel/App.php` แทน `Container.php` — DI container ที่ผูกด้วยสตริงทำให้ IDE
>   ตามชนิดข้อมูลไม่ได้ ซึ่งเสียประโยชน์มากกว่าได้สำหรับโค้ดที่ต้องตรวจด้านความปลอดภัย
> - เพิ่ม `Paths` layout แบบ `portable` ที่ไม่ได้อยู่ในแผนเดิม — ทำให้รันทดสอบทั้งระบบ
>   บนเครื่องนักพัฒนาได้โดยไม่ต้อง `sudo` และไม่แตะ `/etc` เลย
> - metrics ใช้ polling ผ่าน `/api/metrics` ก่อน ส่วน SSE เลื่อนไปเฟส 1 ตามแผนเดิม

### เฟส 1 — Server layer

- ภาพรวมเซิร์ฟเวอร์ + metrics สด (SSE + กราฟ canvas)
- Services: ตาราง/การ์ด, start/stop/restart/reload, ความสัมพันธ์กับเว็บไซต์, confirm dialog 3 ระดับ
- Logs: viewer หน้าตา terminal, tail สด, ค้นหา/กรอง/ช่วงวันที่/ระดับ
- ผู้ใช้งานระบบ + RBAC เต็มรูปแบบ

**เกณฑ์รับงาน:** กด restart service ใน sandbox แล้วสถานะเปลี่ยนจริง · `webadmin` เปิด URL ของหน้า SERVER ได้ 403 ทุกหน้า

> **สถานะ: เสร็จแล้ว** — capability เพิ่มเป็น 8 ตัว (เพิ่ม service.start/stop/restart/reload,
> system.info, system.logs_tail) · เส้นทาง 24 เส้น · ทดสอบความปลอดภัยผ่าน 39/39
> ยืนยันเกณฑ์รับงานทั้งสองข้อผ่านหน้าเว็บจริงแล้ว
>
> บั๊กที่เจอระหว่างทางและแก้แล้ว:
> - **CSRF ผูกกับ path ทำให้ฟอร์มข้ามเส้นทางพังทั้งหมด** — หน้า Services เรนเดอร์ที่
>   `/server/services` แต่ POST ไป `/server/services/{unit}/{action}` token จึงไม่มีวันตรง
>   เปลี่ยนมาผูกกับ session อย่างเดียวตามมาตรฐาน (แก้ SECURITY §2.3 ให้ตรงด้วย)
> - **403 กลายเป็น 500** — middleware เรนเดอร์หน้า error ผ่าน layout หลักโดยไม่ได้ share
>   ตัวแปรของ layout รวมไว้เป็น `View::chrome()` ใช้ร่วมกันทั้ง controller และหน้า error
> - **SSE ทำให้ `phpcp serve` ค้างทั้งเว็บ** — `php -S` มี worker เดียว การเชื่อมต่อ stream
>   เส้นเดียวยึดไว้จนหน้าอื่นโหลดไม่ได้ แก้ด้วย `PHP_CLI_SERVER_WORKERS`
>   (บนเซิร์ฟเวอร์จริงไม่มีปัญหานี้เพราะ phpcp-fpm ตั้ง pm.static ไว้แล้ว)

### เฟส 2 — Hosting layer แกนหลัก

- เว็บไซต์: รายการ + สร้าง (สร้าง user + pool + vhost จริง) + ระงับ/ลบ + หน้ารายละเอียด 8 แท็บ
- PHP: รายการเวอร์ชัน, สถานะ FPM, จำนวนเว็บที่ใช้, extension, เปลี่ยนเวอร์ชันต่อเว็บไซต์
- โดเมน: subdomain, alias, redirect + ตาราง DNS records 6 ชนิด
- Apache driver ครบ + ลำดับ configtest → reload → rollback

**เกณฑ์รับงาน:** สร้างเว็บไซต์ใหม่ใน sandbox แล้ว `apache2ctl -t` ผ่านกับ vhost ที่ generate ออกมาจริง · เปลี่ยน PHP version แล้ว vhost ชี้ socket ใหม่ถูกต้อง

> **สถานะ: เสร็จแล้ว** — capability 15 ตัว · เส้นทาง 37 เส้น · ทดสอบผ่าน 82/82
> ยืนยันทั้งสองเกณฑ์ด้วย `apache2 -t` ตัวจริงบนเครื่องนี้
>
> โครงสร้างที่เพิ่ม: `ConfigTransaction` (เขียน config แบบย้อนกลับได้),
> `Template`/`SafeBlock` (กันการแทรก directive), `ApacheDriver`, `FpmManager`,
> `SiteProvisioner` (งานฝั่ง OS ของเว็บไซต์รวมไว้ที่เดียว)
>
> บั๊กที่เจอระหว่างทางและแก้แล้ว:
> - **SIGCHLD ของ agent ขโมยสถานะออกของโปรเซสลูก** — handler ที่ลูกสืบทอดมาจากพ่อ
>   เรียก `pcntl_waitpid(-1)` ไปเก็บศพโปรเซสที่ `proc_open` สร้าง ทำให้ `proc_close()`
>   คืน -1 ระบบจึงเห็นว่า *ทุกคำสั่งล้มเหลว* ทั้งที่สำเร็จ อาการไม่โผล่จนกว่าจะมี
>   capability ตัวแรกที่รันโปรเซสจริง แก้ด้วยการคืน SIGCHLD เป็น SIG_DFL ในลูก
> - **เส้นทางที่อยู่ "ข้างใน" ไฟล์ config ไม่ถูกแมปตามโหมด** — vhost ใน sandbox ชี้ไป
>   `/srv/phpcp/...` ของจริงซึ่งไม่มีอยู่ ทำให้ configtest ล้มทั้งที่ไฟล์ถูกต้อง
>   แก้ด้วยการส่ง `Executor` เข้า `renderVhost()`/`renderPool()`
> - **`PRAGMA journal_mode = WAL` ล้มด้วย "database is locked"** — สั่งซ้ำทุกการเชื่อมต่อ
>   ขณะมี SSE ค้างอยู่ ต้องตั้ง `busy_timeout` ก่อน และตรวจว่าอยู่โหมดนั้นแล้วหรือยัง
>   พร้อมถอยไปโหมดปกติถ้า filesystem ไม่รองรับ (FUSE/NFS)

### เฟส 3 — ข้อมูลและไฟล์

- ฐานข้อมูล: สร้าง/ลบ DB และ user, สิทธิ์, import/export ผ่าน job queue
- ตัวจัดการไฟล์ครบทุกฟีเจอร์ + drag-and-drop upload + ตัวแก้ไขข้อความ + ZIP/Unzip
- งานอัตโนมัติ (Cron)

**เกณฑ์รับงาน:** ทดสอบความปลอดภัยชุด PathTraversal และ ZipSlip ผ่านทั้งหมด · อัปโหลดไฟล์ 100 MB แบบ chunk สำเร็จ

> **สถานะ: เสร็จแล้ว** — capability 31 ตัว · เส้นทาง 58 เส้น · ทดสอบผ่าน 87/87
>
> ตัวจัดการไฟล์ออกแบบใหม่เป็นแบบ Explorer พร้อมขยายขอบเขตการเข้าถึงตามที่ตกลง:
> ผู้ดูแลระดับเซิร์ฟเวอร์เข้าถึงได้ทั้งเครื่อง (7 ขอบเขต) ผู้ดูแลเว็บไซต์เห็นเฉพาะเว็บของตัวเอง
> นโยบายทั้งหมดรวมอยู่ใน `FileRoots` ที่เดียว และ `FileScope` แทนที่การผูกกับ `site_id`
>
> บั๊กที่เจอระหว่างทางและแก้แล้ว:
> - **โหมด sandbox ไปแตะ MariaDB จริงของเครื่อง** — `--defaults-file=/etc/mysql/debian.cnf`
>   ไม่ได้ผ่าน `Executor::path()` คำสั่งฐานข้อมูลจึงอ่าน credential จริงแล้วสร้าง/ลบ
>   ฐานข้อมูลบนเครื่องจริง ผิดสัญญาหลักของโหมดทดสอบ แก้ด้วยการแมปเส้นทาง
>   และเพิ่ม `MariaDbSimulator` ให้ sandbox มีฐานข้อมูลจำลองของตัวเอง
> - **`FileController::respond()` ยังอ่าน `$args['site_id']` ที่ถูกลบไปแล้ว** — ทุก endpoint
>   ของไฟล์คืน 500 หลังย้ายมาใช้ระบบ scope
> - **`/files/browse` ไม่มี route** — มีเมธอดในคอนโทรลเลอร์แต่ไม่เคยผูก ต้นไม้โฟลเดอร์
>   จึงขยายกิ่งไม่ได้ เพิ่มเป็น `GET /api/files/browse`
> - **CSS ของ folder tree หายไปตอนไฟล์ถูก format ใหม่** และกฎ `.fm-scope` เดิมชนกับโครงใหม่
>
> ที่ยังไม่ได้ทำในเฟสนี้: นำเข้า/ส่งออกฐานข้อมูล (ย้ายไปรวมกับระบบสำรองข้อมูลในเฟส 4)

### เฟส 4 — SSL, Backup, Security Center, Firewall, Mail Server

- SSL: Let's Encrypt ผ่าน certbot, self-signed, นำเข้าเอง, auto-renew, เตือนใกล้หมดอายุ
- Backup: เว็บไซต์/ฐานข้อมูล/config, ตั้งเวลา, restore พร้อมตรวจ checksum, ดาวน์โหลด
- Security Center: คะแนน + คำแนะนำที่กดแก้ได้
- Firewall + SSH พร้อม auto-rollback
- Mail Server (วางแผน/เพิ่มเติม): Postfix + Dovecot + Roundcube Webmail / Rspamd, การสร้าง DKIM keys, SPF/DMARC DNS Records

> **สถานะปัจจุบัน (อัปเดต 2026-08):**
> - **ตัวติดตั้งและสภาพแวดล้อมระบบ (`install.sh` & Docker):**
>   - รองรับ Multi-PHP (7.4 & 8.4) จาก Ondřej Surý PPA / sury.org
>   - ติดตั้งและตั้งค่า Apache2, Nginx, BIND9 (`named`), MariaDB Server, phpMyAdmin, OpenSSH, UFW Firewall, Fail2ban, Certbot, Cron
>   - สุ่มสร้างรหัสผ่าน MariaDB root อัตโนมัติและสลับเก็บใน `/root/.my.cnf`
>   - ตั้งค่า `blowfish_secret` อัตโนมัติสำหรับ phpMyAdmin (`/phpmyadmin`) และแก้ไข Apache URI directive บล็อก JavaScript error (`Messages is not defined`) เรียบร้อยแล้ว
>   - BIND9 เปิดใช้งาน BIND/named ใน ServiceCatalog (`KIND_DNS`)
>   - UFW เปิดพอร์ต 22 (SSH), 53 (DNS), 80 (HTTP), 443 (HTTPS), 8443 (Panel)
> - **การรองรับ Docker Testing Environment:**
>   - รองรับ `docker-compose` & `docker/Dockerfile` สำหรับรันสภาพแวดล้อมทดสอบ `phpcp-prod-test`
>   - `phpcp-prod-test` ผูก source จาก repo เข้าไปแบบ read-only bind mount ทับ `/usr/share/phpcp` รายโฟลเดอร์ — แก้โค้ดแล้วเห็นผลทันทีโดยไม่ต้อง `docker cp`/restart (ดู `docs/INSTALL.md` ข้อ 9)
>   - `docker/dev-start.sh` เป็นคำสั่งหลักของคอนเทนเนอร์ ไล่สตาร์ตบริการทั้งหมดแทน systemd
>   - รันด้วย `--cap-add NET_ADMIN --cap-add NET_RAW` เพื่อให้ `ufw`/`fail2ban` เขียนกฎ netfilter ได้จริง
>   - เพิ่มระบบ fallback `service <unit> status` ใน `ServiceProbe.php` เพื่อการตรวจสอบสถานะบริการที่แม่นยำในคอนเทนเนอร์ไร้ systemd
>   - `UfwDriver.php` ปรับปรุงให้รองรับการทำงานใน unprivileged container (จับข้อยกเว้น `NET_ADMIN` ไร้สิทธิ์ได้อย่างปลอดภัย)
>   - แก้ไข bug `--skip-column-names` ใน `MariaDbManager.php`
>   - ผ่าน Unit Tests ทั้งหมด 188 ข้อ · ยืนยัน 1223 ครั้ง (0 failure)

**เกณฑ์รับงาน:** ทดสอบ restore จริงจนเว็บกลับมาทำงาน · ทดสอบ auto-rollback โดยจงใจไม่กดยืนยันแล้วค่าต้องกลับเป็นเดิม

> **สถานะ: เสร็จแล้ว** — capability 49 ตัว · ทดสอบผ่าน 150/150
>
> **เกณฑ์รับงานทั้งสองข้อผ่านแล้ว** ยืนยันด้วยการทดสอบจริง:
> - สำรอง → ทำลายไฟล์ → กู้คืน → ไฟล์กลับมาครบและไฟล์แปลกปลอมถูกล้าง
> - เปลี่ยนพอร์ต SSH เป็น 2222 แล้วจงใจไม่กดยืนยัน → หมดเวลา 30 วินาที → คืนเป็น 22 เอง
>
> ที่ทำเสร็จ: `BackupManager` (สำรอง/กู้คืน/ตรวจ checksum), `RollbackGuard` (ยืนยันภายในเวลา
> แบบเดียวกับ `netplan try`), `SshManager` + capability `ssh.config_get/set`,
> `rollback.confirm/run`, migration `0002_rollback`
>
> ที่ทำเพิ่มรอบนี้: หน้าจอ **สำรองข้อมูล**, หน้าจอ **SSH** พร้อมกล่องนับถอยหลัง,
> `UfwDriver` + capability `firewall.*` 5 ตัว + หน้าจอ **Firewall**, `UfwSimulator`,
> `CertbotManager` + capability `ssl.*` 5 ตัว + หน้าจอ **SSL Certificates**, `CertbotSimulator`
>
> ทดสอบ SSL บน container โหมด production จริง (Debian 12 + Apache 2.4 + certbot + PHP 8.4):
> ออกใบ → เปิด HTTPS → บังคับ HTTPS → ให้บริการจริงด้วย TLS 1.3 · PHP รันด้วย uid `web_1`
> ของเว็บไซต์เอง · `open_basedir` กัน `/etc/shadow` ได้ · `.env` และ `.git` ตอบ 403 บน HTTPS ·
> `/.well-known/acme-challenge` ตอบ 200 แม้อยู่ในโหมดบังคับ HTTPS
>
> **ศูนย์ความปลอดภัย** ตรวจ 9 ข้อจากสภาพจริงของเครื่องทุกครั้งที่เปิดหน้า
> (firewall · SSH · ใบรับรอง · บังคับ HTTPS · ต่ออายุอัตโนมัติ · 2FA · การเดารหัส ·
> เวอร์ชัน PHP · สิทธิ์ไฟล์) แล้วคิดคะแนนถ่วงน้ำหนัก พร้อมลิงก์ไปหน้าที่แก้เรื่องนั้นได้จริง
>
> พิสูจน์ว่าวงจรปิดครบบน container จริง: ตรวจได้ 62 → แก้ตามคำแนะนำผ่านหน้า SSH ของ panel เอง
> → ตรวจใหม่ได้ 69 และรายการที่ต้องทำลดจาก 3 เหลือ 2 ข้อ
>
> บั๊กร้ายแรงที่เจอและแก้แล้ว:
> - **ไฟล์สำรองนิรภัยเขียนทับไฟล์ต้นฉบับที่กำลังจะกู้คืน** — ชื่อไฟล์ใช้เวลาระดับวินาที
>   ทั้งสองไฟล์จึงชนกันเมื่อเกิดในวินาทีเดียวกัน ผลคือ restore ไปกู้ "สถานะที่พังแล้ว"
>   กลับมาแทนของเดิม โดยรายงานว่าสำเร็จ แก้ด้วยการเติมค่าสุ่มท้ายชื่อไฟล์
>   และตรวจ checksum ซ้ำอีกครั้งก่อนแตกไฟล์
> - **ลบไฟล์นอกไดเรกทอรีสำรองได้ด้วย ..** — `/var/lib/phpcp/backups/../panel.db`
>   ผ่านการเทียบ prefix แบบสตริง ทั้งที่ชี้ไปยังไฟล์ฐานข้อมูลของ panel เอง
>   (เทสต์ที่เขียนใหม่เป็นตัวจับได้)
> - **โหมดทดสอบจะไปสั่ง ufw จริงของเครื่อง** — `/usr/sbin` อยู่ในรายการ passthrough
>   ของ `SandboxExecutor` (เพราะเป็นที่อยู่ของ binary) คำสั่ง `ufw --force enable`
>   ในโหมดทดสอบจึงเปิด firewall จริงและอาจตัดการเชื่อมต่อของคนที่กำลังทดสอบอยู่
>   เป็นบั๊กชนิดเดียวกับที่เคยเจอกับ MariaDB แก้ด้วย `UfwSimulator`
> - **พอร์ต 0 รอดการตรวจช่วง** — `array_filter` ทิ้งสตริง `'0'` เพราะเป็นค่า falsy ของ PHP
>   การตรวจ 1–65535 จึงถูกข้ามไปเงียบ ๆ แก้ด้วยการเทียบกับ `''` ตรง ๆ แทน
> - **ศูนย์ความปลอดภัยรายงานการตั้งค่าที่ถูกต้องว่าผิด** — ตรวจสิทธิ์ไฟล์โดยเทียบกับ
>   `0600` ตรง ๆ ทำให้ `config.php` ที่ตัวติดตั้งตั้งเป็น `root:phpcp 0640` โดยเจตนา
>   (web tier อ่านได้แต่เขียนไม่ได้และไม่ใช่เจ้าของ ซึ่งแข็งแรงกว่า 0600) ถูกนับว่าไม่ผ่าน
>   คะแนนที่รายงานของที่ถูกว่าผิดจะไม่มีใครเชื่ออีกเลย แก้เป็นตรวจ "คุณสมบัติ" แทน:
>   ผู้ใช้อื่นต้องแตะไม่ได้ และกลุ่มต้องเขียนไม่ได้
> - **ทั้ง Apache และ FPM สั่ง reload แล้วไม่ดูรหัสออก** — panel รายงานว่า
>   "สร้างเว็บไซต์เรียบร้อยแล้ว" ทั้งที่ FPM ยังไม่ได้สร้าง socket ของ pool ใหม่
>   เว็บจึงตอบ 503 ทุกคำขอโดยผู้ใช้ไม่มีทางรู้ว่าต้องไป reload เอง
> - **ไฟล์กับฐานข้อมูลไม่ตรงกันเมื่อ reload ล้ม** — บันทึกฐานข้อมูลอยู่ "หลัง" reload
>   พอ reload โยน error ออกไป ไฟล์บนดิสก์เป็นค่าใหม่แต่ฐานข้อมูลยังเป็นค่าเก่า
>   แก้เป็น commit ไฟล์ → บันทึกฐานข้อมูล → reload (กระทบ `ssl.set_mode` และ `site.set_php`)
> - **เว็บไซต์ทุกเว็บตอบ 403 กับไฟล์สแตติก** — `chown -R <ผู้ใช้>:<ผู้ใช้>` คู่กับ mode 0750
>   ทำให้ Apache เดินผ่านไดเรกทอรีของเว็บไซต์ไม่ได้เลย รวมถึงไฟล์ตรวจสอบของ Let's Encrypt
>   ซึ่งทำให้ต่ออายุใบรับรองไม่ได้ด้วย แก้เป็น `<ผู้ใช้>:<กลุ่มของเว็บเซิร์ฟเวอร์>`
> - **vhost ที่สร้างขึ้นใช้ directive ที่โมดูลยังไม่ได้เปิด** — `Header`, `RewriteEngine`
>   และ `SSLEngine` ทำให้ configtest ล้มทั้งเครื่องบน Apache ที่ยังไม่ได้เปิดโมดูลเหล่านั้น
>   เพิ่ม `ensureModules()` ที่เปิดให้ก่อนเขียนไฟล์
> - **ใบรับรองที่เซ็นเองเป็นใบ CA** — `openssl req -x509` ตั้ง `CA:TRUE` ให้เองถ้าไม่ระบุ
>   Apache เตือน AH01906 และเบราว์เซอร์รุ่นใหม่ปฏิเสธใบนั้นทันที
> - **หน้า Firewall แสดง "ไม่มีกฎ" ตอนที่ firewall ปิดอยู่** — `ufw status` ไม่พิมพ์กฎเลย
>   เมื่อ firewall ปิด ทั้งที่กฎยังถูกเก็บไว้ครบ ผลคือหน้าจอโกหกในจังหวะที่ผู้ดูแล
>   ต้องการตรวจกฎมากที่สุด คือก่อนกดเปิดใช้งาน แก้ด้วยการอ่านจาก `ufw show added` แทน
>   และเปิดให้เพิ่ม/ลบกฎได้ตั้งแต่ตอนที่ firewall ยังปิดอยู่

### หลังเฟส 5 — ส่วนที่เพิ่มตามที่ผู้ใช้ขอ

> **สถานะ: เสร็จแล้ว** — capability 55 ตัว · เส้นทาง 84 เส้น · ทดสอบผ่าน 188/188
>
> - **ตัด Adminer ออกจากตัวติดตั้ง** — ตัวติดตั้งเคยดาวน์โหลดไฟล์ PHP จากอินเทอร์เน็ต
>   มาวางในเว็บรูทของ panel โดยไม่ตรวจ checksum หรือลายเซ็น ซึ่งเป็นความเสี่ยง
>   supply-chain ชนิดเดียวกับที่ `Updater` ออกแบบมาป้องกัน — ไฟล์ที่ถูกสับเปลี่ยน
>   ระหว่างทางจะรันด้วยสิทธิ์ของ panel ทันที ตัดออกได้เพราะ phpMyAdmin ทำงานนี้อยู่แล้ว
> - **หน้าการตั้งค่า** (`/server/settings`) + `SettingsRepository` ที่แยกจาก `config.php`
>   โดยเจตนา — ถ้าให้หน้าเว็บเขียน `config.php` ได้ ช่องโหว่เดียวในหน้าตั้งค่า
>   จะกลายเป็นการรันโค้ดทันที เพราะเป็นไฟล์ PHP ที่ถูก include ตอนบูต
> - **แจ้งเตือนผ่าน Telegram** — `TelegramNotifier` + `Notifier` (ตัดสินใจว่าเรื่องไหนควรแจ้ง)
>   เชื่อมที่ `Dispatcher` จุดเดียว แจ้งเฉพาะ 12 คำสั่งที่คัดไว้ ไม่ใช่ทุกคำสั่งที่เปลี่ยนระบบ
> - **`site.reset_owner`** — ซ่อมเจ้าของไฟล์ทีละเว็บไซต์ ไม่มีปุ่ม "ซ่อมทุกเว็บพร้อมกัน"
>   เพราะ `chown` ที่ครอบคลุมทั้งเครื่องคือคำสั่งที่พังแล้วกู้ยากที่สุดคำสั่งหนึ่ง
> - **เมลขาออกผ่าน Postfix** — โหมด local และ relay เท่านั้น ตั้ง `inet_interfaces = loopback-only`
>   เสมอ จึงไม่มีทางกลายเป็น open relay (พิสูจน์บน container จริง: ฟังเฉพาะ 127.0.0.1 และ ::1)
>
> **ตั้งใจไม่ทำ: เมลเซิร์ฟเวอร์เต็มรูปแบบ** — การรับเมลเข้าต้องมี Dovecot ระบบผู้ใช้เมล
> โควตา ตัวกรองสแปม ตัวสแกนไวรัส webmail และการสำรองกล่องจดหมาย ที่สำคัญกว่านั้น
> คือต้องมีคนดูแลชื่อเสียงของไอพีตลอดเวลา ถ้า SPF/DKIM/DMARC/rDNS ไม่ครบ เมลจะเข้า
> ถังขยะทั้งหมด และถ้าตั้ง relay ผิดจนเป็น open relay ไอพีจะติดบัญชีดำถาวร
> ซึ่งกระทบ *ทุกเว็บไซต์* บนเครื่องเดียวกัน — งานชุดนั้นใหญ่พอที่จะเป็นผลิตภัณฑ์แยกต่างหาก

---

### เฟส 5 — ทำให้พร้อมแจกจ่าย

- `NginxDriver`
- `phpcp self-update` + การเซ็นลายเซ็น release
- ทดสอบติดตั้งสะอาดบน Ubuntu 22.04 / 24.04 / Debian 12
- ปรับแต่งประสิทธิภาพให้เข้าเป้าใน [ARCHITECTURE §14](ARCHITECTURE.md#14-ประสิทธิภาพ)
- เอกสารติดตั้งและคู่มือผู้ใช้ภาษาไทย

**เกณฑ์รับงาน:** ติดตั้งบน VM เปล่าเสร็จใน 1 คำสั่ง แล้วสร้างเว็บไซต์ที่เปิดใช้งานได้จริงพร้อม SSL

> **สถานะ: เสร็จแล้ว** — capability 49 ตัว · เส้นทาง 79 เส้น · ทดสอบผ่าน 171/171
>
> **เกณฑ์รับงานผ่านแล้วบนทั้งสามระบบ** — `docker/Dockerfile.install-test` รัน `install.sh`
> ตัวจริงบนอิมเมจเปล่า ถ้าตัวติดตั้งพัง build จะล้มทันที จากนั้น `docker/verify-install.sh`
> ตรวจผลลัพธ์จากภายนอก 23 ข้อ และ `docker/acceptance.sh` สร้างเว็บไซต์จริงพร้อม SSL อีก 17 ข้อ
>
> | ระบบ | ติดตั้ง | เกณฑ์รับงาน |
> |---|---|---|
> | Debian 12 | 23/23 | 17/17 |
> | Ubuntu 24.04 | 23/23 | 17/17 |
> | Ubuntu 22.04 | 23/23 | 17/17 (ต้องเพิ่ม ppa:ondrej/php) |
>
> ที่ทำเสร็จ: `NginxDriver` + เทมเพลต 4 ไฟล์ (ตรวจด้วย `nginx -t` ตัวจริง),
> `Updater` + `tools/sign-release.php` (ลายเซ็น Ed25519), `phpcp self-update`,
> คีย์ `webserver` ใน config, [INSTALL.md](INSTALL.md), [USER-GUIDE.md](USER-GUIDE.md)
>
> บั๊กร้ายแรงที่เจอและแก้แล้ว:
> - **ตัวติดตั้งรับ PHP 8.1 ทั้งที่โค้ดต้องการ 8.2** — โค้ดใช้ `readonly class` ซึ่งมีตั้งแต่ 8.2
>   ผลคือบน Ubuntu 22.04 ตัวติดตั้งจบด้วยรหัส 0 รายงานว่าสำเร็จทุกขั้น แล้ว panel
>   ตายด้วย parse error ตอนใช้งานครั้งแรก ผู้ติดตั้งไม่มีทางรู้ล่วงหน้าเลย
> - **คำสั่งกู้ระบบใช้ไม่ได้ในจังหวะที่ต้องใช้ที่สุด** — บน Ubuntu 22.04 ที่เพิ่ม PPA แล้ว
>   ตัวติดตั้งเลือก php8.4 มาใช้อย่างถูกต้อง แต่ `php` ที่ผู้ดูแลพิมพ์เองยังชี้ไปที่ 8.1
>   `phpcp user:passwd` จึงตายด้วย parse error แก้ด้วยด่านตรวจเวอร์ชันที่หัวไฟล์
>   ซึ่งหาไบนารีที่ใหม่พอแล้วเรียกตัวเองซ้ำให้เอง
> - **เทมเพลต nginx ใช้ไวยากรณ์ที่ nginx ทุกรุ่นที่รองรับอ่านไม่ออก** — `http2 on;`
>   แบบแยกบรรทัดมีตั้งแต่ 1.25.1 ขณะที่ Ubuntu 22.04 ส่ง 1.18, Debian 12 ส่ง 1.22
>   และ Ubuntu 24.04 ส่ง 1.24 — nginx จะไม่ยอมโหลด config เลยทุกเครื่อง (เจอตอนรัน `nginx -t` จริง)

---

### เฟส 6 — ใช้ควบคุมเครื่องที่มีเว็บอยู่แล้ว

เฟส 0–5 ตั้งอยู่บนสมมติฐานว่า panel เป็นเจ้าของเครื่องตั้งแต่ต้น — เว็บทุกเว็บถูกสร้างผ่าน panel
และไฟล์ทั้งหมดอยู่ใต้ `/srv/phpcp/sites` เฟสนี้แก้สมมติฐานนั้น เพื่อให้เอา panel ไปครอบ
เครื่องที่ตั้ง Apache ด้วยมือไว้แล้วได้ โดยไม่ต้องย้ายไฟล์และไม่ต้องสร้างเว็บใหม่ทั้งหมด

> **สถานะ: เสร็จแล้ว** — ทดสอบผ่าน 201/201
>
> - **`sites.dir` ตั้งค่าได้** — เดิมเส้นทางถูกฝังไว้ในโค้ดหลายจุด ตอนนี้อยู่ที่
>   `Paths::sitesDir()` จุดเดียว ตรวจตั้งแต่ตอนอ่าน config ว่าเป็นเส้นทางสัมบูรณ์และไม่มี `..`
>   เพราะค่านี้กลายเป็น `DocumentRoot` และ `open_basedir` ของทุกเว็บ
> - **Domain Pointer** (`sites.pointer_roots` + คอลัมน์ `docroot_override`) — ชี้โดเมนไปยัง
>   โฟลเดอร์โปรเจกต์ที่มีอยู่แล้วได้ โดยที่ log, tmp และ backup ยังแยกตามเว็บเหมือนเดิม
>   ชี้ได้เฉพาะใต้โฟลเดอร์ที่ระบุไว้ใน config เท่านั้น — ถ้าไม่ตั้งค่านี้จะชี้ออกนอก `sites.dir` ไม่ได้เลย
>   เพราะ DocumentRoot ที่กรอกได้อิสระเท่ากับปุ่ม "อ่านไฟล์ไหนก็ได้ในเครื่องผ่านเว็บ"
> - **`sites.shared_owner` แบบ fail-closed** — สำหรับเครื่องที่เก็บเว็บบน NTFS/exFAT
>   ซึ่งเก็บ uid/gid ไม่ได้ agent จะ **ทดสอบ `chown` จริง** ก่อนใช้ทุกครั้ง ถ้า filesystem
>   รองรับ ownership อยู่แล้วมันจะ *ปฏิเสธไม่ทำงาน* แทนที่จะเงียบ ๆ ยอมทำงานต่อ
>   ตั้งใจให้เป็นแบบนี้เพราะโหมดนี้ = เว็บอ่านไฟล์ของกันและกันได้ ถ้าหลุดขึ้น server จริง
>   โดยไม่มีใครรู้ จะเป็นการลดความปลอดภัยที่มองไม่เห็น (ยังเหลือ `open_basedir` และ
>   `disable_functions` ของแต่ละ FPM pool กันอยู่)
> - **`tools/migrate-host.sh`** — ย้ายเครื่องที่ใช้ mod_php มาเป็น FPM: สำรอง `/etc/apache2` ก่อน,
>   เปิด `proxy_fcgi`, ปิด mod_php, สลับ prefork → event และ **วาง handler สำรองไว้ให้ vhost เดิม**
>   ก่อนปิด mod_php — ถ้าลืมขั้นนี้ Apache จะส่งไฟล์ `.php` เป็นข้อความธรรมดา
>   ซึ่งแปลว่า source code ทั้งเครื่อง (รวมรหัสผ่านฐานข้อมูล) รั่วออกเว็บทันที
>   มี `--check` ที่แสดงทุกคำสั่งโดยไม่แก้อะไรเลย

**ที่ยังไม่ทำ — subdomain แบบ wildcard** (`*.example.com`)

เตรียมข้อสรุปการออกแบบไว้ก่อน ยังไม่ลงมือ:

| เรื่อง | ข้อสรุป |
|---|---|
| ฐานข้อมูล | `domains.type` มี `primary/subdomain/alias/redirect` อยู่แล้ว — เพิ่ม `wildcard` เป็นค่าที่ห้า ไม่ต้องมีตารางใหม่ |
| เทมเพลต | Apache: `ServerAlias *.example.com` · nginx: `server_name .example.com` — แก้เทมเพลตอย่างเดียว ไม่แตะ driver |
| ลำดับความสำคัญ | vhost ที่ระบุชื่อเต็มต้องชนะ wildcard เสมอ Apache เลือก vhost ตามลำดับไฟล์ ชื่อไฟล์ของ wildcard จึงต้องเรียงท้ายสุด (เช่น `phpcp-zz-wildcard-<domain>.conf`) — ถ้าพลาดข้อนี้ เว็บลูกที่ตั้งใจแยกไว้จะถูก wildcard กลืนไปเงียบ ๆ |
| **TLS — ข้อจำกัดหลัก** | Let's Encrypt ออกใบรับรอง wildcard ผ่าน **DNS-01 เท่านั้น** HTTP-01 ทำไม่ได้ แปลว่าต้องเก็บ credential ของผู้ให้บริการ DNS ไว้ในเครื่อง หรือให้ผู้ใช้วาง TXT record เองทุก 60 วัน ทั้งสองทางแย่คนละแบบ — ต้องตอบข้อนี้ให้ได้ก่อนเริ่มเขียนโค้ด |
| ตั้งใจไม่ทำ | `VirtualDocumentRoot` / `mod_vhost_alias` (แปลงชื่อ subdomain เป็นเส้นทางโฟลเดอร์อัตโนมัติ) — ทำแล้วทุก subdomain ใช้ FPM pool เดียวกันและ `open_basedir` ต้องกว้างพอครอบทุกโฟลเดอร์ เท่ากับยกเลิกการแยกสิทธิ์ต่อเว็บซึ่งเป็นแกนของสถาปัตยกรรมนี้ |
| ความเสี่ยงที่ต้องเขียนไว้ในเอกสาร | wildcard = ชื่อ subdomain ที่ไม่มีใครจดก็เข้าเว็บนี้ได้ แอปที่เชื่อค่า `Host` โดยไม่ตรวจ (สร้างลิงก์รีเซ็ตรหัสผ่าน, ตั้ง cookie domain) จะโดนโจมตีผ่านทางนี้ |

---

## 3. ลำดับที่แนะนำให้เริ่ม

3 อย่างแรกที่ควรเขียน เพราะทุกอย่างที่เหลือขึ้นกับมัน และผิดแล้วแก้ทีหลังแพงที่สุด:

1. **`Executor` interface + 3 implementation** — ถ้าไม่ทำก่อน จะมีโค้ดเรียก shell กระจัดกระจายจนทำโหมด sandbox ไม่ได้อีกเลย
2. **`CapabilityRegistry` + `SelfProtection` + capability ตัวอย่าง 2 ตัว** — วางแบบแผนให้อีก 58 ตัวลอกตาม
3. **Protocol ระหว่างชั้น 1 ↔ ชั้น 2** — เปลี่ยนทีหลังกระทบทุกไฟล์

---

## 4. สิ่งที่ทำกับ `index.html` เดิม

prototype เดิมมีประโยชน์เป็นข้อมูลอ้างอิงด้าน UI: โครงเมนู ชื่อหน้าไทย breadcrumb และสไตล์ terminal ของ log viewer นำมาใช้ได้เลย

แต่**ใช้เป็นฐานโค้ดต่อไม่ได้** ด้วยเหตุผลใน [ARCHITECTURE §9.1](ARCHITECTURE.md#91-สิ่งที่ตัดออกจาก-prototype-เดิม-และเหตุผล) — สรุปสั้น ๆ: Tailwind CDN เป็น build สำหรับ dev เท่านั้น, `onclick=` inline ทำให้เปิด CSP เข้มไม่ได้, และการยัด 17 หน้าไว้ในไฟล์เดียวขัดกับ server-rendered

แผน: ย้ายไป `_reference/index.html` แล้วดึงเฉพาะ 4 อย่างมาใช้ — โครงสร้างเมนูและคำแปลไทย, ลำดับชั้น breadcrumb, ชุดสี slate/indigo (แปลงเป็น CSS custom property), และสไตล์ terminal ของ log viewer

---

## 5. ประมาณการขนาดงาน

| เฟส | ไฟล์ใหม่โดยประมาณ | บรรทัด (ไม่รวม view) | หมายเหตุ |
|---|---|---|---|
| 0 | ~45 | ~3,500 | หนักที่สุด เป็นรากฐานทั้งหมด |
| 1 | ~25 | ~2,000 | |
| 2 | ~35 | ~3,000 | Apache driver + logic สร้างเว็บไซต์ |
| 3 | ~30 | ~2,800 | file manager กินเวลามากกว่าที่คิดเสมอ |
| 4 | ~30 | ~2,500 | |
| 5 | ~15 | ~1,200 | |
| **รวม** | **~180** | **~15,000** | + view/CSS/JS อีกราว 6,000 |

ขนาดนี้เทียบเคียงได้กับ control panel โอเพนซอร์สขนาดกลาง และยังอยู่ในระดับที่คนเดียวอ่านโค้ดทั้งหมดได้ — ซึ่งเป็นคุณสมบัติด้านความปลอดภัยในตัวมันเอง

---

## 6. คำถามที่ยังเปิดอยู่ (ตอบตอนเริ่มเฟสที่เกี่ยวข้อง)

| # | คำถาม | ต้องตอบก่อนเฟส | ค่าเริ่มต้นถ้าไม่ตอบ |
|---|---|---|---|
| Q1 | DNS records — จัดการ zone file ของ bind/PowerDNS บนเครื่อง หรือเก็บไว้ให้ผู้ใช้ไปกรอกที่ผู้ให้บริการ DNS เอง | 2 | เก็บใน panel + ส่งออกเป็น zone file ให้ ไม่ยุ่งกับ DNS server |
| Q2 | โควตาพื้นที่ต่อเว็บไซต์ — บังคับจริงด้วย filesystem quota หรือแค่แสดงและเตือน | 2 | แสดงและเตือน (quota จริงต้องตั้งที่ mount option) |
| Q3 | ปลายทางของ backup — เก็บในเครื่องอย่างเดียว หรือรองรับ S3/rsync ปลายทางนอก | 4 | ในเครื่อง + ดาวน์โหลดเอง |
| Q4 | รองรับ RHEL/Alma/Rocky หรือไม่ | 5 | ไม่ใน v1 |
