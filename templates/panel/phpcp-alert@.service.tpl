[Unit]
Description=PHP Control Panel — แจ้งเตือนเมื่อ %i ล้มเหลว
Documentation=file://{{INSTALL_DIR}}/docs/PLAN-V2.md

# ห้ามพึ่งอะไรที่อาจล้มไปพร้อมกับ unit ที่กำลังแจ้ง — โปรแกรมนี้ต้องทำงานได้
# ในจังหวะที่ระบบมีปัญหาอยู่แล้ว จึงไม่มี After=/Wants= ของบริการ panel ตัวใดเลย

[Service]
Type=oneshot
ExecStart={{PHP_BIN}} {{INSTALL_DIR}}/bin/phpcp-alert unit-failed %i

# รันเป็น root โดยเจตนา — เป็นโปรเซสเดียวที่ยังส่งข้อความออกได้ตอน agent ตาย
# ต้องยิง HTTPS (Telegram/webhook) และเรียก sendmail ซึ่งต้องใช้ setgid ของ postdrop
#
# ความเสี่ยงที่แลกมาถูกจำกัดด้วยขอบเขตของโปรแกรมเอง: phpcp-alert **ไม่แตะระบบอะไรเลย**
# ไม่สั่งงาน ไม่แก้ไฟล์ ไม่รีสตาร์ตอะไร — อ่านค่าตั้งกับเขียน alert_state เท่านั้น

# กันค้าง: ปลายทางที่ไม่ตอบต้องไม่ทำให้โปรเซสนี้ค้างจนกลายเป็นปัญหาเสียเอง
TimeoutStartSec=60

# ล้มเหลวแล้วไม่ต้องพยายามใหม่ — ถ้าส่งไม่ออกรอบนี้ การวนซ้ำก็ไม่ช่วย
# และจะกลายเป็นลูป unit ล้มเหลวซ้อนกันสองชั้น
Restart=no

# --- ชั้นป้องกัน ---
#
# แคบที่สุดเท่าที่ยังทำงานได้: ต้องเปิด AF_INET/AF_INET6 เพื่อยิง Telegram/webhook
# และห้ามใส่ NoNewPrivileges=yes เพราะจะปิด setgid ของ postdrop ทำให้อีเมลเข้าคิวไม่ได้
# (เป็นข้อจำกัดเดียวกับที่ทำให้ phpcp-scheduler ส่งข้อความไม่ได้)
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
ProtectSystem=strict
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictRealtime=yes
LockPersonality=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service

# เขียนได้เฉพาะฐานข้อมูล (บันทึก alert_state) และคิวเมลของ Postfix
ReadWritePaths={{DATA_DIR}} /var/spool/postfix/maildrop /var/spool/postfix/public
