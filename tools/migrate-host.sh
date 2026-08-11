#!/usr/bin/env bash
#
# ย้ายเครื่องนี้มาให้ PHP Server Control Panel ดูแลแทนการตั้ง Apache ด้วยมือ
#
#   sudo tools/migrate-host.sh --check                    ตรวจอย่างเดียว ไม่แก้อะไร
#   sudo tools/migrate-host.sh                            ย้ายจริง เก็บ vhost เดิมไว้ให้ทำงานต่อ
#   sudo tools/migrate-host.sh --retire-legacy            ย้ายจริง + ปิด vhost เดิมทั้งหมด
#   sudo tools/migrate-host.sh --sites-dir=/srv/phpcp/sites --php=8.4,8.3
#
# สิ่งที่สคริปต์นี้ทำ
#   1. สำรอง /etc/apache2, /etc/phpcp และฐานข้อมูล panel ไว้ก่อนเสมอ
#   2. ติดตั้ง phpX.Y-fpm ตามเวอร์ชันที่ระบุ (panel จ่ายงาน PHP ผ่าน FPM ไม่ใช่ mod_php)
#   3. เปิด proxy_fcgi/ssl/rewrite/headers, ปิด mod_php, สลับ prefork → event
#   4. จัดการ vhost เดิมที่ตั้งด้วยมือ (ดู --retire-legacy ข้างล่าง)
#   5. เขียนบล็อก sites ลง /etc/phpcp/config.php ให้ตรงกับที่เก็บไฟล์จริงของเครื่อง
#   6. อัปเดตโค้ดที่ /usr/share/phpcp แล้วรัน db:migrate
#   7. เปิดบริการ phpcp-agentd, phpcp-fpm, phpcp-web
#
# โหมดปกติ (ไม่ใส่ --retire-legacy)
#   vhost เดิมยังทำงานต่อโดยรัน PHP ผ่าน FPM pool กลาง ที่รันเป็น www-data
#   เหมือนที่ mod_php เคยให้ — สะดวกตอนย้าย แต่ไม่ได้ปลอดภัยขึ้น
#
# โหมด --retire-legacy
#   ปิด vhost ที่ไม่ได้สร้างจาก panel ทั้งหมด แล้วให้ panel คุมพอร์ต 80/443 แต่ผู้เดียว
#   เว็บเดิมจะหยุดทันที จนกว่าจะสร้างใหม่ใน panel — ไฟล์เดิมยังอยู่ครบทุกไฟล์
#
# สิ่งที่สคริปต์นี้ไม่ทำ — ตั้งใจให้ทำเองผ่านหน้าเว็บ เพราะต้องตัดสินใจทีละเว็บ
#   • ไม่ลบไฟล์ vhost เดิมของคุณ (แค่ปิดการใช้งาน)
#   • ไม่ย้ายไฟล์เว็บไซต์
#   • ไม่แตะฐานข้อมูล MariaDB ของเว็บ

set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

INSTALL_DIR="/usr/share/phpcp"
CONF_DIR="/etc/phpcp"
DATA_DIR="/var/lib/phpcp"
PANEL_USER="phpcp-web"
PANEL_GROUP="phpcp"

CHECK_ONLY="no"
RETIRE_LEGACY="no"
SITES_DIR="/mnt/Server/htdocs"
PHP_VERSIONS="8.4"
LEGACY_PHP="8.4"      # เวอร์ชันที่ vhost เดิม (ที่ตั้งด้วยมือ) จะใช้หลังปิด mod_php
POINTER_ROOTS="/mnt/Server/htdocs"

# ---------------------------------------------------------------------------
# ข้อความ
# ---------------------------------------------------------------------------
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
head_() { printf '\n%s\n%s\n' "$*" "$(printf '─%.0s' $(seq 1 56))"; }

# รันคำสั่งจริง หรือแค่แสดงเมื่ออยู่ในโหมด --check
run() {
  if [ "$CHECK_ONLY" = "yes" ]; then
    dim "จะรัน: $*"
  else
    "$@"
  fi
}

