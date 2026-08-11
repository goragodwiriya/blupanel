# หมุน log ของ panel — ติดตั้งโดย install.sh ห้ามแก้ด้วยมือ ค่าจะถูกเขียนทับ
#
# ทุกตัวเขียน log ด้วย file_put_contents(FILE_APPEND) คือเปิด-ปิดไฟล์ใหม่ทุกบรรทัด
# จึงใช้การเปลี่ยนชื่อไฟล์ (create) ได้ตรง ๆ ไม่ต้องใช้ copytruncate ที่ทำให้เสีย
# บรรทัดที่กำลังเขียนอยู่ และไม่ต้องส่งสัญญาณให้โปรเซสไหนเปิดไฟล์ใหม่
#
# su root {{PANEL_GROUP}} — ไดเรกทอรี log เป็นของ {{PANEL_USER}} ไม่ใช่ root ถ้าไม่ระบุ
# logrotate จะเตือนเรื่องสิทธิ์ของไดเรกทอรีแม่แล้วข้ามไปทั้งชุด

{{LOG_DIR}}/agent.log
{{LOG_DIR}}/agent-stdout.log
{{LOG_DIR}}/panel.log
{{LOG_DIR}}/scheduler.log
{{LOG_DIR}}/serve.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 {{PANEL_USER}} {{PANEL_GROUP}}
    su root {{PANEL_GROUP}}
}

# audit.log เป็น **สำเนาเงา** ของตาราง audit_log ในฐานข้อมูล ไม่ใช่ต้นฉบับ —
# hash-chain ที่ `phpcp audit:verify` ตรวจอยู่ในฐานข้อมูล การหมุนไฟล์นี้จึงไม่ทำให้
# การตรวจสอบเสียหาย · แต่คุณค่าของมันคือการเป็นหลักฐานคู่ขนานเมื่อฐานข้อมูลถูกแก้
# จึงเก็บย้อนหลังนานกว่าและหมุนถี่น้อยกว่าตัวอื่นมาก
#
# ถ้าทำตาม SECURITY.md §5 แล้วตั้ง `chattr +a` ไว้ ต้องปลดชั่วคราวตอนหมุน ไม่งั้น
# logrotate จะเปลี่ยนชื่อไฟล์ไม่ได้ · ปลดเฉพาะเมื่อตั้งไว้จริงเท่านั้นแล้วคืนค่าเดิม
# ไม่ใช่ไปตั้งให้เองซึ่งจะเปลี่ยนพฤติกรรมเครื่องโดยที่ผู้ดูแลไม่ได้สั่ง
{{LOG_DIR}}/audit.log {
    monthly
    rotate 24
    compress
    delaycompress
    missingok
    notifempty
    create 0640 {{PANEL_USER}} {{PANEL_GROUP}}
    prerotate
        if command -v lsattr >/dev/null 2>&1 && lsattr {{LOG_DIR}}/audit.log 2>/dev/null | awk '{print $1}' | grep -q a; then
            chattr -a {{LOG_DIR}}/audit.log && touch {{RUN_DIR}}/.audit-was-append-only
        fi
    endscript
    postrotate
        if [ -f {{RUN_DIR}}/.audit-was-append-only ]; then
            chattr +a {{LOG_DIR}}/audit.log
            rm -f {{RUN_DIR}}/.audit-was-append-only
        fi
    endscript
}
