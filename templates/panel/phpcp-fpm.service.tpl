[Unit]
Description=PHP Control Panel — FPM ของ panel เอง (แยกจาก php*-fpm ของระบบ)
Documentation=file://{{INSTALL_DIR}}/docs/ARCHITECTURE.md
After=network.target

[Service]
Type=notify
# ใช้ binary เดียวกับของระบบ แต่คนละ config tree คนละ pid คนละ socket
# panel จึงไม่ล่มเมื่อผู้ใช้สั่งหยุด php*-fpm ที่มันบริหาร (ARCHITECTURE §5.2)
Group={{PANEL_GROUP}}
ExecStart={{FPM_BIN}} --nodaemonize --fpm-config {{CONF_DIR}}/fpm/php-fpm.conf
ExecReload=/bin/kill -USR2 $MAINPID
Restart=always
RestartSec=2

RuntimeDirectory=phpcp
RuntimeDirectoryMode=0750
# ห้ามลบ — ดูคำอธิบายเต็มใน phpcp-agentd.service
# ถ้าไม่มีบรรทัดนี้ การรีสตาร์ต phpcp-fpm จะลบ agent.sock ของ phpcp-agentd ไปด้วย
RuntimeDirectoryPreserve=yes

NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
RestrictNamespaces=yes
RestrictRealtime=yes
LockPersonality=yes
ReadWritePaths={{DATA_DIR}} {{LOG_DIR}} {{RUN_DIR}}

[Install]
WantedBy=multi-user.target
