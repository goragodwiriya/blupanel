# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# เว็บไซต์ {{DOMAIN}} ถูกระงับการใช้งาน
# ไฟล์และฐานข้อมูลยังอยู่ครบ เปิดใช้งานใหม่ได้จากหน้าจัดการเว็บไซต์

server {
    listen {{HTTP_PORT}};
    listen [::]:{{HTTP_PORT}};

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    add_header Retry-After "3600" always;

    # ตอบ 503 ทุกเส้นทาง โดยไม่ส่งคำขอไปยัง PHP-FPM เลยแม้แต่คำขอเดียว
    # ใช้ 503 ไม่ใช่ 403 เพราะเป็นการหยุดชั่วคราว — เครื่องมือค้นหาจะไม่ถอดหน้าออกจากดัชนี
    error_page 503 /__suspended.html;

    location = /__suspended.html {
        alias {{SUSPENDED_PAGE}};
        internal;
    }

    location / {
        return 503;
    }
}
