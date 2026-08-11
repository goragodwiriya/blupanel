# คู่มือติดตั้ง

PHP Server Control Panel — แผงควบคุมเซิร์ฟเวอร์ที่เขียนด้วย PHP ล้วน ไม่มี Composer ไม่มี npm

---

## 1. ก่อนเริ่ม

### ระบบที่รองรับ

| ระบบปฏิบัติการ | สถานะ | หมายเหตุ |
|---|---|---|
| Debian 12 (bookworm) | ทดสอบแล้ว | PHP 8.2 ที่มากับระบบใช้ได้เลย |
| Ubuntu 24.04 LTS | ทดสอบแล้ว | PHP 8.3 ที่มากับระบบใช้ได้เลย |
| Ubuntu 22.04 LTS | ทดสอบแล้ว | **ต้องเพิ่ม `ppa:ondrej/php` ก่อน** — PHP 8.1 ที่มากับระบบเก่าเกินไป |
| Linux Mint 21+ | น่าจะใช้ได้ | ฐานเดียวกับ Ubuntu 22.04 จึงต้องเพิ่ม PPA เหมือนกัน |
| อื่น ๆ | ยังไม่รองรับ | ตัวติดตั้งจะหยุดและแนะนำให้ใช้ `--mode=sandbox` ทดลองแทน |

### ข้อกำหนดขั้นต่ำ

- **PHP 8.2 ขึ้นไป** — ต่ำกว่านี้ใช้ไม่ได้ เพราะโค้ดใช้ `readonly class` ซึ่งมีตั้งแต่ 8.2
- ส่วนขยาย PHP: `pdo_sqlite` `sqlite3` `sodium` `posix` `pcntl` `sockets` `openssl` `mbstring` `json` `filter` `fileinfo` `curl` `zip`
- Apache 2.4 (หรือ nginx — ดู §6)
- สิทธิ์ root
- RAM 512 MB ขึ้นไป · พื้นที่ว่าง 1 GB ขึ้นไป

### เตรียมแพ็กเกจ

**Debian 12 / Ubuntu 24.04**

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server certbot ufw \
    php-cli php-fpm php-sqlite3 php-mbstring php-curl php-zip php-mysql
```

**Ubuntu 22.04 / Linux Mint 21** — ต้องเพิ่มแหล่งแพ็กเกจของ PHP ก่อน

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y apache2 mariadb-server certbot ufw \
    php8.3-cli php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-curl php8.3-zip php8.3-mysql
```

---

## 2. ติดตั้ง

```bash
sudo ./install.sh
```

เท่านี้ ตัวติดตั้งจะจัดการทุกอย่างเอง แล้วพิมพ์รหัสผ่านชั่วคราวของผู้ดูแลออกมาตอนจบ
**คัดลอกเก็บไว้ทันที เพราะจะไม่แสดงอีก**

ตัวเลือกที่มี:

```bash
sudo ./install.sh --port=9443          # เปลี่ยนพอร์ตของหน้าจัดการ (ค่าเริ่มต้น 8443)
sudo ./install.sh --user=somchai       # เปลี่ยนชื่อผู้ดูแลคนแรก (ค่าเริ่มต้น admin)
sudo ./install.sh --mode=sandbox       # ติดตั้งเพื่อทดลอง ไม่แตะระบบจริง
./install.sh --mode=sandbox --portable # ทดลองในโฟลเดอร์โปรเจกต์ ไม่ต้องใช้ root
```

เปิดใช้งาน DNS ไปพร้อมกันได้เลยถ้ารู้ชื่อ nameserver ของตัวเองแล้ว:

```bash
sudo ./install.sh --dns-ns=ns1.example.com,ns2.example.com \
                  --dns-email=hostmaster@example.com
```

ไม่ใส่ `--dns-ns` = ติดตั้ง BIND9 ไว้ให้แต่ยังไม่เชื่อม (`dns.enabled = false`) เพราะการเชื่อม
แปลว่า panel จะเขียนทับ `named.conf.local` ทั้งไฟล์ — โซนที่ตั้งเองไว้ก่อนจะหายไป

