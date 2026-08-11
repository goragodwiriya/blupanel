<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Header ความปลอดภัย — SECURITY §2.3
 *
 * CSP ไม่มี unsafe-inline และไม่มี unsafe-eval เลย ซึ่งเป็นเหตุผลที่ทั้งระบบ
 * ต้องเลิกใช้ onclick= แบบ inline และเลิกใช้ Tailwind CDN ที่คอมไพล์ CSS ในเบราว์เซอร์
 *
 * ทำงานเป็นชั้นนอกสุดเพื่อให้ header ติดไปกับทุกคำตอบ รวมถึงหน้า error และ 404
 */
final class SecurityHeaders implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'none'",
            "script-src 'self' 'nonce-{$ctx->nonce}'",
            "style-src 'self'",
            "img-src 'self' data:",
            // เสียง/วิดีโอในตัวจัดการไฟล์เล่นจาก `data:` ที่ประกอบในเบราว์เซอร์จากเนื้อไฟล์
            // ที่ `GET /files/download` ส่งมา — ไม่ได้ชี้กลับไปที่ URL ของเซิร์ฟเวอร์
            //
            // จำเป็นต้องเป็น `data:` ไม่ใช่ URL ตรง เพราะ endpoint ดาวน์โหลดส่ง
            // `application/octet-stream` + `attachment` เสมอโดยตั้งใจ (กัน stored XSS
            // จากไฟล์ .html ของผู้ใช้) ซึ่งเบราว์เซอร์เล่นเป็นสื่อไม่ได้
            //
            // ไม่มีบรรทัดนี้ media-src จะตกไปใช้ `default-src 'none'` แล้วถูกบล็อกเงียบ ๆ
            "media-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "manifest-src 'self'",
        ]);

        $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader(
                'Permissions-Policy',
                'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
            );

        // HSTS ส่งเฉพาะตอนที่มาทาง HTTPS จริง — ส่งตอน HTTP จะไม่มีผลและทำให้ dev ใช้งานลำบาก
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // หน้าของ panel มีข้อมูลอ่อนไหว ห้าม proxy หรือเบราว์เซอร์เก็บแคชไว้
        if (!str_starts_with($request->path, '/assets/')) {
            $response
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
                ->withHeader('Pragma', 'no-cache');
        }

        return $response;
    }
}
