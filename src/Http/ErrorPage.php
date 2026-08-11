<?php

declare(strict_types=1);

namespace Phpcp\Http;

use Phpcp\Kernel\Response;

/**
 * หน้าข้อผิดพลาดแบบ HTML — ทางออกสุดท้ายเมื่อคำขอไม่ใช่ JSON
 *
 * **ทำไมยังต้องมีทั้งที่ระบบเหลือแต่ SPA แล้ว:** ผู้ใช้พิมพ์ URL เองได้ และ middleware
 * บางตัวตัดสินใจ*ก่อน*ที่ router จะรู้ว่าเส้นทางนั้นคืออะไร (เช่นตัวจำกัดอัตราที่
 * ทำงานก่อนทุกอย่าง) · คำขอเหล่านั้นไม่มี `Accept: application/json` ติดมา จะตอบ
 * JSON ก้อนหนึ่งใส่หน้าเบราว์เซอร์ก็ไม่มีประโยชน์กับคนอ่าน
 *
 * ประกอบ HTML ด้วยมือแทนที่จะมีเอนจินเทมเพลต — มีอยู่สี่จุดที่เรียก และทุกจุดคือ
 * จังหวะที่ระบบกำลังมีปัญหาอยู่แล้ว ยิ่งชั้นที่ต้องทำงานถูกน้อยยิ่งดี · CSS อยู่ที่
 * `/assets/css/error.css` ไฟล์เดียวที่ไม่พึ่ง bundle ของ SPA เลย
 *
 * ห้ามใส่ `style="..."` — CSP ของ panel บล็อก inline style ทั้งหมด (SECURITY §2.3)
 */
final class ErrorPage
{
    public static function response(int $status, string $title, string $message, bool $home = true): Response
    {
        return Response::html(self::html($status, $title, $message, $home), $status);
    }

    private static function html(int $status, string $title, string $message, bool $home): string
    {
        $safe = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="th"><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$safe($title).'</title>'
            .'<link rel="stylesheet" href="/assets/css/error.css">'
            .'<body><div class="error-card">'
            .'<div class="error-code">'.$status.'</div>'
            .'<h1 class="error-title">'.$safe($title).'</h1>'
            .'<p class="error-message">'.$safe($message).'</p>'
            .($home ? '<a class="error-link" href="/app/">กลับหน้าหลัก</a>' : '')
            .'</div>';
    }
}
