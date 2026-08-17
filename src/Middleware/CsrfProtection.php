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
 * Checks the CSRF token on every request that changes data — SECURITY §2.3
 *
 * Runs after Session, since the token is bound to the session id, and before
 * Authorize, since a request with no token must be rejected before touching any logic at all
 *
 * The token is bound only to the session, never to the route — binding it to
 * the route was tried once, to prevent a token from one form being reused on
 * another, but it didn't work in practice because many forms are rendered on
 * one page and then POST to a different route (the Services page, for
 * example, which sends to /server/services/{unit}/{action}) — the token
 * would then never match, and the form would fail silently · the extra
 * benefit was already small anyway, given SameSite=Strict and a token per session
 */
final class CsrfProtection implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $csrf = new Csrf($ctx->app->config->secretKey());

        // Uses a fixed value before signing in, so the login form itself has a working token
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
            $ctx->app->logger()->warn('Invalid CSRF token', [
                'path' => $request->path,
                'ip' => $request->ip,
                'user' => $ctx->username()
            ]);

            if ($request->isApiV2()) {
                // Sends the valid token back too, so the SPA can retry
                // immediately once without making the user fill in the form
                // again — this is the behavior §4.4 requires
                return ApiProblem::CsrfInvalid
                    ->response($ctx->app->t('Session expired — please refresh the page and try again'))
                    ->withHeader(Csrf::HEADER, $ctx->csrfToken);
            }

            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => $ctx->app->t('Session expired — please refresh the page and try again')], 419)
                : ErrorPage::response(419, $ctx->app->t('Session expired'), $ctx->app->t('Please refresh the page and try again'));
        }

        return $this->withFreshToken($next($request), $request, $ctx, $csrf, $bind);
    }

    /**
     * Attaches the current CSRF token to every API v2 response
     *
     * Needed because the token is bound to a session id that **can change
     * within a single request** — on a successful login, on confirming 2FA,
     * and whenever SessionMiddleware rotates the id on schedule (every 15
     * minutes) · if the fresh value weren't sent back, the SPA's next request
     * would use a token bound to the old session and get 419, even though the user did nothing wrong
     *
     * Only sent for API v2 — the old HTML pages already get their token through a `<meta>` tag on the page itself
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
