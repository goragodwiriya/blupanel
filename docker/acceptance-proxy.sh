#!/usr/bin/env bash
#
# เกณฑ์รับงานของโหมด nginx-proxy: **.htaccess ของลูกค้าต้องทำงานจริงหลัง nginx**
#
# รันในคอนเทนเนอร์ที่ผ่าน install.sh มาแล้ว (ดู docker/Dockerfile.install-test)
# ต่างจาก docker/acceptance.sh ตรงที่สลับ `webserver` เป็น nginx-proxy ก่อนสร้างเว็บ
#
# ข้อที่สำคัญที่สุดคือข้อ 4 และ 5 — ถ้ากฎใน .htaccess ไม่มีผล โหมดนี้ก็ไม่มีเหตุผลให้มีอยู่

set -uo pipefail

PASS=0; FAIL=0
ok()  { printf '  \033[32m✔\033[0m %s\n' "$*"; PASS=$((PASS+1)); }
bad() { printf '  \033[31m✘\033[0m %s\n' "$*"; FAIL=$((FAIL+1)); }

DOMAIN=proxy.test
OWNER_USER=proxytest

printf '\nเกณฑ์รับงาน nginx-proxy: .htaccess ต้องทำงานจริง\n%s\n' "$(printf '─%.0s' $(seq 1 52))"

if ! systemctl is-system-running >/dev/null 2>&1; then
  [ -f /usr/bin/systemctl.real ] || cp /usr/bin/systemctl /usr/bin/systemctl.real
  install -m 755 "$(dirname "$0")/systemctl-shim.sh" /usr/bin/systemctl
fi

PHP_BIN=$(command -v php8.4 || command -v php8.3 || command -v php8.2 || command -v php)
PHPV=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
mkdir -p /run/php

# --- สลับโหมดก่อนสร้างเว็บ ---
"$PHP_BIN" -r '
  $f = "/etc/phpcp/config.php";
  $s = file_get_contents($f);
  $s = preg_replace("/(\x27webserver\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27nginx-proxy\x27", $s, 1);
  file_put_contents($f, $s);
'
grep -q "'webserver' => 'nginx-proxy'" /etc/phpcp/config.php \
  && ok "สลับ webserver เป็น nginx-proxy แล้ว" || bad "สลับโหมดไม่สำเร็จ"

if ! pgrep -f "php-fpm: [m]aster process \(/etc/php/${PHPV}/" >/dev/null; then
  "/usr/sbin/php-fpm${PHPV}" --fpm-config "/etc/php/${PHPV}/fpm/php-fpm.conf" 2>/dev/null
  sleep 2
fi

/usr/share/phpcp/bin/phpcp-agentd >/var/log/phpcp/agent.log 2>&1 &
sleep 2
pgrep -f phpcp-agentd >/dev/null && ok "agent ทำงาน" || bad "agent ไม่ทำงาน"

