# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# http://localhost สำหรับเครื่องพัฒนา — เปิดเมื่อ `sites.localhost_docroot` ถูกตั้งค่า
# เครื่องที่ให้บริการจริงปล่อยค่านั้นว่างไว้ ไฟล์นี้จึงไม่มีอยู่เลย
#
# **ผูกกับ *:80 ไม่ใช่ 127.0.0.1:80 โดยตั้งใจ** — Apache เลือก vhost จากที่อยู่ก่อน
# ชื่อโฮสต์ ถ้าไฟล์นี้ประกาศ 127.0.0.1:80 อยู่ไฟล์เดียว คำขอทุกอันที่เข้ามาทาง
# loopback จะตกมาที่นี่หมด รวมถึงโดเมนทดสอบใน /etc/hosts ที่ชี้มาที่ 127.0.0.1
# ซึ่งแปลว่าเว็บทดสอบทุกเว็บบนเครื่องพัง · การจำกัดให้เรียกได้เฉพาะเครื่องตัวเอง
# ทำด้วย Require ip แทน
#
# PHP รันผ่าน pool มาตรฐานของดิสโทร (www-data) ไม่ใช่ pool ของบัญชีลูกค้า —
# โฟลเดอร์พัฒนาไม่ใช่ของลูกค้าคนไหน ไม่ควรถูก chown และไม่ควรติด open_basedir ของใคร
#
# ในโหมด nginx-proxy ไฟล์นี้คือชั้นหลังที่ฟัง 127.0.0.1:8080 — คำขอมาจาก nginx เสมอ
# `Require ip` จึงเป็นจริงตลอด และตัวที่กันเครื่องอื่นจริง ๆ คือ allow/deny ที่ชั้นหน้า
<VirtualHost {{LISTEN}}>
    ServerName localhost

    DocumentRoot {{DOCROOT}}

    <Directory {{DOCROOT}}>
        Options -Indexes +FollowSymLinks
        AllowOverride All

        # เฉพาะเครื่องตัวเอง — เครื่องอื่นในวงเน็ตที่ส่ง Host: localhost มาไม่ได้อ่าน
        Require ip 127.0.0.1
        Require ip ::1
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{FPM_SOCKET}}|fcgi://localhost"
    </FilesMatch>

    # โฟลเดอร์พัฒนามักมีทั้งซอร์ส ไฟล์ตั้งค่า และฐานข้อมูลปนกัน — กันชั้นเดียวกับเว็บจริง
    <FilesMatch "^\.|(^|/)(composer\.(json|lock)|package(-lock)?\.json|\.env.*|\.git.*)$">
        Require all denied
    </FilesMatch>

    <FilesMatch "\.(db|sqlite3?|sql|key|pem|p12|pfx|bak|backup|log)$">
        Require all denied
    </FilesMatch>

    <DirectoryMatch "/(\.git|\.svn|node_modules|vendor/bin)/">
        Require all denied
    </DirectoryMatch>

    ErrorLog {{ERROR_LOG}}
    CustomLog {{ACCESS_LOG}} combined

    Header always unset X-Powered-By
</VirtualHost>
