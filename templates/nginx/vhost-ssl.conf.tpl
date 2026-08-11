# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงเว็บไซต์ผ่านหน้าเว็บ
#
# เว็บไซต์  : {{DOMAIN}}
# ผู้ใช้ระบบ : {{SITE_USER}}
# PHP       : {{PHP_VERSION}}
# SSL       : {{SSL_MODE_LABEL}}
# สร้างเมื่อ : {{GENERATED_AT}}

server {
    listen {{HTTP_PORT}};
    listen [::]:{{HTTP_PORT}};

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    # ต้องเปิดให้เข้าถึงทาง HTTP เสมอ แม้ตอนบังคับ HTTPS — Let's Encrypt ตรวจสอบ
    # ความเป็นเจ้าของโดเมนผ่านพอร์ต 80 เท่านั้น ถ้า redirect ทั้งหมดไป HTTPS
    # การต่ออายุอัตโนมัติจะล้มเงียบ ๆ แล้วใบรับรองหมดอายุใน 90 วัน
    location ^~ /.well-known/acme-challenge/ {
        root {{DOCROOT}};
        default_type "text/plain";
        try_files $uri =404;
    }
{{HTTP_SECTION}}
}

server {
    # ใช้พารามิเตอร์ http2 ของ listen ไม่ใช่ directive `http2 on;` แยกบรรทัด
    # เพราะ directive แบบใหม่มีตั้งแต่ nginx 1.25.1 ขึ้นไป ขณะที่ระบบที่ v1 รองรับ
    # ยังส่ง nginx เก่ากว่านั้นทั้งหมด (Ubuntu 22.04 = 1.18, Debian 12 = 1.22,
    # Ubuntu 24.04 = 1.24) — เขียนแบบใหม่แล้ว nginx จะไม่ยอมโหลด config เลย
    # แบบนี้ nginx รุ่นใหม่ยังรับได้อยู่ เพียงแต่ขึ้นคำเตือนว่าเลิกใช้แล้ว
    listen {{HTTPS_PORT}} ssl http2;
    listen [::]:{{HTTPS_PORT}} ssl http2;

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    ssl_certificate     {{SSL_CERT}};
    ssl_certificate_key {{SSL_KEY}};

    # ใช้ค่าที่ Mozilla แนะนำระดับ intermediate — รองรับเบราว์เซอร์ที่ยังใช้งานจริง
    # โดยไม่เปิดโปรโตคอลที่ถูกเจาะไปแล้ว
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_tickets off;
    ssl_session_cache shared:SSL:10m;
{{SITE_BODY}}
{{HSTS_HEADER}}
}