ปิดสิ่งที่ตัวติดตั้งทำให้เป็นรายข้อได้ เมื่อเครื่องมีของพวกนี้อยู่แล้วหรือดูแลด้วยวิธีอื่น:

| ตัวเลือก | ผล |
|---|---|
| `--no-postfix` | ไม่ติดตั้ง Postfix (ตรวจให้อยู่แล้วว่ามี MTA อื่นหรือไม่) |
| `--no-logrotate` | ไม่เขียน `/etc/logrotate.d/phpcp` |
| `--no-check` | ข้าม `phpcp doctor` ตอนจบ |
| `--smoke-user=U --smoke-password-file=P` | ยิงทุก endpoint ใส่เครื่องจริงตอนจบ · ใช้ได้เฉพาะบัญชีที่เปลี่ยนรหัสผ่านครั้งแรกไปแล้ว จึงเหมาะกับการติดตั้งซ้ำ ไม่ใช่ครั้งแรก |

### ตัวติดตั้งทำอะไรบ้าง

1. ตรวจระบบปฏิบัติการ เวอร์ชัน PHP และส่วนขยายที่ต้องมี
2. สร้างผู้ใช้ระบบ `phpcp-web` (ล็อกอินไม่ได้) และกลุ่ม `phpcp`
3. วางโค้ดที่ `/usr/share/phpcp` เป็นของ `root` และ**อ่านอย่างเดียว** — ผู้ใช้ของเว็บแก้ไม่ได้
4. สร้าง config tree ของ panel ที่ `/etc/phpcp` — **ไม่แตะ `/etc/apache2` และ `/etc/php` ของระบบเลยแม้แต่ไฟล์เดียว**
5. สร้างใบรับรอง TLS ชั่วคราวสำหรับหน้าจัดการ
6. ติดตั้ง systemd unit สามตัว: `phpcp-agentd` `phpcp-fpm` `phpcp-web`
7. สร้างฐานข้อมูล SQLite และบัญชีผู้ดูแลคนแรก
8. ติดตั้งและตั้งค่า Postfix ให้ส่งอีเมลแจ้งเตือนออกได้ (ข้ามถ้าเครื่องมี MTA อื่นอยู่แล้ว)
9. ตั้งกฎ firewall — **อ่านพอร์ต SSH จริงจาก `sshd -T` ก่อนเปิด ufw** ไม่ได้เปิดแค่ 22 ตายตัว
   จึงไม่ตัดขาดเครื่องที่ย้าย SSH ไปพอร์ตอื่น · เปิด 53, 80, 443 และพอร์ตของหน้าจัดการด้วย
10. เขียนกฎหมุน log ที่ `/etc/logrotate.d/phpcp` — ทั่วไปทุกสัปดาห์เก็บ 8 รอบ ·
    `audit.log` ทุกเดือนเก็บ 24 รอบ เพราะเป็นหลักฐานคู่ขนานของตาราง `audit_log`
11. รัน `phpcp doctor` **ในฐานะผู้ใช้ที่ panel รันจริง** แล้วรายงานผลออกมา — ตัวติดตั้ง
    ที่จบด้วยรหัส 0 ไม่ได้แปลว่าระบบใช้งานได้ ต้องเห็นผลตรวจจากมุมของผู้ใช้นั้นด้วย

### หลังติดตั้งเสร็จ

```bash
phpcp status     # ตรวจว่าทุกส่วนทำงาน
phpcp doctor     # ตรวจการตั้งค่าและสิทธิ์ที่ผิดพลาด
```

เปิดเบราว์เซอร์ไปที่ `https://<ไอพีของเซิร์ฟเวอร์>:8443`

เบราว์เซอร์จะเตือนเรื่องใบรับรอง เพราะเป็นใบที่เซ็นเอง — **ถูกต้องแล้ว**
เปลี่ยนเป็นใบจริงจาก Let's Encrypt ได้ทีหลังในหน้า SSL Certificates

---

## 3. สิ่งที่ต้องทำทันทีหลังติดตั้ง

