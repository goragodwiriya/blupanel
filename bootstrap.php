<?php

declare(strict_types=1);

/**
 * จุดเริ่มต้นร่วมของทุก entry point — public/index.php, bin/phpcp, bin/phpcp-agentd
 *
 * ไม่มี Composer ตามการตัดสินใจ D6 จึงใช้ autoloader PSR-4 ที่เขียนเองสั้น ๆ
 * ข้อดีเพิ่มเติมคือ opcache แคชไฟล์นี้ได้ทั้งไฟล์ ไม่มีการ scan directory ตอน runtime
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'phpcp ต้องการ PHP 8.1 ขึ้นไป (พบ ' . PHP_VERSION . ")\n");
    exit(1);
}

define('PHPCP_ROOT', __DIR__);
define('PHPCP_VERSION', '0.1.0-dev');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Phpcp\\')) {
        return;
    }
    $file = PHPCP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ค่าที่ต้องบังคับเหมือนกันทุก entry point ไม่ว่า php.ini ของเครื่องจะตั้งมาอย่างไร
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '0');
