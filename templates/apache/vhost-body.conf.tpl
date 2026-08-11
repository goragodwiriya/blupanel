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
