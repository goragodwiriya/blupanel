#!/usr/bin/env bash
#
# ตรวจว่าการติดตั้งได้ผลลัพธ์ที่ควรจะเป็นจริง ไม่ใช่แค่สคริปต์จบด้วยรหัส 0
#
# แยกจาก install.sh โดยเจตนา — ตัวติดตั้งตรวจตัวเองผ่านเสมออยู่แล้วก็ไม่มีความหมาย
# ไฟล์นี้เป็นมุมมองจากภายนอกว่า "ถ้าฉันเป็นผู้ดูแลที่เพิ่งติดตั้งเสร็จ ฉันควรเจออะไร"

set -uo pipefail

PASS=0
FAIL=0

ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; PASS=$((PASS + 1)); }
bad()  { printf '  \033[31m✘\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }

check_file() {
  [ -f "$1" ] && ok "มีไฟล์ $1" || bad "ไม่มีไฟล์ $1"
}

check_dir() {
  [ -d "$1" ] && ok "มีไดเรกทอรี $1" || bad "ไม่มีไดเรกทอรี $1"
}

# ตรวจสิทธิ์แบบ "ไม่กว้างเกินไป" ให้ตรงกับที่ศูนย์ความปลอดภัยตรวจ
check_not_world() {
  local path="$1" mode
  mode=$(stat -c '%a' "$path" 2>/dev/null) || { bad "อ่านสิทธิ์ $path ไม่ได้"; return; }

  if [ $((0$mode & 0007)) -ne 0 ]; then
    bad "$path เปิดให้ผู้ใช้อื่นเข้าถึง (mode $mode)"
  else
    ok "$path ผู้ใช้อื่นแตะไม่ได้ (mode $mode)"
  fi
}

printf '\nตรวจผลการติดตั้ง\n%s\n' "$(printf '─%.0s' $(seq 1 46))"

# --- 1. โค้ดและคำสั่ง ---
check_dir  /usr/share/phpcp/src
check_file /usr/share/phpcp/bootstrap.php
[ -x /usr/local/bin/phpcp ] && ok "คำสั่ง phpcp เรียกใช้ได้" || bad "ไม่มีคำสั่ง phpcp"

# โค้ดต้องเป็นของ root และผู้ใช้ของเว็บแก้ไม่ได้ (ARCHITECTURE §13)
owner=$(stat -c '%U' /usr/share/phpcp/src 2>/dev/null)
[ "$owner" = "root" ] && ok "โค้ดเป็นของ root" || bad "โค้ดเป็นของ $owner ไม่ใช่ root"

# --- 2. ผู้ใช้และกลุ่ม ---
id phpcp-web >/dev/null 2>&1 && ok "มีผู้ใช้ phpcp-web" || bad "ไม่มีผู้ใช้ phpcp-web"
getent group phpcp >/dev/null 2>&1 && ok "มีกลุ่ม phpcp" || bad "ไม่มีกลุ่ม phpcp"

# ผู้ใช้ของ web tier ต้องล็อกอินไม่ได้ — มันมีไว้รันเว็บอย่างเดียว
shell=$(getent passwd phpcp-web | cut -d: -f7)
case "$shell" in
  */nologin|*/false) ok "phpcp-web ล็อกอินไม่ได้ ($shell)" ;;
  *) bad "phpcp-web ใช้เชลล์ $shell ซึ่งล็อกอินได้" ;;
esac

# --- 3. config tree ของ panel (ต้องแยกจากของระบบ) ---
check_file /etc/phpcp/config.php
check_file /etc/phpcp/httpd/httpd.conf
check_file /etc/phpcp/fpm/php-fpm.conf
check_not_world /etc/phpcp/config.php

# ห้ามแตะ config ของระบบแม้แต่ไฟล์เดียว (ARCHITECTURE §5.2)
if [ -d /etc/apache2/sites-enabled ]; then
  stray=$(find /etc/apache2 -name 'phpcp-panel*' 2>/dev/null | wc -l)
  [ "$stray" -eq 0 ] && ok "ไม่มีไฟล์ของ panel ปนใน /etc/apache2" || bad "พบไฟล์ของ panel ใน /etc/apache2"
fi

# --- 4. ฐานข้อมูลและบัญชีผู้ดูแล ---
check_file /var/lib/phpcp/panel.db
check_not_world /var/lib/phpcp/panel.db

