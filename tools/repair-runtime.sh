#!/usr/bin/env bash
#
# ซ่อม runtime ของ panel ที่ติดตั้งไว้แล้ว โดยไม่ต้องรัน install.sh ใหม่ทั้งชุด
#
#   sudo tools/repair-runtime.sh            ซ่อมจริง
#   sudo tools/repair-runtime.sh --check    ตรวจอย่างเดียว ไม่แก้อะไร
#
# ใช้เมื่อไร
#   • panel ตอบ 503 หรือแจ้ง "ติดต่อ agent ไม่ได้" ทั้งที่ทุกบริการขึ้น active
#   • หลังอัปเดตโค้ดแล้วอยากให้ไฟล์ unit / httpd.conf / php-fpm.conf ตรงกับ template ล่าสุด
#   • อยากตรวจว่าเครื่องจะกลับมาใช้งานได้เองจริงหลังรีสตาร์ต
#
# สิ่งที่สคริปต์นี้ทำ
#   1. สร้างไฟล์ unit และ config tree ของ panel ใหม่จาก templates/panel/
#   2. configtest ของ httpd และ fpm ก่อนแตะบริการ
#   3. daemon-reload แล้วรีสตาร์ตทั้งสามบริการ "พร้อมกัน"
#   4. ตรวจว่า socket ครบและหน้า panel ตอบ 200
#   5. ตรวจว่าทุกบริการที่จำเป็นจะขึ้นเองตอนบูต
#
# สิ่งที่สคริปต์นี้ไม่ทำ
#   • ไม่แตะ /etc/apache2 หรือเว็บไซต์ของผู้ใช้
#   • ไม่แตะฐานข้อมูล panel และไม่แตะ MariaDB
#   • ไม่คัดลอกโค้ดใหม่ (ใช้ tools/migrate-host.sh หรือ install.sh สำหรับงานนั้น)

set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

INSTALL_DIR="/usr/share/phpcp"
CONF_DIR="/etc/phpcp"
DATA_DIR="/var/lib/phpcp"
LOG_DIR="/var/log/phpcp"
RUN_DIR="/run/phpcp"
TMP_DIR="$DATA_DIR/tmp"
PANEL_USER="phpcp-web"
PANEL_GROUP="phpcp"

CHECK_ONLY="no"

if [ -t 1 ]; then
  C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[90m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_DIM=""; C_OFF=""
fi

say()   { printf '  %s\n' "$*"; }
dim()   { printf '  %s%s%s\n' "$C_DIM" "$*" "$C_OFF"; }
ok()    { printf '  %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; }
warn()  { printf '  %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()   { printf '  %s✘%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }
head_() { printf '\n%s\n%s\n' "$*" "$(printf '─%.0s' $(seq 1 52))"; }

for arg in "$@"; do
  case "$arg" in
    --check|--dry-run) CHECK_ONLY="yes" ;;
    -h|--help) sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "ไม่รู้จักตัวเลือก: $arg" ;;
  esac
done

# ---------------------------------------------------------------------------
head_ "0. ตรวจก่อนเริ่ม"
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "ต้องรันด้วย sudo"
[ "$CHECK_ONLY" = "yes" ] && warn "โหมดตรวจอย่างเดียว — ไม่มีอะไรถูกแก้"

[ -d /run/systemd/system ] || die "เครื่องนี้ไม่ได้ใช้ systemd — สคริปต์นี้ใช้ไม่ได้"
[ -d "$INSTALL_DIR" ] || die "ยังไม่ได้ติดตั้ง panel — รัน sudo ./install.sh ก่อน"
[ -f "$CONF_DIR/config.php" ] || die "ไม่พบ $CONF_DIR/config.php — รัน sudo ./install.sh ก่อน"

getent passwd "$PANEL_USER" >/dev/null || die "ไม่พบผู้ใช้ $PANEL_USER — รัน sudo ./install.sh ก่อน"
getent group "$PANEL_GROUP" >/dev/null || die "ไม่พบกลุ่ม $PANEL_GROUP — รัน sudo ./install.sh ก่อน"

PHP_BIN="$(command -v php8.4 || command -v php8.3 || command -v php || true)"
[ -n "$PHP_BIN" ] || die "ไม่พบคำสั่ง php"
PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

FPM_BIN="$(command -v "php-fpm$PHP_VER" || command -v php-fpm || echo "/usr/sbin/php-fpm$PHP_VER")"
HTTPD_BIN="$(command -v apache2 || command -v httpd || echo /usr/sbin/apache2)"
[ -x "$FPM_BIN" ] || die "ไม่พบ php-fpm ที่ $FPM_BIN"
[ -x "$HTTPD_BIN" ] || die "ไม่พบ apache2 ที่ $HTTPD_BIN"

