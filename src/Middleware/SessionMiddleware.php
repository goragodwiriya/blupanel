<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\SessionStore;

/**
 * โหลด session จากคุกกี้ ตรวจอายุ และหมุน id ตามรอบ — SECURITY §2.2
 *
 * ไม่ใช้ session ของ PHP เลย ทั้งหมดเก็บใน SQLite เพื่อให้ควบคุมได้ครบ
 * ทั้งการผูก IP/User-Agent, idle timeout และการสั่งตัด session จากหน้าจัดการผู้ใช้
 */
final class SessionMiddleware implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $store = new SessionStore($ctx->app->db(), $ctx->app->config);
        $cookieName = $store->cookieName();

        $rawId = $request->cookie($cookieName);
        $rotatedTo = null;

        if ($rawId !== '') {
            $userAgent = SessionStore::hashUserAgent($request->userAgent);
            $session = $store->load($rawId, $request->ip, $userAgent);

            if ($session !== null) {
                $ctx->session = $session;
                $ctx->sessionId = $rawId;

                $this->noteUserAgentChange($ctx, $request, $store, $rawId, $session, $userAgent);

                $store->touch($rawId);

                $rotatedTo = $store->rotateIfDue($rawId, (int) $session['rotated_at']);
                if ($rotatedTo !== null) {
                    $ctx->sessionId = $rotatedTo;
                }
            }
        }

        $response = $next($request);

        // ส่งคุกกี้ใหม่เมื่อหมุน id หรือเมื่อ controller เพิ่งสร้าง session (ล็อกอินสำเร็จ)
        if ($rotatedTo !== null) {
            $this->setCookie($response, $ctx, $cookieName, $rotatedTo);
        } elseif ($ctx->sessionId !== '' && $ctx->sessionId !== $rawId) {
            $this->setCookie($response, $ctx, $cookieName, $ctx->sessionId);
        } elseif ($rawId !== '' && $ctx->session === null && $ctx->sessionId === '') {
            // session ใช้ไม่ได้แล้ว (หมดอายุ/ถูกตัด) — ลบคุกกี้ทิ้งเพื่อไม่ให้ส่งซ้ำทุกครั้ง
            $this->clearCookie($response, $ctx, $cookieName);
        }

        return $response;
    }

    /**
     * บันทึกเมื่อคุกกี้ก้อนเดิมถูกใช้จากเบราว์เซอร์ที่ต่างไป
     *
     * ตั้งแต่เลิกผูก session กับ User-Agent (2026-08-11) การเปลี่ยน UA ไม่ตัด session
     * อีกแล้ว — แต่ยัง**ต้องเห็น**ว่ามันเกิดขึ้น เพราะเป็นสัญญาณเดียวที่เหลืออยู่ว่า
     * คุกกี้อาจถูกนำไปใช้ต่อที่อื่น · ผู้ดูแลตรวจย้อนหลังได้จาก audit log
     *
     * อัปเดตค่าที่เก็บไว้ด้วย เพื่อให้บันทึกครั้งเดียวต่อการเปลี่ยนหนึ่งครั้ง
     * ไม่ใช่ทุกคำขอหลังจากนั้น (หน้าเดียวยิงหลายคำขอพร้อมกันเป็นเรื่องปกติของ SPA)
     *
     * @param array<string,mixed> $session
     */
    private function noteUserAgentChange(
        Ctx $ctx,
        Request $request,
        SessionStore $store,
        string $rawId,
        array $session,
        string $userAgent,
    ): void {
        $stored = (string) ($session['ua_hash'] ?? '');

        if ($stored === '' || hash_equals($stored, $userAgent)) {
            return;
        }

        $store->noteUserAgent($rawId, $userAgent);

        try {
            $ctx->app->audit()->write(
                $ctx->actor($request),
                'session.user_agent_changed',
                (string) ($session['username'] ?? ''),
                'warn',
                ['ip' => $request->ip],
            );
        } catch (\Throwable) {
            // เขียน audit ไม่ได้ต้องไม่ทำให้คำขอที่ถูกต้องล้ม — เหตุการณ์นี้เป็นข้อมูล
            // ประกอบ ไม่ใช่ด่านความปลอดภัย
        }
    }

    private function setCookie(Response $response, Ctx $ctx, string $name, string $value): void
    {
        $response->withCookie($name, $value, [
            'expires' => 0,                 // คุกกี้แบบ session ตายเมื่อปิดเบราว์เซอร์
            'path' => '/',
            'secure' => $ctx->app->config->bool('panel.cookie_secure'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function clearCookie(Response $response, Ctx $ctx, string $name): void
    {
        $response->withCookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $ctx->app->config->bool('panel.cookie_secure'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
