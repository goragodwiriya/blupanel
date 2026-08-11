[Unit]
Description=PHP Control Panel — งานตามเวลา (scheduled_jobs)
Documentation=file://{{INSTALL_DIR}}/docs/PLAN-V2.md
After=network.target phpcp-agentd.service
# Wants ไม่ใช่ Requires — ถ้า agent ล่ม ตัวจับเวลายังต้องทำงานต่อเพื่อบันทึกว่า
# งานล้มเหลวเพราะติดต่อ agent ไม่ได้ ซึ่งเป็นข้อมูลที่ผู้ดูแลต้องเห็นใน `phpcp doctor`
Wants=phpcp-agentd.service

[Service]
Type=oneshot
# รันด้วยสิทธิ์เดียวกับชั้นเว็บเป๊ะ ๆ ไม่ใช่ root — scheduler เป็นแค่ "ผู้กดปุ่มตามเวลา"
# ทุกอย่างที่ต้องใช้สิทธิ์สูงเดินผ่าน agent เหมือนคำสั่งที่มาจากหน้าเว็บทุกประการ
# ถ้าให้เป็น root จะเกิดทางที่สองที่แตะระบบได้โดยไม่ผ่านชั้นที่ 2 ซึ่งขัดกับ ARCHITECTURE §4
User={{PANEL_USER}}
Group={{PANEL_GROUP}}
ExecStart={{PHP_BIN}} {{INSTALL_DIR}}/bin/phpcp-scheduler --quiet

# งานหนึ่งรอบต้องจบก่อนรอบถัดไปเสมอ ตัวสคริปต์มี flock กันซ้อนอยู่แล้ว
# ค่านี้เป็นตาข่ายชั้นสอง กันงานที่ค้างจนไม่มีวันจบ (เช่น agent ไม่ตอบ)
TimeoutStartSec=300

RuntimeDirectory=phpcp
RuntimeDirectoryMode=0750
# ห้ามลบ — ดูคำอธิบายเต็มใน phpcp-agentd.service
# ถ้าไม่มีบรรทัดนี้ การจบงานของ scheduler จะลบ agent.sock ของทั้งระบบไปด้วย
RuntimeDirectoryPreserve=yes

NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service
RestrictAddressFamilies=AF_UNIX
ReadWritePaths={{DATA_DIR}} {{LOG_DIR}} {{RUN_DIR}}

[Install]
WantedBy=multi-user.target
