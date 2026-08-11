#!/usr/bin/env bash
#
# เกณฑ์รับงานเฟส 5: "ติดตั้งบน VM เปล่าเสร็จใน 1 คำสั่ง แล้วสร้างเว็บไซต์ที่เปิดใช้งานได้จริงพร้อม SSL"
#
# รันในคอนเทนเนอร์ที่ผ่าน install.sh มาแล้ว (ดู docker/Dockerfile.install-test)
# ส่วนที่คอนเทนเนอร์ทำแทน systemd ไม่ได้ ถูกสตาร์ตด้วยมือตรงนี้ — นอกนั้นเป็นของจริงทั้งหมด

set -uo pipefail

PASS=0; FAIL=0
ok()  { printf '  \033[32m✔\033[0m %s\n' "$*"; PASS=$((PASS+1)); }
bad() { printf '  \033[31m✘\033[0m %s\n' "$*"; FAIL=$((FAIL+1)); }

DOMAIN=acceptance.test

printf '\nเกณฑ์รับงาน: สร้างเว็บไซต์ที่ใช้งานได้จริงพร้อม SSL\n%s\n' "$(printf '─%.0s' $(seq 1 52))"

# --- แทน systemd ในคอนเทนเนอร์ ---
#
# panel เรียก `systemctl reload <unit>` เพื่อให้ค่าตั้งใหม่มีผล ซึ่งในคอนเทนเนอร์ที่ไม่มี
# systemd จะล้มเสมอ และ panel รายงานความล้มเหลวนั้นออกมาอย่างถูกต้อง (ตั้งใจให้ดัง)
# การทดสอบจึงต้องจัดหา systemctl ที่ทำงานได้ให้ ไม่ใช่แก้ panel ให้เงียบ
#
# บนเซิร์ฟเวอร์จริงไม่ต้องมีขั้นตอนนี้เลย — systemd ทำให้อยู่แล้ว
if ! systemctl is-system-running >/dev/null 2>&1; then
  [ -f /usr/bin/systemctl.real ] || cp /usr/bin/systemctl /usr/bin/systemctl.real
  install -m 755 "$(dirname "$0")/systemctl-shim.sh" /usr/bin/systemctl
  printf '  \033[33m!\033[0m คอนเทนเนอร์ไม่มี systemd — ใช้ตัวแทนสำหรับ reload เท่านั้น\n'
fi

# --- สตาร์ตสิ่งที่ systemd ควรทำให้ (คอนเทนเนอร์ไม่มี systemd) ---
# ใช้ PHP ตัวเดียวกับที่ตัวติดตั้งเลือก ไม่ใช่ `php` ของระบบ
# บน Ubuntu 22.04 ที่เพิ่ม ppa:ondrej/php แล้ว `php` ยังชี้ไปที่ 8.1 ของดิสทริบิวชันอยู่
# ขณะที่ panel ถูกติดตั้งด้วย 8.4 จาก PPA — ถ้าทดสอบด้วย `php` เฉย ๆ จะได้ผลลวง
PHP_BIN=$(command -v php8.4 || command -v php8.3 || command -v php8.2 || command -v php)
PHPV=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
printf '  ใช้ PHP %s ที่ %s\n' "$PHPV" "$PHP_BIN"
mkdir -p /run/php

if ! pgrep -f "php-fpm: [m]aster process \(/etc/php/${PHPV}/" >/dev/null; then
  "/usr/sbin/php-fpm${PHPV}" --fpm-config "/etc/php/${PHPV}/fpm/php-fpm.conf" 2>/dev/null
  sleep 2
fi

pgrep -f "php-fpm: [m]aster process \(/etc/php/${PHPV}/" >/dev/null \
  && ok "php-fpm ${PHPV} ทำงาน" || bad "php-fpm ${PHPV} ไม่ทำงาน"

apache2ctl -k start 2>/dev/null || true
sleep 1
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

# --- 1. สร้างเว็บไซต์ ---
if run_cap site.create "{\"name\":\"เว็บทดสอบ\",\"domain\":\"${DOMAIN}\",\"php_version\":\"${PHPV}\"}" >/dev/null 2>/tmp/e; then
  ok "สร้างเว็บไซต์ ${DOMAIN}"
else
  bad "สร้างเว็บไซต์ไม่สำเร็จ: $(head -2 /tmp/e | tr '\n' ' ')"
fi

SITE_ID=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  echo (int) Phpcp\Kernel\App::boot()->db()->value("SELECT id FROM sites WHERE primary_domain=:d",["d"=>$argv[1]],0);' "$DOMAIN")

if [ "${SITE_ID:-0}" -gt 0 ]; then
  ok "เว็บไซต์อยู่ในฐานข้อมูล (#${SITE_ID})"
else
  bad "ไม่พบเว็บไซต์ในฐานข้อมูล"
  # หยุดตรงนี้ — การตรวจที่เหลือทั้งหมดขึ้นกับเว็บไซต์นี้ ถ้าไล่ตรวจต่อจะได้ผล "ผ่าน"
  # จากการเทียบค่าว่างกับค่าว่าง ซึ่งอันตรายกว่าการไม่ตรวจเลย
  printf '%s\n  ผ่าน %d · ไม่ผ่าน %d — หยุดเพราะไม่มีเว็บไซต์ให้ตรวจต่อ\n\n' \
    "$(printf '─%.0s' $(seq 1 52))" "$PASS" "$FAIL"
  exit 1
fi

