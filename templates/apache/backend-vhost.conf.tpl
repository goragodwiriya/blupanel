# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงเว็บไซต์ผ่านหน้าเว็บ
#
# ชั้นหลังของโหมด nginx-proxy — **ไม่รับคำขอจากอินเทอร์เน็ตโดยตรง**
# nginx ที่พอร์ต 80/443 เป็นคนรับแล้วส่งต่อมาที่นี่ ชั้นนี้มีไว้เพื่ออ่าน .htaccess
# ของลูกค้าโดยเฉพาะ ซึ่ง nginx ทำเองไม่ได้ตามการออกแบบของมัน
#
# เว็บไซต์  : {{DOMAIN}}
# ผู้ใช้ระบบ : {{SITE_USER}}
# PHP       : {{PHP_VERSION}}
# สร้างเมื่อ : {{GENERATED_AT}}

<VirtualHost {{BACKEND}}>
    ServerName {{DOMAIN}}
{{SERVER_ALIASES}}

    # ที่อยู่ผู้ใช้จริงต้องมาจาก X-Forwarded-For ไม่งั้น log ทุกบรรทัดเป็น 127.0.0.1
    # ซึ่งทำให้ **fail2ban แบนตัวเอง** หรือแบนไม่ได้เลย และสถิติผู้เข้าชมผิดทั้งหมด
    #
    # เชื่อเฉพาะ 127.0.0.1 เท่านั้น (RemoteIPTrustedProxy ไม่ใช่ RemoteIPInternalProxy)
    # ถ้าเชื่อกว้างกว่านี้ ใครก็ปลอม X-Forwarded-For เพื่อเลี่ยงการแบนได้
    RemoteIPHeader X-Forwarded-For
    RemoteIPTrustedProxy 127.0.0.1
    RemoteIPTrustedProxy ::1

    # ให้ PHP เห็นว่าคำขอเดิมมาทาง https — ไม่มีบรรทัดนี้ CMS จะสร้างลิงก์เป็น http
    # แล้วเบราว์เซอร์ถูกส่งกลับไป https ที่ชั้นหน้าอีกรอบจนวนไม่จบ
    SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on

    # ห้ามใส่ชื่อโฮสต์:พอร์ตของ backend ลงใน redirect ที่ส่งกลับไปหาผู้ใช้ —
    # ไม่งั้นผู้ใช้จะถูกส่งไปที่ 127.0.0.1:{{BACKEND_PORT}} ซึ่งเข้าไม่ได้จากภายนอก
    UseCanonicalName Off
{{SITE_BODY}}
</VirtualHost>
