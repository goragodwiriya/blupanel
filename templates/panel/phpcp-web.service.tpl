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
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
RestrictNamespaces=yes
LockPersonality=yes
ReadWritePaths={{LOG_DIR}} {{RUN_DIR}}

[Install]
WantedBy=multi-user.target
