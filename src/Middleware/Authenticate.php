<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Requires sign-in before entering a route that needs a permission
 *
 * A route whose permission is null is a public route (the login page) — every
 * other route requires sign-in · the default is therefore "sign-in
 * required" — forgetting to set a permission closes a route, never opens one
 */
final class Authenticate implements Middleware
{
    /**
     * Routes reachable while waiting for 2FA to be confirmed
     *
     * The API side must also include `GET /api/v2/session`, since the SPA
     * always calls it first when the app opens — if it were blocked, the web
     * page would have no way to find out it's stuck at the 2FA step
     */
    private const TWO_FACTOR_PATHS = [
        '/api/v2/session',
        '/api/v2/session/2fa',
    ];

    /**
     * Routes reachable while a password change is being forced
     *
     * `/api/v2/me` is **deliberately not in this list** — the user data the
     * SPA needs at that point is already fully present in `GET
     * /api/v2/session`, so opening /me too would only narrow the
     * enforcement's scope without gaining anything in return
     */
    private const PASSWORD_PATHS = [
        '/api/v2/session',
        '/api/v2/me/password',
    ];

    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $route = $ctx->route;

        // A 404 or a public route — the SPA's shell and `GET /api/v2/session`
        //
        // No need to bounce an already-signed-in user away from the login
        // page here either: every page is the same single shell file, the
        // sub-route decides on the browser side · that check lives in the
        // SPA's `PhpcpAuth.guard()`, which orders its conditions to match this exactly
        if ($route === null || $route->permission === null) {
            return $next($request);
        }

        if ($ctx->session === null) {
            return $this->unauthenticated($request, $ctx);
        }

        // Password verified but 2FA not yet confirmed — only the verification page is reachable
        if ($ctx->awaiting2fa() && !in_array($request->path, self::TWO_FACTOR_PATHS, true)) {
            if ($request->isApiV2()) {
                return ApiProblem::TwoFactorRequired->response($ctx->app->t('Two-factor verification is required before continuing'));
            }

            return Response::json(['ok' => false, 'error' => $ctx->app->t('Two-factor verification is required before continuing')], 401);
        }

        // Forces the first password change (the one the system randomly generated during setup)
        if ($ctx->mustChangePassword() && !in_array($request->path, self::PASSWORD_PATHS, true)) {
            if ($request->isApiV2()) {
                return ApiProblem::PasswordChangeRequired->response($ctx->app->t('A password change is required before continuing'));
            }

            return Response::json(['ok' => false, 'error' => $ctx->app->t('A password change is required before continuing')], 403);
        }

        return $next($request);
    }

    /**
     * No session — always answers 401, never redirects anywhere
     *
     * Every route that requires a permission is already `/api/v2/*` · a 302
     * redirect on a request fired with fetch would make the SPA see "success
     * with HTML" instead of seeing that it isn't signed in, then fail
     * somewhere far from the actual cause · returning to the same page after
     * signing in is the browser-side router's job, which already knows the current URL better than the server does
     */
    private function unauthenticated(Request $request, Ctx $ctx): Response
    {
        return $request->isApiV2()
            ? ApiProblem::Unauthenticated->response($ctx->app->t('Please sign in'))
            : Response::json(['ok' => false, 'error' => $ctx->app->t('Please sign in')], 401);
    }
}