เรียงตามความสำคัญ:

1. **เปลี่ยนรหัสผ่าน** — ระบบบังคับตั้งแต่เข้าครั้งแรกอยู่แล้ว
2. **เปิด 2FA** ที่หน้าบัญชีของคุณ — รหัสผ่านอย่างเดียวกันการสวมสิทธิ์ไม่ได้
3. **เปิดหน้าความปลอดภัย** (`/server/security`) แล้วไล่แก้ตามรายการที่ขึ้น
4. **จำกัด IP ที่เข้าหน้าจัดการได้** ใน `/etc/phpcp/config.php` คีย์ `panel.ip_allowlist`
   ถ้าเข้าจากที่เดียวเป็นประจำ
5. อ่าน [SECURITY.md](SECURITY.md) §5 รายการตรวจก่อนขึ้นใช้งานจริง

---

## 4. โครงสร้างหลังติดตั้ง

```
/usr/share/phpcp/        โค้ด — root:root อ่านอย่างเดียว
/etc/phpcp/              ค่าตั้งของ panel — แยกจาก /etc/apache2 โดยสิ้นเชิง
  config.php             ค่าตั้งหลัก (root:phpcp 0640 — web tier อ่านได้แต่เขียนไม่ได้)
  httpd/  fpm/           runtime ของหน้าจัดการเอง
  tls/                   ใบรับรองของหน้าจัดการ
/var/lib/phpcp/          ฐานข้อมูล SQLite และไฟล์สำรอง
/var/log/phpcp/          log ของ panel
/srv/phpcp/sites/        ไฟล์ของเว็บไซต์ที่สร้างผ่าน panel
/run/phpcp/              socket ของ agent
```

**ทำไม panel ถึงรัน Apache กับ PHP-FPM ของตัวเองแยกต่างหาก**
เพื่อให้สั่ง `restart apache2` ของระบบจากหน้าเว็บได้โดยหน้าเว็บไม่ดับตามไปด้วย
ถ้าใช้ตัวเดียวกัน การรีสตาร์ตเว็บเซิร์ฟเวอร์ก็คือการตัดขาตัวเอง

---

## 5. อัปเดต

```bash
phpcp self-update --check --manifest=https://<แหล่งที่เชื่อถือได้>/latest.json
```

ระบบ**ตรวจลายเซ็น Ed25519 ก่อนแตะไฟล์เสมอ** และปฏิเสธ:

- แพ็กเกจที่ไม่มีลายเซ็นหรือลายเซ็นไม่ตรง
- แพ็กเกจที่เวอร์ชันเก่ากว่าที่ติดตั้งอยู่ (กันการถูกย้อนไปเวอร์ชันที่มีช่องโหว่)
- ที่อยู่ที่ไม่ใช่ `https://`

ถ้า build ที่ใช้อยู่ไม่ได้ฝังกุญแจสาธารณะไว้ `self-update` จะถูกปิดทั้งหมด —
เป็นค่าเริ่มต้นที่ตั้งใจ ไม่ใช่ข้อผิดพลาด ให้อัปเดตด้วยการดาวน์โหลดจากแหล่งที่เชื่อถือได้
แล้วรัน `./install.sh` ซ้ำแทน (ตัวติดตั้งไม่เขียนทับ `config.php` และฐานข้อมูล)

---

## 6. ใช้ nginx แทน Apache

แก้ `/etc/phpcp/config.php`:

```php
'webserver' => 'nginx',
```

**ต้องรู้ก่อนเปลี่ยน:** nginx ไม่มี `.htaccess` เว็บไซต์ที่พึ่งไฟล์นั้น
(WordPress, Laravel และอีกมาก) จะทำงานไม่เหมือนเดิมจนกว่าจะแปลงกฎเป็นรูปแบบของ nginx เอง
ระบบสร้างกฎพื้นฐานให้แล้ว (rewrite เข้า `index.php`, กันไฟล์ลับ, กัน path info ปลอมของ fastcgi)
แต่กฎเฉพาะของแต่ละเว็บต้องเขียนเพิ่มเอง