# รันคำสั่งด้วยสิทธิ์ของผู้ใช้ panel (ไม่ใช่ root) — ให้เหมือน install.sh
# ไฟล์ที่ CLI ของ panel สร้าง/แตะ (โดยเฉพาะ panel.db ที่เปิดโหมด WAL แล้วมี
# .db-wal/.db-shm ตามมา) ต้องเป็นของ $PANEL_USER ไม่งั้น phpcp-fpm ที่รันเป็น
# ผู้ใช้นั้นจะเปิดไฟล์เองไม่ได้ทีหลัง (unable to open database file)
#
# runuser มากับ util-linux จึงมีเสมอ ต่างจาก sudo ที่อาจไม่ได้ติดตั้งบน Debian แบบ minimal
as_panel() {
  if command -v runuser >/dev/null; then
    runuser -u "$PANEL_USER" -- "$@"
  else
    sudo -u "$PANEL_USER" "$@"
  fi
}

# ---------------------------------------------------------------------------
# อ่านตัวเลือก
# ---------------------------------------------------------------------------
for arg in "$@"; do
  case "$arg" in
    --check|--dry-run)  CHECK_ONLY="yes" ;;
    --retire-legacy)    RETIRE_LEGACY="yes" ;;
    --sites-dir=*)      SITES_DIR="${arg#*=}" ;;
    --php=*)            PHP_VERSIONS="${arg#*=}" ;;
    --legacy-php=*)     LEGACY_PHP="${arg#*=}" ;;
    --pointer-roots=*)  POINTER_ROOTS="${arg#*=}" ;;
    -h|--help)
      sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "ไม่รู้จักตัวเลือก: $arg" ;;
  esac
done

SITES_DIR="${SITES_DIR%/}"
case "$SITES_DIR" in
  /*) ;;
  *)  die "--sites-dir ต้องเป็นเส้นทางสัมบูรณ์: $SITES_DIR" ;;
esac
case "$SITES_DIR" in
  */../*|*/..) die "--sites-dir ต้องไม่มี .. : $SITES_DIR" ;;
esac

IFS=',' read -r -a PHP_LIST <<< "$PHP_VERSIONS"
for v in "${PHP_LIST[@]}"; do
  [[ "$v" =~ ^[578]\.[0-9]+$ ]] || die "เวอร์ชัน PHP ไม่ถูกต้อง: $v"
done

[[ "$LEGACY_PHP" =~ ^[578]\.[0-9]+$ ]] || die "เวอร์ชัน --legacy-php ไม่ถูกต้อง: $LEGACY_PHP"

# handler สำรองของ vhost เดิมชี้ไปที่ socket ของ LEGACY_PHP — ถ้าเวอร์ชันนั้นไม่ได้ถูกติดตั้ง
# socket จะไม่มีอยู่จริง แล้วทุกเว็บเดิมจะตอบ 503 ทันทีที่ reload apache — จึงต้องบังคับให้มันอยู่ในรายการที่ติดตั้ง
# โหมด --retire-legacy ไม่มี handler นี้ จึงไม่ต้องลง PHP เวอร์ชันนั้นเพิ่มโดยไม่จำเป็น
if [ "$RETIRE_LEGACY" = "no" ] && ! printf '%s\n' "${PHP_LIST[@]}" | grep -qx "$LEGACY_PHP"; then
  PHP_LIST+=("$LEGACY_PHP")
  PHP_VERSIONS="$PHP_VERSIONS,$LEGACY_PHP"
fi

# ---------------------------------------------------------------------------
head_ "0. ตรวจก่อนเริ่ม"
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "ต้องรันด้วย sudo"
[ "$CHECK_ONLY" = "yes" ] && warn "โหมดตรวจอย่างเดียว — ไม่มีอะไรถูกแก้"

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "ไม่พบคำสั่ง php"
[ -d "$INSTALL_DIR" ] || die "ยังไม่ได้ติดตั้ง panel — รัน sudo ./install.sh ก่อน"
[ -f "$CONF_DIR/config.php" ] || die "ไม่พบ $CONF_DIR/config.php — รัน sudo ./install.sh ก่อน"
[ -x /usr/sbin/apache2 ] || die "ไม่พบ apache2 — สคริปต์นี้เขียนไว้สำหรับเครื่องที่ใช้ Apache"
ok "panel ติดตั้งอยู่แล้วที่ $INSTALL_DIR"

if [ "$RETIRE_LEGACY" = "yes" ]; then
  warn "โหมดเลิกใช้ของเดิม — vhost ที่ตั้งด้วยมือทั้งหมดจะถูกปิด เว็บเดิมจะเข้าไม่ได้"
else
  dim "โหมดปกติ — vhost เดิมยังทำงานต่อ (ใส่ --retire-legacy เพื่อเลิกใช้ของเดิม)"
fi

