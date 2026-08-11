# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนค่าตั้งเมลผ่านหน้าเว็บ
#
# สร้างเมื่อ : {{GENERATED_AT}}
#
# ตั้งค่าไว้สำหรับ "ส่งออกอย่างเดียว" — ไม่รับเมลเข้าจากภายนอก
# ดูเหตุผลใน src/Driver/Mail/MailManager.php

compatibility_level = 3.6

myhostname = {{HOSTNAME}}
myorigin = {{ORIGIN}}
mydestination = localhost

# บรรทัดสองบรรทัดนี้คือสิ่งที่กันไม่ให้เครื่องกลายเป็น open relay
# รับคำขอส่งจากเครื่องนี้เท่านั้น ไม่เปิดพอร์ต 25 ออกสู่ภายนอกเลย
# ถ้าแก้สองค่านี้ผิด เครื่องจะถูกใช้ส่งสแปมภายในไม่กี่ชั่วโมง
# แล้วไอพีติดบัญชีดำถาวร ซึ่งกระทบทุกเว็บไซต์บนเครื่องเดียวกัน
inet_interfaces = loopback-only
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128

relayhost = {{RELAY_HOST}}

# ยืนยันตัวตนกับผู้ให้บริการ relay
smtp_sasl_auth_enable = {{SASL_ENABLED}}
smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd
smtp_sasl_security_options = noanonymous
smtp_sasl_tls_security_options = noanonymous

# encrypt = ปฏิเสธที่จะส่งถ้าเข้ารหัสไม่ได้ (ปลอดภัยกว่า)
# may     = ลองเข้ารหัสก่อน ถ้าไม่ได้ก็ส่งแบบไม่เข้ารหัส
smtp_tls_security_level = {{TLS_SECURITY}}
smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt
smtp_tls_loglevel = 1

# ไม่เปิดเผยชื่อเครื่องภายในและเวอร์ชันของ Postfix ให้ปลายทาง
smtpd_banner = $myhostname ESMTP
biff = no
append_dot_mydomain = no
readme_directory = no

# ไม่ให้เมลค้างในคิวข้ามสัปดาห์ — ถ้าส่งไม่ได้ภายใน 1 วันแปลว่ามีปัญหาที่ต้องแก้
# ไม่ใช่รอต่อไปเรื่อย ๆ จนคิวบวมและดิสก์เต็ม
maximal_queue_lifetime = 1d
bounce_queue_lifetime = 1d

# ขนาดเมลสูงสุด 25 MB — ผู้ให้บริการส่วนใหญ่ปฏิเสธที่ใหญ่กว่านี้อยู่แล้ว
message_size_limit = 26214400
