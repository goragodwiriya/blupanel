# ไฟล์นี้สร้างโดยตัวติดตั้ง PHP Server Control Panel — ห้ามแก้ด้วยมือ
#
# Apache instance ของ panel เอง (ARCHITECTURE §5.2)
# ใช้ binary ตัวเดียวกับของระบบ แต่คนละ config tree คนละ pid คนละพอร์ต
# จึงไม่อ่านอะไรจาก /etc/apache2 เลย และไม่ดับตามเมื่อผู้ใช้สั่งหยุด apache2.service
#
# โหลด module เองทีละตัวเพราะไม่ได้ใช้ mods-enabled ของระบบ — ถ้าผู้ใช้ปิด module
# ของระบบผ่านหน้าเว็บ panel ต้องไม่ได้รับผลกระทบ

ServerRoot        {{CONF_DIR}}/httpd
DefaultRuntimeDir {{RUN_DIR}}
PidFile           {{RUN_DIR}}/panel-httpd.pid
Mutex             file:{{RUN_DIR}} default

ServerName      {{PANEL_HOST}}
ServerAdmin     root@localhost
ServerTokens    Prod
ServerSignature Off
TraceEnable     Off

Timeout              60
KeepAlive            On
MaxKeepAliveRequests 100
KeepAliveTimeout     5

# ---------------------------------------------------------------------------
# Module — เท่าที่ panel ใช้จริงเท่านั้น
# ห่อด้วย <IfModule !x> เพราะบางดิสโทรคอมไพล์บางตัวติดมากับ binary แล้ว
# (เช่น unixd ของ Debian) การ LoadModule ซ้ำจะทำให้ apache2 ไม่ยอมสตาร์ต
# ---------------------------------------------------------------------------
<IfModule !mpm_event_module>
    LoadModule mpm_event_module {{MODULES_DIR}}/mod_mpm_event.so
</IfModule>
<IfModule !unixd_module>
    LoadModule unixd_module {{MODULES_DIR}}/mod_unixd.so
</IfModule>
<IfModule !authz_core_module>
    LoadModule authz_core_module {{MODULES_DIR}}/mod_authz_core.so
</IfModule>
<IfModule !log_config_module>
    LoadModule log_config_module {{MODULES_DIR}}/mod_log_config.so
</IfModule>
<IfModule !mime_module>
    LoadModule mime_module {{MODULES_DIR}}/mod_mime.so
</IfModule>
<IfModule !dir_module>
    LoadModule dir_module {{MODULES_DIR}}/mod_dir.so
</IfModule>
<IfModule !headers_module>
    LoadModule headers_module {{MODULES_DIR}}/mod_headers.so
</IfModule>
<IfModule !filter_module>
    LoadModule filter_module {{MODULES_DIR}}/mod_filter.so
</IfModule>
<IfModule !deflate_module>
    LoadModule deflate_module {{MODULES_DIR}}/mod_deflate.so
</IfModule>
<IfModule !reqtimeout_module>
    LoadModule reqtimeout_module {{MODULES_DIR}}/mod_reqtimeout.so
</IfModule>
<IfModule !proxy_module>
    LoadModule proxy_module {{MODULES_DIR}}/mod_proxy.so
</IfModule>
<IfModule !proxy_fcgi_module>
    LoadModule proxy_fcgi_module {{MODULES_DIR}}/mod_proxy_fcgi.so
</IfModule>
<IfModule !socache_shmcb_module>
    LoadModule socache_shmcb_module {{MODULES_DIR}}/mod_socache_shmcb.so
</IfModule>
<IfModule !ssl_module>
    LoadModule ssl_module {{MODULES_DIR}}/mod_ssl.so
</IfModule>

# master เริ่มด้วย root เพื่อ bind พอร์ตและอ่านใบรับรอง แล้วทิ้งสิทธิ์ทันที
User  {{PANEL_USER}}
Group {{PANEL_GROUP}}

<IfModule mpm_event_module>
    StartServers           1
    MinSpareThreads        4
    MaxSpareThreads        8
    ThreadsPerChild        16
    MaxRequestWorkers      32
    MaxConnectionsPerChild 0
</IfModule>

# กันการยึด worker ด้วยคำขอที่ส่งช้า ๆ (slowloris)
<IfModule reqtimeout_module>
    RequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500
</IfModule>

# ---------------------------------------------------------------------------
# ชนิดไฟล์ — ประกาศเองเพื่อไม่ต้องพึ่ง /etc/mime.types ของระบบ
# ---------------------------------------------------------------------------
<IfFile /etc/mime.types>
    TypesConfig /etc/mime.types
</IfFile>
AddType text/css                  .css
AddType text/javascript           .js
AddType image/svg+xml             .svg
AddType application/json          .json
AddType font/woff2                .woff2
AddDefaultCharset UTF-8