[ -d "$SITES_DIR" ] || die "ไม่พบไดเรกทอรี $SITES_DIR"
FS_TYPE="$(stat -f -c %T "$SITES_DIR")"
say "ที่เก็บไฟล์เว็บไซต์ : $SITES_DIR ($FS_TYPE)"

# ตัดสินใจเรื่องการแยกสิทธิ์จาก filesystem จริง ไม่ใช่จากชื่อไดเรกทอรี
# ทดสอบด้วยการ chown ไฟล์จริงแล้วอ่านกลับ เพราะ NTFS รับคำสั่งเงียบ ๆ โดยไม่เปลี่ยนอะไร
SHARED_OWNER="false"
PROBE="$SITES_DIR/.phpcp-migrate-probe.$$"
if : > "$PROBE" 2>/dev/null; then
  chown nobody "$PROBE" 2>/dev/null || true
  [ "$(stat -c %U "$PROBE")" = "nobody" ] || SHARED_OWNER="true"
  rm -f "$PROBE"
else
  die "เขียนไฟล์ใน $SITES_DIR ไม่ได้"
fi

if [ "$SHARED_OWNER" = "true" ]; then
  warn "filesystem นี้เก็บเจ้าของไฟล์ไม่ได้ → จะตั้ง sites.shared_owner = true"
  warn "แต่ละเว็บจะ 'อ่านไฟล์ของเว็บอื่นได้' — เหมาะกับเครื่องที่เว็บทั้งหมดเป็นของคุณคนเดียว"
  warn "ถ้าเครื่องนี้จะให้คนอื่นใช้ ให้ย้ายที่เก็บไปยัง ext4 ด้วย --sites-dir=/srv/phpcp/sites"
else
  ok "filesystem รองรับการแยกสิทธิ์ตามปกติ → sites.shared_owner = false"
fi

# ---------------------------------------------------------------------------
head_ "1. สำรองของเดิม"
# ---------------------------------------------------------------------------
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$DATA_DIR/backups/pre-migrate-$STAMP.tar.gz"
run mkdir -p "$DATA_DIR/backups"
if [ "$CHECK_ONLY" = "yes" ]; then
  dim "จะสำรอง /etc/apache2 /etc/phpcp $DATA_DIR/panel.db ไปที่ $BACKUP"
else
  tar -czf "$BACKUP" -C / etc/apache2 etc/phpcp \
      $([ -f "$DATA_DIR/panel.db" ] && echo "${DATA_DIR#/}/panel.db") 2>/dev/null
  chmod 600 "$BACKUP"
  ok "สำรองไว้ที่ $BACKUP"
  dim "กู้คืน: sudo systemctl stop apache2 && sudo tar -xzf $BACKUP -C /"
fi

# ---------------------------------------------------------------------------
head_ "2. ติดตั้ง PHP-FPM"
# ---------------------------------------------------------------------------
# panel จ่ายงาน PHP ให้แต่ละเว็บผ่าน FPM pool คนละ socket คนละผู้ใช้
# mod_php ทำแบบนั้นไม่ได้เลยเพราะรันทุกเว็บในโปรเซสเดียวของ Apache
MISSING=""
for v in "${PHP_LIST[@]}"; do
  [ -d "/etc/php/$v/fpm" ] || MISSING="$MISSING php$v-fpm"
done

if [ -n "$MISSING" ]; then
  say "ยังไม่มี:$MISSING"
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y $MISSING
  for v in "${PHP_LIST[@]}"; do
    [ "$CHECK_ONLY" = "yes" ] || [ -d "/etc/php/$v/fpm" ] || die "ติดตั้ง php$v-fpm ไม่สำเร็จ"
  done
  [ "$CHECK_ONLY" = "yes" ] || ok "ติดตั้ง PHP-FPM แล้ว"
else
  ok "มี PHP-FPM ครบทุกเวอร์ชันที่ต้องการแล้ว"
fi

for v in "${PHP_LIST[@]}"; do
  run systemctl enable --now "php$v-fpm"
done

# ---------------------------------------------------------------------------
head_ "3. ปรับ Apache ให้ใช้ FPM"
# ---------------------------------------------------------------------------
for mod in proxy proxy_fcgi setenvif ssl rewrite headers; do
  if [ -e "/etc/apache2/mods-enabled/$mod.load" ]; then
    dim "เปิดอยู่แล้ว: $mod"
  else
    run a2enmod -q "$mod"
    [ "$CHECK_ONLY" = "yes" ] || ok "เปิดโมดูล $mod"
  fi
done

