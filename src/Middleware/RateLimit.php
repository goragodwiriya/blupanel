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
 * Rate-limits requests — SECURITY §2.1
 *
 * Deliberately placed before Session: a burst of requests must be cut off
 * before the system ever touches the users table or computes Argon2id, both of which are expensive
 *
 * The login page's quota is kept separate from every other page, since it's the target of password guessing
 */
final class RateLimit implements Middleware
{
    /**
     * Routes that can be used to guess a password — a separate, much stricter quota than ordinary routes
     *
     * Down to two routes after the HTML-based UI was removed · back when
     * there were two frontends, this list had to include both `/login` and
     * `/api/v2/session`, or moving to the SPA would **weaken** the
     * protection silently, with nobody intending it — leaving password
     * guessing to run on the ordinary-request quota (a burst of 120) instead
     * of 5 · that lesson still applies to whatever frontend comes next
     */
    private const LOGIN_PATHS = ['/api/v2/session', '/api/v2/session/2fa'];

    /**
     * The login page's quota — how many rapid attempts, and how fast it refills
     *
     * **Public because something else has to compute against it, not just use it
     * here** — requests rejected with 429 are cut off at this layer, so they never
     * reach the audit log. The jail that counts those lines
     * ({@see \Phpcp\Driver\Security\Fail2banManager::PANEL_LOGIN_JAIL}) therefore has a
     * ceiling on how many lines it can ever see in one window — set `maxretry` above
     * that ceiling and the jail can never fire, with nothing to say so.
     */
    public const LOGIN_BURST = 5.0;
    public const LOGIN_REFILL_PER_SECOND = 1 / 60;

    /**
     * The most failed logins one IP can produce in a given window
     *
     * = the initial burst + whatever refills during that window. Used as the ceiling
     * on `maxretry`.
     */
    public static function maxLoginFailuresWithin(int $seconds): int
    {
        return (int) floor(self::LOGIN_BURST + $seconds * self::LOGIN_REFILL_PER_SECOND);
    }

    /**
     * @param Request $request
     * @param Ctx $ctx
     * @param $next
     * @return mixed
     */
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        // Static files don't count — otherwise loading a single page would already exhaust the quota
        if (str_starts_with($request->path, '/assets/')) {
            return $next($request);
        }

        $limiter = new RateLimiter($ctx->app->db());
        $isLogin = $request->isPost() && in_array($request->path, self::LOGIN_PATHS, true);

        if ($isLogin) {
            // A burst of 5, then refills at 1 per 60 seconds
            $bucket = 'login:'.$request->ip;
            if (!$limiter->allow($bucket, self::LOGIN_BURST, self::LOGIN_REFILL_PER_SECOND)) {
                return $this->tooMany($request, $ctx, $limiter->retryAfter($bucket, self::LOGIN_REFILL_PER_SECOND));
            }

            return $next($request);
        }

        // Ordinary requests: a burst of 120, refilling at 2 per second — plenty for normal use
        $bucket = 'req:'.$request->ip;
        if (!$limiter->allow($bucket, 120.0, 2.0)) {
            return $this->tooMany($request, $ctx, $limiter->retryAfter($bucket, 2.0));
        }

        return $next($request);
    }

    /**
     * @param Request $request
     * @param int $retryAfter
     */
    private function tooMany(Request $request, Ctx $ctx, int $retryAfter): Response
    {
        $message = $retryAfter > 0
            ? $ctx->app->t('Too many requests — please wait {seconds} seconds', ['seconds' => $retryAfter])
            : $ctx->app->t('Too many requests — please wait a moment');

        $response = match (true) {
            $request->isApiV2() => ApiProblem::RateLimited->response($message),
            $request->wantsJson() => Response::json(['ok' => false, 'error' => $message], 429),
            default => ErrorPage::response(429, $ctx->app->t('Too many requests'), $message),
        };

        return $retryAfter > 0
            ? $response->withHeader('Retry-After', (string) $retryAfter)
            : $response;
    }
}
