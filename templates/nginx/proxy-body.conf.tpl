
    # ส่งต่อ **ทุกคำขอ** ไปยัง Apache รวมทั้งไฟล์ static
    #
    # จงใจไม่ให้ nginx เสิร์ฟไฟล์ static เองทั้งที่เร็วกว่า เพราะ `.htaccess` คุมไฟล์
    # static ด้วย (Require all denied, auth_basic, RedirectMatch, Header set ฯลฯ)
    # ถ้า nginx ตอบไฟล์เองกฎเหล่านั้นจะถูกข้ามไปเงียบ ๆ — ลูกค้าที่ป้องกันโฟลเดอร์
    # ด้วย .htaccess จะกลายเป็นเปิดให้ทุกคนเข้าถึงโดยไม่มีอะไรบอก ซึ่งเป็นช่องโหว่
    # ที่เกิดจากตัว panel เอง ไม่ใช่จากค่าที่ลูกค้าตั้ง
    #
    # สิ่งที่ยังได้จาก nginx ชั้นหน้า: จบ TLS · รับ-ส่งกับผู้ใช้ที่เน็ตช้าแทน Apache
    # (Apache หนึ่ง process ต่อหนึ่งการเชื่อมต่อ nginx จึงกันไม่ให้ worker ถูกกินหมด)
    # · จำกัดขนาด body · เป็นจุดเดียวที่คุม rate limit ทั้งเครื่อง
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