run_cap() {
  "$PHP_BIN" -r '
    require "/usr/share/phpcp/bootstrap.php";
    $args = json_decode($argv[2], true) ?: [];
    try {
        $r = Phpcp\Kernel\App::boot()->agent()->data(
            $argv[1], $args,
            new Phpcp\Agent\Actor(1, "admin", "superadmin", "127.0.0.1", "acceptance"),
        );
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
  ' "$1" "$2"
}

# --- 1. สร้างบัญชีและเว็บไซต์ ---
run_cap customer.create \
  "{\"username\":\"${OWNER_USER}\",\"password\":\"Acceptance-Test-1234\",\"email\":\"${OWNER_USER}@example.com\"}" \
  >/dev/null 2>/tmp/e || bad "สร้างบัญชีโฮสติ้งไม่สำเร็จ: $(head -2 /tmp/e | tr '\n' ' ')"

OWNER_ID=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  echo (int) Phpcp\Kernel\App::boot()->db()->value("SELECT id FROM users WHERE username=:u",["u"=>$argv[1]],0);' "$OWNER_USER")

if run_cap site.create "{\"name\":\"เว็บทดสอบ\",\"domain\":\"${DOMAIN}\",\"php_version\":\"${PHPV}\",\"owner_user_id\":${OWNER_ID}}" >/dev/null 2>/tmp/e; then
  ok "สร้างเว็บไซต์ ${DOMAIN}"
else
  bad "สร้างเว็บไซต์ไม่สำเร็จ: $(head -3 /tmp/e | tr '\n' ' ')"
  printf '%s\n  ผ่าน %d · ไม่ผ่าน %d — หยุดเพราะไม่มีเว็บให้ตรวจ\n\n' \
    "$(printf '─%.0s' $(seq 1 52))" "$PASS" "$FAIL"
  exit 1
fi

SITE_ID=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  echo (int) Phpcp\Kernel\App::boot()->db()->value("SELECT id FROM sites WHERE primary_domain=:d",["d"=>$argv[1]],0);' "$DOMAIN")
DOCROOT=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  $s = (new Phpcp\Domain\SiteRepository(Phpcp\Kernel\App::boot()->db()))->load((int) $argv[1]);
  echo $s === null ? "" : $s->docroot();' "$SITE_ID")
SU=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  $s = (new Phpcp\Domain\SiteRepository(Phpcp\Kernel\App::boot()->db()))->load((int) $argv[1]);
  echo $s === null ? "" : $s->systemUser();' "$SITE_ID")

# --- 2. ไฟล์ตั้งค่าต้องครบทั้งสองชั้น ---
[ -f "/etc/nginx/conf.d/phpcp-${DOMAIN}.conf" ] \
  && ok "มี vhost ของ nginx (ชั้นหน้า)" || bad "ไม่มี vhost ของ nginx"
[ -f "/etc/apache2/sites-enabled/phpcp-${DOMAIN}.conf" ] \
  && ok "มี vhost ของ Apache (ชั้นหลัง)" || bad "ไม่มี vhost ของ Apache"
grep -q "Listen 127.0.0.1:8080" /etc/apache2/ports.conf \
  && ok "Apache ถอยไปฟังเฉพาะ loopback" || bad "ports.conf ยังไม่ถูกเขียนใหม่"

# --- 3. สตาร์ตทั้งสองชั้น ---
# vhost เริ่มต้นของ nginx จองพอร์ต 80 เป็น default_server ซึ่งไม่เกี่ยวกับ panel
rm -f /etc/nginx/sites-enabled/default
apache2ctl -k start 2>/dev/null || true
sleep 1
nginx 2>/dev/null || nginx -s reload 2>/dev/null
sleep 1

ss -ltn 2>/dev/null | grep -q ':8080' && ok "Apache ฟังที่ 8080" || bad "Apache ไม่ได้ฟังที่ 8080"
curl -s -o /dev/null -w '' --max-time 5 http://127.0.0.1/ 2>/dev/null
ss -ltn 2>/dev/null | grep -qE ':80\b' && ok "nginx ถือพอร์ต 80" || bad "ไม่มีอะไรฟังที่พอร์ต 80"

# --- 4. .htaccess ต้องมีผลจริง (หัวใจของโหมดนี้) ---
cat > "${DOCROOT}/index.php" <<'PHP'
<?php echo "หน้าแรก PHP · scheme=", ($_SERVER['HTTPS'] ?? 'off'), " · ip=", $_SERVER['REMOTE_ADDR'];
PHP
mkdir -p "${DOCROOT}/private"
echo "ความลับของลูกค้า" > "${DOCROOT}/private/secret.txt"
cat > "${DOCROOT}/rewritten.php" <<'PHP'
<?php echo "REWRITE-OK";
PHP

# กฎสามชนิดที่ลูกค้าใช้จริงบ่อยที่สุด
cat > "${DOCROOT}/.htaccess" <<'HTA'
RewriteEngine On
RewriteRule ^hello$ /rewritten.php [L]

Header set X-From-Htaccess "yes"
HTA

# กฎกันโฟลเดอร์ — ไฟล์ static ล้วน ๆ ซึ่งเป็นจุดที่ nginx จะข้ามถ้าเสิร์ฟไฟล์เอง
cat > "${DOCROOT}/private/.htaccess" <<'HTA'
Require all denied
HTA

chown -R "${SU}:www-data" "${DOCROOT}"
apache2ctl -k graceful 2>/dev/null

# index.php ของโครงเว็บถูกเขียนทับด้วยไฟล์ทดสอบ แต่ opcache ยังถือของเดิมอยู่
# (validate_timestamps เปิดอยู่ แต่ revalidate_freq = 2 วินาที) — ถ้าไม่รอ
# จะได้หน้าเดิมกลับมาแล้วเข้าใจผิดว่า proxy ส่งคำขอผิดที่
sleep 3

code=$(curl -s -o /tmp/body -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/)
[ "$code" = "200" ] && ok "เว็บตอบ 200 ผ่าน nginx → Apache" || bad "เว็บตอบ ${code}"

body=$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/hello)
[ "$body" = "REWRITE-OK" ] \
  && ok "RewriteRule ใน .htaccess ทำงาน (/hello → rewritten.php)" \
  || bad "RewriteRule ไม่มีผล — ได้: ${body}"

