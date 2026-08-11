# PHP Server Control Panel — อิมเมจสำหรับ "ทดสอบใช้งานจริง" ในโหมด sandbox
#
# ทำไมใช้ได้ทั้งที่คอนเทนเนอร์ไม่มี systemd:
#   โหมด sandbox เปลี่ยนเส้นทางไฟล์ทุกเส้นเข้า prefix ของตัวเอง และจำลองคำสั่งที่
#   เปลี่ยนสถานะระบบ (systemctl, useradd, ufw, certbot, mariadb) ไว้ในตัวอยู่แล้ว
#   ส่วนคำสั่งอ่านอย่างเดียวที่สำคัญ เช่น `apache2 -t` ยังรันของจริง จึงต้องมี apache2 ในอิมเมจ
#
# อิมเมจนี้ใช้กับโหมด production ไม่ได้ — ดูเหตุผลใน docs/ARCHITECTURE.md §5.2 และ §13
# (panel ตัวจริงต้องคุม systemd unit, ผู้ใช้ระบบ และ Apache/FPM ของโฮสต์)

FROM debian:bookworm-slim

ARG DEBIAN_FRONTEND=noninteractive

# policy-rc.d กัน postinst ของ apache2 พยายามสตาร์ตบริการระหว่าง build (ในคอนเทนเนอร์ไม่มี init)
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
    php-cli php-sqlite3 php-mbstring php-curl php-zip \
    apache2 openssl ca-certificates procps \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --system phpcp \
    && useradd --system --gid phpcp --home-dir /opt/phpcp --shell /usr/sbin/nologin phpcp

WORKDIR /opt/phpcp

# โค้ดเป็นของ root และอ่านอย่างเดียว เหมือนการติดตั้งจริง (ARCHITECTURE §13)
COPY . /opt/phpcp
COPY docker/entrypoint.sh /usr/local/bin/phpcp-entrypoint

RUN chmod +x /usr/local/bin/phpcp-entrypoint bin/phpcp bin/phpcp-agentd install.sh \
    && ln -sf /opt/phpcp/bin/phpcp /usr/local/bin/phpcp \
    && mkdir -p etc var \
    && chown phpcp:phpcp etc var

USER phpcp

ENV PHPCP_PORT=8080 \
    PHPCP_ADMIN=admin

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/phpcp-entrypoint"]
