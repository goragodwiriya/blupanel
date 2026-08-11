# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# เว็บไซต์ {{DOMAIN}} ถูกระงับการใช้งาน
# ไฟล์และฐานข้อมูลยังอยู่ครบ เปิดใช้งานใหม่ได้จากหน้าจัดการเว็บไซต์

<VirtualHost *:{{HTTP_PORT}}>
    ServerName {{DOMAIN}}
{{SERVER_ALIASES}}
    DocumentRoot {{DOCROOT}}

    # ตอบ 503 ทุกเส้นทาง โดยไม่รัน PHP ของเว็บไซต์เลย
    # ใช้ 503 ไม่ใช่ 403 เพราะเป็นการหยุดชั่วคราว — เครื่องมือค้นหาจะไม่ถอดหน้าออกจากดัชนี
    RedirectMatch 503 ^/(?!__suspended\.html$).*

    ErrorDocument 503 /__suspended.html
    Alias /__suspended.html {{SUSPENDED_PAGE}}

    <Files "__suspended.html">
        Require all granted
    </Files>

    Header always set Retry-After "3600"

    ErrorLog {{ERROR_LOG}}
    CustomLog {{ACCESS_LOG}} combined
</VirtualHost>