# ถามฐานข้อมูลตรง ๆ ไม่ใช่ grep ผลลัพธ์ที่พิมพ์ออกหน้าจอ — user:list แสดงป้ายภาษาไทย
# ("ผู้ดูแลระบบ") ไม่ใช่ชื่อ role ดิบ การ grep หา 'superadmin' จึงไม่เจอทั้งที่บัญชีมีอยู่จริง
users=$(php -r '
    require "/usr/share/phpcp/bootstrap.php";
    echo (int) Phpcp\Kernel\App::boot()->db()->value(
        "SELECT count(*) FROM users WHERE role = :r AND status = :s",
        ["r" => "superadmin", "s" => "active"],
        0,
    );
' 2>/dev/null || echo 0)
[ "${users:-0}" -ge 1 ] && ok "มีบัญชีผู้ดูแลระบบ $users บัญชี" || bad "ไม่มีบัญชีผู้ดูแลระบบ"

# รหัสผ่านเริ่มต้นต้องถูกบังคับให้เปลี่ยนตั้งแต่เข้าครั้งแรก
must_change=$(php -r '
    require "/usr/share/phpcp/bootstrap.php";
    echo (int) Phpcp\Kernel\App::boot()->db()->value(
        "SELECT count(*) FROM users WHERE must_change_password = 1",
        [],
        0,
    );
' 2>/dev/null || echo 0)
[ "${must_change:-0}" -ge 1 ] && ok "บังคับเปลี่ยนรหัสผ่านครั้งแรก" || bad "ไม่ได้บังคับเปลี่ยนรหัสผ่านครั้งแรก"

# --- 5. configtest ของ runtime ที่ panel สร้างเอง ---
if apache2 -t -f /etc/phpcp/httpd/httpd.conf >/dev/null 2>&1; then
  ok "httpd.conf ของ panel ผ่าน configtest"
else
  bad "httpd.conf ของ panel ไม่ผ่าน configtest"
fi

fpm_bin=$(command -v php-fpm8.4 || command -v php-fpm8.3 || command -v php-fpm8.2 || command -v php-fpm || true)
if [ -n "$fpm_bin" ] && "$fpm_bin" -t -y /etc/phpcp/fpm/php-fpm.conf >/dev/null 2>&1; then
  ok "php-fpm.conf ของ panel ผ่าน configtest"
else
  bad "php-fpm.conf ของ panel ไม่ผ่าน configtest"
fi

# --- 5.1 ของที่ตัวติดตั้งต้องเตรียมให้พร้อมใช้ทันที ---
#
# สามอย่างนี้เคยเป็น "ผู้ดูแลต้องไปทำเอง" ซึ่งแปลว่าไม่มีใครทำ — log โตจนดิสก์เต็ม
# และการแจ้งเตือนทางอีเมลใช้ไม่ได้เงียบ ๆ จนกว่าจะมีเหตุจริงแล้วไม่มีใครได้รับแจ้ง
check_file /etc/logrotate.d/phpcp

if command -v logrotate >/dev/null 2>&1; then
  if logrotate -d /etc/logrotate.d/phpcp >/dev/null 2>&1; then
    ok "ไฟล์ตั้งค่า logrotate ใช้งานได้จริง"
  else
    bad "ไฟล์ตั้งค่า logrotate ไม่ผ่านการตรวจของ logrotate เอง"
  fi
fi

# audit.log ต้องไม่ถูกหมุนบ่อยเท่า log ทั่วไป — มันคือหลักฐานคู่ขนานของ audit_log
grep -q 'rotate 24' /etc/logrotate.d/phpcp 2>/dev/null \
  && ok "audit.log เก็บย้อนหลังนานกว่า log ทั่วไป" \
  || bad "ไม่มีกฎแยกสำหรับ audit.log"

command -v postfix >/dev/null 2>&1 \
  && ok "มี Postfix สำหรับส่งอีเมลแจ้งเตือน" \
  || bad "ไม่มี Postfix — การแจ้งเตือนทางอีเมลจะใช้ไม่ได้"

# --- 6. systemd unit (ติดตั้งไว้แม้คอนเทนเนอร์จะเปิดใช้งานไม่ได้) ---
for unit in phpcp-agentd phpcp-fpm phpcp-web; do
  check_file "/etc/systemd/system/${unit}.service"
done

# --- 7. โปรแกรมต้องรันได้จริง ---
if php /usr/share/phpcp/bin/phpcp --version >/dev/null 2>&1; then
  ok "phpcp --version ทำงานได้"
else
  bad "phpcp --version ล้มเหลว"
fi

if php /usr/share/phpcp/bin/phpcp doctor >/dev/null 2>&1; then
  ok "phpcp doctor ทำงานได้"
else
  # doctor รายงานปัญหาของสภาพแวดล้อมได้เป็นเรื่องปกติ ขอแค่ไม่ crash
  php /usr/share/phpcp/bin/phpcp doctor >/dev/null 2>&1
  [ $? -le 1 ] && ok "phpcp doctor ทำงานได้ (รายงานข้อควรปรับปรุง)" || bad "phpcp doctor crash"
fi

printf '%s\n' "$(printf '─%.0s' $(seq 1 46))"
printf '  ผ่าน %d · ไม่ผ่าน %d\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ] || exit 1