MODULES_DIR=""
for candidate in /usr/lib/apache2/modules /usr/lib64/httpd/modules /usr/libexec/apache2; do
  [ -d "$candidate" ] && { MODULES_DIR="$candidate"; break; }
done
[ -n "$MODULES_DIR" ] || MODULES_DIR="/usr/lib/apache2/modules"

# อ่านพอร์ตจาก config ของจริง ไม่เดาเอง — ผู้ดูแลอาจเปลี่ยนไปแล้ว
PANEL_PORT="$("$PHP_BIN" -r '$c=require $argv[1]; echo (int)($c["panel"]["port"] ?? 8443);' \
  "$CONF_DIR/config.php" 2>/dev/null || echo 8443)"
[ "$PANEL_PORT" -gt 0 ] 2>/dev/null || PANEL_PORT=8443
PANEL_HOST="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"

ok "panel อยู่ที่ $INSTALL_DIR · PHP $PHP_VER · พอร์ต $PANEL_PORT"

for tpl in phpcp-agentd.service phpcp-fpm.service phpcp-web.service php-fpm.conf panel-pool.conf httpd.conf; do
  [ -f "$SRC_DIR/templates/panel/$tpl.tpl" ] || die "ไม่พบ template: $SRC_DIR/templates/panel/$tpl.tpl"
done
ok "template ครบทุกไฟล์"

# ---------------------------------------------------------------------------
head_ "1. สร้างไฟล์ unit และ config ใหม่จาก template"
# ---------------------------------------------------------------------------
render() {
  sed -e "s|{{INSTALL_DIR}}|$INSTALL_DIR|g" \
      -e "s|{{CONF_DIR}}|$CONF_DIR|g" \
      -e "s|{{DATA_DIR}}|$DATA_DIR|g" \
      -e "s|{{LOG_DIR}}|$LOG_DIR|g" \
      -e "s|{{RUN_DIR}}|$RUN_DIR|g" \
      -e "s|{{TMP_DIR}}|$TMP_DIR|g" \
      -e "s|{{PHP_BIN}}|$PHP_BIN|g" \
      -e "s|{{FPM_BIN}}|$FPM_BIN|g" \
      -e "s|{{HTTPD_BIN}}|$HTTPD_BIN|g" \
      -e "s|{{MODULES_DIR}}|$MODULES_DIR|g" \
      -e "s|{{PANEL_USER}}|$PANEL_USER|g" \
      -e "s|{{PANEL_GROUP}}|$PANEL_GROUP|g" \
      -e "s|{{PANEL_HOST}}|$PANEL_HOST|g" \
      -e "s|{{PANEL_PORT}}|$PANEL_PORT|g" \
      "$1" > "$2"
}

# เขียนลงที่ชั่วคราวก่อนเสมอ แล้วค่อยตรวจ — ไฟล์เดิมยังทำงานอยู่จนกว่าจะผ่าน configtest
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

render "$SRC_DIR/templates/panel/phpcp-agentd.service.tpl" "$STAGE/phpcp-agentd.service"
render "$SRC_DIR/templates/panel/phpcp-fpm.service.tpl"    "$STAGE/phpcp-fpm.service"
render "$SRC_DIR/templates/panel/phpcp-web.service.tpl"    "$STAGE/phpcp-web.service"
render "$SRC_DIR/templates/panel/php-fpm.conf.tpl"         "$STAGE/php-fpm.conf"
render "$SRC_DIR/templates/panel/panel-pool.conf.tpl"      "$STAGE/panel.conf"
render "$SRC_DIR/templates/panel/httpd.conf.tpl"           "$STAGE/httpd.conf"

# บรรทัดนี้คือหัวใจของการซ่อม — ถ้าหายไปแม้แต่ไฟล์เดียว การรีสตาร์ตบริการหนึ่ง
# จะลบ socket ของอีกสองตัวทิ้ง แล้วอาการ 503 / "ติดต่อ agent ไม่ได้" จะกลับมาอีก
for unit in phpcp-agentd phpcp-fpm phpcp-web; do
  grep -q '^RuntimeDirectoryPreserve=yes' "$STAGE/$unit.service" \
    || die "template ของ $unit ขาด RuntimeDirectoryPreserve=yes — อย่าซ่อมด้วยไฟล์ชุดนี้"
done
ok "ทั้งสาม unit มี RuntimeDirectoryPreserve=yes ครบ"

if [ "$CHECK_ONLY" = "yes" ]; then
  for unit in phpcp-agentd phpcp-fpm phpcp-web; do
    if diff -q "/etc/systemd/system/$unit.service" "$STAGE/$unit.service" >/dev/null 2>&1; then
      dim "$unit.service ตรงกับ template อยู่แล้ว"
    else
      warn "$unit.service ต่างจาก template — จะถูกเขียนใหม่เมื่อรันจริง"
      diff -u "/etc/systemd/system/$unit.service" "$STAGE/$unit.service" 2>/dev/null \
        | sed -n '3,$p' | sed 's/^/      /' || true
    fi
  done
