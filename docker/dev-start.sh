#!/usr/bin/env bash
# สตาร์ตบริการทั้งหมดในคอนเทนเนอร์ทดสอบแบบ production
#
# คอนเทนเนอร์ไม่มี systemd จึงเรียก init script ตรง ๆ  ใช้สคริปต์นี้เป็นคำสั่งหลัก
# ของคอนเทนเนอร์เพื่อให้ docker start/restart แล้วบริการขึ้นครบเองโดยไม่ต้อง exec ทีละตัว
#
#   docker run -d --name phpcp-prod-test ... phpcp:full-prod /usr/local/bin/phpcp-dev-start
#
# ห้ามใช้บนเซิร์ฟเวอร์จริง — เครื่องจริงใช้ systemd unit ที่ install.sh ติดตั้งให้

set -u

CONF_DIR=/etc/phpcp
INSTALL_DIR=/usr/share/phpcp
LOG=/var/log/phpcp/dev-start.log

mkdir -p /var/log/phpcp

# panel สั่ง `systemctl reload <unit>` เมื่อสร้าง/แก้เว็บไซต์ คอนเทนเนอร์ไม่มี systemd
# จึงต้องวางตัวแทนที่ "ทำงานจริง" ไว้ — ถ้า reload ไม่เกิดขึ้น pool ของเว็บไซต์ใหม่
# จะไม่ถูกสร้าง แล้วทุกคำขอจะตอบ 503 โดยที่ panel บอกว่าสำเร็จ
SHIM_SRC="${INSTALL_DIR}/docker/systemctl-shim.sh"
if [ -f "${SHIM_SRC}" ] && ! systemctl is-system-running >/dev/null 2>&1; then
    [ -f /usr/bin/systemctl.real ] || cp /usr/bin/systemctl /usr/bin/systemctl.real
    install -m 755 "${SHIM_SRC}" /usr/bin/systemctl
    echo "  ✔ systemctl shim (คอนเทนเนอร์ไม่มี systemd)"
fi

# บริการที่ daemonize ตัวเองจะค้างถ้ายังถือ stdout ของสคริปต์ไว้ จึงตัด stdio ให้ขาด
# และครอบด้วย timeout เพื่อไม่ให้ตัวใดตัวหนึ่งบล็อกลำดับการสตาร์ตทั้งหมด
run_daemon() {
    timeout 25 "$@" </dev/null >>"${LOG}" 2>&1
}

start_unit() {
    local unit="$1"
    if [ ! -x "/etc/init.d/${unit}" ]; then
        echo "  - ${unit} (ไม่ได้ติดตั้ง ข้าม)"
        return
    fi
    if run_daemon service "${unit}" start; then
        echo "  ✔ ${unit}"
    else
        echo "  ! ${unit} (สตาร์ตไม่สำเร็จ)"
    fi
}

echo "== บริการของระบบ =="
for unit in mariadb apache2 nginx named php7.4-fpm php8.4-fpm ssh cron; do
    start_unit "${unit}"
done

echo "== runtime stack ของ panel =="

# กันซ้ำกรณีสคริปต์ถูกเรียกซ้ำในคอนเทนเนอร์ที่ยังรันอยู่
pkill -f 'bin/phpcp-agentd' >/dev/null 2>&1 || true
pkill -f "${CONF_DIR}/fpm/php-fpm.conf" >/dev/null 2>&1 || true
if [ -f /run/phpcp/panel-httpd.pid ]; then
    kill "$(cat /run/phpcp/panel-httpd.pid)" >/dev/null 2>&1 || true
fi
# ต้องลบ pid file ทิ้งด้วย ไม่ใช่แค่ kill — คอนเทนเนอร์ที่สร้างจาก snapshot จะมี pid เก่า
# ค้างอยู่ ถ้าเลข pid นั้นบังเอิญตรงกับ process อื่น Apache จะตอบ "already running" แล้วไม่สตาร์ต
rm -f /run/phpcp/panel-httpd.pid /run/phpcp/panel-fpm.pid
sleep 1

if [ -x "${INSTALL_DIR}/bin/phpcp-agentd" ]; then
    setsid "${INSTALL_DIR}/bin/phpcp-agentd" </dev/null >>"${LOG}" 2>&1 &
    echo "  ✔ phpcp-agentd"
else
    echo "  ! phpcp-agentd (ไม่พบไฟล์)"
fi

# pool ของ panel ตั้ง daemonize = no ไว้ (ออกแบบให้ systemd คุม) จึงต้องส่งไปเบื้องหลังเอง
setsid /usr/sbin/php-fpm8.4 -y "${CONF_DIR}/fpm/php-fpm.conf" </dev/null >>"${LOG}" 2>&1 &
sleep 2
if [ -S /run/phpcp/panel-fpm.sock ]; then
    echo "  ✔ panel php-fpm"
else
    echo "  ! panel php-fpm (สตาร์ตไม่สำเร็จ — ดู ${LOG})"
fi

run_daemon /usr/sbin/apache2 -f "${CONF_DIR}/httpd/httpd.conf"
# Apache เขียน pid file หลัง fork จึงต้องรอ ไม่ใช่เช็คทันที
# (และ Apache ตอบ exit 0 พร้อมข้อความ "already running" ด้วย จึงเชื่อรหัสออกอย่างเดียวไม่ได้)
for _ in 1 2 3 4 5; do
    [ -f /run/phpcp/panel-httpd.pid ] && break
    sleep 1
done
if [ -f /run/phpcp/panel-httpd.pid ]; then
    echo "  ✔ panel httpd (8443)"
else
    echo "  ! panel httpd (สตาร์ตไม่สำเร็จ — ดู /var/log/phpcp/panel-error.log)"
fi

echo "พร้อมใช้งาน — https://localhost:8443/"

# ตัวคอนเทนเนอร์ต้องมี process ค้างไว้ ไม่งั้นจะดับทันทีที่สคริปต์จบ
exec sleep infinity
