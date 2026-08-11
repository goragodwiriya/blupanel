# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงเว็บไซต์ผ่านหน้าเว็บ
#
# เว็บไซต์  : {{DOMAIN}}
# ผู้ใช้ระบบ : {{SITE_USER}}
# PHP       : {{PHP_VERSION}}
# SSL       : {{SSL_MODE_LABEL}}
# ชั้นหลัง   : Apache ที่ {{BACKEND}} (อ่าน .htaccess ของเว็บนี้)
# สร้างเมื่อ : {{GENERATED_AT}}

server {
    listen {{HTTP_PORT}};
    listen [::]:{{HTTP_PORT}};

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    client_max_body_size {{UPLOAD_LIMIT}}M;

    # เส้นทางตรวจสอบของ Let's Encrypt ต้องเข้าถึงทาง HTTP ได้เสมอ แม้ตอนบังคับ HTTPS
    # ส่งต่อไปให้ Apache เหมือนคำขออื่น เพราะไฟล์ที่ certbot วางไว้อยู่ใต้ docroot
    # ซึ่ง Apache เป็นคนเสิร์ฟ — ถ้าให้ nginx อ่านเองต้องรู้ docroot ซ้ำอีกที่
    location ^~ /.well-known/acme-challenge/ {
        proxy_pass http://{{BACKEND}};
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto http;
    }
{{HTTP_SECTION}}
}

server {
    # ใช้พารามิเตอร์ http2 ของ listen ไม่ใช่ directive `http2 on;` แยกบรรทัด
    # เพราะ directive แบบใหม่มีตั้งแต่ nginx 1.25.1 ขึ้นไป ขณะที่ระบบที่ v1 รองรับ
    # ยังส่ง nginx เก่ากว่านั้นทั้งหมด (Ubuntu 22.04 = 1.18, Debian 12 = 1.22,
    # Ubuntu 24.04 = 1.24)
    listen {{HTTPS_PORT}} ssl http2;
    listen [::]:{{HTTPS_PORT}} ssl http2;

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    client_max_body_size {{UPLOAD_LIMIT}}M;

    ssl_certificate     {{SSL_CERT}};
    ssl_certificate_key {{SSL_KEY}};

    # ใช้ค่าที่ Mozilla แนะนำระดับ intermediate — รองรับเบราว์เซอร์ที่ยังใช้งานจริง
    # โดยไม่เปิดโปรโตคอลที่ถูกเจาะไปแล้ว
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_tickets off;
    ssl_session_cache shared:SSL:10m;
{{HSTS_HEADER}}
{{PROXY_BODY}}
}
