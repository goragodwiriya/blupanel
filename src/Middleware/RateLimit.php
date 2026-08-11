<?php

declare (strict_types = 1);

namespace Phpcp\Middleware;

use Phpcp\Http\ApiProblem;
use Phpcp\Http\ErrorPage;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\RateLimiter;

/**
 * จำกัดอัตราคำขอ — SECURITY §2.1
 *
 * อยู่ก่อน Session โดยเจตนา: การยิงคำขอรัว ๆ ต้องถูกตัดตั้งแต่ก่อนที่ระบบ
 * จะไปแตะฐานข้อมูลผู้ใช้หรือคำนวณ Argon2id ซึ่งกินทรัพยากรมาก
 *
 * แยกโควตาของหน้าล็อกอินออกจากหน้าอื่น เพราะเป็นเป้าของการเดารหัสผ่าน
 */
final class RateLimit implements Middleware
{
    /**
     * เส้นทางที่ใช้เดารหัสผ่านได้ — โควตาแยกและเข้มกว่าเส้นทางทั่วไปมาก
     *
     * เหลือสองเส้นทางหลัง UI แบบ HTML ถูกลบ · ตอนที่มีสอง frontend รายการนี้ต้องมี
     * ทั้ง `/login` และ `/api/v2/session` ไม่งั้นการย้ายไป SPA จะ**ลด**การป้องกันลง
     * เงียบ ๆ โดยที่ไม่มีใครตั้งใจ — ปล่อยให้เดารหัสผ่านด้วยโควตาของคำขอทั่วไป
     * (120 ครั้งรัว) แทนที่จะเป็น 5 · บทเรียนนั้นยังใช้ได้กับ frontend ตัวถัดไป
     */
    private const LOGIN_PATHS = ['/api/v2/session', '/api/v2/session/2fa'];

    /**
     * @param Request $request
     * @param Ctx $ctx
     * @param $next
     * @return mixed
     */
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        // ไฟล์ static ไม่ต้องนับ ไม่อย่างนั้นการโหลดหน้าเดียวก็กินโควตาหมดแล้ว
        if (str_starts_with($request->path, '/assets/')) {
            return $next($request);
        }

        $limiter = new RateLimiter($ctx->app->db());
        $isLogin = $request->isPost() && in_array($request->path, self::LOGIN_PATHS, true);

        if ($isLogin) {
            // 5 ครั้งรัว แล้วเติมกลับ 1 ครั้งต่อ 60 วินาที
            $bucket = 'login:'.$request->ip;
            if (!$limiter->allow($bucket, 5.0, 1 / 60)) {
                return $this->tooMany($request, $limiter->retryAfter($bucket, 1 / 60));
            }

            return $next($request);
        }

        // คำขอทั่วไป: 120 ครั้งรัว เติมกลับ 2 ครั้งต่อวินาที เหลือเฟือสำหรับการใช้งานปกติ
        $bucket = 'req:'.$request->ip;
        if (!$limiter->allow($bucket, 120.0, 2.0)) {
            return $this->tooMany($request, $limiter->retryAfter($bucket, 2.0));
        }

        return $next($request);
    }

    /**
     * @param Request $request
     * @param int $retryAfter
     */
    private function tooMany(Request $request, int $retryAfter): Response
    {
        $message = $retryAfter > 0
            ? "มีคำขอมากเกินไป กรุณารออีก {$retryAfter} วินาที"
            : 'มีคำขอมากเกินไป กรุณารอสักครู่';

        $response = match (true) {
            $request->isApiV2() => ApiProblem::RateLimited->response($message),
            $request->wantsJson() => Response::json(['ok' => false, 'error' => $message], 429),
            default => ErrorPage::response(429, 'คำขอมากเกินไป', $message),
        };

        return $retryAfter > 0
            ? $response->withHeader('Retry-After', (string) $retryAfter)
            : $response;
    }
}
