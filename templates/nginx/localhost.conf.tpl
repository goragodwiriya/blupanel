# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# http://localhost สำหรับเครื่องพัฒนา — เปิดเมื่อ `sites.localhost_docroot` ถูกตั้งค่า
# เครื่องที่ให้บริการจริงปล่อยค่านั้นว่างไว้ ไฟล์นี้จึงไม่มีอยู่เลย
#
# ชั้นหน้าส่งต่อให้ Apache ที่ {{BACKEND}} เหมือนเว็บอื่นในโหมดนี้ เพราะโฟลเดอร์
# พัฒนาเต็มไปด้วยโปรเจกต์ที่พึ่ง .htaccess — ถ้าเสิร์ฟเองจะได้ 404 กับ 403 เต็มไปหมด
#
# จำกัดด้วย allow/deny ไม่ใช่ listen 127.0.0.1 — จะได้ใช้กติกาเลือก server block
# ชุดเดียวกับเว็บอื่นทั้งเครื่อง ไม่ต้องมีข้อยกเว้นให้จำ
server {
    listen {{HTTP_PORT}};
    listen [::]:{{HTTP_PORT}};

    server_name localhost;

    allow 127.0.0.1;
    allow ::1;
    deny  all;

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    client_max_body_size {{UPLOAD_LIMIT}}M;

    location / {
        proxy_pass http://{{BACKEND}};

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto http;
        proxy_set_header X-Forwarded-Host  $host;

        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;

        proxy_connect_timeout 10s;
        proxy_send_timeout    300s;
        proxy_read_timeout    300s;
    }
}
