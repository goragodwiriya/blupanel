{{FORCE_PROXY_DIRS}}{{STATIC_SECTION}}
    # คำขอที่เหลือทั้งหมดส่งให้ Apache — ที่นั่นคือที่เดียวที่อ่าน `.htaccess` ได้
    location / {
        proxy_pass http://{{BACKEND}};

        # Host ต้องเป็นชื่อจริงที่ผู้ใช้ขอมา ไม่ใช่ที่อยู่ของ backend
        # Apache เลือก vhost จากค่านี้ ถ้าส่งผิดทุกเว็บจะตกไปที่ vhost แรกของเครื่อง
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;

        # ชั้นหลังต้องรู้ว่าคำขอเดิมมาทาง https หรือไม่ — mod_remoteip ไม่ได้บอกเรื่องนี้
        # ไม่มีค่านี้ PHP จะเห็นเป็น http เสมอ แล้ว CMS จะสร้างลิงก์และ redirect เป็น
        # http ทั้งเว็บ กลายเป็นวนซ้ำกับกฎบังคับ HTTPS ที่ชั้นหน้า
        proxy_set_header X-Forwarded-Proto {{SCHEME}};
        proxy_set_header X-Forwarded-Host  $host;

        # WebSocket และ HTTP/1.1 keep-alive — ค่าเริ่มต้นของ proxy_pass คือ 1.0
        # ซึ่งทำให้แอปที่ใช้ WebSocket ต่อไม่ติดโดยไม่มีข้อความบอกสาเหตุ
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;

        # ยาวพอสำหรับสคริปต์ที่ทำงานนาน (นำเข้าข้อมูล ส่งเมลจำนวนมาก)
        # แต่ไม่นานจนคำขอที่ค้างสะสมกินการเชื่อมต่อทั้งหมด
        proxy_connect_timeout 10s;
        proxy_send_timeout    300s;
        proxy_read_timeout    300s;

        proxy_buffering on;
        proxy_buffer_size 8k;
    }

    # ปลายทางของ try_files เมื่อไฟล์ไม่มีอยู่จริง — ให้ Apache ตัดสินด้วยกฎ rewrite
    # ของลูกค้า ไม่ใช่ให้ nginx ตอบ 404 ไปเอง
    location @backend {
        proxy_pass http://{{BACKEND}};

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto {{SCHEME}};
        proxy_set_header X-Forwarded-Host  $host;

        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
    }
