<?php

declare(strict_types=1);

/**
 * Front controller — ทางเข้าเดียวของชั้นที่ 1
 *
 * document root ชี้มาที่โฟลเดอร์ public/ เท่านั้น โค้ดใน src/, ไฟล์ config
 * และฐานข้อมูลจึงอยู่นอกพื้นที่ที่เว็บเซิร์ฟเวอร์เข้าถึงได้โดยตรง
 */

require dirname(__DIR__) . '/bootstrap.php';

use Phpcp\Kernel\App;
use Phpcp\Kernel\HttpKernel;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

try {
    $app = App::boot();
    $request = Request::capture($app->config);

    // จำกัด IP ที่เข้า panel ได้ ตรวจก่อนทุกอย่างเพราะถูกที่สุด (SECURITY §2.1)
    $allowlist = $app->config->list('panel.ip_allowlist');
    if ($allowlist !== [] && !ipAllowed($request->ip, $allowlist)) {
        $app->logger()->warn('ปฏิเสธการเข้าถึงจาก IP นอกรายการที่อนุญาต', ['ip' => $request->ip]);

        (Response::text('ไม่อนุญาตให้เข้าถึงจากที่อยู่นี้', 403))->send();
        exit;
    }

    (new HttpKernel($app))->handle($request)->send();
} catch (Throwable $e) {
    // ข้อผิดพลาดที่เกิดก่อน kernel พร้อมทำงาน (config พัง, DB เปิดไม่ได้)
    error_log('[phpcp] bootstrap ล้มเหลว: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="th"><meta charset="utf-8"><title>ระบบยังไม่พร้อมใช้งาน</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.25rem">ระบบยังไม่พร้อมใช้งาน</h1>'
        . '<p>เกิดข้อผิดพลาดระหว่างเริ่มต้นระบบ กรุณาตรวจสอบด้วยคำสั่ง <code>phpcp doctor</code> ที่หน้าเครื่อง</p>';
    exit;
}

/**
 * ตรวจว่า IP อยู่ในรายการที่อนุญาตหรือไม่ รองรับทั้ง IP เดี่ยวและ CIDR
 *
 * @param list<string> $allowlist
 */
function ipAllowed(string $ip, array $allowlist): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    foreach ($allowlist as $rule) {
        if (!str_contains($rule, '/')) {
            if ($ip === $rule) {
                return true;
            }
            continue;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $subnetPacked = @inet_pton($subnet);

        if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
            continue;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && strncmp($packed, $subnetPacked, $fullBytes) !== 0) {
            continue;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
        if ((ord($packed[$fullBytes]) & $mask) === (ord($subnetPacked[$fullBytes]) & $mask)) {
            return true;
        }
    }

    return false;
}