<IfModule deflate_module>
    AddOutputFilterByType DEFLATE text/html text/css text/javascript application/json image/svg+xml
</IfModule>

# ---------------------------------------------------------------------------
# Log ของ panel แยกจาก /var/log/apache2 ของระบบ
# ---------------------------------------------------------------------------
ErrorLog  {{LOG_DIR}}/panel-error.log
LogLevel  warn
LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" combined
CustomLog {{LOG_DIR}}/panel-access.log combined

# ปิดทั้ง filesystem ก่อน แล้วค่อยเปิดเฉพาะ document root
<Directory />
    Options None
    AllowOverride None
    Require all denied
</Directory>

Listen {{PANEL_PORT}}

<VirtualHost *:{{PANEL_PORT}}>
    DocumentRoot {{INSTALL_DIR}}/public

    SSLEngine on
    SSLCertificateFile    {{CONF_DIR}}/tls/panel.crt
    SSLCertificateKeyFile {{CONF_DIR}}/tls/panel.key
    SSLProtocol         -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off
    SSLSessionTickets   off
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384

    # เพดานของ body — ใหญ่กว่าที่ตัวจัดการไฟล์ส่งได้จริง (FileCatalog::MAX_TRANSFER_BYTES) พอสมควร
    LimitRequestBody 33554432

    <Directory {{INSTALL_DIR}}/public>
        Options -Indexes -Includes -ExecCGI +FollowSymLinks
        AllowOverride None
        Require all granted

        # front controller เดียวตาม public/index.php — ไม่มี .htaccess ให้ต้องอ่าน
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    # PHP ของ panel ไปที่ FPM master ของ panel เท่านั้น ไม่ใช่ pool ของเว็บไซต์ใด
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:{{RUN_DIR}}/panel-fpm.sock|fcgi://panel"
    </FilesMatch>

    # ส่งข้อมูลออกทันทีที่ PHP flush ไม่ต้องรอให้คำตอบจบ
    #
    # mod_proxy_fcgi บัฟเฟอร์คำตอบทั้งก้อนเป็นค่าเริ่มต้น ซึ่งใช้กับ Server-Sent Events
    # ไม่ได้เลย: `/api/v2/metrics/stream` ไม่มีวันจบเองภายใน 30 นาที Apache จึงไม่ส่ง
    # แม้แต่ header ออกมา — หน้าจอที่รอค่าสดจะค้างเปล่าโดยไม่มี error ใด ๆ ให้เห็น
    # (พบจากการยิงทดสอบจริง ไม่ใช่จากเทสต์ เพราะเทสต์ไม่ได้เดินผ่าน Apache)
    <Proxy "unix:{{RUN_DIR}}/panel-fpm.sock|fcgi://panel">
        ProxySet flushpackets=on
    </Proxy>

    # ไฟล์ที่ยอมให้ PHP ประมวลผลคือ front controller, Adminer และ phpMyAdmin
    #
    # ใช้ RequireAny เดี่ยว ๆ ห้ามใส่ "Require all denied" ไว้ข้างในกำกับด้วย —
    # RequireAll ต้องให้ทุกเงื่อนไขผ่านพร้อมกัน แต่ "Require all denied" ปฏิเสธ
    # เสมอโดยนิยาม เมื่อมันอยู่ใน RequireAll ร่วมกับเงื่อนไขอื่น บล็อกทั้งก้อนจะ
    # ปฏิเสธทุกคำขอเสมอ ไม่ว่า RequireAny ข้างในจะตรงเงื่อนไขหรือไม่ (พบว่า
    # phpMyAdmin ทุกไฟล์ยกเว้น index.php ตอบ 403 เพราะบั๊กนี้)
    #
    # Apache ปฏิเสธเป็นค่าเริ่มต้นอยู่แล้วถ้าไม่มี Require ไหนผ่าน จึงไม่ต้องมี
    # "Require all denied" มากำกับซ้ำ
    <Files ~ "^(?!index\.php$).*\.php$">
        <RequireAny>
            Require expr "%{REQUEST_URI} =~ m#^/(adminer|phpmyadmin)#"
        </RequireAny>
    </Files>

    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    # header ความปลอดภัยที่เหลือ (CSP, HSTS, X-Frame-Options ฯลฯ) ตั้งโดย
    # Middleware\SecurityHeaders เพื่อไม่ให้มีสองแหล่งที่ขัดกัน
    Header always unset X-Powered-By
    Header always unset Server
</VirtualHost>

SSLSessionCache        shmcb:{{RUN_DIR}}/panel-ssl-cache(512000)
SSLSessionCacheTimeout 300
SSLRandomSeed startup file:/dev/urandom 512
SSLRandomSeed connect builtin
