
    # ไฟล์ static ตอบจาก nginx โดยตรง — ไม่ต้องปลุก Apache หนึ่ง process ต่อหนึ่งรูป
    #
    # แยกเป็นสอง location เพราะสองชั้นนี้รู้ข้อมูลไม่เท่ากัน:
    #
    #   1. ไฟล์ที่รากเว็บ — `.htaccess` ที่รากถูกตรวจแล้วตอนเขียนไฟล์นี้ว่ามีแต่กฎ
    #      rewrite (ถ้ามีกฎควบคุมการเข้าถึง บล็อกทั้งชุดนี้จะไม่ถูกเขียนออกมาเลย)
    #      จึงตอบได้ทันทีโดยไม่ต้องตรวจอะไรอีก — นี่คือเส้นทางของ WordPress/Laravel
    #      ที่มี .htaccess ของ front controller อยู่ที่รากเสมอ
    #   2. ไฟล์ในโฟลเดอร์ย่อย — **ตรวจทุกคำขอ** ว่าโฟลเดอร์นั้นมี `.htaccess` ไหม
    #      ลูกค้าอัปโหลดไฟล์นั้นผ่าน SFTP ได้ตลอดเวลาโดยที่ panel ไม่รู้ · ถ้าเชื่อแต่
    #      ผลสแกนย้อนหลัง โฟลเดอร์ที่ลูกค้าเพิ่งสั่งป้องกันจะยังเปิดให้ทุกคนเข้าถึง
    #      จนกว่าจะมีการเขียน vhost รอบถัดไป ซึ่งอาจไม่เกิดขึ้นอีกเลยเป็นเดือน
    #
    # `return` ใน `if` เป็นหนึ่งในสองรูปแบบที่ nginx รับรองว่าปลอดภัย (อีกอันคือ rewrite)
    # ส่วน 418 เป็นรหัสที่ไม่มีใครใช้จริง จึงใช้เป็นสัญญาณภายในส่งต่อไป @backend ได้

    location ~* ^/[^/]+\.(?:css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3|wav|pdf|zip|gz|txt|map)$ {
        root {{DOCROOT}};

        try_files $uri @backend;

        access_log off;
        expires 7d;
        add_header Cache-Control "public";
    }

    location ~* ^(?<phpcp_dir>/.+/)[^/]+\.(?:css|js|mjs|jpg|jpeg|png|gif|webp|avif|svg|ico|bmp|woff|woff2|ttf|otf|eot|mp4|webm|ogg|mp3|wav|pdf|zip|gz|txt|map)$ {
        root {{DOCROOT}};

        error_page 418 = @backend;

        # มี .htaccess ในโฟลเดอร์เดียวกับไฟล์ที่ขอ = อาจมีกฎที่ nginx ทำแทนไม่ได้
        # ให้ Apache ตอบไปเลย ไม่ต้องเดาว่าข้างในเขียนอะไร
        if (-f "$document_root$phpcp_dir.htaccess") {
            return 418;
        }

        try_files $uri @backend;

        access_log off;
        expires 7d;
        add_header Cache-Control "public";
    }

    # ไฟล์ที่ซ่อนอยู่ต้องไม่หลุดผ่านทางนี้ — กฎเดียวกับที่ Apache บังคับไว้
    location ~ /\. {
        return 403;
    }
