<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * บังคับให้ล็อกอินก่อนเข้าเส้นทางที่ต้องใช้สิทธิ์
 *
 * เส้นทางที่ permission เป็น null คือเส้นทางสาธารณะ (หน้าล็อกอิน) นอกนั้นต้องล็อกอินหมด
 * ค่าเริ่มต้นจึงเป็น "ต้องล็อกอิน" — ลืมกำหนด permission แล้วเส้นทางจะถูกปิด ไม่ใช่เปิด
 */
final class Authenticate implements Middleware
{
    /**
     * เส้นทางที่เข้าได้ระหว่างรอยืนยัน 2FA
     *
     * ฝั่ง API ต้องมี `GET /api/v2/session` ด้วย เพราะ SPA เรียกมันเป็นอย่างแรกเสมอ
     * ตอนเปิดแอป — ถ้าถูกปิดกั้น หน้าเว็บจะไม่มีทางรู้ว่าตัวเองค้างอยู่ที่ขั้นยืนยัน 2FA
     */
    private const TWO_FACTOR_PATHS = [
        '/api/v2/session',
        '/api/v2/session/2fa',
    ];

    /**
     * เส้นทางที่เข้าได้ตอนถูกบังคับให้เปลี่ยนรหัสผ่าน
     *
     * `/api/v2/me` **ไม่อยู่ในรายการนี้โดยเจตนา** — ข้อมูลผู้ใช้ที่ SPA ต้องใช้ตอนนั้น
     * มีอยู่ใน `GET /api/v2/session` ครบแล้ว การเปิด /me เพิ่มจึงได้แค่ทำให้ขอบเขต
     * ของการบังคับกว้างน้อยลงโดยไม่ได้อะไรกลับมา
     */
    private const PASSWORD_PATHS = [
        '/api/v2/session',
        '/api/v2/me/password',
    ];

    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $route = $ctx->route;

        // 404 หรือเส้นทางสาธารณะ — shell ของ SPA กับ `GET /api/v2/session`
        //
        // ไม่ต้องเด้งคนที่ล็อกอินแล้วออกจากหน้าล็อกอินที่นี่อีก: ทุกหน้าเป็น shell
        // ไฟล์เดียวกัน เส้นทางย่อยตัดสินฝั่งเบราว์เซอร์ · ด่านนั้นอยู่ที่
        // `PhpcpAuth.guard()` ของ SPA ซึ่งเรียงลำดับเงื่อนไขให้ตรงกับที่นี่พอดี
        if ($route === null || $route->permission === null) {
            return $next($request);
        }

        if ($ctx->session === null) {
            return $this->unauthenticated($request);
        }

        // ผ่านรหัสผ่านแล้วแต่ยังไม่ยืนยัน 2FA — เข้าได้เฉพาะหน้ายืนยัน
        if ($ctx->awaiting2fa() && !in_array($request->path, self::TWO_FACTOR_PATHS, true)) {
            if ($request->isApiV2()) {
                return ApiProblem::TwoFactorRequired->response('ต้องยืนยันรหัส 2FA ก่อนใช้งาน');
            }

            return Response::json(['ok' => false, 'error' => 'ต้องยืนยันรหัส 2FA ก่อน'], 401);
        }

        // บังคับเปลี่ยนรหัสผ่านครั้งแรก (รหัสที่ระบบสุ่มให้ตอนติดตั้ง)
        if ($ctx->mustChangePassword() && !in_array($request->path, self::PASSWORD_PATHS, true)) {
            if ($request->isApiV2()) {
                return ApiProblem::PasswordChangeRequired->response('ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน');
            }

            return Response::json(['ok' => false, 'error' => 'ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน'], 403);
        }

        return $next($request);
    }

    /**
     * ไม่มีเซสชัน — ตอบ 401 เสมอ ไม่เด้งไปหน้าไหน
     *
     * ทุกเส้นทางที่ต้องใช้สิทธิ์คือ `/api/v2/*` แล้วทั้งหมด · การเด้ง 302 ใส่คำขอ
     * ที่ยิงด้วย fetch ทำให้ฝั่ง SPA เห็น "สำเร็จพร้อม HTML" แทนที่จะเห็นว่าไม่ได้
     * ล็อกอิน แล้วพังในที่ที่ไกลจากต้นเหตุ · การพากลับหน้าเดิมหลังล็อกอินเป็นงาน
     * ของ router ฝั่งเบราว์เซอร์ ซึ่งรู้จัก URL ปัจจุบันดีกว่าฝั่งเซิร์ฟเวอร์อยู่แล้ว
     */
    private function unauthenticated(Request $request): Response
    {
        return $request->isApiV2()
            ? ApiProblem::Unauthenticated->response('กรุณาเข้าสู่ระบบ')
            : Response::json(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบ'], 401);
    }
}