# mod_php ต้องปิด ไม่ใช่แค่ "ไม่ใช้" — ถ้ายังโหลดอยู่ Apache จะบังคับใช้ prefork
# และไฟล์ .php ที่หลุดออกนอก vhost ของ panel จะรันด้วยสิทธิ์ www-data ทั้งเครื่อง
for f in /etc/apache2/mods-enabled/php*.load; do
  [ -e "$f" ] || continue
  m="$(basename "$f" .load)"
  run a2dismod -q "$m"
  [ "$CHECK_ONLY" = "yes" ] || ok "ปิด mod_php ($m)"
done

if [ -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
  run a2dismod -q mpm_prefork
  run a2enmod -q mpm_event
  [ "$CHECK_ONLY" = "yes" ] || ok "สลับ MPM: prefork → event"
else
  dim "MPM ไม่ใช่ prefork อยู่แล้ว"
fi

# ---------------------------------------------------------------------------
head_ "4. จัดการ vhost เดิม"
# ---------------------------------------------------------------------------
LEGACY_SOCKET="/run/php/php$LEGACY_PHP-fpm.sock"
LEGACY_CONF="/etc/apache2/conf-available/phpcp-legacy-fpm.conf"
CATCHALL="/etc/apache2/sites-available/phpcp-000-catchall.conf"

# vhost ที่ไม่ได้ขึ้นต้นด้วย phpcp- คือของที่ตั้งด้วยมือไว้ก่อนหน้านี้
LEGACY_SITES=""
for f in /etc/apache2/sites-enabled/*.conf; do
  [ -e "$f" ] || continue
  b="$(basename "$f" .conf)"
  case "$b" in
    phpcp-*) ;;
    *) LEGACY_SITES="$LEGACY_SITES $b" ;;
  esac
done

if [ "$RETIRE_LEGACY" = "yes" ]; then
  # โหมดเลิกใช้ของเดิม — panel คุมพอร์ต 80/443 แต่ผู้เดียว
  #
  # ปิด vhost เดิมแทนที่จะวาง handler สำรอง ทำให้ปัญหา "ปิด mod_php แล้ว .php
  # กลายเป็นข้อความธรรมดา" หายไปด้วยตัวมันเอง เพราะไม่มี vhost ไหนเสิร์ฟไฟล์นั้นแล้ว
  if [ -n "$LEGACY_SITES" ]; then
    for b in $LEGACY_SITES; do
      run a2dissite -q "$b"
      [ "$CHECK_ONLY" = "yes" ] || ok "ปิด vhost เดิม: $b"
    done
  else
    dim "ไม่มี vhost เดิมที่ต้องปิด"
  fi

  if [ -e /etc/apache2/conf-enabled/phpcp-legacy-fpm.conf ]; then
    run a2disconf -q phpcp-legacy-fpm
    [ "$CHECK_ONLY" = "yes" ] || ok "ปิด handler สำรองของ vhost เดิม"
  fi

  # ต้องมี vhost ตัวแรกที่ไม่เสิร์ฟอะไรเลย ไม่งั้น Host ที่ไม่ตรงกับเว็บไหนจะตกไปที่
  # เว็บแรกตามลำดับไฟล์ — เท่ากับเว็บของคนหนึ่งถูกเปิดผ่านชื่อโดเมนของอีกคนได้
  # ชื่อไฟล์ขึ้นต้นด้วย 000 เพราะ Apache เลือก vhost ตัวแรกที่โหลดเป็นค่าเริ่มต้น
  if [ "$CHECK_ONLY" = "yes" ]; then
    dim "จะเขียน $CATCHALL แล้วเปิดใช้งาน (ตอบ 404 ให้ Host ที่ไม่รู้จัก)"
  else
    # ไดเรกทอรีว่างจริง ๆ นอก /var/lib/phpcp เพราะที่นั่นเป็น 750 ของผู้ใช้ panel
    # ซึ่ง www-data เข้าไม่ได้ แล้ว Apache จะบ่นทุกคำขอโดยไม่จำเป็น
    mkdir -p /var/www/phpcp-catchall
    chmod 755 /var/www/phpcp-catchall
    cat > "$CATCHALL" <<'EOF'
# สร้างโดย tools/migrate-host.sh --retire-legacy
#
# ตัวรับคำขอที่ Host ไม่ตรงกับเว็บไหนเลย — ตอบ 404 อย่างเดียว ไม่เสิร์ฟไฟล์
# ถ้าไม่มีไฟล์นี้ Apache จะยกเว็บแรกตามลำดับตัวอักษรขึ้นมาเป็นค่าเริ่มต้นแทน
# ซึ่งแปลว่าเว็บนั้นเปิดได้ผ่านชื่อโดเมนอะไรก็ได้ที่ชี้มาที่เครื่องนี้
#
# ห้ามเปลี่ยนชื่อไฟล์ให้เรียงหลัง phpcp-<โดเมน> ไม่งั้นมันจะกลืนเว็บทุกเว็บแทน
<VirtualHost *:80>
    ServerName phpcp-catchall.invalid
    DocumentRoot /var/www/phpcp-catchall
    RedirectMatch 404 ^/
    ErrorLog ${APACHE_LOG_DIR}/phpcp-catchall-error.log
</VirtualHost>
EOF
    a2ensite -q phpcp-000-catchall
    ok "Host ที่ไม่ตรงกับเว็บไหนจะได้ 404 (phpcp-000-catchall)"

    # พอร์ต 443 ต้องมีตัวรับของตัวเองด้วย ไม่งั้น SNI ที่ไม่ตรงกับเว็บไหนจะตกไปที่
    # vhost SSL ตัวแรก — ผู้ใช้ปลายทางเห็นคำเตือนใบรับรอง แต่เนื้อหาเว็บนั้นออกไปแล้ว
    # ใช้ใบรับรอง snakeoil ของ distro เพราะตัวนี้ไม่ได้ตั้งใจให้ใครเชื่อถืออยู่แล้ว
    if [ -f /etc/ssl/certs/ssl-cert-snakeoil.pem ] && [ -f /etc/ssl/private/ssl-cert-snakeoil.key ]; then
      cat >> "$CATCHALL" <<'EOF'

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName phpcp-catchall.invalid
    DocumentRoot /var/www/phpcp-catchall
    RedirectMatch 404 ^/
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key
    ErrorLog ${APACHE_LOG_DIR}/phpcp-catchall-error.log
</VirtualHost>
</IfModule>
EOF
      ok "SNI ที่ไม่ตรงกับเว็บไหนก็ได้ 404 เช่นกัน (ใบรับรอง snakeoil)"
    else
      warn "ไม่พบใบรับรอง snakeoil — พอร์ต 443 จะยังตกไปที่เว็บ SSL ตัวแรก ติดตั้งด้วย: apt install ssl-cert"
    fi
  fi

  # /phpmyadmin ของ distro เป็น Alias ระดับ server จึงโผล่บน "ทุก" โดเมนที่ panel สร้าง
  # panel มีสำเนาของตัวเองอยู่หลังการล็อกอินที่พอร์ต panel แล้ว จึงปิดตัวนี้ทิ้ง
  if [ -e /etc/apache2/conf-enabled/phpmyadmin.conf ]; then
    run a2disconf -q phpmyadmin
    [ "$CHECK_ONLY" = "yes" ] || ok "ปิด /phpmyadmin ระดับ server — ใช้ของ panel แทน"
  fi

  warn "เว็บเดิมจะเข้าไม่ได้ทันทีหลังจากนี้ จนกว่าจะสร้างใหม่ในหน้า panel"
  dim "ไฟล์ vhost เดิมยังอยู่ครบใน /etc/apache2/sites-available — เปิดคืนด้วย a2ensite <ชื่อ>"
else
  # โหมดปกติ — vhost เดิมพึ่ง mod_php ทั้งหมด พอปิด mod_php ไฟล์ .php จะถูกส่งเป็น
  # ข้อความธรรมดา ซึ่งแปลว่า source code ทั้งเครื่องรั่วออกเว็บทันที จึงต้องวาง handler
  # กลางไว้ก่อน แล้วค่อยทยอยย้ายแต่ละเว็บเข้ามาใน panel
  #
  # vhost ที่ panel สร้างประกาศ handler ของตัวเองใน <VirtualHost> ซึ่ง Apache รวมค่า
  # ทีหลังกว่าระดับ server จึงชนะเสมอ — เว็บที่ย้ายเข้ามาแล้วยังคงแยก pool ตามเดิม
  if [ "$CHECK_ONLY" = "yes" ]; then
    dim "จะเขียน $LEGACY_CONF ให้ .php ไปที่ $LEGACY_SOCKET"
  else
    cat > "$LEGACY_CONF" <<EOF
# สร้างโดย tools/migrate-host.sh เมื่อ $(date '+%Y-%m-%d %H:%M:%S')
#
# ตัวสำรองสำหรับ vhost ที่ยังไม่ได้ย้ายเข้ามาใน PHP Server Control Panel
# เว็บที่ย้ายเข้า panel แล้วจะมี handler ของตัวเองใน <VirtualHost> ซึ่งชนะไฟล์นี้
#
# เมื่อย้ายครบทุกเว็บแล้วให้ปิดด้วย: sudo a2disconf phpcp-legacy-fpm
<FilesMatch "\.php\$">
    SetHandler "proxy:unix:$LEGACY_SOCKET|fcgi://localhost"
</FilesMatch>
EOF
    a2enconf -q phpcp-legacy-fpm
    ok "vhost เดิมจะรัน PHP ผ่าน $LEGACY_SOCKET"
    warn "pool นี้รันเป็น www-data ไม่มี open_basedir — เท่ากับของเดิมที่ mod_php ให้ ไม่ใช่ของใหม่ที่ปลอดภัยขึ้น"
  fi
fi

# ---------------------------------------------------------------------------
head_ "5. ตั้งค่า config ของ panel"
# ---------------------------------------------------------------------------
if [ "$CHECK_ONLY" = "yes" ]; then
  dim "จะตั้ง sites.dir = $SITES_DIR"
  dim "จะตั้ง sites.shared_owner = $SHARED_OWNER"
  dim "จะตั้ง sites.pointer_roots = $POINTER_ROOTS"
else
  "$PHP_BIN" -d error_reporting=E_ALL "$SRC_DIR/tools/write-sites-config.php" \
    "$CONF_DIR/config.php" "$SITES_DIR" "$SHARED_OWNER" "$POINTER_ROOTS" \
    || die "เขียนบล็อก sites ลง config.php ไม่สำเร็จ"
  chown root:"$PANEL_GROUP" "$CONF_DIR/config.php"
  chmod 640 "$CONF_DIR/config.php"
  ok "เขียนบล็อก sites ลง $CONF_DIR/config.php"
fi

run mkdir -p "$SITES_DIR"

# ---------------------------------------------------------------------------
head_ "6. อัปเดตโค้ดและฐานข้อมูล"
# ---------------------------------------------------------------------------
if [ "$CHECK_ONLY" = "yes" ]; then
  dim "จะคัดลอก bin src templates db docs public จาก $SRC_DIR ไปที่ $INSTALL_DIR"
  dim "จะรัน $INSTALL_DIR/bin/phpcp db:migrate"
else
  for dir in bin src templates db docs public; do
    [ -d "$SRC_DIR/$dir" ] || die "ไม่พบ $SRC_DIR/$dir"
  done
  # public/phpmyadmin ถูกวางโดยตัวติดตั้ง ไม่ได้อยู่ใน repo — เก็บไว้ก่อนแล้วคืนที่เดิม
  PMA_KEEP=""
  if [ -e "$INSTALL_DIR/public/phpmyadmin" ]; then
    PMA_KEEP="$(mktemp -d)"
    cp -a "$INSTALL_DIR/public/phpmyadmin" "$PMA_KEEP/"
  fi

  for dir in bin src templates db docs public; do
    rm -rf "${INSTALL_DIR:?}/$dir"
    cp -a "$SRC_DIR/$dir" "$INSTALL_DIR/"
  done
  cp -a "$SRC_DIR/bootstrap.php" "$INSTALL_DIR/"

  if [ -n "$PMA_KEEP" ]; then
    cp -a "$PMA_KEEP/phpmyadmin" "$INSTALL_DIR/public/"
    rm -rf "$PMA_KEEP"
  fi

  # ถ้ารอบก่อนไม่เคยมีลิงก์ (เพิ่งติดตั้ง phpmyadmin ทีหลัง หรือลิงก์หายไปแล้ว)
  # การ "เก็บของเดิมไว้" อย่างเดียวจะไม่พอ ต้องสร้างใหม่ให้ด้วย ไม่งั้น /phpmyadmin
  # จะตอบ 404 ตลอดไปโดยไม่มีอะไรบอก
  if [ -d /usr/share/phpmyadmin ] && [ ! -f "$INSTALL_DIR/public/phpmyadmin/index.php" ]; then
    mkdir -p "$INSTALL_DIR/public/phpmyadmin"
    ln -sf /usr/share/phpmyadmin/* "$INSTALL_DIR/public/phpmyadmin/" 2>/dev/null || true
    [ -f "$INSTALL_DIR/public/phpmyadmin/index.php" ] && ok "ผูก phpMyAdmin ที่ /phpmyadmin"
  fi

  chown -R root:root "$INSTALL_DIR"
  find "$INSTALL_DIR" -type d -exec chmod 755 {} +
  find "$INSTALL_DIR" -type f -exec chmod 644 {} +
  chmod 755 "$INSTALL_DIR/bin/phpcp" "$INSTALL_DIR/bin/phpcp-agentd"
  ok "อัปเดตโค้ดที่ $INSTALL_DIR"

  # คืนความเป็นเจ้าของ DATA_DIR ก่อนเสมอ เผื่อรอบก่อนหน้าเคยรัน db:migrate ด้วย
  # root แล้วทิ้ง panel.db (หรือ .db-wal/.db-shm) ไว้เป็นของ root ค้างอยู่
  chown -R "$PANEL_USER:$PANEL_GROUP" "$DATA_DIR"
  as_panel "$PHP_BIN" "$INSTALL_DIR/bin/phpcp" db:migrate || die "db:migrate ไม่สำเร็จ"
  ok "ฐานข้อมูล panel เป็นรุ่นล่าสุด"
fi

# ---------------------------------------------------------------------------
head_ "7. เปิดบริการ"
# ---------------------------------------------------------------------------
run systemctl daemon-reload

# เก็บ unit ที่มีจริงไว้ก่อน แล้วสั่งทีเดียวพร้อมกันตอนท้าย
PANEL_UNITS=""
for unit in phpcp-agentd phpcp-fpm phpcp-web; do
  if systemctl list-unit-files "$unit.service" >/dev/null 2>&1; then
    PANEL_UNITS="$PANEL_UNITS $unit"
  else
    warn "ไม่พบ $unit.service"
  fi
done

if [ -n "$PANEL_UNITS" ]; then
  # shellcheck disable=SC2086
  run systemctl enable $PANEL_UNITS

  # ต้อง restart ไม่ใช่ `enable --now` — ขั้นที่ 6 เพิ่งเขียนทับโค้ดที่ $INSTALL_DIR
  # แต่ phpcp-agentd เป็นโปรเซส PHP ที่รันค้างยาว ๆ มันโหลดโค้ดตอนสตาร์ตครั้งเดียว
  # ถ้าไม่ restart agent จะยังทำงานด้วยโค้ดชุดเก่าต่อไปโดยไม่มีอะไรบอก
  #
  # สั่งทุก unit ในคำสั่งเดียว เพราะทั้งสามใช้ RuntimeDirectory=phpcp ร่วมกัน
  # การสั่งทีละตัวบนเครื่องที่ยังใช้ unit รุ่นเก่า (ไม่มี RuntimeDirectoryPreserve)
  # จะลบ socket ของอีกสองตัวทิ้ง แล้ว panel จะตอบ 503 ทั้งที่ทุกบริการขึ้น active
  # shellcheck disable=SC2086
  run systemctl restart $PANEL_UNITS
  [ "$CHECK_ONLY" = "yes" ] || ok "เปิดและรีสตาร์ต:$PANEL_UNITS"
fi

# apache2 คือตัวเสิร์ฟเว็บของผู้ใช้ทั้งหมด ถ้ามัน disabled อยู่ เว็บจะหายหมดตอนรีสตาร์ตเครื่อง
# โดยที่สคริปต์นี้รายงานว่าสำเร็จ — enable ซ้ำไม่มีผลเสียถ้าเปิดอยู่แล้ว
if ! systemctl is-enabled --quiet apache2 2>/dev/null; then
  run systemctl enable apache2
  [ "$CHECK_ONLY" = "yes" ] || ok "ตั้งให้ apache2 สตาร์ตตอนเปิดเครื่อง"
else
  dim "apache2 สตาร์ตตอนเปิดเครื่องอยู่แล้ว"
fi

# เว็บส่วนใหญ่พึ่งฐานข้อมูล — ไม่แตะข้อมูล แต่เตือนถ้ามันจะไม่ขึ้นตอนบูต
if systemctl list-unit-files mariadb.service >/dev/null 2>&1 \
   && ! systemctl is-enabled --quiet mariadb 2>/dev/null; then
  warn "mariadb ไม่ได้ตั้งให้สตาร์ตตอนเปิดเครื่อง — เปิดด้วย sudo systemctl enable mariadb"
fi

if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q '^Status: active'; then
  for port in 80/tcp 443/tcp 8443/tcp; do
    run ufw allow "$port"
  done
  [ "$CHECK_ONLY" = "yes" ] || ok "เปิดพอร์ต 80, 443, 8443 บน ufw"
fi

# ---------------------------------------------------------------------------
head_ "8. ตรวจผล"
# ---------------------------------------------------------------------------
if [ "$CHECK_ONLY" = "yes" ]; then
  dim "จะรัน apache2ctl -t แล้ว reload apache2"
  printf '\n  %sตรวจเสร็จ — รันซ้ำโดยไม่ใส่ --check เพื่อทำจริง%s\n\n' "$C_WARN" "$C_OFF"
  exit 0
fi

apache2ctl -t 2>&1 | grep -q 'Syntax OK' || {
  warn "Apache config ไม่ผ่าน — ยังไม่ reload"
  apache2ctl -t 2>&1 | tail -5
  die "กู้คืนด้วย: sudo tar -xzf $BACKUP -C / && sudo systemctl restart apache2"
}
ok "Apache config ผ่าน"

systemctl restart apache2 || die "restart apache2 ไม่สำเร็จ"
ok "Apache ทำงานอยู่"

for unit in phpcp-agentd phpcp-web; do
  systemctl is-active --quiet "$unit" && ok "$unit ทำงานอยู่" || warn "$unit ไม่ทำงาน — ดู journalctl -u $unit"
done

PANEL_PORT="$("$PHP_BIN" -r '$c=require $argv[1]; echo (int)($c["panel"]["port"] ?? 8443);' "$CONF_DIR/config.php" 2>/dev/null || echo 8443)"
CODE="$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$PANEL_PORT/api/v2/session" || echo 000)"
[ "$CODE" = "200" ] && ok "หน้า panel ตอบ 200" || warn "หน้า panel ตอบ $CODE"

# ---------------------------------------------------------------------------
head_ "9. สถานะหลังรีสตาร์ตเครื่อง"
# ---------------------------------------------------------------------------
# "ทำงานอยู่ตอนนี้" กับ "ขึ้นเองตอนบูต" เป็นคนละเรื่อง — ตารางนี้ตอบเรื่องหลัง
BOOT_BAD=0
for unit in apache2 mariadb phpcp-agentd phpcp-fpm phpcp-web $(printf 'php%s-fpm ' "${PHP_LIST[@]}"); do
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

if [ "$RETIRE_LEGACY" = "yes" ]; then
  cat <<EOF

$(head_ "ขั้นต่อไป — ทำผ่านหน้าเว็บ")
  Apache รับพอร์ต 80/443 ให้เฉพาะเว็บที่สร้างจาก panel แล้ว
  Host ที่ไม่ตรงกับเว็บไหนจะได้ 404 ไม่ใช่เว็บแรกในรายการ

  1. เปิด https://localhost:$PANEL_PORT/
  2. สร้างเว็บไซต์ แล้วกรอกช่อง "โฟลเดอร์ที่เก็บไฟล์" ให้ชี้ไปยังโปรเจกต์เดิม
       ตัวอย่าง: โดเมน bbl.local → $SITES_DIR/BBL
  3. เพิ่มโดเมนลง /etc/hosts (ใช้ในเครื่อง) หรือชี้ DNS มาที่เครื่องนี้

  vhost เดิมที่ปิดไป:${LEGACY_SITES:- (ไม่มี)}
  ไฟล์ยังอยู่ครบใน /etc/apache2/sites-available — เปิดคืนทีละตัวได้ด้วย a2ensite <ชื่อ>

  กู้คืนทั้งหมด: sudo tar -xzf $BACKUP -C / && sudo systemctl restart apache2
  สำรองก่อนย้าย: $BACKUP

EOF
else
  cat <<EOF

$(head_ "ขั้นต่อไป — ทำผ่านหน้าเว็บ")
  1. เปิด https://localhost:$PANEL_PORT/
  2. สร้างเว็บไซต์ใหม่ แล้วกรอกช่อง "โฟลเดอร์ที่เก็บไฟล์" ให้ชี้ไปยังโปรเจกต์เดิม
       ตัวอย่าง: โดเมน bbl.local → $SITES_DIR/BBL
  3. เพิ่มโดเมนลง /etc/hosts (สำหรับใช้ในเครื่อง) หรือชี้ DNS มาที่เครื่องนี้
  4. เมื่อย้ายครบทุกเว็บแล้ว ให้เลิกใช้ของเดิมด้วย
       sudo tools/migrate-host.sh --retire-legacy

  สำรองก่อนย้าย: $BACKUP

EOF
fi
