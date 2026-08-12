# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# โหมด apache: Apache ถือพอร์ตของเครื่องเอง ไม่มีชั้นหน้ามาคั่น
#
# ไฟล์นี้ถูกเขียนทุกครั้งที่สร้างไฟล์ตั้งค่าใหม่ เพราะโหมด nginx-proxy เขียนทับมัน
# ให้ Apache ถอยไปฟังแค่ 127.0.0.1:8080 · ถ้าไม่เขียนคืน การสลับกลับมาโหมดนี้จะได้
# เครื่องที่ไม่มีใครฟังพอร์ต 80 เลย — vhost ทุกไฟล์ประกาศ *:80 แต่ Apache ไม่ได้ฟัง
# ผลคือทุกเว็บบนเครื่องเงียบไปพร้อมกันโดยไม่มีอะไรฟ้อง

Listen {{HTTP_PORT}}

<IfModule ssl_module>
	Listen {{HTTPS_PORT}}
</IfModule>

<IfModule mod_gnutls.c>
	Listen {{HTTPS_PORT}}
</IfModule>