### ต้องการ nginx **และ** .htaccess พร้อมกัน

```php
'webserver' => 'nginx-proxy',
```

nginx รับพอร์ต 80/443 จบ TLS แล้วส่งต่อทุกคำขอให้ Apache ที่ `127.0.0.1:8080`
ซึ่งอ่าน `.htaccess` ให้ตามปกติ — เว็บที่ย้ายมาจากโฮสต์อื่นใช้งานได้ทันทีโดยไม่ต้องแปลงกฎ

```
ผู้ใช้ ──► nginx :80/:443 ──► Apache 127.0.0.1:8080 ──► PHP-FPM (uid ของลูกค้า)
          จบ TLS ที่นี่        อ่าน .htaccess ที่นี่
```

สิ่งที่ต้องรู้:

- **ต้องรีสตาร์ต Apache หนึ่งครั้งหลังเปลี่ยนโหมด** ไม่ใช่แค่ reload — โหมดนี้เขียนทับ
  `/etc/apache2/ports.conf` ให้ฟังเฉพาะ loopback และ Apache เปลี่ยนพอร์ตที่ฟังตอน reload ไม่ได้

  ```bash
  sudo phpcp sites:rebuild
  sudo systemctl restart apache2 && sudo systemctl reload nginx
  ```

- **nginx ไม่เสิร์ฟไฟล์ static เอง** ทั้งที่เร็วกว่า — เพราะ `.htaccess` คุมไฟล์ static ด้วย
  (กันโฟลเดอร์, auth, redirect) ถ้าให้ nginx ตอบเองกฎเหล่านั้นจะถูกข้ามเงียบ ๆ
  ซึ่งเป็นช่องโหว่ที่เกิดจากตัว panel เอง · สิ่งที่ยังได้จาก nginx คือจบ TLS,
  รับผู้ใช้เน็ตช้าแทน Apache, จำกัดขนาด body และเป็นจุดเดียวที่คุม rate limit
- ที่อยู่ผู้ใช้จริงส่งถึง Apache ผ่าน `X-Forwarded-For` + `mod_remoteip` (เชื่อเฉพาะ
  127.0.0.1) — log และ fail2ban จึงยังเห็นไอพีจริง ไม่ใช่ 127.0.0.1 ทุกบรรทัด
- ใช้ RAM มากกว่าโหมดอื่นเพราะรันสองตัว

---

## 7. แก้ปัญหาที่พบบ่อย

**เข้าหน้าจัดการไม่ได้**

```bash
systemctl status phpcp-web phpcp-fpm phpcp-agentd
journalctl -u phpcp-web -n 50
ss -tlnp | grep 8443          # มีอะไรฟังพอร์ตอยู่ไหม
ufw status                    # firewall เปิดพอร์ตไว้ไหม
```

**ลืมรหัสผ่าน / 2FA หาย** — กู้จากบรรทัดคำสั่งได้เสมอ

```bash
sudo phpcp user:passwd admin '<รหัสผ่านใหม่>'
sudo phpcp user:disable-2fa admin
```

**เว็บไซต์ที่สร้างตอบ 503** — แปลว่า FPM pool ยังไม่ทำงาน

```bash
systemctl status php8.3-fpm       # เปลี่ยนเลขเวอร์ชันตามที่เว็บนั้นใช้
php-fpm8.3 -t                     # ตรวจไฟล์ค่าตั้ง
```

**เว็บไซต์ตอบ 403 กับไฟล์รูปหรือ CSS** — ตรวจว่าเจ้าของไฟล์ถูกต้อง

```bash
ls -ld /srv/phpcp/sites/<โดเมน>/public
# ต้องเป็น web_<id>:www-data — ถ้ากลุ่มไม่ใช่ www-data เว็บเซิร์ฟเวอร์จะอ่านไม่ได้
```

**ขอใบรับรอง Let's Encrypt ไม่ผ่าน** — เกือบทุกครั้งเป็นสองสาเหตุนี้