# --- 2. ผู้ใช้ระบบและสิทธิ์ไฟล์ ---
SU=$("$PHP_BIN" -r 'require "/usr/share/phpcp/bootstrap.php";
  echo (string) Phpcp\Kernel\App::boot()->db()->value("SELECT system_user FROM sites WHERE id=:i",["i"=>(int)$argv[1]],"");' "$SITE_ID")
id "$SU" >/dev/null 2>&1 && ok "สร้างผู้ใช้ระบบ ${SU} แยกเฉพาะเว็บนี้" || bad "ไม่ได้สร้างผู้ใช้ระบบ"

OWNER=$(stat -c '%U:%G' "/srv/phpcp/sites/${DOMAIN}/public" 2>/dev/null)
[ "$OWNER" = "${SU}:www-data" ] && ok "สิทธิ์ไฟล์ ${OWNER} (เว็บเซิร์ฟเวอร์อ่านได้ เว็บอื่นอ่านไม่ได้)" \
  || bad "สิทธิ์ไฟล์เป็น ${OWNER} ควรเป็น ${SU}:www-data"

# --- 3. SSL ---
if run_cap ssl.issue "{\"site_id\":${SITE_ID},\"method\":\"self-signed\"}" >/dev/null 2>/tmp/e; then
  ok "ออกใบรับรอง"
else
  bad "ออกใบรับรองไม่สำเร็จ: $(head -2 /tmp/e | tr '\n' ' ')"
fi

if run_cap ssl.set_mode "{\"site_id\":${SITE_ID},\"mode\":\"forced\"}" >/dev/null 2>/tmp/e; then
  ok "เปิดบังคับ HTTPS"
else
  bad "เปิด HTTPS ไม่สำเร็จ: $(head -2 /tmp/e | tr '\n' ' ')"
fi

apache2ctl -k graceful 2>/dev/null

# USR2 ของ php-fpm คืนค่าทันทีโดยไม่รอให้ pool ใหม่พร้อม ต้องรอ socket โผล่เอง
for _ in $(seq 1 20); do
  [ -S "/run/php/phpcp-${DOMAIN}-${PHPV}.sock" ] && break
  sleep 0.5
done

[ -S "/run/php/phpcp-${DOMAIN}-${PHPV}.sock" ] \
  && ok "FPM pool ของเว็บนี้พร้อมใช้งาน" || bad "ไม่มี socket ของ FPM pool"


# --- 4. เว็บให้บริการได้จริง ---
code=$(curl -sk -o /tmp/body -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/")
[ "$code" = "200" ] && ok "HTTPS ตอบ 200" || bad "HTTPS ตอบ ${code}"
grep -q "PHP" /tmp/body 2>/dev/null && ok "PHP ทำงานผ่าน FPM pool ของเว็บนี้" || bad "หน้าเว็บไม่ได้มาจาก PHP"

code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/)
[ "$code" = "301" ] && ok "HTTP redirect ไป HTTPS (301)" || bad "HTTP ตอบ ${code} ควรเป็น 301"

mkdir -p "/srv/phpcp/sites/${DOMAIN}/public/.well-known/acme-challenge"
echo ok > "/srv/phpcp/sites/${DOMAIN}/public/.well-known/acme-challenge/probe"
chown -R "${SU}:www-data" "/srv/phpcp/sites/${DOMAIN}/public/.well-known"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" "http://127.0.0.1/.well-known/acme-challenge/probe")
[ "$code" = "200" ] && ok "เส้นทางต่ออายุใบรับรองเข้าถึงได้ (200)" || bad "acme-challenge ตอบ ${code} — ต่ออายุใบรับรองจะล้ม"

echo "SECRET=1" > "/srv/phpcp/sites/${DOMAIN}/public/.env"
chown "${SU}:www-data" "/srv/phpcp/sites/${DOMAIN}/public/.env"
code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/.env")
[ "$code" = "403" ] && ok ".env ถูกปฏิเสธบน HTTPS (403)" || bad ".env ตอบ ${code} ควรเป็น 403"

# --- 5. การแยกเว็บไซต์ออกจากกัน ---
cat > "/srv/phpcp/sites/${DOMAIN}/public/esc.php" <<'PHP'
<?php echo @file_get_contents("/etc/shadow") ? "LEAK" : "BLOCKED";
PHP
chown "${SU}:www-data" "/srv/phpcp/sites/${DOMAIN}/public/esc.php"
body=$(curl -sk --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/esc.php")
[ "$body" = "BLOCKED" ] && ok "open_basedir กันการอ่านไฟล์นอกเว็บไซต์" || bad "อ่าน /etc/shadow ได้ — ${body}"

cat > "/srv/phpcp/sites/${DOMAIN}/public/who.php" <<'PHP'
<?php echo posix_getpwuid(posix_geteuid())["name"];
PHP
chown "${SU}:www-data" "/srv/phpcp/sites/${DOMAIN}/public/who.php"
who=$(curl -sk --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/who.php")
[ "$who" = "$SU" ] && ok "PHP รันด้วย uid ของเว็บไซต์ (${who})" || bad "PHP รันด้วย ${who} ควรเป็น ${SU}"

# --- 6. หน้าจัดการของ panel เอง ---
/usr/sbin/php-fpm${PHPV} --fpm-config /etc/phpcp/fpm/php-fpm.conf 2>/dev/null &
sleep 1
/usr/sbin/apache2 -f /etc/phpcp/httpd/httpd.conf -DFOREGROUND 2>/dev/null &
sleep 3
code=$(curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:8443/login)
[ "$code" = "200" ] && ok "หน้าเข้าสู่ระบบของ panel ตอบ 200 บน HTTPS" || bad "panel ตอบ ${code}"

rm -f "/srv/phpcp/sites/${DOMAIN}/public/"{esc,who}.php "/srv/phpcp/sites/${DOMAIN}/public/.env"

printf '%s\n  ผ่าน %d · ไม่ผ่าน %d\n\n' "$(printf '─%.0s' $(seq 1 52))" "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
