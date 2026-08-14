[Unit]
Description=PHP Control Panel — เว็บเซิร์ฟเวอร์ของ panel เอง (พอร์ต {{PANEL_PORT}})
Documentation=file://{{INSTALL_DIR}}/docs/ARCHITECTURE.md
After=network.target phpcp-fpm.service phpcp-agentd.service
Requires=phpcp-fpm.service
# Wants ไม่ใช่ Requires — panel ต้องเปิดหน้าเว็บได้แม้ agent ล่ม เพื่อให้ผู้ดูแล
# เข้ามาอ่านสาเหตุได้ (หน้าเว็บจะแจ้ง "ติดต่อ agent ไม่ได้" แทนที่จะไม่ขึ้นเลย)
Wants=phpcp-agentd.service

[Service]
Type=simple
# instance แยกของ apache2 ใช้ config tree ของ panel เท่านั้น
# ไม่แตะ /etc/apache2 และไม่ขึ้นกับ apache2.service ที่ผู้ใช้จัดการผ่าน UI ได้
# Group=phpcp เพื่อให้ RuntimeDirectory เป็น root:phpcp ผู้ใช้เว็บจึงเข้า socket ได้
Group={{PANEL_GROUP}}
Environment=APACHE_CONFDIR={{CONF_DIR}}/httpd
ExecStart={{HTTPD_BIN}} -d {{CONF_DIR}}/httpd -f {{CONF_DIR}}/httpd/httpd.conf -DFOREGROUND
ExecReload={{HTTPD_BIN}} -d {{CONF_DIR}}/httpd -f {{CONF_DIR}}/httpd/httpd.conf -k graceful
Restart=always
RestartSec=2

RuntimeDirectory=phpcp
RuntimeDirectoryMode=0750
# ห้ามลบ — ดูคำอธิบายเต็มใน phpcp-agentd.service
# ถ้าไม่มีบรรทัดนี้ การรีสตาร์ต phpcp-web จะลบ socket ของอีกสองบริการไปด้วย
RuntimeDirectoryPreserve=yes

NoNewPrivileges=yes
# ProtectHome=no + InaccessiblePaths=/root — ไม่ใช่การผ่อนความปลอดภัย แต่เป็นการเล็งให้ตรง
#
# `ProtectHome=yes` ทำให้ /home, /root และ /run/user **ว่างเปล่า**ในมุมมองของบริการนี้ ·
# ตั้งแต่บ้านของลูกค้าย้ายมาอยู่ที่ /home ตัว agent จึงสร้างหรือแตะบ้านใครไม่ได้เลย
# — `site.create` ล้มด้วย "สร้างไดเรกทอรีไม่สำเร็จ: /home/<ผู้ใช้>" ทุกครั้ง
#
# `ReadWritePaths=/home` **ปลดล็อกไม่ได้** — ทดสอบบนเครื่องจริงแล้ว (2026-08-14) systemd
# ซ่อน /home ตั้งแต่ตอนสร้าง mount namespace ก่อนที่ ReadWritePaths จะมีผล
#
# จึงปิด ProtectHome แล้วกัน /root ตรง ๆ แทน ซึ่งเป็นสิ่งเดียวในสามอย่างนั้นที่ panel
# ไม่มีเหตุต้องแตะเลย · /home เป็นเนื้องานของ panel โดยตรง การกันมันคือการกันไม่ให้ทำงาน
ProtectHome=no
InaccessiblePaths=/root
ProtectKernelTunables=yes
ProtectKernelModules=yes
RestrictNamespaces=yes
LockPersonality=yes
ReadWritePaths={{LOG_DIR}} {{RUN_DIR}}

[Install]
WantedBy=multi-user.target