- โดเมนยังไม่ได้ชี้มาที่ไอพีของเครื่องนี้ — ตรวจด้วย `dig +short <โดเมน>`
- พอร์ต 80 ถูกปิด — Let's Encrypt ต้องเรียกเข้ามาตรวจสอบทางพอร์ตนี้เท่านั้น

---

## 8. ถอนการติดตั้ง

```bash
sudo systemctl disable --now phpcp-web phpcp-fpm phpcp-agentd
sudo rm -f /etc/systemd/system/phpcp-*.service
sudo systemctl daemon-reload
sudo rm -rf /usr/share/phpcp /etc/phpcp /var/log/phpcp /run/phpcp
sudo rm -f /usr/local/bin/phpcp
```

**ยังไม่ลบให้:** `/var/lib/phpcp` (ฐานข้อมูลและไฟล์สำรอง) และ `/srv/phpcp/sites`
(ไฟล์เว็บไซต์ทั้งหมด) — ต้องลบเองถ้าแน่ใจจริง ๆ

ผู้ใช้ระบบของเว็บไซต์ (`web_1`, `web_2`, …) และฐานข้อมูล MariaDB ก็ยังอยู่เช่นกัน

---

## 9. คอนเทนเนอร์ทดสอบแบบ production (สำหรับนักพัฒนา)

คอนเทนเนอร์ `phpcp-prod-test` คือเครื่องทดสอบที่ติดตั้งด้วย `install.sh` โหมด production
จริง (Debian 12 + Apache + Nginx + BIND9 + MariaDB + PHP 7.4/8.4 + phpMyAdmin) ต่างจาก
`docker-compose.yml` ที่เป็นโหมด sandbox เบา ๆ

**สำคัญ:** คอนเทนเนอร์นี้ผูก source จาก repo เข้าไปแบบ read-only bind mount ทับ
`/usr/share/phpcp` เป็นรายโฟลเดอร์ — แก้ไฟล์ในเครื่องแล้วรีเฟรชหน้าเว็บเห็นผลทันที
ไม่ต้อง `docker cp` และไม่ต้อง restart (`opcache.validate_timestamps=On`,
`revalidate_freq=2` จึงหน่วงไม่เกิน ~2 วินาที)

```bash
docker run -d \
  --name phpcp-prod-test --hostname phpcp-prod-test \
  --cap-add NET_ADMIN --cap-add NET_RAW \
  -p 127.0.0.1:8443:8443 -p 127.0.0.1:8080:80 -p 127.0.0.1:8444:443 \
  -v "$PWD/src:/usr/share/phpcp/src:ro" \
  -v "$PWD/views:/usr/share/phpcp/views:ro" \
  -v "$PWD/templates:/usr/share/phpcp/templates:ro" \
  -v "$PWD/db:/usr/share/phpcp/db:ro" \
  -v "$PWD/bin:/usr/share/phpcp/bin:ro" \
  -v "$PWD/tests:/usr/share/phpcp/tests:ro" \
  -v "$PWD/docker:/usr/share/phpcp/docker:ro" \
  -v "$PWD/bootstrap.php:/usr/share/phpcp/bootstrap.php:ro" \
  -v "$PWD/install.sh:/usr/share/phpcp/install.sh:ro" \
  -v "$PWD/public/index.php:/usr/share/phpcp/public/index.php:ro" \
  -v "$PWD/public/assets:/usr/share/phpcp/public/assets:ro" \
  -v "$PWD/docker/dev-start.sh:/usr/local/bin/phpcp-dev-start:ro" \
  phpcp:full-prod-data /usr/local/bin/phpcp-dev-start
```

เหตุผลของรายละเอียดแต่ละข้อ:

