#!/usr/bin/env bash
#
# PHP Server Control Panel installer - ARCHITECTURE §13
#
#   sudo ./install.sh                          install for production use
#   sudo ./install.sh --mode=sandbox           install for testing, never touches the real system
#   ./install.sh --mode=sandbox --portable     test inside the project folder, no root required
#
#   --users-dir=/mnt/Server/hosting    keep user home directories somewhere else than /srv/phpcp/users
#   --sites-dir=/mnt/Server/htdocs     where sites of the legacy layout live (before migration 0006)
#   --pointer-root=/mnt/Server/htdocs  allow a DocumentRoot to point into this folder (repeatable)
#   --shared-owner                     NTFS/exFAT/FAT only - skip ownership separation between sites
#
# The default is "install everything, ready to use" - switch off individual pieces when the
# machine already has them, or when you manage them some other way:
#
#   --dns-ns=ns1.a.com,ns2.a.com  enable BIND9 right away (omit = installed but not enabled)
#   --dns-email=hostmaster@a.com  admin email in the SOA record (omit = hostmaster@<hostname>)
#   --no-postfix                  do not install/configure Postfix (another MTA is already in use)
#   --no-logrotate                do not write /etc/logrotate.d/phpcp
#   --no-check                    skip the `phpcp doctor` check at the end
#   --smoke-user=U --smoke-password-file=P
#                                 hit every endpoint on the real machine at the end with this account
#                                 (only works with an account that already changed its first password)
#
# Principle: everything the panel owns lives in its own config tree.
# It never touches a single file under /etc/apache2 or /etc/php (ARCHITECTURE §5.2)

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
# The panel's tmp lives under DATA_DIR, not /tmp, because /tmp is wiped at boot and the service would fail to start
TMP_DIR="$DATA_DIR/tmp"
SITES_DIR="/srv/phpcp/sites"
# Home directories of hosting users - site files live at <USERS_DIR>/<user>/domains/<domain>/
# Since migration 0006 the uid and the disk quota belong to the user, not to the site
USERS_DIR="/srv/phpcp/users"
SHARED_OWNER="no"
POINTER_ROOTS=""  # empty = derive from sites_dir automatically
SANDBOX_DIR="/opt/phpcp-sandbox"
PANEL_USER="phpcp-web"
PANEL_GROUP="phpcp"
# Owner of every mailbox - see the reasoning in section 3
VMAIL_USER="vmail"
MAIL_ROOT="/srv/phpcp/mail"

WITH_POSTFIX="yes"
WITH_LOGROTATE="yes"
RUN_DOCTOR="yes"
DNS_NS=""
DNS_EMAIL=""
SMOKE_USER=""
SMOKE_PASSWORD_FILE=""

# ---------------------------------------------------------------------------
# Messages
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
# `printf --` is required: without it printf reads the leading "-" of the separator as an option flag
head_() { printf '\n%s\n%s\n' "$*" "$(printf -- '-%.0s' $(seq 1 46))"; }

# ---------------------------------------------------------------------------
# Read the options
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
    --no-postfix)     WITH_POSTFIX="no" ;;
    --no-logrotate)   WITH_LOGROTATE="no" ;;
    --no-check)       RUN_DOCTOR="no" ;;
    --dns-ns=*)       DNS_NS="${arg#*=}" ;;
    --dns-email=*)    DNS_EMAIL="${arg#*=}" ;;
    --smoke-user=*)          SMOKE_USER="${arg#*=}" ;;
    --smoke-password-file=*) SMOKE_PASSWORD_FILE="${arg#*=}" ;;
    -h|--help)
      # Print the whole header comment block, not a fixed line range - it used to be pinned
      # at 2,14 and options added later never showed up in --help at all
      awk 'NR > 1 { if ($0 ~ /^#/) { sub(/^# ?/, ""); print } else { exit } }' "${BASH_SOURCE[0]}"
      exit 0 ;;
    *) die "Unknown option: $arg" ;;
  esac
done

case "$MODE" in
  production|sandbox|dryrun) ;;
  *) die "Mode must be production, sandbox or dryrun" ;;
esac

if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
  die "Invalid port number: $PORT"
fi

