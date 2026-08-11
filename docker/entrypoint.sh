#!/usr/bin/env bash
#
# จุดเริ่มของคอนเทนเนอร์ — ติดตั้งครั้งแรก, เปิด agent, แล้วเปิดเว็บ
#
# ตัวแปรที่ปรับได้:
#   PHPCP_PORT   พอร์ตที่เปิดในคอนเทนเนอร์ (ค่าเริ่มต้น 8080)
#   PHPCP_ADMIN  ชื่อผู้ดูแลระบบคนแรก (ค่าเริ่มต้น admin)
#   PHPCP_SEED   yes = ใส่ข้อมูลตัวอย่างซ้ำทุกครั้งที่เริ่มคอนเทนเนอร์

set -euo pipefail

cd /opt/phpcp

PORT="${PHPCP_PORT:-8080}"
ADMIN="${PHPCP_ADMIN:-admin}"

if [ ! -f etc/config.php ]; then
    ./install.sh --mode=sandbox --portable --user="$ADMIN" --port="$PORT"
else
    printf '\n  ติดตั้งไว้แล้ว — ข้ามขั้นตอนติดตั้ง\n\n'
    php bin/phpcp db:migrate
    [ "${PHPCP_SEED:-no}" = "yes" ] && php bin/phpcp sandbox:seed >/dev/null
fi

# ชั้นที่ 2 — ในโหมด sandbox ไม่ต้องเป็น root
php bin/phpcp-agentd &
AGENT_PID=$!
trap 'kill -TERM "$AGENT_PID" 2>/dev/null || true' TERM INT

for _ in $(seq 1 50); do
    [ -S var/run/agent.sock ] && break
    sleep 0.1
done

if [ ! -S var/run/agent.sock ]; then
    echo "phpcp-agentd ไม่ได้เปิด socket — ดู var/log/agent.log" >&2
    exit 1
fi

# php -S ผูกกับ 0.0.0.0 เพราะต้องเข้าถึงจากนอกคอนเทนเนอร์
# ปลอดภัยเท่าที่ Docker map พอร์ตให้เท่านั้น — อย่าเปิดออกอินเทอร์เน็ต
exec php bin/phpcp serve --host=0.0.0.0 --port="$PORT"
