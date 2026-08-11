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
            $session = $store->load(
                $rawId,
                $request->ip,
                SessionStore::hashUserAgent($request->userAgent),
            );

            if ($session !== null) {
                $ctx->session = $session;
                $ctx->sessionId = $rawId;

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