# Paths from the command line get written into the config and become a vhost DocumentRoot - validate them at the source
check_abs_path() {
  case "$1" in
    /*) ;;
    *) die "$2 must be an absolute path: $1" ;;
  esac
  case "/$1/" in
    */../*) die "$2 must not contain .. : $1" ;;
  esac
  case "$1" in
    *[\'\"\\]*) die "$2 contains characters that cannot be used in a config file: $1" ;;
  esac
}

# The --dns-* values are written into config.php, which is included at boot, so they get
# filtered at the source just like paths - only characters that can genuinely appear in a
# hostname or an email address are allowed
if [ -n "$DNS_NS" ]; then
  case "$DNS_NS" in
    *[!A-Za-z0-9.,-]*) die "--dns-ns contains characters that are not part of a hostname: $DNS_NS" ;;
  esac
fi
if [ -n "$DNS_EMAIL" ]; then
  case "$DNS_EMAIL" in
    *[!A-Za-z0-9.@_-]*) die "--dns-email contains characters that are not part of an email address: $DNS_EMAIL" ;;
    *@*) ;;
    *) die "--dns-email must be an email address: $DNS_EMAIL" ;;
  esac
fi
if [ -n "$SMOKE_PASSWORD_FILE" ] && [ ! -r "$SMOKE_PASSWORD_FILE" ]; then
  die "Cannot read --smoke-password-file: $SMOKE_PASSWORD_FILE"
fi

SITES_DIR="$(printf '%s' "$SITES_DIR" | sed 's:/*$::')"
check_abs_path "$SITES_DIR" "--sites-dir"
[ "$SITES_DIR" = "/" ] && die "--sites-dir cannot be /"

USERS_DIR="$(printf '%s' "$USERS_DIR" | sed 's:/*$::')"
check_abs_path "$USERS_DIR" "--users-dir"
[ "$USERS_DIR" = "/" ] && die "--users-dir cannot be /"

# When pointer roots are empty, fall back to sites_dir automatically
if [ -z "$POINTER_ROOTS" ]; then
  POINTER_ROOTS="$SITES_DIR"
fi

if [ -n "$POINTER_ROOTS" ]; then
  IFS=',' read -r -a _roots <<< "$POINTER_ROOTS"
  for _root in "${_roots[@]}"; do
    check_abs_path "$_root" "--pointer-root"
    case "$_root" in
      /|/etc|/home|/root|/usr|/var|/bin|/sbin|/boot|/dev|/proc|/sys)
        die "--pointer-root=$_root would allow a vhost that serves system files over the web" ;;
    esac
  done
fi

head_ "Installing PHP Server Control Panel"
say "Mode         : $MODE"
say "Port         : $PORT"
say "Source       : $SRC_DIR"
say "User homes   : $USERS_DIR"
say "Legacy sites : $SITES_DIR"
[ -n "$POINTER_ROOTS" ] && say "Pointer roots: $POINTER_ROOTS"
[ "$SHARED_OWNER" = "yes" ] && warn "shared_owner is on - every site shares one file owner, never use this for sites owned by other people"

# ---------------------------------------------------------------------------
# 1. Check the distro
# ---------------------------------------------------------------------------
head_ "1. Checking the operating system"

[ -r /etc/os-release ] || die "Cannot read /etc/os-release - this system is not supported"
# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}${ID_LIKE:-}" in
  *debian*|*ubuntu*) ok "Supported: ${PRETTY_NAME:-$ID}" ;;
  *)
    warn "Not tested on ${PRETTY_NAME:-$ID} - v1 supports Debian 12+, Ubuntu 22.04+, Linux Mint 21+"
    [ "$MODE" = "production" ] && die "Stopping to stay on the safe side, use --mode=sandbox to try it out"
    ;;
esac

# ---------------------------------------------------------------------------
# 2. Check PHP
# ---------------------------------------------------------------------------
head_ "2. Checking and installing the required packages"

# Packages can only be installed as root - --portable mode is meant to run without root
# (see the file header and docker/Dockerfile, which runs as the phpcp user). Without this
# guard the line that writes /etc/apt/sources.list.d/php.list fails with Permission denied
# and set -e kills the whole script - the sandbox container would never start even once
if [ "$(id -u)" -eq 0 ] && command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  say "Updating and installing the required PPA / packages..."
  apt-get update -qq >/dev/null 2>&1 || true
  # gnupg has to come along - `add-apt-repository` calls gpg to import the PPA key.
  # Minimal installs ship without gpg, the PPA step then fails silently and the machine is
  # stuck with the distribution's PHP (8.1 on Ubuntu 22.04), which is too old for this codebase
  apt-get install -y -qq --no-install-recommends software-properties-common lsb-release ca-certificates gnupg curl wget >/dev/null 2>&1 || true

  # Add the Ondřej Surý PPA (for Ubuntu/Debian) so PHP 7.4 and PHP 8.4 are both available
  if ! command -v php7.4 >/dev/null 2>&1 || ! command -v php8.4 >/dev/null 2>&1; then
    say "Installing the repository for Multi-PHP (PHP 7.4 & 8.4)..."
    if [ "${ID:-}" = "ubuntu" ] || [ "${ID_LIKE:-}" = "ubuntu" ]; then
      LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
    elif [ "${ID:-}" = "debian" ] || [ "${ID_LIKE:-}" = "debian" ]; then
      curl -sSLo /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg >/dev/null 2>&1 || true
      echo "deb https://packages.sury.org/php/ $(lsb_release -sc 2>/dev/null || echo bookworm) main" > /etc/apt/sources.list.d/php.list
    fi
    apt-get update -qq >/dev/null 2>&1 || true
  fi

  # Postfix asks for its settings during installation unless they are answered up front -
  # answer them here so it never sits waiting for a human.
  #
  # "Internet Site" is the value that actually allows outgoing mail, which is the only thing
  # the panel needs (outbound notifications, not inbound mail). An admin who has to send
  # through a relay can set that later on the panel's settings page, which rewrites main.cf
  # through MailApply.
  #
  # Skip the whole block when the machine already has another MTA - installing Postfix over an
  # exim/sendmail the admin deliberately chose changes the system beyond an installer's scope
  POSTFIX_PKG=""
  if [ "$WITH_POSTFIX" = "yes" ]; then
    OTHER_MTA="no"
    if ! command -v postfix >/dev/null 2>&1; then
      # `sendmail` is a command Postfix installs too, so it can only be checked while Postfix is absent
      command -v exim4 >/dev/null 2>&1 && OTHER_MTA="yes"
      command -v sendmail >/dev/null 2>&1 && OTHER_MTA="yes"
    fi

    if [ "$OTHER_MTA" = "yes" ]; then
      warn "Another MTA is present on this machine - skipping Postfix (use --no-postfix to silence this warning)"
    else
      # Dovecot always comes with Postfix - mail hosting (PLAN-MAIL) needs both, and installing
      # them ahead of time does not accept inbound mail by itself (`phpcp mail:enable` does).
      # rspamd signs outbound mail with DKIM - unsigned mail lands in Gmail's spam folder nearly every time
      POSTFIX_PKG="postfix dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd rspamd"
      debconf-set-selections <<EOF >/dev/null 2>&1 || true
postfix postfix/main_mailer_type select Internet Site
postfix postfix/mailname string $(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)
EOF
    fi
  fi

  # ---------------------------------------------------------------------------
  # Installing packages - **the must-have group is kept separate from the nice-to-have group**
  #
  # This used to be one long command ending in `>/dev/null 2>&1 || true`, which is more
  # dangerous than it looks: `apt-get install` is **all or nothing** - a single package name
  # missing from that release's repository (or a mirror that is briefly down) means **nothing
  # gets installed at all**, and `|| true` swallows the error so the script carries on as if it
  # succeeded. The person installing then hits the failure much later ("missing PHP extension",
  # "apache2 not found") with no way to guess that one wrong package name was the real cause.
  #
  # The required group stops the installer and prints apt's real message. The optional group
  # retries one package at a time so the ones that do exist still get installed, then reports
  # the names that did not.
  # ---------------------------------------------------------------------------
  say "Installing PHP 7.4, PHP 8.4, Apache2, Nginx, BIND9, MariaDB, OpenSSH, UFW, Fail2ban, phpMyAdmin, Cron${POSTFIX_PKG:+, Postfix, Dovecot, rspamd}..."

  # Miss any one of these and the panel genuinely cannot work - stop here rather than fail later
  APT_REQUIRED="cron openssh-server logrotate ca-certificates openssl procps
    php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-mbstring php8.4-curl php8.4-zip php8.4-xml
    apache2"

  # These can be missing and the panel still works, only that feature turns off - PHP 7.4 exists
  # on some releases only, phpmyadmin/rspamd live in universe, and some admins run MariaDB on a separate server
  APT_OPTIONAL="bind9 bind9-utils nginx ufw fail2ban mariadb-server phpmyadmin
    certbot python3-certbot-apache python3-certbot-nginx
    php8.4-gd php8.4-intl php8.4-mysql php8.4-imagick php8.4-opcache
    php7.4-cli php7.4-fpm php7.4-sqlite3 php7.4-mysql php7.4-mbstring php7.4-curl php7.4-zip php7.4-gd php7.4-xml php7.4-intl
    $POSTFIX_PKG"

  if ! APT_ERR=$(apt-get install -y -qq --no-install-recommends $APT_REQUIRED 2>&1); then
    printf '%s\n' "$APT_ERR" >&2
    die "Failed to install the required packages - fix what the message above says and run again (usually an apt update that was never run, or an unreachable repository)"
  fi
  ok "All required packages are present"

  if ! apt-get install -y -qq --no-install-recommends $APT_OPTIONAL >/dev/null 2>&1; then
    # A whole-group failure means one of them is missing from the repository - retry one at a
    # time so the usable ones do not disappear along with it. Slower, but only when something is actually wrong
    APT_SKIPPED=""
    for pkg in $APT_OPTIONAL; do
      apt-get install -y -qq --no-install-recommends "$pkg" >/dev/null 2>&1 || APT_SKIPPED="$APT_SKIPPED $pkg"
    done
    [ -z "$APT_SKIPPED" ] || warn "Could not install (the related features stay off):$APT_SKIPPED"
  fi
elif command -v apt-get >/dev/null 2>&1; then
  say "$C_DIM Skipping package installation (not running as root) - using the PHP already on this machine$C_OFF"
fi

PHP_BIN="$(command -v php8.4 || command -v php8.3 || command -v php || true)"
[ -n "$PHP_BIN" ] || die "PHP not found - please install php-cli or php-fpm first"

PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
# 8.2 or newer is required because the code uses readonly classes, which arrived in 8.2.
# This used to say 8.1, which was wrong - the installer exited 0 on Ubuntu 22.04 (PHP 8.1)
# and the panel then died with a parse error on first use, with no warning beforehand
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP 8.2 or newer is required (found $PHP_VER) - on Ubuntu 22.04 add ppa:ondrej/php first"
ok "PHP $PHP_VER at $PHP_BIN"

MISSING=""
for ext in pdo_sqlite sqlite3 sodium posix pcntl sockets openssl mbstring json filter fileinfo curl zip; do
  "$PHP_BIN" -m | grep -qix "$ext" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || die "Missing PHP extensions:$MISSING (install with apt install php$PHP_VER-{sqlite3,mbstring,curl,zip})"
ok "Every required PHP extension is present"

# ---------------------------------------------------------------------------
# Portable mode - installs inside the project folder, no root required
# ---------------------------------------------------------------------------
if [ "$PORTABLE" = "yes" ]; then
  [ "$MODE" != "production" ] || die "Portable mode cannot be used with production"

  head_ "Portable install (no root required)"

  CONF_DIR="$SRC_DIR/etc"
  mkdir -p "$CONF_DIR" "$SRC_DIR/var/"{lib,log,run,sandbox,sites}

  if [ ! -f "$CONF_DIR/config.php" ]; then
    cp "$CONF_DIR/config.example.php" "$CONF_DIR/config.php"
    chmod 600 "$CONF_DIR/config.php"
    ok "Created etc/config.php"
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

  [ "$MODE" = "sandbox" ] && "$PHP_BIN" "$SRC_DIR/bin/phpcp" sandbox:seed >/dev/null && ok "Sample data loaded"

  head_ "Done"
  say "Start the agent : $C_DIM$SRC_DIR/bin/phpcp-agentd$C_OFF"
  say "Start the web   : $C_DIM$SRC_DIR/bin/phpcp serve --port=$PORT$C_OFF"
  printf '\n'
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. System users
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "Must be run with sudo (or use --portable to test without root)"

head_ "3. The panel's user and group"

getent group "$PANEL_GROUP" >/dev/null || { groupadd --system "$PANEL_GROUP"; ok "Created group $PANEL_GROUP"; }
getent passwd "$PANEL_USER" >/dev/null || {
  useradd --system --gid "$PANEL_GROUP" --home-dir "$DATA_DIR" \
          --shell /usr/sbin/nologin --comment "PHP Control Panel web tier" "$PANEL_USER"
  ok "Created user $PANEL_USER"
}
ok "User $PANEL_USER is in group $PANEL_GROUP"

# The account that owns every mailbox (PLAN-MAIL M-A)
#
# **One account for every mailbox**, not one per mailbox - 500 mailboxes should not bloat
# /etc/passwd and turn into 500 ways into the machine. Keeping mailboxes apart is Dovecot's
# job: it confines each session to that mailbox's folder.
#
# Always created, even when mail is not enabled yet - an unused account costs nothing, while
# forgetting it costs a failing `phpcp mail:enable` on a live machine while the admin is in a hurry
if ! getent group "$VMAIL_USER" >/dev/null; then
  groupadd --system "$VMAIL_USER"
fi
if ! getent passwd "$VMAIL_USER" >/dev/null; then
  useradd --system --gid "$VMAIL_USER" --home-dir "$MAIL_ROOT" \
          --shell /usr/sbin/nologin --comment "phpcp virtual mailboxes" "$VMAIL_USER"
fi
mkdir -p "$MAIL_ROOT"
chown "$VMAIL_USER:$VMAIL_USER" "$MAIL_ROOT"
chmod 0750 "$MAIL_ROOT"
ok "User $VMAIL_USER and the mailbox store $MAIL_ROOT are ready"

# ---------------------------------------------------------------------------
# 4. Install the files
# ---------------------------------------------------------------------------
head_ "4. Installing files and setting permissions"

mkdir -p "$INSTALL_DIR" "$CONF_DIR" "$DATA_DIR/backups" "$TMP_DIR" "$LOG_DIR" "$SITES_DIR" "$USERS_DIR" "$RUN_DIR"

# 0711 = walk into your own home, but never `ls` the list of all homes,
# so a customer cannot tell how many other customers are on this machine or what they are called
chmod 0711 "$USERS_DIR"

# `views` left this list on 2026-08-08 - the HTML UI was deleted entirely.
# Every page is now the SPA under `public/assets/spa/`, and the `templates` that remain are
# systemd/Apache/FPM config files, not web page templates
for dir in bin src templates db docs public; do
  rm -rf "${INSTALL_DIR:?}/$dir"
  cp -a "$SRC_DIR/$dir" "$INSTALL_DIR/"
done

# Clear leftovers from an older install - otherwise an upgraded machine keeps the old UI
# running quietly in parallel even though the PHP side no longer routes to it
rm -rf "${INSTALL_DIR:?}/views" "${INSTALL_DIR:?}/public/assets/js" \
       "${INSTALL_DIR:?}/public/assets/icons" "${INSTALL_DIR:?}/public/assets/images"
cp -a "$SRC_DIR/bootstrap.php" "$INSTALL_DIR/"

# The code is owned by root and read-only - neither the panel's web user nor the sites can change it
chown -R root:root "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} +
find "$INSTALL_DIR" -type f -exec chmod 644 {} +
chmod 755 "$INSTALL_DIR/bin/phpcp" "$INSTALL_DIR/bin/phpcp-agentd" "$INSTALL_DIR/bin/phpcp-scheduler" "$INSTALL_DIR/bin/phpcp-alert" "$INSTALL_DIR/bin/phpcp-acme-hook"
ok "Code installed at $INSTALL_DIR (read-only)"

# Data and logs belong to the web user
chown -R "$PANEL_USER:$PANEL_GROUP" "$DATA_DIR" "$LOG_DIR"
chmod 750 "$DATA_DIR" "$LOG_DIR" "$DATA_DIR/backups" "$TMP_DIR"
chown root:"$PANEL_GROUP" "$CONF_DIR" "$RUN_DIR"
chmod 750 "$CONF_DIR" "$RUN_DIR"
ok "Permissions set on the data and log directories"

ln -sf "$INSTALL_DIR/bin/phpcp" /usr/local/bin/phpcp
ok "Installed the phpcp command"

# ---------------------------------------------------------------------------
# 5. The panel's own config tree (the system's is never touched)
# ---------------------------------------------------------------------------
head_ "5. The panel's config tree"

if [ ! -f "$CONF_DIR/config.php" ]; then
  cp "$SRC_DIR/etc/config.example.php" "$CONF_DIR/config.php"
  ok "Created $CONF_DIR/config.php"
else
  warn "config.php already exists - not overwriting it"
fi

"$PHP_BIN" -r '
  $f = $argv[1];
  $s = file_get_contents($f);
  $s = preg_replace("/(\x27mode\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27".$argv[2]."\x27", $s, 1);
  $s = preg_replace("/(\x27layout\x27\s*=>\s*)\x27[^\x27]*\x27/", "$1\x27system\x27", $s, 1);
  $s = preg_replace("/(\x27port\x27\s*=>\s*)\d+/", "$1".$argv[3], $s, 1);
  $s = preg_replace("/(\x27cookie_secure\x27\s*=>\s*)(true|false)/", "$1true", $s, 1);

  // var_export every value that came from the command line - these are written into a PHP file that runs with the privileges of the panel
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

# What was just written has to parse, otherwise the panel will not start and the cause is hard to find
"$PHP_BIN" -l "$CONF_DIR/config.php" >/dev/null || die "config.php is broken after being written - check --sites-dir/--pointer-root"

# root:phpcp 0640 - never delete these two lines
#
# This file is `cp`'d by the installer, which runs as root, so it lands as root:root, while
# `key:generate` only ever sets chmod 0640 and never the group. The result is that the web user
# (phpcp-web) **cannot read the config at all**, App::boot() quietly falls back to the defaults,
# and the panel turns into sandbox mode with no database and no admin account - while the
# installer reports that every step passed.
# (This went missing once and installs on a clean machine stopped working - docker/verify-install.sh caught it)
#
# The group must be phpcp, not world-readable, because this file holds the system's secret_key
chown root:"$PANEL_GROUP" "$CONF_DIR/config.php"
chmod 640 "$CONF_DIR/config.php"

# ---------------------------------------------------------------------------
# 5.0 Get BIND9 ready to be switched on from the web UI later
#
# The service is started here on every install, but `dns.enabled` stays off until someone
# supplies nameserver names - those are two different questions and they were previously
# answered by the same flag:
#
#   "is the DNS daemon running?"      - always yes, it costs nothing and cannot be fixed
#                                       from the web UI once the admin has left the console
#   "may the panel own named.conf?"   - only once nameservers are known, because turning it
#                                       on rewrites that file and any hand-made zone in it
#                                       would vanish silently
#
# Tying the first to `--dns-ns` meant an admin who installed without that flag got a machine
# where flipping the DNS switch in Settings saved the value and nothing else: the daemon was
# never enabled and /etc/bind/zones did not exist, so the first record they added failed at
# `rndc reload` with a message that did not mention either. Doing this unconditionally is what
# lets `SettingsSet::activateDns()` finish the job from the web UI.
# ---------------------------------------------------------------------------
if [ "$MODE" = "production" ] && command -v named-checkzone >/dev/null 2>&1; then
  head_ "5.0 Preparing BIND9"

  mkdir -p /etc/bind/zones
  chown root:bind /etc/bind/zones 2>/dev/null || true
  chmod 775 /etc/bind/zones 2>/dev/null || true
  ok "Created /etc/bind/zones"

  if [ -d /run/systemd/system ]; then
    if systemctl enable --now named >/dev/null 2>&1 || systemctl enable --now bind9 >/dev/null 2>&1; then
      ok "BIND9 is running and set to start at boot"
    else
      warn "Could not start BIND9 - turn it on later from Settings or with systemctl status named"
    fi
  fi

  [ -z "$DNS_NS" ] && say "DNS stays switched off until nameservers are set - do that in Settings, or reinstall with --dns-ns"
fi

# ---------------------------------------------------------------------------
# 5.1 Hand named.conf.local over to the panel (only when nameserver names were given)
#
# `dns.enabled` is **never turned on by itself** without `--dns-ns`, because turning it on
# means the panel rewrites the whole of `named.conf.local` - zones the admin set up beforehand
# would vanish silently. BIND9 also rejects a zone with no NS record, so enabling it without
# knowing the nameserver names produces a system that cannot create a single zone.
#
# The same switch is available in Settings, and flipping it there now does everything this
# block does - so omitting `--dns-ns` costs the admin nothing but a visit to that page.
# ---------------------------------------------------------------------------
if [ -n "$DNS_NS" ]; then
  head_ "5.1 Wiring up BIND9"

  DNS_EMAIL_FINAL="${DNS_EMAIL:-hostmaster@$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)}"

  # Insert the block between the installer's own markers - reinstalling replaces the previous
  # one instead of appending copy after copy. var_export over the whole file is avoided because
  # it would wipe every comment explaining why each value is what it is, which is exactly what
  # the next admin needs to read
  "$PHP_BIN" -r '
    $f = $argv[1];
    $s = file_get_contents($f);

    $ns = array_values(array_filter(array_map("trim", explode(",", $argv[2]))));
    $block = "    /* phpcp:dns */\n"
      . "    // Written by install.sh --dns-ns - safe to edit, but reinstalling overwrites this block\n"
      . "    \x27dns\x27 => [\n"
      . "        \x27enabled\x27 => true,\n"
      . "        \x27nameservers\x27 => [" . implode(", ", array_map(
          static fn ($n) => var_export($n, true),
          $ns,
      )) . "],\n"
      . "        \x27soa_email\x27 => " . var_export($argv[3], true) . ",\n"
      . "    ],\n"
      . "    /* phpcp:dns:end */\n";

    if (str_contains($s, "/* phpcp:dns */")) {
        $s = preg_replace(
            "~[ \t]*/\* phpcp:dns \*/.*?/\* phpcp:dns:end \*/\n~s",
            $block,
            $s,
            1,
        );
    } else {
        // With duplicate keys in an array literal the later one wins, so this must always be
        // inserted "before" the other values, not appended, in case config.example.php one day
        // grows a dns key of its own
        $s = preg_replace("~(return\s*\[\R)~", "$1" . $block, $s, 1);
    }

    file_put_contents($f, $s);
  ' "$CONF_DIR/config.php" "$DNS_NS" "$DNS_EMAIL_FINAL"

  "$PHP_BIN" -l "$CONF_DIR/config.php" >/dev/null || die "config.php is broken after writing the DNS values - check --dns-ns/--dns-email"
  ok "Enabled dns.enabled with nameservers: $DNS_NS (SOA: $DNS_EMAIL_FINAL)"

  # The zone directory and the service itself were already handled in 5.0 on every install

  warn "Zones you set up yourself in named.conf.local will be overwritten by the panel - back them up first if you have any"
fi

if [ "$MODE" = "production" ]; then
  # Extra config for internal machines that run BIND but still want a hosts entry
  "$PHP_BIN" -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    // Find the log level line and add force_hosts_update_for_test_domains
    if (!str_contains($s, "force_hosts_update_for_test_domains")) {
      $s = preg_replace(
        "/(\x27log\x27\s*=>\s*\[[^\]]*?)(\x27level\x27\s*=>\s*\x27info\x27[^\]]*?)(\])/",
        "$1$2,\n        // Update /etc/hosts for .test domains even when BIND/named is running\n        // Suits development machines that run DNS but still want a hosts entry\n        \x27force_hosts_update_for_test_domains\x27 => true,$3",
        $s,
        1
      );
    }
    file_put_contents($f, $s);
  ' "$CONF_DIR/config.php"

  say "Set mode=production layout=system port=$PORT sites.dir=$SITES_DIR"
  say "Enabled hosts updates for .test domains"
else
  say "Set mode=$MODE layout=system port=$PORT sites.dir=$SITES_DIR"
fi

  # ---------------------------------------------------------------------------
  # 5.2 Link the phpMyAdmin that is already installed on this machine
  #
  # Only what is already there gets linked; nothing is downloaded from the internet into the
  # panel's web root. An earlier installer pulled in Adminer without checking a checksum or a
  # signature, which is exactly the kind of supply-chain risk the Updater was designed to
  # prevent - a PHP file swapped in transit would run with the panel's privileges immediately.
  # Dropping it is the better answer because phpMyAdmin already does this job.
  # ---------------------------------------------------------------------------
  PMA_DIR="$INSTALL_DIR/public/phpmyadmin"
  if [ -d /usr/share/phpmyadmin ] && [ ! -f "$PMA_DIR/index.php" ]; then
    mkdir -p "$PMA_DIR"
    ln -s /usr/share/phpmyadmin/* "$PMA_DIR/" 2>/dev/null || true
    # Set blowfish_secret so cookie logins do not hang
    if [ -f /etc/phpmyadmin/config.inc.php ]; then
      PMA_SECRET=$(openssl rand -hex 16)
      if ! grep -q "blowfish_secret" /etc/phpmyadmin/config.inc.php; then
        echo "\$cfg['blowfish_secret'] = '${PMA_SECRET}';" >> /etc/phpmyadmin/config.inc.php
      fi
    fi

    # ---------------------------------------------------------------------
    # Signon access - the panel prepares the session so the user never types a password
    #
    # Users never see their own database password (the panel generates it and stores it
    # encrypted). Without this setting the "Open phpMyAdmin" button leads to a login page
    # that nobody can fill in.
    #
    # SignonURL points back at the panel's database page - anyone opening /phpmyadmin
    # directly, without the button, is sent back to press the right button instead of
    # landing on a blank page.
    # ---------------------------------------------------------------------
    if [ -f /etc/phpmyadmin/config.inc.php ] && ! grep -q "phpcp_pma_signon" /etc/phpmyadmin/config.inc.php; then
      cat >> /etc/phpmyadmin/config.inc.php <<'PMACFG'

// --- Added by PHP Server Control Panel - do not edit by hand ---
// Sign in through the session the panel prepares (PLAN-V2 phase M5)
$i = 1;
$cfg['Servers'][$i]['auth_type']     = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'phpcp_pma_signon';
$cfg['Servers'][$i]['SignonURL']     = '/databases';
$cfg['Servers'][$i]['host']          = 'localhost';
$cfg['Servers'][$i]['compress']      = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
// Users only see the databases their own account may access, which the panel GRANTs at creation time
$cfg['Servers'][$i]['only_db']       = '';
PMACFG
      ok "Configured phpMyAdmin so the panel can sign users in without a password prompt"
    fi
    ok "phpMyAdmin linked (/phpmyadmin)"
  elif [ ! -d /usr/share/phpmyadmin ]; then
    say "phpMyAdmin not found - install it with apt install phpmyadmin and re-run the installer if you want it"
  fi

  # Clear traces of the Adminer an older installer used to download
  rm -rf "$INSTALL_DIR/public/adminer"

mkdir -p "$CONF_DIR/httpd" "$CONF_DIR/fpm/pool.d" "$CONF_DIR/vhosts.d" "$CONF_DIR/tls"

# Extra config files written by the admin - the generated vhost includes this directory last.
# The panel never overwrites anything in here, so it is the one place where edits survive
mkdir -p "$CONF_DIR/custom/apache" "$CONF_DIR/custom/nginx" \
         "$CONF_DIR/custom/postfix" "$CONF_DIR/custom/dovecot"
chmod 750 "$CONF_DIR/httpd" "$CONF_DIR/fpm" "$CONF_DIR/vhosts.d"
chmod 700 "$CONF_DIR/tls"
ok "Created a config tree separate from /etc/apache2 and /etc/php"


# `< /dev/null` matters: when a secret_key already exists (a reinstall), this command calls
# confirm(), which really does read STDIN, but the question itself is swallowed by >/dev/null.
# Without closing stdin the script would sit waiting for a keypress with nothing on screen to
# say so. Closed, fgets(STDIN) hits EOF immediately and answers "do not regenerate", which is
# what this line intends anyway - generate only when there is nothing yet
"$PHP_BIN" /usr/local/bin/phpcp key:generate < /dev/null >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 6. Temporary certificate
# ---------------------------------------------------------------------------
head_ "6. The panel's TLS certificate"

PANEL_HOST="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"

if [ ! -f "$CONF_DIR/tls/panel.crt" ]; then
  # SAN and CA:FALSE are required, otherwise modern browsers refuse it even after you accept the risk
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$CONF_DIR/tls/panel.key" -out "$CONF_DIR/tls/panel.crt" \
    -subj "/CN=$PANEL_HOST" \
    -addext "subjectAltName=DNS:$PANEL_HOST,DNS:localhost,IP:127.0.0.1" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth" >/dev/null 2>&1
  chmod 600 "$CONF_DIR/tls/panel.key"
  chmod 644 "$CONF_DIR/tls/panel.crt"
  ok "Created a self-signed certificate for $PANEL_HOST (swap it for Let's Encrypt later on the settings page)"
else
  ok "A certificate already exists"
fi

# A copy of the self-signed certificate - **the way back that must always exist**
#
# The settings page can replace the panel's certificate with a real one, and once it does,
# panel.crt becomes the Let's Encrypt certificate. Without this copy, going back to the
# self-signed one would mean generating a new one at the moment everything is already broken,
# which is the worst possible time to have to do something complicated
if [ ! -f "$CONF_DIR/tls/panel.selfsigned.crt" ] && [ -f "$CONF_DIR/tls/panel.crt" ]; then
  cp "$CONF_DIR/tls/panel.crt" "$CONF_DIR/tls/panel.selfsigned.crt"
  cp "$CONF_DIR/tls/panel.key" "$CONF_DIR/tls/panel.selfsigned.key"
  chmod 644 "$CONF_DIR/tls/panel.selfsigned.crt"
  chmod 600 "$CONF_DIR/tls/panel.selfsigned.key"
fi

# ---------------------------------------------------------------------------
# 7. systemd units
# ---------------------------------------------------------------------------
head_ "7. The panel's runtime stack"

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
ok "Installed phpcp-agentd.service"

# The notifier for a failed unit - only ever started by phpcp-agentd's OnFailure=
#
# It is not enabled, because it is a template unit systemd starts by name (phpcp-alert@...)
# when the agent enters the failed state. Enabling the template itself is meaningless and errors out
render "$SRC_DIR/templates/panel/phpcp-alert@.service.tpl" /etc/systemd/system/phpcp-alert@.service
ok "Installed phpcp-alert@.service"

# The timer - without it, the automatic rollback for SSH/firewall does not really exist,
# because it is designed for the case where the admin has already lost their connection (no request comes in to trigger it)
render "$SRC_DIR/templates/panel/phpcp-scheduler.service.tpl" /etc/systemd/system/phpcp-scheduler.service
render "$SRC_DIR/templates/panel/phpcp-scheduler.timer.tpl"   /etc/systemd/system/phpcp-scheduler.timer
ok "Installed phpcp-scheduler.service and phpcp-scheduler.timer"

UNITS="phpcp-agentd.service"

if [ "$MODE" = "production" ]; then
  render "$SRC_DIR/templates/panel/phpcp-fpm.service.tpl" /etc/systemd/system/phpcp-fpm.service
  render "$SRC_DIR/templates/panel/phpcp-web.service.tpl" /etc/systemd/system/phpcp-web.service
  ok "Installed phpcp-fpm.service and phpcp-web.service"

  # The panel's own config tree - regenerated every time, because these files belong to the installer, not to the admin
  render "$SRC_DIR/templates/panel/php-fpm.conf.tpl"    "$CONF_DIR/fpm/php-fpm.conf"
  render "$SRC_DIR/templates/panel/panel-pool.conf.tpl" "$CONF_DIR/fpm/pool.d/panel.conf"
  render "$SRC_DIR/templates/panel/httpd.conf.tpl"      "$CONF_DIR/httpd/httpd.conf"
  chown root:"$PANEL_GROUP" "$CONF_DIR/fpm/php-fpm.conf" "$CONF_DIR/fpm/pool.d/panel.conf" "$CONF_DIR/httpd/httpd.conf"
  chmod 640 "$CONF_DIR/fpm/php-fpm.conf" "$CONF_DIR/fpm/pool.d/panel.conf" "$CONF_DIR/httpd/httpd.conf"
  ok "Generated the panel's httpd.conf and php-fpm.conf"

  # Check the config before starting - failing here is still easier to recover from than failing under systemd
  "$HTTPD_BIN" -t -f "$CONF_DIR/httpd/httpd.conf" >/dev/null 2>&1 \
    || die "The panel's httpd.conf failed configtest: $("$HTTPD_BIN" -t -f "$CONF_DIR/httpd/httpd.conf" 2>&1 | tail -3)"
  "$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" >/dev/null 2>&1 \
    || die "The panel's php-fpm.conf failed configtest: $("$FPM_BIN" -t -y "$CONF_DIR/fpm/php-fpm.conf" 2>&1 | tail -3)"
  ok "configtest passed for httpd and fpm"

  UNITS="$UNITS phpcp-fpm.service phpcp-web.service"
fi

if [ -d /run/systemd/system ]; then
  systemctl daemon-reload
  # shellcheck disable=SC2086
  systemctl enable $UNITS >/dev/null 2>&1

  # restart, not `enable --now` - on a machine being reinstalled to update the code the services
  # are still active, so `--now` does nothing at all, and phpcp-agentd, a long-running PHP
  # process, keeps using the old code it loaded at startup even though the files on disk are new.
  # (restart starts the unit by itself when it is not running, so it works for both fresh installs and reinstalls)
  #
  # All units in a single command, because all three share RuntimeDirectory=phpcp
  # shellcheck disable=SC2086
  systemctl restart $UNITS
  ok "Enabled and restarted: $UNITS"

  # The timer is kept out of $UNITS because its .service is a oneshot the timer triggers,
  # not a long-running service - `restart` on it directly would just run one round and finish
  systemctl enable phpcp-scheduler.timer >/dev/null 2>&1
  systemctl restart phpcp-scheduler.timer
  ok "Enabled phpcp-scheduler.timer (every minute)"

  # "running right now" and "starts at boot" are two different things - every user site on this
  # machine depends on apache2 and mariadb, and if those are left disabled the machine comes
  # back with every site down while the installer reported success.
  # Enabling again does no harm when they are already enabled
  if [ "$MODE" = "production" ]; then
    for unit in apache2 mariadb; do
      systemctl list-unit-files "$unit.service" >/dev/null 2>&1 || continue
      if systemctl is-enabled --quiet "$unit" 2>/dev/null; then
        say "$unit is already set to start at boot"
      else
        systemctl enable "$unit" >/dev/null 2>&1 && ok "Set $unit to start at boot" \
          || warn "Could not set $unit to start at boot - check with systemctl status $unit"
      fi
    done
  fi
else
  warn "systemd not found - the unit files are installed but not enabled"
fi

# ---------------------------------------------------------------------------
# 8. Database and the first administrator
# ---------------------------------------------------------------------------
head_ "8. Database and the admin account"
# Check that the panel can reach MariaDB - "check" only, nothing is changed
#
# Never run ALTER USER against root; it has caused real damage before:
# Debian/Ubuntu set root@localhost to unix_socket from the start, which means anyone who is
# OS root can connect immediately with no password stored anywhere. The panel's agent runs as
# root, so it already uses that path directly.
#
# ALTER USER ... IDENTIFIED BY '<password>' switches the plugin from unix_socket to a password,
# so the admin's `sudo mariadb` stops working forever, and if writing /root/.my.cnf then fails
# (full disk, interrupted script) nobody knows that password at all
# = locked out of the database permanently, recoverable only with --skip-grant-tables
if command -v mariadb >/dev/null 2>&1; then
  # Wrapped in && / || so set -e does not end the script when the database cannot be reached
  MARIADB_PROBE=$(mariadb -e "SELECT 1" 2>&1) && MARIADB_PROBE_RC=0 || MARIADB_PROBE_RC=$?

  if [ "$MARIADB_PROBE_RC" -eq 0 ]; then
    # Covers both unix_socket and the case where /root/.my.cnf already exists
    # (the client reads /root/.my.cnf automatically when run as root)
    ok "Connected to MariaDB as root - the panel uses this same path through the agent"
  elif mariadb --defaults-file=/etc/mysql/debian.cnf -e "SELECT 1" >/dev/null 2>&1; then
    ok "Connected to MariaDB through /etc/mysql/debian.cnf - the panel will use this file"
  else
    warn "Cannot connect to MariaDB - the database pages in the panel will not work yet"
    warn "  If the service is not running : sudo systemctl enable --now mariadb"
    warn "  If root has a password nobody knows, put it back to unix_socket with"
    warn "    sudo systemctl stop mariadb"
    warn "    sudo systemctl set-environment MYSQLD_OPTS=\"--skip-grant-tables --skip-networking\""
    warn "    sudo systemctl start mariadb && sudo mariadb -u root"
    warn "    > FLUSH PRIVILEGES; ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket; FLUSH PRIVILEGES;"
    warn "    sudo systemctl stop mariadb && sudo systemctl unset-environment MYSQLD_OPTS"
    warn "    sudo systemctl start mariadb"
  fi
fi
# runuser ships with util-linux so it is always there, unlike sudo, which a minimal Debian may not have
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
  ok "Created $SANDBOX_DIR and loaded the sample data"
fi

# ---------------------------------------------------------------------------
# 9. firewall
# ---------------------------------------------------------------------------
head_ "9. Firewall (UFW)"

if [ "$MODE" = "production" ] && command -v ufw >/dev/null; then
  # The real SSH port has to be found before the firewall goes up - opening a hard-coded 22 and
  # then enabling would cut the admin off the machine instantly if they moved SSH elsewhere,
  # which is very common on real servers (this panel even has a button to move the SSH port).
  #
  # Order of trust: sshd -T (reads the real config including every Include) -> the config files
  # -> the port of the session doing the install right now -> 22
  # Every line needs `|| true` - `set -e` kills the whole script when a command inside $( )
  # returns non-zero, and both of these fail routinely on a machine with no host key or no config file
  # (seen for real while testing an install on debian:12 - the installer died here with code 255
  # even though the database and the admin account had already been created)
  SSH_PORTS=""
  if command -v sshd >/dev/null 2>&1; then
    SSH_PORTS="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2 }' || true)"
  fi
  if [ -z "$SSH_PORTS" ]; then
    SSH_PORTS="$(awk '/^[[:space:]]*[Pp]ort[[:space:]]+[0-9]+/ { print $2 }' \
      /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true)"
  fi
  # The destination port of the current session - the value we know for certain still lets us in
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

  say "Enabling UFW and opening the required ports (SSH: $SSH_ALLOWED, 53, 80, 443, $PORT)..."
  ufw allow 53 comment "DNS" >/dev/null 2>&1 || true
  ufw allow 80/tcp comment "HTTP Web" >/dev/null 2>&1 || true
  ufw allow 443/tcp comment "HTTPS Web" >/dev/null 2>&1 || true
  ufw allow "$PORT/tcp" comment "PHP Control Panel" >/dev/null 2>&1 || true
  # Mail ports - opened at install time even though mail is not enabled for any domain yet.
  #
  # Nothing listens on them until `phpcp mail:enable` runs, so this adds no attack surface now.
  # But opening them later, on a machine that is already receiving mail, means there is a window
  # where inbound mail is dropped by the firewall silently, which is very hard to diagnose
  if [ "$WITH_POSTFIX" = "yes" ]; then
    ufw allow 25/tcp comment "SMTP" >/dev/null 2>&1 || true
    ufw allow 465/tcp comment "SMTP submission (TLS)" >/dev/null 2>&1 || true
    ufw allow 587/tcp comment "SMTP submission (STARTTLS)" >/dev/null 2>&1 || true
    ufw allow 993/tcp comment "IMAP over TLS" >/dev/null 2>&1 || true
    ufw allow 995/tcp comment "POP3 over TLS" >/dev/null 2>&1 || true
  fi
  ufw --force enable >/dev/null 2>&1 || true
  ok "UFW firewall configured and enabled (SSH: $SSH_ALLOWED, 53, 80, 443, $PORT)"
  case " $SSH_ALLOWED " in
    *" 22 "*) ;;
    *) warn "Port 22 was not opened because sshd is not listening on it - run ufw allow 22/tcp first if you move back" ;;
  esac
else
  say "$C_DIM Skipped (ufw is not installed, or this is not production mode)$C_OFF"
fi

# ---------------------------------------------------------------------------
# 10. Log rotation
#
# Without this, /var/log/phpcp grows without limit. panel.log and agent.log grow fastest on a
# machine in real use, because they record every request and every command that goes through the agent
# ---------------------------------------------------------------------------
if [ "$WITH_LOGROTATE" = "yes" ]; then
  head_ "10. Log rotation"

  if [ -d /etc/logrotate.d ]; then
    render "$SRC_DIR/templates/panel/logrotate.conf.tpl" /etc/logrotate.d/phpcp
    chown root:root /etc/logrotate.d/phpcp
    chmod 644 /etc/logrotate.d/phpcp

    # logrotate skips a malformed config file entirely without telling anyone - check it now
    if command -v logrotate >/dev/null 2>&1; then
      if LOGROTATE_ERR="$(logrotate -d /etc/logrotate.d/phpcp 2>&1)"; then
        ok "Log rotation configured at /etc/logrotate.d/phpcp (weekly in general, monthly for audit)"
      else
        # The message has to be captured before the file is deleted - checking the template that
        # still has {{...}} in it would produce an error about something else entirely
        rm -f /etc/logrotate.d/phpcp
        die "The logrotate config file failed its check: $(printf '%s' "$LOGROTATE_ERR" | tail -3)"
      fi
    else
      ok "Wrote /etc/logrotate.d/phpcp (the logrotate command was not found, so the check was skipped)"
    fi
  else
    warn "No /etc/logrotate.d - skipping log rotation setup"
  fi
else
  say "$C_DIM Skipping log rotation setup (--no-logrotate)$C_OFF"
fi

# ---------------------------------------------------------------------------
# 10.9 Regenerate the web server config files for the current mode
#
# A reinstall has to end up with files that match the current mode - both ports.conf, which says
# which port Apache listens on, and the vhost for http://localhost (if this machine has it).
# Without this call those files only come back when somebody remembers to run sites:rebuild.
#
# Failing here is not a reason for the install to fail (a machine with no sites yet is normal), but it has to be visible
# ---------------------------------------------------------------------------
if as_panel "$PHP_BIN" /usr/local/bin/phpcp sites:rebuild >/dev/null 2>&1; then
  ok "Regenerated the web server config files"
else
  warn "Could not regenerate the web server config files - run 'phpcp sites:rebuild' to see the full message"
fi

# ---------------------------------------------------------------------------
# 11. Check the result of the install
#
# An installer that exits 0 does not mean the system works - it has happened that every step
# reported success while the panel could not read its config and therefore had no database at
# all. doctor checks from the point of view of the user the panel really runs as (not root),
# which is the only angle that catches a permission problem like that
# ---------------------------------------------------------------------------
if [ "$RUN_DOCTOR" = "yes" ]; then
  head_ "11. Checking the result of the install"

  as_panel "$PHP_BIN" /usr/local/bin/phpcp doctor || DOCTOR_RC=$?
  if [ "${DOCTOR_RC:-0}" -ne 0 ]; then
    warn "doctor found the problems above - fix them all before going live"
  fi
fi

# Hit every endpoint on the real machine - this needs an account that can really log in, so it
# is optional rather than the default (a freshly created account carries the must_change_password flag and cannot log in)
if [ -n "$SMOKE_USER" ] && [ -n "$SMOKE_PASSWORD_FILE" ]; then
  head_ "12. Hitting every endpoint on the real machine"

  if as_panel "$PHP_BIN" "$INSTALL_DIR/bin/phpcp-smoke" \
      --url="https://127.0.0.1:$PORT" --user="$SMOKE_USER" --password-file="$SMOKE_PASSWORD_FILE"; then
    ok "Every endpoint answered as promised"
  else
    warn "phpcp-smoke found problems - see the output above"
  fi
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
head_ "Done"
say "Check the status : ${C_DIM}phpcp status${C_OFF}"
say "Check for issues : ${C_DIM}phpcp doctor${C_OFF}"

if [ "$MODE" = "production" ]; then
  say "Open the panel   : ${C_DIM}https://$(hostname -I 2>/dev/null | awk '{print $1}'):$PORT${C_OFF}"
  printf '\n'
  warn "Do not forget the pre-production checklist in docs/SECURITY.md §5"
  printf '\n'
  if [ -n "$DNS_NS" ]; then
    say "DNS  : BIND9 is wired up - create records from the Domains page right away"
  else
    warn "BIND9 is running but the panel is not wired up to it (dns.enabled = false)"
    say "Turn it on in ${C_DIM}Settings -> DNS${C_OFF}: fill in the nameserver names and flip the switch"
  fi
  if [ "$WITH_POSTFIX" = "yes" ]; then
    say "Mail : set the relay/sender on the Settings page - what you set is written into main.cf for you"
  fi
else
  printf '\n'
  warn "The system is in $MODE mode - commands will not affect the real server"
fi
printf '\n'
