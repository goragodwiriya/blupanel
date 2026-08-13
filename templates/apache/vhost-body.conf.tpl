    DocumentRoot {{DOCROOT}}

    <Directory {{DOCROOT}}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # ส่งไฟล์ PHP ไปยัง FPM pool ของเว็บไซต์นี้โดยเฉพาะ
    # แต่ละเว็บมี pool ของตัวเองที่รันด้วย uid ของตัวเอง (ARCHITECTURE §11)
    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{FPM_SOCKET}}|fcgi://localhost"
    </FilesMatch>

    # ไม่ให้เข้าถึงไฟล์ที่ไม่ควรเปิดเผยผ่านเว็บ
    <FilesMatch "^\.|(^|/)(composer\.(json|lock)|package(-lock)?\.json|\.env.*|\.git.*)$">
        Require all denied
    </FilesMatch>

    <DirectoryMatch "/(\.git|\.svn|node_modules|vendor/bin)/">
        Require all denied
    </DirectoryMatch>

    ErrorLog {{ERROR_LOG}}
    CustomLog {{ACCESS_LOG}} combined

    # ซ่อนรายละเอียดเวอร์ชันของ PHP ออกจาก response header
    Header always unset X-Powered-By

    # -----------------------------------------------------------------------
    # ค่าตั้งเพิ่มเติมของผู้ดูแล — **แก้ที่นี่ ไม่ใช่ที่ไฟล์นี้**
    #
    # ไฟล์ vhost นี้ถูกเขียนทับทั้งไฟล์ทุกครั้งที่เว็บไซต์เปลี่ยน สิ่งที่แก้ลงไปตรง ๆ
    # จะหายเงียบ ๆ ในภายหลัง · ไฟล์ในไดเรกทอรีข้างล่างเป็นของผู้ดูแลล้วน panel ไม่แตะ
    # และถูกอ่าน**ท้ายสุด** ค่าที่เขียนที่นั่นจึงชนะค่าเริ่มต้นข้างบนเสมอ
    #
    # `IncludeOptional` ไม่ล้มเมื่อยังไม่มีไฟล์ · และเพราะอยู่ในบริบท VirtualHost
    # คำสั่งระดับเครื่อง (User, Listen, LoadModule) จะถูก configtest ปฏิเสธเอง
    # -----------------------------------------------------------------------
    IncludeOptional {{CUSTOM_DIR}}/*.conf