fi

# ---------------------------------------------------------------------------
head_ "2. configtest ก่อนแตะบริการ"
# ---------------------------------------------------------------------------
# ตรวจจากไฟล์ใน STAGE โดยตรง — httpd.conf อ้าง ServerRoot ที่ติดตั้งจริงอยู่แล้ว
"$HTTPD_BIN" -t -f "$STAGE/httpd.conf" >/dev/null 2>&1 \
  || die "httpd.conf ใหม่ไม่ผ่าน configtest: $("$HTTPD_BIN" -t -f "$STAGE/httpd.conf" 2>&1 | tail -3)"
ok "httpd.conf ผ่าน configtest"

if [ "$CHECK_ONLY" = "yes" ]; then
  printf '\n  %sตรวจเสร็จ — รันซ้ำโดยไม่ใส่ --check เพื่อซ่อมจริง%s\n\n' "$C_WARN" "$C_OFF"
  exit 0
fi

# ---------------------------------------------------------------------------
head_ "3. ติดตั้งไฟล์ใหม่"
# ---------------------------------------------------------------------------
mkdir -p "$CONF_DIR/httpd" "$CONF_DIR/fpm/pool.d" "$CONF_DIR/tls" "$CONF_DIR/vhosts.d"
mkdir -p "$DATA_DIR/backups" "$TMP_DIR" "$LOG_DIR"

install -m 644 "$STAGE/phpcp-agentd.service" /etc/systemd/system/phpcp-agentd.service
install -m 644 "$STAGE/phpcp-fpm.service"    /etc/systemd/system/phpcp-fpm.service
install -m 644 "$STAGE/phpcp-web.service"    /etc/systemd/system/phpcp-web.service
ok "เขียนไฟล์ unit ทั้งสามแล้ว"

install -o root -g "$PANEL_GROUP" -m 640 "$STAGE/php-fpm.conf" "$CONF_DIR/fpm/php-fpm.conf"
install -o root -g "$PANEL_GROUP" -m 640 "$STAGE/panel.conf"   "$CONF_DIR/fpm/pool.d/panel.conf"
install -o root -g "$PANEL_GROUP" -m 640 "$STAGE/httpd.conf"   "$CONF_DIR/httpd/httpd.conf"
ok "เขียน httpd.conf และ php-fpm.conf ของ panel แล้ว"

"$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" >/dev/null 2>&1 \
  || die "php-fpm.conf ไม่ผ่าน configtest: $("$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" 2>&1 | tail -3)"
ok "php-fpm.conf ผ่าน configtest"

# ฐานข้อมูลและ log ต้องเป็นของผู้ใช้ panel — ถ้าเคยมีคำสั่งไหนรันด้วย root แล้วทิ้ง
# ไฟล์ของ root ไว้ (โดยเฉพาะ panel.db-wal / panel.db-shm ของ SQLite โหมด WAL)
# ตัว FPM ที่รันเป็น phpcp-web จะเปิดฐานข้อมูลไม่ได้เลย → panel ตอบ 500 ทุกหน้า
chown -R "$PANEL_USER:$PANEL_GROUP" "$DATA_DIR" "$LOG_DIR"
chmod 750 "$DATA_DIR" "$LOG_DIR" "$DATA_DIR/backups" "$TMP_DIR"
[ -f "$DATA_DIR/panel.db" ] && chmod 600 "$DATA_DIR/panel.db"
ok "คืนความเป็นเจ้าของ $DATA_DIR และ $LOG_DIR ให้ $PANEL_USER"

