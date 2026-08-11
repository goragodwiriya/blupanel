; ไฟล์นี้สร้างโดยตัวติดตั้ง PHP Server Control Panel — ห้ามแก้ด้วยมือ
;
; pool เดียวของ panel (ARCHITECTURE §3.1)
; หัวใจของไฟล์นี้คือ disable_functions และ open_basedir:
; ต่อให้มีช่องโหว่ RCE ในโค้ด PHP ของ panel ก็สั่ง shell ไม่ได้และอ่านไฟล์นอกขอบเขตไม่ได้
; ทำได้อย่างเดียวคือเรียก capability ที่ agent อนุญาต ซึ่งถูกจำกัดและถูก audit ครบ

[panel]

user  = {{PANEL_USER}}
; group เดียวกับ socket ของ agent จึงต่อ agent.sock ได้
group = {{PANEL_GROUP}}

listen       = {{RUN_DIR}}/panel-fpm.sock
listen.owner = {{PANEL_USER}}
listen.group = {{PANEL_GROUP}}
listen.mode  = 0660

; panel มีผู้ใช้ไม่กี่คน แต่ SSE กินสล็อตค้างไว้ 1 ตัวต่อแท็บที่เปิด
; (มี guard ปิด stream อัตโนมัติที่ 30 นาทีใน StreamController)
pm                     = static
pm.max_children        = 4
pm.max_requests        = 500
request_terminate_timeout = 0

catch_workers_output = yes
php_admin_value[error_log]     = {{LOG_DIR}}/panel-php.log
php_admin_flag[log_errors]     = on
php_admin_flag[display_errors] = off

; ---------------------------------------------------------------------------
; ขอบเขตของ panel — ต้องครบทุกเส้นทางที่ชั้นที่ 1 แตะจริง ไม่มีเกินกว่านั้น
;   INSTALL_DIR โค้ด + db/migrations
;   CONF_DIR    config.php ที่มี secret key
;   DATA_DIR    panel.db + backups
;   LOG_DIR     log ของ panel
;   RUN_DIR     agent.sock (Client ต่อผ่าน stream_socket_client จึงติด open_basedir)
;   TMP_DIR     ไฟล์อัปโหลดชั่วคราว
; ---------------------------------------------------------------------------
php_admin_value[open_basedir] = {{INSTALL_DIR}}/:{{CONF_DIR}}/:{{DATA_DIR}}/:{{LOG_DIR}}/:{{RUN_DIR}}/:{{TMP_DIR}}/:/usr/share/phpmyadmin/:/etc/phpmyadmin/:/usr/share/php/:/var/lib/phpmyadmin/:/etc/mariadb/
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,posix_setuid,dl

php_admin_value[upload_tmp_dir] = {{TMP_DIR}}
php_admin_value[sys_temp_dir]   = {{TMP_DIR}}

; session ของ PHP ต้องอยู่ในที่ที่ open_basedir ข้างบนอนุญาต — ค่าปริยายของ Debian
; คือ /var/lib/php/sessions ซึ่งไม่อยู่ในรายการ ทำให้ session_start() เขียนไม่ได้เลย
;
; panel เองไม่ได้ใช้ session ของ PHP (มี SessionStore ในฐานข้อมูลของตัวเอง) แต่สะพาน
; เข้า phpMyAdmin ต้องใช้ เพราะ phpMyAdmin อ่านชื่อผู้ใช้/รหัสจาก session ของ PHP
; ตาม auth_type = signon · ทั้งสองฝั่งอยู่ pool เดียวกันจึงใช้ที่เก็บเดียวกันโดยอัตโนมัติ
php_admin_value[session.save_path] = {{TMP_DIR}}
php_admin_value[memory_limit]   = 128M
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size]       = 32M
php_admin_value[max_execution_time]  = 120

php_admin_flag[allow_url_fopen]    = off
php_admin_flag[allow_url_include]  = off
php_admin_value[expose_php]        = off
