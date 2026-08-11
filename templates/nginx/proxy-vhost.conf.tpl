# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงเว็บไซต์ผ่านหน้าเว็บ
#
# เว็บไซต์  : {{DOMAIN}}
# ผู้ใช้ระบบ : {{SITE_USER}}
# PHP       : {{PHP_VERSION}}
# ชั้นหลัง   : Apache ที่ {{BACKEND}} (อ่าน .htaccess ของเว็บนี้)
# สร้างเมื่อ : {{GENERATED_AT}}

server {
    listen {{HTTP_PORT}};
    listen [::]:{{HTTP_PORT}};

    server_name {{DOMAIN}}{{SERVER_ALIASES}};

    access_log {{ACCESS_LOG}};
    error_log  {{ERROR_LOG}};

    client_max_body_size {{UPLOAD_LIMIT}}M;
{{PROXY_BODY}}
}
