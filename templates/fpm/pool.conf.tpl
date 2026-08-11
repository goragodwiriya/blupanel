; ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ
;
; FPM pool ของบัญชี {{ACCOUNT_USER}} บน PHP {{PHP_VERSION}} — ARCHITECTURE §11
;
; หนึ่งบัญชี = หนึ่ง uid = หนึ่ง pool ต่อเวอร์ชัน PHP · เว็บทุกแห่งของบัญชีนี้ที่ใช้
; PHP {{PHP_VERSION}} ทำงานผ่าน pool เดียวกันนี้
;
; ลูกค้ารายอื่นแตะไฟล์ของบัญชีนี้ไม่ได้เลย เพราะรันคนละ uid และมี open_basedir คนละชุด
; ส่วนเว็บของบัญชี**เดียวกัน**อ่านไฟล์กันได้โดยตั้งใจ — เป็นทรัพย์สินของคนเดียวกัน
; และเป็นโมเดลเดียวกับ cPanel/Plesk/DirectAdmin

[{{POOL_NAME}}]

user  = {{ACCOUNT_USER}}
group = {{ACCOUNT_USER}}

listen = {{FPM_SOCKET}}
; เว็บเซิร์ฟเวอร์ต้องต่อ socket ได้ แต่ผู้ใช้อื่นในระบบต้องต่อไม่ได้
listen.owner = {{WEBSERVER_USER}}
listen.group = {{WEBSERVER_USER}}
listen.mode  = 0660

pm                   = ondemand
pm.max_children      = {{MAX_CHILDREN}}
pm.process_idle_timeout = 30s
pm.max_requests      = 500

; log ของ pool อยู่ระดับบัญชี ไม่ใช่ระดับเว็บ เพราะ pool เดียวรับหลายเว็บ
; (log ของเว็บเซิร์ฟเวอร์ยังแยกรายเว็บเหมือนเดิม เพราะ vhost แยกกันจริง)
slowlog                    = {{SLOW_LOG}}
request_slowlog_timeout    = 10s
request_terminate_timeout  = 120s

catch_workers_output = yes
php_admin_value[error_log] = {{PHP_ERROR_LOG}}
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off

; ---------------------------------------------------------------------------
; ขอบเขตของบัญชี — สองบรรทัดนี้คือหัวใจของการแยกลูกค้าออกจากกัน
; open_basedir กันแม้ในกรณีที่ PHP เองมีบั๊กเรื่องเส้นทาง
; disable_functions ทำให้ช่องโหว่ในเว็บกลายเป็น shell ไม่ได้
; ---------------------------------------------------------------------------
php_admin_value[open_basedir] = {{OPEN_BASEDIR}}
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,proc_nice,proc_get_status,proc_terminate,pcntl_exec,pcntl_fork,posix_setuid,posix_setgid,posix_kill,dl,symlink,link

php_admin_value[upload_tmp_dir] = {{TMP_DIR}}
php_admin_value[sys_temp_dir]   = {{TMP_DIR}}
php_admin_value[session.save_path] = {{TMP_DIR}}

php_admin_value[memory_limit]       = {{MEMORY_LIMIT}}
php_admin_value[upload_max_filesize] = {{UPLOAD_LIMIT}}
php_admin_value[post_max_size]      = {{UPLOAD_LIMIT}}
php_admin_value[max_execution_time] = 120

php_admin_flag[allow_url_fopen]  = off
php_admin_flag[allow_url_include] = off
php_admin_value[expose_php] = off
