; ไฟล์นี้สร้างโดยตัวติดตั้ง PHP Server Control Panel — ห้ามแก้ด้วยมือ
;
; FPM master ของ panel เอง (ARCHITECTURE §5.2)
; แยกจาก php*-fpm ของระบบทั้ง pid, socket และ config tree
; ผู้ใช้จึงสั่งหยุด/รีสตาร์ต php-fpm ของระบบผ่านหน้าเว็บได้โดย panel ไม่ดับตาม

[global]

pid       = {{RUN_DIR}}/panel-fpm.pid
error_log = {{LOG_DIR}}/panel-fpm.log
log_level = notice

; systemd เป็นคนดูแลการรีสตาร์ต — ไม่ต้องให้ FPM ตัดสินใจเอง
emergency_restart_threshold  = 0
process_control_timeout      = 10s
daemonize                    = no

include = {{CONF_DIR}}/fpm/pool.d/*.conf