| ข้อ | ทำไม |
| --- | --- |
| mount รายโฟลเดอร์ ไม่ mount `/usr/share/phpcp` ทั้งก้อน | ถ้าครอบทั้งก้อนจะบัง `public/phpmyadmin` (symlink ที่ตัวติดตั้งสร้าง) และ `etc/`, `var/` ที่คอนเทนเนอร์สร้างเอง |
| `:ro` | คอนเทนเนอร์รันเป็น root แต่ห้ามให้มันเขียนกลับมาที่ repo |
| `--cap-add NET_ADMIN NET_RAW` | ให้ `ufw` / `fail2ban` เขียนกฎ netfilter ได้ ไม่งั้นหน้า Firewall จะขึ้น `iptables-restore: Permission denied` |
| เปิดพอร์ต 80/443 ออกมาเป็น 8080/8444 | ไม่งั้น**เว็บไซต์ที่สร้างจาก panel จะเรียกจากเครื่องไม่ได้เลย** — เห็นแต่หน้า panel เท่านั้น (พอร์ต 80 ของเครื่องมักถูก Apache ตัวเดิมยึดอยู่แล้ว) |
| `docker/dev-start.sh` | คอนเทนเนอร์ไม่มี systemd จึงต้องไล่เรียก init script เอง สคริปต์นี้เป็นคำสั่งหลักของคอนเทนเนอร์ `docker start`/`restart` แล้วบริการขึ้นครบเอง |
| mount `docker/` ด้วย | `dev-start.sh` ติดตั้ง `docker/systemctl-shim.sh` ทับ `/usr/bin/systemctl` ตอนบูต |
| image `phpcp:full-prod-data` | snapshot (`docker commit`) ที่มีทั้งแพ็กเกจ ข้อมูล MariaDB และ `panel.db` อยู่ใน layer เขียนได้ของคอนเทนเนอร์เดิม |

### เรียกเว็บไซต์ที่สร้างจาก panel

1. ชี้ชื่อโดเมนมาที่ loopback ใน `/etc/hosts` ของเครื่อง เช่น `127.0.0.1  example.test`
2. เปิด `http://example.test:8080/` (ไม่ใช่พอร์ต 80 — พอร์ตนั้นเป็นของ Apache ตัวเดิมของเครื่อง)
3. โดเมนย่อย (`www.`, `one.`, …) ต้อง**เพิ่มในหน้ารายละเอียดเว็บไซต์ของ panel** ด้วย
   ไม่งั้น Apache จะตกไปที่ vhost แรก (`000-default`) แล้วขึ้นหน้า "Apache2 Debian Default Page"
4. เลี่ยงนามสกุล `.local` — สงวนไว้ให้ mDNS ตาม RFC 6762 ใช้ `.test` หรือ `.localhost` แทน

**ข้อจำกัดที่ยัง mount ไม่ช่วย:**

- แก้ `src/Agent/**` ต้อง `docker exec phpcp-prod-test pkill -f bin/phpcp-agentd` แล้วสตาร์ตใหม่ —
  `phpcp-agentd` เป็น daemon ที่โหลดคลาสไว้ตั้งแต่ตอนบูต
- แก้ `templates/**` ต้อง render ใหม่ (`phpcp setup` หรือกดจากหน้า panel) เพราะไฟล์จริง
  อยู่ที่ `/etc/phpcp/`
- แก้ `db/migrations/**` ต้องรัน migration ใหม่
- pool ของ panel ตั้ง `daemonize = no` (ออกแบบให้ systemd คุม) — ถ้าจะสตาร์ตมือ
  ต้องส่งไปเบื้องหลังเอง ไม่งั้นจะบล็อก terminal

**ทำไมต้องมี `docker/systemctl-shim.sh`:** panel สั่ง `systemctl reload php<ver>-fpm`
หลังสร้างเว็บไซต์ เพื่อให้ FPM สร้าง socket ของ pool ใหม่ คอนเทนเนอร์ไม่มี systemd
ถ้าไม่มีตัวแทนที่ทำงานจริง คำสั่งจะเงียบ ๆ ไม่เกิดอะไรขึ้น แล้วเว็บไซต์ที่เพิ่งสร้าง
จะตอบ **503** ทุกคำขอ ทั้งที่ panel รายงานว่าสำเร็จ (แก้ชั่วคราวได้ด้วย
`docker exec phpcp-prod-test service php8.4-fpm restart`)

