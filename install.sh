#!/usr/bin/env bash
#
# ตัวติดตั้ง PHP Server Control Panel — ARCHITECTURE §13
#
#   sudo ./install.sh                          ติดตั้งใช้งานจริง
#   sudo ./install.sh --mode=sandbox           ติดตั้งเพื่อทดสอบ ไม่แตะระบบจริง
#   ./install.sh --mode=sandbox --portable     ทดสอบในโฟลเดอร์โปรเจกต์ ไม่ต้องใช้ root
#
#   --users-dir=/mnt/Server/hosting    เก็บบ้านของผู้ใช้ที่อื่นแทน /srv/phpcp/users
#   --sites-dir=/mnt/Server/htdocs     ที่เก็บเว็บของเลย์เอาต์เดิม (ก่อน migration 0006)
#   --pointer-root=/mnt/Server/htdocs  ยอมให้ชี้ DocumentRoot เข้าโฟลเดอร์นี้ (ซ้ำได้)
#   --shared-owner                     เฉพาะ NTFS/exFAT/FAT — ข้ามการแยกสิทธิ์ระหว่างเว็บ
#
# หลักการ: ทุกอย่างของ panel อยู่ใน config tree ของตัวเอง
# ไม่แตะ /etc/apache2 และ /etc/php ของระบบเลยแม้แต่ไฟล์เดียว (ARCHITECTURE §5.2)

set -euo pipefail

MODE="production"
PORT="8443"
PORTABLE="no"
ADMIN_USER="admin"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

INSTALL_DIR="/usr/share/phpcp"
CONF_DIR="/etc/phpcp"
DATA_DIR="/var/lib/phpcp"
LOG_DIR="/var/log/phpcp"
RUN_DIR="/run/phpcp"
# tmp ของ panel อยู่ใต้ DATA_DIR ไม่ใช่ /tmp เพราะ /tmp ถูกล้างตอนบูตแล้วบริการจะสตาร์ตไม่ขึ้น
TMP_DIR="$DATA_DIR/tmp"
SITES_DIR="/srv/phpcp/sites"
# บ้านของผู้ใช้โฮสติ้ง — ไฟล์เว็บอยู่ที่ <USERS_DIR>/<ผู้ใช้>/domains/<โดเมน>/
# ตั้งแต่ migration 0006 (uid และโควตาดิสก์ผูกกับผู้ใช้ ไม่ใช่ผูกกับเว็บ)
USERS_DIR="/srv/phpcp/users"
SHARED_OWNER="no"
POINTER_ROOTS=""  # ว่าง = ใช้ค่าจาก sites_dir อัตโนมัติ
SANDBOX_DIR="/opt/phpcp-sandbox"
PANEL_USER="phpcp-web"
PANEL_GROUP="phpcp"

# ---------------------------------------------------------------------------
# ข้อความ
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_DIM=$'\033[90m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_DIM=""; C_OFF=""
fi

say()  { printf '  %s\n' "$*"; }
ok()   { printf '  %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '  %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()  { printf '  %s✘%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }
head_() { printf '\n%s\n%s\n' "$*" "$(printf '─%.0s' $(seq 1 46))"; }

# ---------------------------------------------------------------------------
# อ่านตัวเลือก
# ---------------------------------------------------------------------------
for arg in "$@"; do
  case "$arg" in
    --mode=*)         MODE="${arg#*=}" ;;
    --port=*)         PORT="${arg#*=}" ;;
    --user=*)         ADMIN_USER="${arg#*=}" ;;
    --portable)       PORTABLE="yes" ;;
    --sites-dir=*)    SITES_DIR="${arg#*=}" ;;
    --users-dir=*)    USERS_DIR="${arg#*=}" ;;
    --shared-owner)   SHARED_OWNER="yes" ;;
    --pointer-root=*) POINTER_ROOTS="${POINTER_ROOTS}${POINTER_ROOTS:+,}${arg#*=}" ;;
    -h|--help)
      sed -n '2,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "ไม่รู้จักตัวเลือก: $arg" ;;
  esac
done

case "$MODE" in
  production|sandbox|dryrun) ;;
  *) die "โหมดต้องเป็น production, sandbox หรือ dryrun" ;;
esac

if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
  die "หมายเลขพอร์ตไม่ถูกต้อง: $PORT"
fi