# phpMyAdmin ถูกผูกเป็น symlink ใต้เว็บรูทของ panel โดยตัวติดตั้ง (install.sh §5.1)
# แต่การอัปเดตโค้ดเขียนทับ public/ ทั้งโฟลเดอร์ ลิงก์จึงหายไปเงียบ ๆ แล้ว /phpmyadmin
# ตอบ 404 โดยไม่มีอะไรในล็อกบอกสาเหตุ — สร้างคืนทุกครั้งที่ซ่อม
#
# ใช้ symlink ไม่ใช่สำเนา เพื่อให้ได้แพตช์ความปลอดภัยตาม apt ของดิสทริบิวชัน
PMA_DIR="$INSTALL_DIR/public/phpmyadmin"
if [ -d /usr/share/phpmyadmin ]; then
  if [ -f "$PMA_DIR/index.php" ]; then
    dim "phpMyAdmin ผูกไว้อยู่แล้ว"
  else
    mkdir -p "$PMA_DIR"
    ln -sf /usr/share/phpmyadmin/* "$PMA_DIR/" 2>/dev/null || true
    if [ -f "$PMA_DIR/index.php" ]; then
      ok "ผูก phpMyAdmin คืนแล้ว — เข้าใช้งานที่ /phpmyadmin"
    else
      warn "ผูก phpMyAdmin ไม่สำเร็จ — ตรวจ /usr/share/phpmyadmin ด้วยตัวเอง"
    fi
  fi
else
  dim "ไม่พบ /usr/share/phpmyadmin — ข้าม (ติดตั้งด้วย apt install phpmyadmin ถ้าต้องการ)"
fi

# ---------------------------------------------------------------------------
head_ "4. รีสตาร์ตทั้งสามบริการพร้อมกัน"
# ---------------------------------------------------------------------------
systemctl daemon-reload
ok "daemon-reload"

# ต้องสั่งพร้อมกันในคำสั่งเดียว ไม่ใช่ทีละตัว — ระหว่างที่ยังไม่มี
# RuntimeDirectoryPreserve ครบทุกไฟล์ การสั่งทีละตัวจะลบ socket ของตัวอื่น
systemctl restart phpcp-agentd phpcp-fpm phpcp-web
ok "รีสตาร์ต phpcp-agentd, phpcp-fpm, phpcp-web แล้ว"

systemctl enable phpcp-agentd phpcp-fpm phpcp-web >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
head_ "5. ตรวจผล"
# ---------------------------------------------------------------------------
# socket ถูกสร้างแบบ asynchronous — รอสักครู่ก่อนตัดสินว่าหาย
for _ in $(seq 1 40); do
  [ -S "$RUN_DIR/agent.sock" ] && [ -S "$RUN_DIR/panel-fpm.sock" ] && break
  sleep 0.25
done

RESULT_BAD=0
for sock in agent.sock panel-fpm.sock; do
  if [ -S "$RUN_DIR/$sock" ]; then
    ok "$RUN_DIR/$sock — $(stat -c '%U:%G %a' "$RUN_DIR/$sock")"
  else
    warn "ไม่พบ $RUN_DIR/$sock"
    RESULT_BAD=1
  fi
done

for unit in phpcp-agentd phpcp-fpm phpcp-web; do
  systemctl is-active --quiet "$unit" && ok "$unit ทำงานอยู่" \
    || { warn "$unit ไม่ทำงาน — ดู journalctl -u $unit"; RESULT_BAD=1; }
done

CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://127.0.0.1:$PANEL_PORT/api/v2/session" || echo 000)"
if [ "$CODE" = "200" ]; then
  ok "หน้า panel ตอบ 200"
else
  warn "หน้า panel ตอบ $CODE — ดู $LOG_DIR/panel.log และ $LOG_DIR/panel-error.log"
  RESULT_BAD=1
fi

# ---------------------------------------------------------------------------
head_ "6. สถานะหลังรีสตาร์ตเครื่อง"
# ---------------------------------------------------------------------------
# "ทำงานอยู่ตอนนี้" กับ "ขึ้นเองตอนบูต" เป็นคนละเรื่อง — ตารางนี้ตอบเรื่องหลัง
BOOT_BAD=0
for unit in phpcp-agentd phpcp-fpm phpcp-web apache2 mariadb "php$PHP_VER-fpm"; do
  systemctl list-unit-files "$unit.service" >/dev/null 2>&1 || continue

  if systemctl is-enabled --quiet "$unit" 2>/dev/null; then
    state="$(systemctl is-active "$unit" 2>/dev/null || true)"
    [ "$state" = "active" ] && ok "$unit — ขึ้นเองตอนบูต · ทำงานอยู่" \
      || { warn "$unit — ขึ้นเองตอนบูต แต่ตอนนี้ $state"; BOOT_BAD=1; }
  else
    warn "$unit — ไม่ขึ้นตอนบูต (sudo systemctl enable $unit)"
    BOOT_BAD=1
  fi
done

[ "$BOOT_BAD" -eq 0 ] && ok "ทุกบริการที่จำเป็นจะกลับมาเองหลังเปิดเครื่อง"

printf '\n'
if [ "$RESULT_BAD" -eq 0 ] && [ "$BOOT_BAD" -eq 0 ]; then
  ok "ซ่อมเรียบร้อย — เปิดใช้งานได้ที่ https://localhost:$PANEL_PORT/"
  printf '\n'
else
  warn "ซ่อมเสร็จแล้วแต่ยังมีรายการที่ต้องดูต่อ (ดูบรรทัดที่ขึ้น ! ข้างบน)"
  printf '\n'
  exit 1
fi