code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/private/secret.txt)
[ "$code" = "403" ] \
  && ok "กฎกันโฟลเดอร์ใน .htaccess มีผลกับไฟล์ static ด้วย (403)" \
  || bad "ไฟล์ลับตอบ ${code} ควรเป็น 403 — nginx เสิร์ฟไฟล์เองข้าม .htaccess ไปแล้ว"

curl -s -D /tmp/h -o /dev/null -H "Host: ${DOMAIN}" http://127.0.0.1/
grep -qi 'X-From-Htaccess: yes' /tmp/h \
  && ok "Header ที่ตั้งใน .htaccess ถูกส่งกลับถึงผู้ใช้" || bad "ไม่พบ header จาก .htaccess"

# --- 4.1 nginx ต้องตอบไฟล์ static เอง (เหตุผลที่เอา nginx มาวางหน้า) ---
echo "body{color:red}" > "${DOCROOT}/style.css"
chown "${SU}:www-data" "${DOCROOT}/style.css"

# nginx ที่ตอบเองจะไม่ใส่ header ของ Apache (X-From-Htaccess มาจาก .htaccess)
curl -s -D /tmp/hs -o /dev/null -H "Host: ${DOMAIN}" http://127.0.0.1/style.css
if grep -qi 'X-From-Htaccess' /tmp/hs; then
  bad "style.css ยังผ่าน Apache อยู่ — nginx ไม่ได้ตอบเอง"
else
  ok "nginx ตอบไฟล์ static เองโดยไม่ปลุก Apache"
fi
grep -qi 'Cache-Control: public' /tmp/hs \
  && ok "ไฟล์ static ได้ header แคชจาก nginx" || bad "ไม่มี header แคชของ nginx"

# กฎกันโฟลเดอร์ต้องยังชนะแม้ไฟล์ข้างในจะเป็น static ล้วน
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/private/secret.txt)
[ "$code" = "403" ] \
  && ok "โฟลเดอร์ที่ .htaccess ป้องกันไว้ยังถูกบังคับผ่าน Apache (403)" \
  || bad "ไฟล์ลับตอบ ${code} — nginx แซงกฎ .htaccess ไปแล้ว"

# ไฟล์ที่ไม่มีอยู่จริงต้องตกไปให้ Apache ตัดสินด้วยกฎ rewrite ไม่ใช่ 404 จาก nginx
body=$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/hello)
[ "$body" = "REWRITE-OK" ] \
  && ok "กฎ rewrite ยังทำงานหลังเปิดให้ nginx ตอบ static" \
  || bad "rewrite พังหลังเปิด static — ได้: ${body}"

# --- 5. ที่อยู่ผู้ใช้จริงต้องไปถึงชั้นหลัง ---
# เรียก index.php ตรง ๆ — โครงเว็บที่ panel วางให้มี index.html ซึ่งชนะใน DirectoryIndex
ip=$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/index.php | sed 's/.*ip=//')
[ "$ip" = "127.0.0.1" ] \
  && ok "mod_remoteip ส่งที่อยู่ผู้ใช้จริงถึง PHP (${ip})" \
  || bad "PHP เห็นที่อยู่เป็น ${ip} — fail2ban จะแบนผิดตัว"

# --- 6. ชั้นหลังต้องเข้าถึงจากภายนอกไม่ได้ ---
# ต้องดูคอลัมน์ local address (ช่องที่ 4) เท่านั้น — ช่อง peer เป็น 0.0.0.0:* เสมอ
# การ grep ทั้งบรรทัดจะฟ้องว่า "ฟังทุกหน้าตัดเน็ต" ทั้งที่ผูกกับ loopback อยู่แล้ว
public=$(ss -ltnH 2>/dev/null | awk '$4 ~ /:8080$/ && $4 !~ /^(127\.|\[::1\])/ { print $4 }')
if [ -n "$public" ]; then
  bad "8080 ฟังที่ ${public} — มีทางลัดข้าม TLS และ rate limit"
else
  ok "8080 ผูกกับ loopback เท่านั้น"
fi

# --- 7. PHP ยังรันด้วย uid ของลูกค้า ---
cat > "${DOCROOT}/who.php" <<'PHP'
<?php echo posix_getpwuid(posix_geteuid())["name"];
PHP
chown "${SU}:www-data" "${DOCROOT}/who.php"
who=$(curl -s -H "Host: ${DOMAIN}" http://127.0.0.1/who.php)
[ "$who" = "$SU" ] && ok "PHP รันด้วย uid ของเว็บไซต์ (${who})" || bad "PHP รันด้วย ${who} ควรเป็น ${SU}"

printf '%s\n  ผ่าน %d · ไม่ผ่าน %d\n\n' "$(printf '─%.0s' $(seq 1 52))" "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