# เส้นทางจากบรรทัดคำสั่งจะถูกเขียนลง config แล้วกลายเป็น DocumentRoot ของ vhost — ตรวจตั้งแต่ต้นทาง
check_abs_path() {
  case "$1" in
    /*) ;;
    *) die "$2 ต้องเป็นเส้นทางสัมบูรณ์: $1" ;;
  esac
  case "/$1/" in
    */../*) die "$2 ต้องไม่มี .. : $1" ;;
  esac
  case "$1" in
    *[\'\"\\]*) die "$2 มีอักขระที่ใช้ในไฟล์ตั้งค่าไม่ได้: $1" ;;
  esac
}

SITES_DIR="$(printf '%s' "$SITES_DIR" | sed 's:/*$::')"
check_abs_path "$SITES_DIR" "--sites-dir"
[ "$SITES_DIR" = "/" ] && die "--sites-dir เป็น / ไม่ได้"

USERS_DIR="$(printf '%s' "$USERS_DIR" | sed 's:/*$::')"
check_abs_path "$USERS_DIR" "--users-dir"
[ "$USERS_DIR" = "/" ] && die "--users-dir เป็น / ไม่ได้"

# ถ้า pointer roots ว่าง ให้ใช้ sites_dir อัตโนมัติ
if [ -z "$POINTER_ROOTS" ]; then
  POINTER_ROOTS="$SITES_DIR"
fi

if [ -n "$POINTER_ROOTS" ]; then
  IFS=',' read -r -a _roots <<< "$POINTER_ROOTS"
  for _root in "${_roots[@]}"; do
    check_abs_path "$_root" "--pointer-root"
    case "$_root" in
      /|/etc|/home|/root|/usr|/var|/bin|/sbin|/boot|/dev|/proc|/sys)
        die "--pointer-root=$_root เท่ากับเปิดให้สร้าง vhost เสิร์ฟไฟล์ระบบผ่านเว็บ" ;;
    esac
  done
fi

head_ "ติดตั้ง PHP Server Control Panel"
say "โหมด      : $MODE"
say "พอร์ต     : $PORT"
say "ที่มา      : $SRC_DIR"
say "บ้านผู้ใช้ : $USERS_DIR"
say "ไฟล์เว็บเดิม: $SITES_DIR"
[ -n "$POINTER_ROOTS" ] && say "ชี้ได้ถึง  : $POINTER_ROOTS"
[ "$SHARED_OWNER" = "yes" ] && warn "เปิด shared_owner — เว็บทุกเว็บใช้เจ้าของไฟล์ร่วมกัน ห้ามใช้กับเว็บของคนอื่น"

# ---------------------------------------------------------------------------
# 1. ตรวจ distro
# ---------------------------------------------------------------------------
head_ "1. ตรวจระบบปฏิบัติการ"

[ -r /etc/os-release ] || die "อ่าน /etc/os-release ไม่ได้ — ไม่รองรับระบบนี้"
# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}${ID_LIKE:-}" in
  *debian*|*ubuntu*) ok "รองรับ: ${PRETTY_NAME:-$ID}" ;;
  *)
    warn "ยังไม่ได้ทดสอบกับ ${PRETTY_NAME:-$ID} — v1 รองรับ Debian 12+, Ubuntu 22.04+, Linux Mint 21+"
    [ "$MODE" = "production" ] && die "หยุดเพื่อความปลอดภัย ใช้ --mode=sandbox เพื่อทดลองได้"
    ;;
esac

# ---------------------------------------------------------------------------
# 2. ตรวจ PHP
# ---------------------------------------------------------------------------
head_ "2. ตรวจสอบและติดตั้งแพ็กเกจที่จำเป็น"

# ติดตั้งแพ็กเกจได้เฉพาะตอนเป็น root — โหมด --portable ตั้งใจให้รันโดยไม่ต้องใช้ root
# (ดูหัวไฟล์และ docker/Dockerfile ที่รันด้วยผู้ใช้ phpcp) ถ้าไม่กันไว้ตรงนี้
# บรรทัดเขียน /etc/apt/sources.list.d/php.list จะล้มด้วย Permission denied
# แล้ว set -e จะฆ่าสคริปต์ทิ้งทั้งตัว — คอนเทนเนอร์ sandbox สตาร์ตไม่ขึ้นเลยแม้แต่ครั้งเดียว
if [ "$(id -u)" -eq 0 ] && command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  say "กำลังอัปเดตและติดตั้ง PPA / แพ็กเกจที่จำเป็น..."
  apt-get update -qq >/dev/null 2>&1 || true
  # gnupg ต้องมาด้วย — `add-apt-repository` เรียก gpg เพื่อ import กุญแจของ PPA
  # ระบบที่ติดตั้งแบบ minimal ไม่มี gpg ติดมา ขั้นเพิ่ม PPA จะล้มเงียบ ๆ แล้วเครื่องจะค้าง
  # อยู่กับ PHP ของดิสทริบิวชัน (8.1 บน Ubuntu 22.04) ซึ่งใหม่ไม่พอสำหรับโค้ดชุดนี้
  apt-get install -y -qq --no-install-recommends software-properties-common lsb-release ca-certificates gnupg curl wget >/dev/null 2>&1 || true

  # เพิ่ม Ondřej Surý PPA (สำหรับ Ubuntu/Debian) เพื่อให้มี PHP 7.4 และ PHP 8.4 พร้อมกัน
  if ! command -v php7.4 >/dev/null 2>&1 || ! command -v php8.4 >/dev/null 2>&1; then
    say "กำลังติดตั้ง repository สำหรับ Multi-PHP (PHP 7.4 & 8.4)..."
    if [ "${ID:-}" = "ubuntu" ] || [ "${ID_LIKE:-}" = "ubuntu" ]; then
      LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
    elif [ "${ID:-}" = "debian" ] || [ "${ID_LIKE:-}" = "debian" ]; then
      curl -sSLo /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg >/dev/null 2>&1 || true
      echo "deb https://packages.sury.org/php/ $(lsb_release -sc 2>/dev/null || echo bookworm) main" > /etc/apt/sources.list.d/php.list
    fi
    apt-get update -qq >/dev/null 2>&1 || true
  fi

  say "กำลังติดตั้งแพ็กเกจ PHP 7.4, PHP 8.4, Apache2, Nginx, BIND9, MariaDB, OpenSSH, UFW, Fail2ban, phpMyAdmin, Cron..."
  apt-get install -y -qq --no-install-recommends \
    cron openssh-server bind9 bind9utils \
    php7.4-cli php7.4-fpm php7.4-sqlite3 php7.4-mysql php7.4-mbstring php7.4-curl php7.4-zip php7.4-gd php7.4-xml php7.4-intl \
    php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-mysql php8.4-mbstring php8.4-curl php8.4-zip php8.4-gd php8.4-xml php8.4-intl php8.4-imagick php8.4-opcache \
    apache2 nginx openssl ca-certificates procps ufw fail2ban certbot python3-certbot-apache python3-certbot-nginx mariadb-server phpmyadmin >/dev/null 2>&1 || true
elif command -v apt-get >/dev/null 2>&1; then
  say "$C_DIM ข้ามการติดตั้งแพ็กเกจ (ไม่ได้รันด้วย root) — จะใช้ PHP ที่มีอยู่แล้วบนเครื่อง$C_OFF"
fi

PHP_BIN="$(command -v php8.4 || command -v php8.3 || command -v php || true)"
[ -n "$PHP_BIN" ] || die "ไม่พบ PHP — กรุณาติดตั้ง php-cli หรือ php-fpm ก่อน"

PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
# ต้องการ 8.2 ขึ้นไปเพราะโค้ดใช้ readonly class ซึ่งมีตั้งแต่ 8.2
# เคยตั้งไว้ที่ 8.1 ซึ่งผิด — ตัวติดตั้งจบด้วยรหัส 0 บน Ubuntu 22.04 (PHP 8.1)
# แล้ว panel ตายด้วย parse error ตอนใช้งานครั้งแรก ผู้ติดตั้งไม่มีทางรู้ล่วงหน้าเลย
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "ต้องการ PHP 8.2 ขึ้นไป (พบ $PHP_VER) — Ubuntu 22.04 ต้องเพิ่ม ppa:ondrej/php ก่อน"
ok "PHP $PHP_VER ที่ $PHP_BIN"

MISSING=""
for ext in pdo_sqlite sqlite3 sodium posix pcntl sockets openssl mbstring json filter fileinfo curl zip; do
  "$PHP_BIN" -m | grep -qix "$ext" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || die "ขาด PHP extension:$MISSING (ติดตั้งด้วย apt install php$PHP_VER-{sqlite3,mbstring,curl,zip})"
ok "PHP extension ครบทุกตัวที่ต้องการ"

# ---------------------------------------------------------------------------
# โหมด portable — ติดตั้งในโฟลเดอร์โปรเจกต์ ไม่ต้องใช้ root
# ---------------------------------------------------------------------------
if [ "$PORTABLE" = "yes" ]; then
  [ "$MODE" != "production" ] || die "โหมด portable ใช้กับ production ไม่ได้"

  head_ "ติดตั้งแบบ portable (ไม่ต้องใช้ root)"

  CONF_DIR="$SRC_DIR/etc"
  mkdir -p "$CONF_DIR" "$SRC_DIR/var/"{lib,log,run,sandbox,sites}

  if [ ! -f "$CONF_DIR/config.php" ]; then
    cp "$CONF_DIR/config.example.php" "$CONF_DIR/config.php"
    chmod 600 "$CONF_DIR/config.php"
    ok "สร้าง etc/config.php"
  fi

  "$PHP_BIN" -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    $s = preg_replace("/(\x27mode\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27".$argv[2]."\x27", $s, 1);
    $s = preg_replace("/(\x27layout\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27portable\x27", $s, 1);
    file_put_contents($f, $s);
  ' "$CONF_DIR/config.php" "$MODE"

  "$PHP_BIN" "$SRC_DIR/bin/phpcp" key:generate >/dev/null 2>&1 || true
  "$PHP_BIN" "$SRC_DIR/bin/phpcp" setup --user="$ADMIN_USER"

  [ "$MODE" = "sandbox" ] && "$PHP_BIN" "$SRC_DIR/bin/phpcp" sandbox:seed >/dev/null && ok "ใส่ข้อมูลตัวอย่างแล้ว"

  head_ "เสร็จสิ้น"
  say "เริ่ม agent : $C_DIM$SRC_DIR/bin/phpcp-agentd$C_OFF"
  say "เปิดเว็บ    : $C_DIM$SRC_DIR/bin/phpcp serve --port=$PORT$C_OFF"
  printf '\n'
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. ผู้ใช้ระบบ
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "ต้องรันด้วย sudo (หรือใช้ --portable เพื่อทดสอบโดยไม่ต้องใช้ root)"

head_ "3. ผู้ใช้และกลุ่มของ panel"

getent group "$PANEL_GROUP" >/dev/null || { groupadd --system "$PANEL_GROUP"; ok "สร้างกลุ่ม $PANEL_GROUP"; }
getent passwd "$PANEL_USER" >/dev/null || {
  useradd --system --gid "$PANEL_GROUP" --home-dir "$DATA_DIR" \
          --shell /usr/sbin/nologin --comment "PHP Control Panel web tier" "$PANEL_USER"
  ok "สร้างผู้ใช้ $PANEL_USER"
}
ok "ผู้ใช้ $PANEL_USER อยู่ในกลุ่ม $PANEL_GROUP"

# ---------------------------------------------------------------------------
# 4. วางไฟล์
# ---------------------------------------------------------------------------
head_ "4. วางไฟล์และตั้งสิทธิ์"

mkdir -p "$INSTALL_DIR" "$CONF_DIR" "$DATA_DIR/backups" "$TMP_DIR" "$LOG_DIR" "$SITES_DIR" "$USERS_DIR" "$RUN_DIR"

# 0711 = เดินเข้าบ้านตัวเองได้ แต่ ls ดูรายชื่อบ้านทั้งหมดไม่ได้
# ลูกค้าจึงไม่รู้ว่าบนเครื่องนี้มีลูกค้ารายอื่นอยู่กี่รายและชื่ออะไร
chmod 0711 "$USERS_DIR"

# `views` ไม่อยู่ในรายการแล้วตั้งแต่ 2026-08-08 — UI แบบ HTML ถูกลบทิ้งทั้งชุด
# หน้าเว็บทั้งหมดคือ SPA ใต้ `public/assets/spa/` · `templates` ที่เหลือคือไฟล์ตั้งค่า
# ของ systemd/Apache/FPM ไม่ใช่เทมเพลตหน้าเว็บ
for dir in bin src templates db docs public; do
  rm -rf "${INSTALL_DIR:?}/$dir"
  cp -a "$SRC_DIR/$dir" "$INSTALL_DIR/"
done

# ล้างของเก่าที่ยังค้างอยู่จากการติดตั้งรุ่นก่อน — ไม่งั้นเครื่องที่อัปเกรดจะเหลือ
# UI เก่าทำงานคู่ขนานอยู่เงียบ ๆ ทั้งที่เส้นทางฝั่ง PHP ไม่มีแล้ว
rm -rf "${INSTALL_DIR:?}/views" "${INSTALL_DIR:?}/public/assets/js" \
       "${INSTALL_DIR:?}/public/assets/icons" "${INSTALL_DIR:?}/public/assets/images"
cp -a "$SRC_DIR/bootstrap.php" "$INSTALL_DIR/"

# โค้ดเป็นของ root และอ่านอย่างเดียว — ผู้ใช้ของเว็บทั้ง panel และเว็บไซต์แก้ไม่ได้
chown -R root:root "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} +
find "$INSTALL_DIR" -type f -exec chmod 644 {} +
chmod 755 "$INSTALL_DIR/bin/phpcp" "$INSTALL_DIR/bin/phpcp-agentd" "$INSTALL_DIR/bin/phpcp-scheduler" "$INSTALL_DIR/bin/phpcp-alert" "$INSTALL_DIR/bin/phpcp-acme-hook"
ok "วางโค้ดที่ $INSTALL_DIR (อ่านอย่างเดียว)"

# ข้อมูลและ log เป็นของผู้ใช้เว็บ
chown -R "$PANEL_USER:$PANEL_GROUP" "$DATA_DIR" "$LOG_DIR"
chmod 750 "$DATA_DIR" "$LOG_DIR" "$DATA_DIR/backups" "$TMP_DIR"
chown root:"$PANEL_GROUP" "$CONF_DIR" "$RUN_DIR"
chmod 750 "$CONF_DIR" "$RUN_DIR"
ok "ตั้งสิทธิ์ไดเรกทอรีข้อมูลและ log"

ln -sf "$INSTALL_DIR/bin/phpcp" /usr/local/bin/phpcp
ok "ติดตั้งคำสั่ง phpcp"

# ---------------------------------------------------------------------------
# 5. config tree ของ panel เอง (ไม่แตะของระบบ)
# ---------------------------------------------------------------------------
head_ "5. config tree ของ panel"

if [ ! -f "$CONF_DIR/config.php" ]; then
  cp "$SRC_DIR/etc/config.example.php" "$CONF_DIR/config.php"
  ok "สร้าง $CONF_DIR/config.php"
else
  warn "มี config.php อยู่แล้ว — ไม่เขียนทับ"
fi

"$PHP_BIN" -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $s = preg_replace("/(\x27mode\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27".$argv[2]."\x27", $s, 1);
  $s = preg_replace("/(\x27layout\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27system\x27", $s, 1);
  $s = preg_replace("/(\x27port\x27\s*=>\s*)\d+/", "$1".$argv[3], $s, 1);
  $s = preg_replace("/(\x27cookie_secure\x27\s*=>\s*)(true|false)/", "$1true", $s, 1);

  // var_export ทุกค่าที่มาจากบรรทัดคำสั่ง — ค่าเหล่านี้ถูกเขียนลงไฟล์ PHP ที่รันด้วยสิทธิ์ panel
  $s = preg_replace(
    "/(\x27dir\x27\s*=>\s*)\x27[^\x27]*\x27/",
    "$1".str_replace("$", "\\$", var_export($argv[4], true)),
    $s,
    1
  );
  $s = preg_replace(
    "/(\x27users_dir\x27\s*=>\s*)\x27[^\x27]*\x27/",
    "$1".str_replace("$", "\\$", var_export($argv[7], true)),
    $s,
    1
  );
  $s = preg_replace(
    "/(\x27shared_owner\x27\s*=>\s*)(true|false)/",
    "$1".($argv[5] === "yes" ? "true" : "false"),
    $s,
    1
  );
  $roots = $argv[6] === "" ? [] : explode(",", $argv[6]);
  $roots = array_values(array_filter(array_map(static fn ($r) => rtrim(trim($r), "/"), $roots)));
  $s = preg_replace(
    "/(\x27pointer_roots\x27\s*=>\s*)\[[^\]]*\]/",
    "$1".str_replace("$", "\\$", var_export($roots, true)),
    $s,
    1
  );
  file_put_contents($f, $s);
' "$CONF_DIR/config.php" "$MODE" "$PORT" "$SITES_DIR" "$SHARED_OWNER" "$POINTER_ROOTS" "$USERS_DIR"

# ค่าที่เพิ่งเขียนต้อง parse ได้จริง ไม่งั้น panel จะสตาร์ตไม่ขึ้นแล้วหาสาเหตุยาก
"$PHP_BIN" -l "$CONF_DIR/config.php" >/dev/null || die "เขียน config.php แล้วไฟล์เสีย — ตรวจค่า --sites-dir/--pointer-root"

# root:phpcp 0640 — ห้ามลบสองบรรทัดนี้
#
# ไฟล์นี้ถูก `cp` มาโดยตัวติดตั้งซึ่งรันเป็น root จึงได้ root:root ส่วน `key:generate`
# ตั้งแค่ chmod 0640 ไม่เคยตั้งกลุ่ม ผลคือผู้ใช้ของเว็บ (phpcp-web) **อ่าน config
# ไม่ได้เลย** แล้ว App::boot() ถอยไปใช้ค่าเริ่มต้นเงียบ ๆ — panel กลายเป็นโหมด
# sandbox ไม่มีฐานข้อมูล ไม่มีบัญชีผู้ดูแล ทั้งที่ตัวติดตั้งรายงานว่าผ่านทุกขั้น
# (เคยหายไปช่วงหนึ่งจนติดตั้งบนเครื่องเปล่าไม่สำเร็จ — docker/verify-install.sh จับได้)
#
# กลุ่มต้องเป็น phpcp ไม่ใช่ world-readable เพราะไฟล์นี้มี secret_key ของทั้งระบบ
chown root:"$PANEL_GROUP" "$CONF_DIR/config.php"
chmod 640 "$CONF_DIR/config.php"

if [ "$MODE" = "production" ]; then
  # เพิ่ม config สำหรับเครื่องภายในที่มี BIND แต่ยังต้องการ hosts entry
  "$PHP_BIN" -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    // ค้นหาบรรทัด log level และเพิ่ม force_hosts_update_for_test_domains
    if (!str_contains($s, "force_hosts_update_for_test_domains")) {
      $s = preg_replace(
        "/(\x27log\x27\s*=>\s*\[[^\]]*?)(\x27level\x27\s*=>\s*\x27info\x27[^\]]*?)(\])/",
        "$1$2,\n        // ตั้งให้แก้ไข /etc/hosts สำหรับโดเมน .test แม้มี BIND/named รันอยู่\n        // เหมาะสำหรับเครื่อง development ที่มี DNS รันอยู่แต่ยังต้องการ hosts entry\n        \x27force_hosts_update_for_test_domains\x27 => true,$3",
        $s,
        1
      );
    }
    file_put_contents($f, $s);
  ' "$CONF_DIR/config.php"

  say "ตั้งค่า mode=production layout=system port=$PORT sites.dir=$SITES_DIR"
  say "เปิดใช้งาน hosts update สำหรับโดเมน .test"
else
  say "ตั้งค่า mode=$MODE layout=system port=$PORT sites.dir=$SITES_DIR"
fi

  # ---------------------------------------------------------------------------
  # 5.1 เชื่อมต่อ phpMyAdmin ที่ติดตั้งไว้แล้วบนเครื่อง
  #
  # เชื่อมเฉพาะของที่มีอยู่แล้ว ไม่ดาวน์โหลดอะไรจากอินเทอร์เน็ตมาวางในเว็บรูทของ panel
  # ตัวติดตั้งเคยดึง Adminer มาโดยไม่ตรวจ checksum หรือลายเซ็น ซึ่งเป็นความเสี่ยง
  # supply-chain ชนิดเดียวกับที่ Updater ออกแบบมาป้องกัน — ไฟล์ PHP ที่ถูกสับเปลี่ยน
  # ระหว่างทางจะรันด้วยสิทธิ์ของ panel ทันที ตัดออกดีกว่าเพราะ phpMyAdmin ทำงานนี้อยู่แล้ว
  # ---------------------------------------------------------------------------
  PMA_DIR="$INSTALL_DIR/public/phpmyadmin"
  if [ -d /usr/share/phpmyadmin ] && [ ! -f "$PMA_DIR/index.php" ]; then
    mkdir -p "$PMA_DIR"
    ln -s /usr/share/phpmyadmin/* "$PMA_DIR/" 2>/dev/null || true
    # ตั้งค่า blowfish_secret ให้ล็อกอินด้วย cookie ได้ไม่ค้าง
    if [ -f /etc/phpmyadmin/config.inc.php ]; then
      PMA_SECRET=$(openssl rand -hex 16)
      if ! grep -q "blowfish_secret" /etc/phpmyadmin/config.inc.php; then
        echo "\$cfg['blowfish_secret'] = '${PMA_SECRET}';" >> /etc/phpmyadmin/config.inc.php
      fi
    fi

    # ---------------------------------------------------------------------
    # เข้าใช้งานแบบ signon — panel เตรียม session ให้แล้วผู้ใช้ไม่ต้องพิมพ์รหัสเลย
    #
    # ผู้ใช้ไม่เคยเห็นรหัสผ่านฐานข้อมูลของตัวเอง (panel สุ่มให้และเก็บแบบเข้ารหัส)
    # ถ้าไม่ตั้งค่านี้ ปุ่ม "เปิด phpMyAdmin" จะพาไปหน้าล็อกอินที่ไม่มีใครกรอกได้
    #
    # SignonURL ชี้กลับมาที่หน้าฐานข้อมูลของ panel — คนที่เปิด /phpmyadmin ตรง ๆ
    # โดยไม่ผ่านปุ่มจะถูกส่งกลับมาให้กดปุ่มที่ถูกต้อง แทนที่จะเจอหน้าเปล่า
    # ---------------------------------------------------------------------
    if [ -f /etc/phpmyadmin/config.inc.php ] && ! grep -q "phpcp_pma_signon" /etc/phpmyadmin/config.inc.php; then
      cat >> /etc/phpmyadmin/config.inc.php <<'PMACFG'

// --- เพิ่มโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ ---
// ล็อกอินผ่าน session ที่ panel เตรียมไว้ให้ (PLAN-V2 เฟส M5)
$i = 1;
$cfg['Servers'][$i]['auth_type']     = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'phpcp_pma_signon';
$cfg['Servers'][$i]['SignonURL']     = '/databases';
$cfg['Servers'][$i]['host']          = 'localhost';
$cfg['Servers'][$i]['compress']      = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
// ผู้ใช้เห็นเฉพาะฐานข้อมูลที่บัญชีตัวเองมีสิทธิ์ ซึ่ง panel เป็นคน GRANT ให้ตอนสร้าง
$cfg['Servers'][$i]['only_db']       = '';
PMACFG
      ok "ตั้งค่า phpMyAdmin ให้เข้าใช้งานผ่าน panel ได้โดยไม่ต้องพิมพ์รหัส"
    fi
    ok "เชื่อมต่อ phpMyAdmin เรียบร้อย (/phpmyadmin)"
  elif [ ! -d /usr/share/phpmyadmin ]; then
    say "ไม่พบ phpMyAdmin — ติดตั้งด้วย apt install phpmyadmin แล้วรันตัวติดตั้งซ้ำถ้าต้องการ"
  fi

  # ล้างร่องรอย Adminer ที่ตัวติดตั้งรุ่นก่อนเคยดาวน์โหลดมาวางไว้
  rm -rf "$INSTALL_DIR/public/adminer"

mkdir -p "$CONF_DIR/httpd" "$CONF_DIR/fpm/pool.d" "$CONF_DIR/vhosts.d" "$CONF_DIR/tls"
chmod 750 "$CONF_DIR/httpd" "$CONF_DIR/fpm" "$CONF_DIR/vhosts.d"
chmod 700 "$CONF_DIR/tls"
ok "สร้าง config tree แยกจาก /etc/apache2 และ /etc/php"


# < /dev/null สำคัญ: ถ้ามี secret_key อยู่แล้ว (ติดตั้งซ้ำ) คำสั่งนี้จะถาม confirm()
# ที่อ่าน STDIN จริง แต่คำถามถูกเงียบไปกับ >/dev/null ด้วย — ไม่ปิด stdin สคริปต์จะค้าง
# รอคีย์บอร์ดแบบไม่มีอะไรบอกผู้ใช้เลยว่ากำลังรออยู่ ปิดแล้ว fgets(STDIN) เจอ EOF ทันที
# ตอบเป็น "ไม่สร้างใหม่" โดยอัตโนมัติ ตรงกับเจตนาของบรรทัดนี้อยู่แล้วคือสร้างเฉพาะตอนยังไม่มี
"$PHP_BIN" /usr/local/bin/phpcp key:generate < /dev/null >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 6. ใบรับรองชั่วคราว
# ---------------------------------------------------------------------------
head_ "6. ใบรับรอง TLS ของ panel"

PANEL_HOST="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"

if [ ! -f "$CONF_DIR/tls/panel.crt" ]; then
  # ต้องมี SAN และ CA:FALSE ไม่งั้นเบราว์เซอร์สมัยใหม่ปฏิเสธแม้จะกดยอมรับความเสี่ยงแล้ว
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$CONF_DIR/tls/panel.key" -out "$CONF_DIR/tls/panel.crt" \
    -subj "/CN=$PANEL_HOST" \
    -addext "subjectAltName=DNS:$PANEL_HOST,DNS:localhost,IP:127.0.0.1" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth" >/dev/null 2>&1
  chmod 600 "$CONF_DIR/tls/panel.key"
  chmod 644 "$CONF_DIR/tls/panel.crt"
  ok "สร้าง self-signed certificate สำหรับ $PANEL_HOST (เปลี่ยนเป็น Let's Encrypt ได้ทีหลังใน UI)"
else
  ok "มีใบรับรองอยู่แล้ว"
fi

# ---------------------------------------------------------------------------
# 7. systemd units
# ---------------------------------------------------------------------------
head_ "7. runtime stack ของ panel"

FPM_BIN="$(command -v "php-fpm$PHP_VER" || command -v php-fpm || echo /usr/sbin/php-fpm$PHP_VER)"
HTTPD_BIN="$(command -v apache2 || command -v httpd || echo /usr/sbin/apache2)"

MODULES_DIR=""
for candidate in /usr/lib/apache2/modules /usr/lib64/httpd/modules /usr/libexec/apache2; do
  [ -d "$candidate" ] && { MODULES_DIR="$candidate"; break; }
done
[ -n "$MODULES_DIR" ] || MODULES_DIR="/usr/lib/apache2/modules"

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
      -e "s|{{PANEL_PORT}}|$PORT|g" \
      "$1" > "$2"
}

render "$SRC_DIR/templates/panel/phpcp-agentd.service.tpl" /etc/systemd/system/phpcp-agentd.service
ok "ติดตั้ง phpcp-agentd.service"

# ตัวแจ้งเตือนเมื่อ unit ล้มเหลว — ถูกเรียกโดย OnFailure= ของ phpcp-agentd เท่านั้น
#
# ไม่ต้อง enable เพราะเป็น template unit ที่ systemd สั่งเริ่มเองตามชื่อ (phpcp-alert@...)
# ตอน agent เข้าสถานะ failed · การ enable ตัว template ไม่มีความหมายและจะขึ้น error
render "$SRC_DIR/templates/panel/phpcp-alert@.service.tpl" /etc/systemd/system/phpcp-alert@.service
ok "ติดตั้ง phpcp-alert@.service"

# ตัวจับเวลา — ไม่มีตัวนี้แปลว่ากลไกคืนค่าอัตโนมัติของ SSH/firewall ไม่มีอยู่จริง
# เพราะมันถูกออกแบบมาเพื่อกรณีที่ผู้ดูแลหลุดการเชื่อมต่อไปแล้ว (ไม่มีคำขอเข้ามากระตุ้น)
render "$SRC_DIR/templates/panel/phpcp-scheduler.service.tpl" /etc/systemd/system/phpcp-scheduler.service
render "$SRC_DIR/templates/panel/phpcp-scheduler.timer.tpl"   /etc/systemd/system/phpcp-scheduler.timer
ok "ติดตั้ง phpcp-scheduler.service และ phpcp-scheduler.timer"

UNITS="phpcp-agentd.service"

if [ "$MODE" = "production" ]; then
  render "$SRC_DIR/templates/panel/phpcp-fpm.service.tpl" /etc/systemd/system/phpcp-fpm.service
  render "$SRC_DIR/templates/panel/phpcp-web.service.tpl" /etc/systemd/system/phpcp-web.service
  ok "ติดตั้ง phpcp-fpm.service และ phpcp-web.service"

  # config tree ของ panel เอง — ไฟล์ generate ทุกครั้ง เพราะเป็นของตัวติดตั้งไม่ใช่ของผู้ดูแล
  render "$SRC_DIR/templates/panel/php-fpm.conf.tpl"    "$CONF_DIR/fpm/php-fpm.conf"
  render "$SRC_DIR/templates/panel/panel-pool.conf.tpl" "$CONF_DIR/fpm/pool.d/panel.conf"
  render "$SRC_DIR/templates/panel/httpd.conf.tpl"      "$CONF_DIR/httpd/httpd.conf"
  chown root:"$PANEL_GROUP" "$CONF_DIR/fpm/php-fpm.conf" "$CONF_DIR/fpm/pool.d/panel.conf" "$CONF_DIR/httpd/httpd.conf"
  chmod 640 "$CONF_DIR/fpm/php-fpm.conf" "$CONF_DIR/fpm/pool.d/panel.conf" "$CONF_DIR/httpd/httpd.conf"
  ok "สร้าง httpd.conf และ php-fpm.conf ของ panel"

  # ตรวจ config ก่อนสตาร์ต — ล้มตรงนี้ยังกู้ง่ายกว่าล้มตอน systemd สตาร์ต
  "$HTTPD_BIN" -t -f "$CONF_DIR/httpd/httpd.conf" >/dev/null 2>&1 \
    || die "httpd.conf ของ panel ไม่ผ่าน configtest: $("$HTTPD_BIN" -t -f "$CONF_DIR/httpd/httpd.conf" 2>&1 | tail -3)"
  "$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" >/dev/null 2>&1 \
    || die "php-fpm.conf ของ panel ไม่ผ่าน configtest: $("$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" 2>&1 | tail -3)"
  ok "configtest ของ httpd และ fpm ผ่าน"

  UNITS="$UNITS phpcp-fpm.service phpcp-web.service"
fi

if [ -d /run/systemd/system ]; then
  systemctl daemon-reload
  # shellcheck disable=SC2086
  systemctl enable $UNITS >/dev/null 2>&1

  # restart ไม่ใช่ `enable --now` — บนเครื่องที่ติดตั้งซ้ำเพื่ออัปเดตโค้ด บริการยัง
  # active อยู่แล้ว `--now` จึงไม่ทำอะไรเลย ผลคือ phpcp-agentd ซึ่งเป็นโปรเซส PHP
  # ที่รันค้างยาว ๆ จะยังใช้โค้ดชุดเก่าที่โหลดไว้ตอนสตาร์ตต่อไป ทั้งที่ไฟล์บนดิสก์ใหม่แล้ว
  # (restart สตาร์ตให้เองอยู่แล้วถ้า unit ยังไม่ทำงาน จึงใช้ได้ทั้งติดตั้งใหม่และติดตั้งซ้ำ)
  #
  # สั่งทุก unit ในคำสั่งเดียว เพราะทั้งสามใช้ RuntimeDirectory=phpcp ร่วมกัน
  # shellcheck disable=SC2086
  systemctl restart $UNITS
  ok "เปิดใช้งานและรีสตาร์ต: $UNITS"

  # timer แยกจาก $UNITS เพราะตัว .service เป็น oneshot ที่ timer เป็นคนเรียก
  # ไม่ใช่บริการที่รันค้าง — `restart` ตัวมันตรง ๆ จะได้แค่รันหนึ่งรอบแล้วจบ
  systemctl enable phpcp-scheduler.timer >/dev/null 2>&1
  systemctl restart phpcp-scheduler.timer
  ok "เปิดใช้งาน phpcp-scheduler.timer (ทุกนาที)"

  # "ทำงานอยู่ตอนนี้" กับ "ขึ้นเองตอนบูต" เป็นคนละเรื่อง — เว็บของผู้ใช้ทั้งเครื่อง
  # พึ่ง apache2 และ mariadb ถ้าสองตัวนี้ถูก disable ไว้ เครื่องจะกลับมาแบบเว็บล่มทั้งหมด
  # โดยที่ตัวติดตั้งรายงานว่าสำเร็จ — enable ซ้ำไม่มีผลเสียถ้าเปิดอยู่แล้ว
  if [ "$MODE" = "production" ]; then
    for unit in apache2 mariadb; do
      systemctl list-unit-files "$unit.service" >/dev/null 2>&1 || continue
      if systemctl is-enabled --quiet "$unit" 2>/dev/null; then
        say "$unit ตั้งให้ขึ้นตอนบูตอยู่แล้ว"
      else
        systemctl enable "$unit" >/dev/null 2>&1 && ok "ตั้งให้ $unit ขึ้นตอนบูต" \
          || warn "ตั้งให้ $unit ขึ้นตอนบูตไม่สำเร็จ — ตรวจด้วย systemctl status $unit"
      fi
    done
  fi
else
  warn "ไม่พบ systemd — ติดตั้งไฟล์ unit ไว้แล้วแต่ยังไม่ได้เปิดใช้งาน"
fi

# ---------------------------------------------------------------------------
# 8. ฐานข้อมูลและผู้ดูแลระบบคนแรก
# ---------------------------------------------------------------------------
head_ "8. ฐานข้อมูลและบัญชีผู้ดูแล"
# ตรวจว่า panel จะต่อ MariaDB ได้ — "ตรวจ" เท่านั้น ไม่แก้อะไรทั้งสิ้น
#
# ห้ามรัน ALTER USER กับ root เด็ดขาด เคยทำแล้วพังมาก่อน:
# Debian/Ubuntu ตั้ง root@localhost เป็น unix_socket มาแต่ต้น ซึ่งแปลว่าใครก็ตาม
# ที่เป็น OS root ต่อได้ทันทีโดยไม่ต้องมีรหัสผ่านเก็บไว้ที่ไหนเลย — agent ของ panel
# รันเป็น root จึงใช้ช่องทางนี้ได้ตรง ๆ อยู่แล้ว
#
# การสั่ง ALTER USER ... IDENTIFIED BY '<รหัส>' จะสลับ plugin จาก unix_socket
# ไปเป็นรหัสผ่าน ผลคือ `sudo mariadb` ของผู้ดูแลใช้ไม่ได้อีกเลย และถ้าขั้นตอนเขียน
# /root/.my.cnf ล้มเหลว (ดิสก์เต็ม, สคริปต์ถูกขัดจังหวะ) จะไม่มีใครรู้รหัสนั้นเลย
# = ล็อกตัวเองออกจากฐานข้อมูลถาวร ต้องกู้ด้วย --skip-grant-tables เท่านั้น
if command -v mariadb >/dev/null 2>&1; then
  # อยู่ใน && / || เพื่อไม่ให้ set -e ตัดจบสคริปต์เมื่อต่อฐานข้อมูลไม่ได้
  MARIADB_PROBE=$(mariadb -e "SELECT 1" 2>&1) && MARIADB_PROBE_RC=0 || MARIADB_PROBE_RC=$?

  if [ "$MARIADB_PROBE_RC" -eq 0 ]; then
    # ครอบคลุมทั้ง unix_socket และกรณีที่มี /root/.my.cnf อยู่แล้ว
    # (client อ่าน /root/.my.cnf ให้เองอัตโนมัติเมื่อรันในฐานะ root)
    ok "ต่อ MariaDB ในฐานะ root ได้ — panel ใช้ช่องทางเดียวกันนี้ผ่าน agent"
  elif mariadb --defaults-file=/etc/mysql/debian.cnf -e "SELECT 1" >/dev/null 2>&1; then
    ok "ต่อ MariaDB ผ่าน /etc/mysql/debian.cnf ได้ — panel จะใช้ไฟล์นี้"
  else
    warn "ต่อ MariaDB ไม่ได้ — หน้าจัดการฐานข้อมูลใน panel จะยังใช้ไม่ได้"
    warn "  ถ้าบริการยังไม่ทำงาน : sudo systemctl enable --now mariadb"
    warn "  ถ้า root ถูกตั้งรหัสผ่านไว้แล้วแต่ไม่มีใครรู้รหัส ให้คืนค่าเป็น unix_socket ด้วย"
    warn "    sudo systemctl stop mariadb"
    warn "    sudo systemctl set-environment MYSQLD_OPTS=\"--skip-grant-tables --skip-networking\""
    warn "    sudo systemctl start mariadb && sudo mariadb -u root"
    warn "    > FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket; FLUSH PRIVILEGES;"
    warn "    sudo systemctl stop mariadb && sudo systemctl unset-environment MYSQLD_OPTS"
    warn "    sudo systemctl start mariadb"
  fi
fi
# runuser มากับ util-linux จึงมีเสมอ ต่างจาก sudo ที่อาจไม่ได้ติดตั้งบน Debian แบบ minimal
as_panel() {
  if command -v runuser >/dev/null; then
    runuser -u "$PANEL_USER" -- "$@"
  else
    sudo -u "$PANEL_USER" "$@"
  fi
}

as_panel "$PHP_BIN" /usr/local/bin/phpcp setup --user="$ADMIN_USER"
as_panel "$PHP_BIN" /usr/local/bin/phpcp db:migrate

if [ "$MODE" = "sandbox" ]; then
  mkdir -p "$SANDBOX_DIR/state"
  chown -R "$PANEL_USER:$PANEL_GROUP" "$SANDBOX_DIR"
  as_panel "$PHP_BIN" /usr/local/bin/phpcp sandbox:seed >/dev/null
  ok "สร้าง $SANDBOX_DIR และใส่ข้อมูลตัวอย่างแล้ว"
fi

# ---------------------------------------------------------------------------
# 9. firewall
# ---------------------------------------------------------------------------
head_ "9. Firewall (UFW)"

if [ "$MODE" = "production" ] && command -v ufw >/dev/null; then
  # ต้องหาพอร์ต SSH จริงก่อนเปิด firewall — เปิดแค่ 22 ตายตัวแล้วสั่ง enable
  # เท่ากับตัดผู้ดูแลออกจากเครื่องทันทีถ้าเขาย้าย SSH ไปพอร์ตอื่น ซึ่งเป็นเรื่องปกติมาก
  # บนเซิร์ฟเวอร์จริง (panel ตัวนี้ยังมีปุ่มให้ย้ายพอร์ต SSH เองด้วยซ้ำ)
  #
  # ลำดับความน่าเชื่อถือ: sshd -T (อ่าน config จริงรวม Include ทั้งหมด) → ไฟล์ config
  # → พอร์ตของ session ที่กำลังใช้ติดตั้งอยู่ตอนนี้ → 22
  # ทุกบรรทัดต้องมี `|| true` — `set -e` ฆ่าสคริปต์ทั้งตัวเมื่อคำสั่งใน $( ) คืนค่าไม่เป็น 0
  # และทั้งสองคำสั่งนี้ล้มเป็นปกติบนเครื่องที่ยังไม่มี host key หรือไม่มีไฟล์ config
  # (เจอจริงตอนทดสอบติดตั้งบน debian:12 — ตัวติดตั้งตายที่ขั้นนี้ด้วยรหัส 255
  # ทั้งที่ฐานข้อมูลและบัญชีผู้ดูแลสร้างเสร็จไปแล้ว)
  SSH_PORTS=""
  if command -v sshd >/dev/null 2>&1; then
    SSH_PORTS="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2 }' || true)"
  fi
  if [ -z "$SSH_PORTS" ]; then
    SSH_PORTS="$(awk '/^[[:space:]]*[Pp]ort[[:space:]]+[0-9]+/ { print $2 }' \
      /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true)"
  fi
  # พอร์ตปลายทางของ session ปัจจุบัน — ค่าที่ "รู้แน่" ว่าใช้ต่อเข้ามาได้จริง
  if [ -n "${SSH_CONNECTION:-}" ]; then
    SSH_PORTS="$SSH_PORTS $(printf '%s' "$SSH_CONNECTION" | awk '{ print $4 }' || true)"
  fi
  [ -n "$SSH_PORTS" ] || SSH_PORTS="22"

  SSH_ALLOWED=""
  for p in $SSH_PORTS; do
    case "$p" in
      ''|*[!0-9]*) continue ;;
    esac
    [ "$p" -ge 1 ] && [ "$p" -le 65535 ] || continue
    case " $SSH_ALLOWED " in
      *" $p "*) continue ;;
    esac
    ufw allow "$p/tcp" comment "SSH" >/dev/null 2>&1 || true
    SSH_ALLOWED="$SSH_ALLOWED $p"
  done
  SSH_ALLOWED="${SSH_ALLOWED# }"

  say "กำลังเปิดใช้งาน UFW และเปิดพอร์ตที่จำเป็น (SSH: $SSH_ALLOWED, 53, 80, 443, $PORT)..."
  ufw allow 53 comment "DNS" >/dev/null 2>&1 || true
  ufw allow 80/tcp comment "HTTP Web" >/dev/null 2>&1 || true
  ufw allow 443/tcp comment "HTTPS Web" >/dev/null 2>&1 || true
  ufw allow "$PORT/tcp" comment "PHP Control Panel" >/dev/null 2>&1 || true
  ufw --force enable >/dev/null 2>&1 || true
  ok "ตั้งค่าและเปิดใช้งาน UFW Firewall เรียบร้อยแล้ว (SSH: $SSH_ALLOWED, 53, 80, 443, $PORT)"
  case " $SSH_ALLOWED " in
    *" 22 "*) ;;
    *) warn "ไม่ได้เปิดพอร์ต 22 เพราะ sshd ไม่ได้ฟังอยู่ — ถ้าจะย้ายกลับต้อง ufw allow 22/tcp ก่อน" ;;
  esac
else
  say "$C_DIM ข้าม (ufw ไม่ได้ติดตั้ง หรือไม่ใช่โหมด production)$C_OFF"
fi

# ---------------------------------------------------------------------------
# เสร็จสิ้น
# ---------------------------------------------------------------------------
head_ "เสร็จสิ้น"
say "ตรวจสถานะ : ${C_DIM}phpcp status${C_OFF}"
say "ตรวจปัญหา : ${C_DIM}phpcp doctor${C_OFF}"

if [ "$MODE" = "production" ]; then
  say "เข้าใช้งาน : ${C_DIM}https://$(hostname -I 2>/dev/null | awk '{print $1}'):$PORT${C_OFF}"
  printf '\n'
  warn "อย่าลืมทำตามรายการตรวจก่อนขึ้น production ใน docs/SECURITY.md §5"
  printf '\n'
  warn "ติดตั้ง BIND9 ไว้แล้วแต่ panel ยังไม่เชื่อมให้อัตโนมัติ (dns.enabled = false ในไฟล์ config)"
  say "เปิดใช้งานได้เองหลังตั้งค่า dns.nameservers ใน config.php แล้ว — ดู PLAN-V2.md §6 เฟส E3"
else
  printf '\n'
  warn "ระบบอยู่ในโหมด $MODE — คำสั่งจะไม่มีผลกับเซิร์ฟเวอร์จริง"
fi
printf '\n'
