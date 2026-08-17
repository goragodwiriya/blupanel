<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Agent\Actor;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;
use Phpcp\Security\RateLimiter;
use Phpcp\Security\SessionStore;

/**
 * Loads the session from its cookie, checks its age, and rotates the id on
 * schedule — SECURITY §2.2
 *
 * Never uses PHP's own session mechanism — everything is stored in SQLite so
 * it can be fully controlled: binding to IP/User-Agent, the idle timeout, and
 * being able to destroy a session from the user-management page
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
            } else {
                $this->noteRejection($ctx, $request, $store, $rawId);
            }
        }

        $response = $next($request);

        // Sends a new cookie when the id was rotated, or when the controller just created a session (a successful login)
        if ($rotatedTo !== null) {
            $this->setCookie($response, $ctx, $cookieName, $rotatedTo);
        } elseif ($ctx->sessionId !== '' && $ctx->sessionId !== $rawId) {
            $this->setCookie($response, $ctx, $cookieName, $ctx->sessionId);
        } elseif ($rawId !== '' && $ctx->session === null && $ctx->sessionId === '') {
            // The session is no longer valid (expired/destroyed) — clears the cookie so it isn't resent on every request
            $this->clearCookie($response, $ctx, $cookieName);
        }

        return $response;
    }

    /**
     * Records when a session that hasn't expired was rejected for coming from a different IP
     *
     * Binding the session to an IP is now the one remaining measure against a
     * stolen cookie (binding to the User-Agent was removed on 2026-08-11) ·
     * it used to reject silently — the user just saw themselves get signed
     * out, and an admin saw nothing at all · a stolen cookie being tried out
     * was therefore an event the system **had already caught but told nobody
     * about**, the most wasted outcome of any kind of detection
     *
     * **Rate-limits its own logging**, because `audit_log` is a hash chain
     * that can't be deleted from · a stolen cookie fired repeatedly from the
     * same IP would write one row per request, which would become its own
     * problem by growing the table out of control · the first three are enough to tell the story
     */
    private function noteRejection(Ctx $ctx, Request $request, SessionStore $store, string $rawId): void
    {
        $rejection = $store->lastRejection();

        if ($rejection === []) {
            return;
        }

        try {
            $bucket = 'session-reject:' . SessionStore::hashId($rawId);

            // The first 3 can fire in a burst, then it refills once per hour
            if (!(new RateLimiter($ctx->app->db()))->allow($bucket, 3.0, 1 / 3600)) {
                return;
            }

            $ctx->app->audit()->write(
                new Actor(
                    userId: (int) ($rejection['user_id'] ?? 0),
                    username: (string) ($rejection['username'] ?? ''),
                    role: Permissions::WEBADMIN,
                    ip: $request->ip,
                    requestId: $request->requestId,
                ),
                'session.ip_mismatch',
                (string) ($rejection['username'] ?? ''),
                'warn',
                [
                    // The expected address is the real owner's, the seen address is whoever is holding the cookie right now
                    'expected_ip' => (string) ($rejection['expected_ip'] ?? ''),
                    'seen_ip' => (string) ($rejection['seen_ip'] ?? ''),
                    'path' => $request->path,
                ],
            );
        } catch (\Throwable) {
            // Failing to write the audit entry must never fail the request — the session was already rejected regardless
        }
    }

    /**
     * Records when the same cookie was used from a different browser
     *
     * Since the session stopped being bound to the User-Agent (2026-08-11), a
     * UA change no longer destroys the session — but it still **needs to be
     * visible** that it happened, since it's the one remaining signal that a
     * cookie may have gone on to be used somewhere else · an admin can look back at the audit log
     *
     * Also updates the stored value, so this is recorded once per change,
     * not on every request afterward (one page firing several requests at once is normal for the SPA)
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
            // Failing to write the audit entry must never fail a legitimate
            // request — this event is supporting information, not a security gate
        }
    }

    private function setCookie(Response $response, Ctx $ctx, string $name, string $value): void
    {
        $response->withCookie($name, $value, [
            'expires' => 0,                 // A session-style cookie dies when the browser closes
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
