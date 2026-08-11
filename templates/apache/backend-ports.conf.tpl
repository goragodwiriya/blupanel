# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# โหมด nginx-proxy: nginx ถือพอร์ต 80/443 ของเครื่อง ส่วน Apache ถอยไปเป็นชั้นหลัง
# ที่ฟังเฉพาะ loopback เท่านั้น
#
# ผูกกับ 127.0.0.1 ไม่ใช่ทุกหน้าตัดเน็ต — Apache ชั้นนี้ไม่มี TLS และเชื่อ
# X-Forwarded-For จาก loopback ถ้าเข้าถึงได้จากภายนอกจะกลายเป็นทางลัดข้าม
# ทุกอย่างที่ nginx ทำไว้ รวมถึงปลอมที่อยู่ผู้ใช้เพื่อเลี่ยงการแบนของ fail2ban
#
# คืนค่าเดิม: ติดตั้งซ้ำด้วย `webserver = apache` แล้วรัน `phpcp sites:rebuild`
# ไฟล์เดิมถูกสำรองไว้ที่ {{BACKUP_NOTE}}

Listen 127.0.0.1:{{BACKEND_PORT}}
Listen [::1]:{{BACKEND_PORT}}
