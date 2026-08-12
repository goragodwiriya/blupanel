# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# http://localhost สำหรับเครื่องพัฒนา — เปิดเมื่อ `sites.localhost_docroot` ถูกตั้งค่า
# เครื่องที่ให้บริการจริงปล่อยค่านั้นว่างไว้ ไฟล์นี้จึงไม่มีอยู่เลย
#
# โหมด nginx ล้วนไม่มี Apache ให้ส่งต่อ จึงเสิร์ฟเองทั้งหมด — **โปรเจกต์ที่พึ่ง
# .htaccess จะไม่ทำงาน** เหมือนกับเว็บอื่นทุกเว็บในโหมดนี้ · ถ้าโฟลเดอร์พัฒนามี
# โปรเจกต์แบบนั้นอยู่ ให้ใช้โหมด nginx-proxy แทน
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

    root {{DOCROOT}};
    index index.php index.html;

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    client_max_body_size {{UPLOAD_LIMIT}}M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        # กัน path info ปลอมแบบ /image.gif/x.php ที่หลอกให้ nginx ส่งไฟล์ที่ไม่ใช่ PHP
        # เข้า FPM แล้วถูกรันเป็นโค้ด
        try_files $uri =404;

        include fastcgi_params;
        fastcgi_pass unix:{{FPM_SOCKET}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }

    # โฟลเดอร์พัฒนามักมีทั้งซอร์ส ไฟล์ตั้งค่า และฐานข้อมูลปนกัน
    location ~ /\. {
        deny all;
    }

    location ~ ^/(composer\.(json|lock)|package(-lock)?\.json)$ {
        deny all;
    }

    location ~ \.(db|sqlite3?|sql|key|pem|p12|pfx|bak|backup|log)$ {
        deny all;
    }

    location ~ ^/(\.git|\.svn|node_modules|vendor/bin)/ {
        deny all;
    }
}
