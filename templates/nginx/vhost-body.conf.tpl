    root {{DOCROOT}};
    index index.php index.html;

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    # ขนาดอัปโหลดสูงสุด — ต้องไม่น้อยกว่า upload_max_filesize ของ PHP
    # ไม่อย่างนั้น nginx จะปฏิเสธด้วย 413 ก่อนที่คำขอจะไปถึง PHP เลย
    client_max_body_size {{UPLOAD_LIMIT}}M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ส่งไฟล์ PHP ไปยัง FPM pool ของเว็บไซต์นี้โดยเฉพาะ
    # แต่ละเว็บมี pool ของตัวเองที่รันด้วย uid ของตัวเอง (ARCHITECTURE §11)
    location ~ \.php$ {
        # กัน path info ปลอมแบบ /image.gif/x.php ที่หลอกให้ nginx ส่งไฟล์ที่ไม่ใช่ PHP
        # เข้า FPM แล้วถูกรันเป็นโค้ด — ช่องโหว่คลาสสิกของ nginx + fastcgi
        try_files $uri =404;

        include fastcgi_params;
        fastcgi_pass unix:{{FPM_SOCKET}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }

    # ไม่ให้เข้าถึงไฟล์ที่ไม่ควรเปิดเผยผ่านเว็บ
    location ~ /\. {
        deny all;
    }

    location ~ ^/(composer\.(json|lock)|package(-lock)?\.json)$ {
        deny all;
    }

    location ~ ^/(\.git|\.svn|node_modules|vendor/bin)/ {
        deny all;
    }

    # -----------------------------------------------------------------------
    # ค่าตั้งเพิ่มเติมของผู้ดูแล — แก้ที่ไดเรกทอรีข้างล่าง ไม่ใช่ที่ไฟล์นี้
    #
    # ไฟล์ vhost นี้ถูกเขียนทับทั้งไฟล์ทุกครั้งที่เว็บไซต์เปลี่ยน · ไฟล์ที่นั่นเป็นของ
    # ผู้ดูแลล้วน panel ไม่แตะ และถูกอ่านท้ายสุดจึงชนะค่าเริ่มต้นข้างบน
    #
    # ใช้รูปแบบ mask (`*.conf`) เพราะ nginx ล้มทันทีถ้า `include` ชี้ไปไฟล์ที่ไม่มีอยู่
    # แต่ mask ที่ไม่ตรงกับไฟล์ใดเลยไม่ถือเป็นข้อผิดพลาด
    # -----------------------------------------------------------------------
    include {{CUSTOM_DIR}}/*.conf;
