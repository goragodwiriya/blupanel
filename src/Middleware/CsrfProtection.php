<?php

declare (strict_types = 1);

namespace Phpcp\Middleware;

use Phpcp\Http\ErrorPage;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Csrf;

/**
 * ตรวจ CSRF token ทุกคำขอที่เปลี่ยนแปลงข้อมูล — SECURITY §2.3
 *
 * ทำงานหลัง Session เพราะ token ผูกกับ session id
 * และทำงานก่อน Authorize เพราะคำขอที่ไม่มี token ต้องถูกตัดทิ้งก่อนแตะ logic ใด ๆ
 *
 * token ผูกกับ session อย่างเดียว ไม่ผูกกับเส้นทาง —
 * เคยลองผูกกับเส้นทางเพื่อกันการนำ token ข้ามฟอร์ม แต่ใช้จริงไม่ได้เพราะฟอร์มจำนวนมาก
 * ถูกเรนเดอร์ในหน้าหนึ่งแล้ว POST ไปอีกเส้นทางหนึ่ง (เช่นหน้า Services ที่ส่งไป
 * /server/services/{unit}/{action}) ทำให้ token ไม่มีวันตรงกันและฟอร์มพังเงียบ ๆ
 * ประโยชน์ที่ได้เพิ่มก็น้อยอยู่แล้วเมื่อมี SameSite=Strict และ token ต่อ session อยู่แล้ว
 */
final class CsrfProtection implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $csrf = new Csrf($ctx->app->config->secretKey());

        // ตอนยังไม่ล็อกอินใช้ค่าคงที่เพื่อให้ฟอร์มล็อกอินมี token ใช้ได้
        $bind = $ctx->sessionId !== '' ? $ctx->sessionId : 'guest';
        $ctx->csrfToken = $csrf->token($bind, Csrf::SCOPE);

        if (!$request->isMutating()) {
            return $this->withFreshToken($next($request), $request, $ctx, $csrf, $bind);
        }

        $submitted = $request->input(Csrf::FIELD);
        if ($submitted === '') {
            $submitted = $request->header(Csrf::HEADER);
        }

        if (!$csrf->verify($bind, $submitted, Csrf::SCOPE)) {
            $ctx->app->logger()->warn('CSRF token ไม่ถูกต้อง', [
                'path' => $request->path,
                'ip' => $request->ip,
                'user' => $ctx->username()
            ]);

            if ($request->isApiV2()) {
                // ส่ง token ที่ถูกต้องกลับไปด้วย ฝั่ง SPA จึงลองใหม่ได้ทันทีหนึ่งครั้ง
                // โดยไม่ต้องให้ผู้ใช้กรอกฟอร์มซ้ำ — เป็นพฤติกรรมที่ §4.4 กำหนดไว้
                return ApiProblem::CsrfInvalid
                    ->response('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง')
                    ->withHeader(Csrf::HEADER, $ctx->csrfToken);
            }

            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'], 419)
                : ErrorPage::response(419, 'เซสชันหมดอายุ', 'กรุณารีเฟรชหน้าแล้วลองใหม่อีกครั้ง');
        }

        return $this->withFreshToken($next($request), $request, $ctx, $csrf, $bind);
    }

    /**
     * แนบ CSRF token ปัจจุบันไปกับทุกคำตอบของ API v2
     *
     * จำเป็นเพราะ token ผูกกับ session id ที่ **เปลี่ยนได้ระหว่างคำขอเดียว** — ทั้งตอน
     * ล็อกอินสำเร็จ ตอนยืนยัน 2FA และตอนที่ SessionMiddleware หมุน id ตามรอบ (ทุก 15 นาที)
     * ถ้าไม่ส่งค่าใหม่กลับไป คำขอถัดไปของ SPA จะใช้ token ที่ผูกกับ session เก่าแล้วโดน 419
     * ทั้งที่ผู้ใช้ไม่ได้ทำอะไรผิดเลย
     *
     * ส่งเฉพาะ API v2 — หน้า HTML เดิมได้ token ผ่าน `<meta>` ในตัวหน้าอยู่แล้ว
     */
    private function withFreshToken(
        Response $response,
        Request $request,
        Ctx $ctx,
        Csrf $csrf,
        string $bind,
    ): Response {
        if (!$request->isApiV2()) {
            return $response;
        }

        $current = $ctx->sessionId !== '' ? $ctx->sessionId : 'guest';

        if ($current !== $bind) {
            $ctx->csrfToken = $csrf->token($current, Csrf::SCOPE);
        }

        return $response->withHeader(Csrf::HEADER, $ctx->csrfToken);
    }

}
