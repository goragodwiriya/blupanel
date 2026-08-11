[Unit]
Description=PHP Control Panel — privileged agent (ชั้นที่ 2)
Documentation=file://{{INSTALL_DIR}}/docs/ARCHITECTURE.md
After=network.target
Before=phpcp-web.service

# แจ้งเตือนเมื่อ agent เข้าสถานะ failed — จุดเดียวที่ทำได้จริง (PLAN-V2 เฟส E6)
#
# ตอน agent ตาย ไม่มีใครในระบบส่งข้อความออกได้เลย: `alert.check` เป็น capability
# ที่รันผ่าน agent เอง ส่วน phpcp-scheduler ถูกล็อกไว้ที่ AF_UNIX (ยิง Telegram ไม่ได้)
# และ NoNewPrivileges (เข้าคิวเมลไม่ได้) · systemd จึงเป็นสิ่งเดียวที่ยังเห็นเหตุการณ์นี้
#
# ทำงานร่วมกับ Restart=always ข้างล่างพอดี: crash แล้วสตาร์ตกลับได้เอง = ไม่เข้าสถานะ
# failed = ไม่ปลุกใคร · ต่อเมื่อ restart วนจนชน StartLimitBurst ถึงจะ failed จริง
# ซึ่งคือกรณีที่ต้องมีคนเข้ามาดู
OnFailure=phpcp-alert@%n.service

[Service]
Type=simple
# -d pcre.jit=0 จำเป็นเพราะ MemoryDenyWriteExecute=yes ข้างล่างห้ามขอหน่วยความจำที่
# เขียนและรันได้พร้อมกัน ซึ่งเป็นสิ่งที่ PCRE JIT ต้องใช้ — ถ้าไม่ปิดไว้ PHP จะพ่น
# warning "Allocation of JIT memory failed" ทุกครั้งที่ preg_match ทำงาน (ทุก request
# ที่เข้า Agent\Protocol) จน journal เต็มไปด้วยข้อความเดียวซ้ำ ๆ จนหา error จริงไม่เจอ
# ปิด JIT ตรงนี้ดีกว่าผ่อน MemoryDenyWriteExecute เพราะ regex ของ agent สั้นและไม่ใช่คอขวด
ExecStart={{PHP_BIN}} -d pcre.jit=0 {{INSTALL_DIR}}/bin/phpcp-agentd
Restart=always
RestartSec=2
TimeoutStopSec=10

# Group=phpcp ทำให้ /run/phpcp เป็น root:phpcp 0750 ตาม ARCHITECTURE §13
# ถ้าปล่อยเป็น root:root ชั้นที่ 1 จะเข้าไปหา agent.sock ไม่ได้เลย
Group={{PANEL_GROUP}}
RuntimeDirectory=phpcp
RuntimeDirectoryMode=0750
# ห้ามลบบรรทัดนี้ — ทั้งสามบริการใช้ RuntimeDirectory=phpcp ร่วมกัน
# ค่าเริ่มต้นของ systemd คือลบ /run/phpcp ทิ้งเมื่อบริการหยุด ซึ่งแปลว่าการรีสตาร์ต
# บริการใดบริการหนึ่งจะลบ socket ของอีกสองตัวไปด้วย (agent.sock, panel-fpm.sock)
# ผลคือ panel ตอบ 503 หรือ "ติดต่อ agent ไม่ได้" ทั้งที่ทุกบริการยังขึ้น active อยู่
RuntimeDirectoryPreserve=yes

# ---------------------------------------------------------------------------
# agent ต้องเป็น root เพราะเป็นชั้นเดียวที่ทำงานสิทธิ์สูงได้
# แต่ตัด capability ที่ไม่จำเป็นทิ้งทั้งหมด — ต่อให้ agent ถูกยึด
# ก็โหลด kernel module, แก้ sysctl หรือแตะ /home ของผู้ใช้ไม่ได้
# ---------------------------------------------------------------------------
NoNewPrivileges=yes
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=no
LockPersonality=yes
MemoryDenyWriteExecute=yes
# AF_NETLINK จำเป็น — sendmail/Postfix เรียก getifaddrs() ผ่าน netlink
# ถ้าไม่มีจะได้ fatal: inet_addr_local[getifaddrs]: Address family not supported by protocol
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK
SystemCallArchitectures=native
SystemCallFilter=@system-service @privileged
# CAP_NET_ADMIN จำเป็นสำหรับ ufw — ทั้งอ่านสถานะและแก้กฎล้วนคุยกับ netfilter ของเคอร์เนล
# ถ้าไม่มี `ufw status` จะได้ "Could not fetch rule set generation id: Permission denied"
# แล้วหน้า Firewall จะรายงานว่าปิดอยู่ทั้งที่เปิดอยู่จริง (readable=false)
#
# CAP_NET_BIND_SERVICE จำเป็นสำหรับ `nginx -t` — การตรวจ config ของ nginx **เปิด
# listening socket จริง** ไม่ได้ตรวจแค่ไวยากรณ์ ถ้าไม่มี capability นี้จะได้
# "bind() to 0.0.0.0:80 failed (13: Permission denied)" ทั้งที่บรรทัดก่อนหน้า
# บอกว่า "syntax is ok" แล้ว ผลคือทุกการเขียน config ถูก rollback ทิ้งโดยที่
# ไฟล์ไม่มีอะไรผิดเลย (เจอจริงตอนกดเปลี่ยนเว็บเซิร์ฟเวอร์จากหน้าจอ 2026-08-11)
CapabilityBoundingSet=CAP_CHOWN CAP_DAC_OVERRIDE CAP_FOWNER CAP_SETUID CAP_SETGID CAP_KILL CAP_SYS_ADMIN CAP_NET_ADMIN CAP_NET_BIND_SERVICE
# ต้องเป็น ambient ด้วย ไม่ใช่แค่อยู่ใน bounding set — NoNewPrivileges=yes ทำให้
# capability ไม่ตกทอดไปยังโปรเซสลูกเอง ซึ่ง ufw/iptables/nginx เป็นโปรเซสลูกทั้งหมด
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

# หมายเหตุ: RestrictSUIDSGID ต้องเป็น no เพราะ agent ต้อง setuid ลงเป็นเจ้าของเว็บไซต์
# ก่อนแตะไฟล์ผู้ใช้ (ARCHITECTURE §4.4) ซึ่งเป็นการลดสิทธิ์ ไม่ใช่เพิ่ม

[Install]
WantedBy=multi-user.target
