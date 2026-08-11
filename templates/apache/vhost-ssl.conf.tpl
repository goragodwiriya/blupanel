# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงเว็บไซต์ผ่านหน้าเว็บ
#
# เว็บไซต์  : {{DOMAIN}}
# ผู้ใช้ระบบ : {{SITE_USER}}
# PHP       : {{PHP_VERSION}}
# SSL       : {{SSL_MODE_LABEL}}
# สร้างเมื่อ : {{GENERATED_AT}}

<VirtualHost *:{{HTTP_PORT}}>
    ServerName {{DOMAIN}}
{{SERVER_ALIASES}}

    # ต้องเปิดให้เข้าถึงทาง HTTP เสมอ แม้ตอนบังคับ HTTPS — Let's Encrypt ตรวจสอบ
    # ความเป็นเจ้าของโดเมนผ่านพอร์ต 80 เท่านั้น ถ้า redirect ทั้งหมดไป HTTPS
    # การต่ออายุอัตโนมัติจะล้มเงียบ ๆ แล้วใบรับรองหมดอายุใน 90 วัน
    Alias /.well-known/acme-challenge {{DOCROOT}}/.well-known/acme-challenge
    <Directory {{DOCROOT}}/.well-known/acme-challenge>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
{{HTTP_SECTION}}
</VirtualHost>

<VirtualHost *:{{HTTPS_PORT}}>
    ServerName {{DOMAIN}}
{{SERVER_ALIASES}}

    SSLEngine on
    SSLCertificateFile {{SSL_CERT}}
    SSLCertificateKeyFile {{SSL_KEY}}

    # ใช้ค่าที่ Mozilla แนะนำระดับ intermediate — รองรับเบราว์เซอร์ที่ยังใช้งานจริง
    # โดยไม่เปิดโปรโตคอลที่ถูกเจาะไปแล้ว
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off
    SSLSessionTickets off

{{SITE_BODY}}
{{HSTS_HEADER}}
</VirtualHost>
