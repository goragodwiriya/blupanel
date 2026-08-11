#!/bin/bash
# ตัวแทน systemctl สำหรับคอนเทนเนอร์ที่ไม่มี systemd
#
# panel เรียก `systemctl reload <unit>` เพื่อให้ค่าตั้งใหม่มีผล ซึ่งเป็นพฤติกรรมที่ถูกต้อง
# บนเซิร์ฟเวอร์จริง การทดสอบในคอนเทนเนอร์จึงต้องจัดหา systemctl ที่ "ทำงานได้จริง" ให้
# ไม่ใช่แก้ panel ให้เงียบ — ถ้า reload ไม่เกิดขึ้นจริง pool ของเว็บไซต์ใหม่จะไม่ถูกสร้าง
# แล้วทุกคำขอจะตอบ 503 โดยที่ panel รายงานว่า "สร้างเว็บไซต์เรียบร้อยแล้ว"
#
# ติดตั้งด้วย docker/acceptance.sh หรือ docker/dev-start.sh
# บนเซิร์ฟเวอร์จริงไม่ต้องใช้ไฟล์นี้เลย
case "$1" in
  reload|reload-or-restart|restart)
    case "$2" in
      apache2) /usr/sbin/apache2ctl -k graceful; exit $? ;;
      nginx)   /usr/sbin/nginx -s reload 2>/dev/null; exit 0 ;;
      php*-fpm)
        # อ่าน PID จากไฟล์ที่ php-fpm เขียนไว้ ไม่ใช้ pgrep จับชื่อ process —
        # ชื่อ process ของ php-fpm ต่างกันไปตามดิสทริบิวชัน และ pgrep -f
        # ยังเสี่ยงจับ command line ของตัวเองจนฆ่าผิดตัว
        v="${2#php}"; v="${v%-fpm}"
        for f in "/run/php/php${v}-fpm.pid" "/run/php-fpm/php${v}-fpm.pid"; do
          [ -f "$f" ] && kill -USR2 "$(cat "$f")" 2>/dev/null && exit 0
        done
        # ไม่มี pid file — หาจากชื่อ process แทน
        # วงเล็บเหลี่ยมกัน pgrep จับ command line ของสคริปต์นี้เอง: ตัวสคริปต์มี "[m]aster"
        # ซึ่ง regex "php-fpm: [m]aster" ไม่ match แต่ match ชื่อจริง "php-fpm: master"
        pid=$(pgrep -f "php-fpm: [m]aster process \(/etc/php/${v}/" | head -1)
        if [ -n "$pid" ]; then
          kill -USR2 "$pid" 2>/dev/null && exit 0
        fi
        # ยังไม่ได้รันอยู่ — สตาร์ตผ่าน init script แทน ไม่ใช่แกล้งบอกว่าสำเร็จ
        exec /usr/sbin/service "$2" start
        ;;
    esac
    # unit อื่น ๆ ใช้ init script ถ้ามี ไม่มีก็ถือว่าไม่มีอะไรต้องทำ
    [ -x "/etc/init.d/$2" ] && exec /usr/sbin/service "$2" "${1/reload-or-restart/restart}"
    exit 0 ;;
  is-enabled|is-active) echo active; exit 0 ;;
esac
exec /usr/bin/systemctl.real "$@"
