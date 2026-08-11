# Apache config สำหรับโหมด sandbox เท่านั้น — สร้างโดย `phpcp sandbox:seed`
#
# มีไว้เพื่อให้ `apache2 -t` ตรวจ vhost ที่ panel generate ออกมาได้จริง
# โดยไม่แตะ /etc/apache2 ของเครื่องเลย (ARCHITECTURE §6.3)
#
# เขียนให้ครบในตัวเอง ไม่อ้าง ${APACHE_LOG_DIR} หรือ envvars ใด ๆ
# จึงรันด้วย `apache2 -d <ที่นี่> -f apache2.conf -t` ตรง ๆ ได้โดยไม่ต้องผ่าน apache2ctl

ServerRoot "{{ROOT}}"
ServerName localhost
PidFile "{{ROOT}}/run/apache2.pid"
ErrorLog "{{ROOT}}/logs/error.log"
Listen {{HTTP_PORT}}

User www-data
Group www-data

# mod_unixd และ mod_log_config ถูก compile มาในตัวบน Debian/Ubuntu จึงห้าม LoadModule ซ้ำ
LoadModule mpm_event_module   {{MODULES}}/mod_mpm_event.so
LoadModule authz_core_module  {{MODULES}}/mod_authz_core.so
LoadModule authz_host_module  {{MODULES}}/mod_authz_host.so
LoadModule dir_module         {{MODULES}}/mod_dir.so
LoadModule mime_module        {{MODULES}}/mod_mime.so
LoadModule alias_module       {{MODULES}}/mod_alias.so
LoadModule proxy_module       {{MODULES}}/mod_proxy.so
LoadModule proxy_fcgi_module  {{MODULES}}/mod_proxy_fcgi.so
LoadModule rewrite_module     {{MODULES}}/mod_rewrite.so
LoadModule headers_module     {{MODULES}}/mod_headers.so

<Directory />
    AllowOverride None
    Require all denied
</Directory>

DirectoryIndex index.php index.html

IncludeOptional sites-enabled/*.conf
