<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Http\ApiProblem;
use Phpcp\Http\ErrorPage;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Checks a route's permission — layer 1 of a two-layer check
 *
 * Layer 2 lives at the agent (the Dispatcher), which checks again · two
 * layers are needed because this one prevents someone from opening the URL
 * directly, while that one covers the case where this layer has a bug
 *
 * The result PROMPT.md requires: a website admin opening /server/services
 * directly must get 403, not just fail to see the menu item
 */
final class Authorize implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $route = $ctx->route;

        if ($route === null || $route->permission === null) {
            return $next($request);
        }

        if ($ctx->can($route->permission)) {
            return $next($request);
        }

        $ctx->app->audit()->write(
            $ctx->actor($request),
            'http.forbidden',
            $request->path,
            'denied',
            ['permission' => $route->permission, 'role' => $ctx->role()],
        );

        if ($request->isApiV2()) {
            return ApiProblem::Forbidden->response($ctx->app->t('You do not have permission to access this section'));
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $ctx->app->t('You do not have permission to access this section')], 403);
        }

        return ErrorPage::response(
            403,
            $ctx->app->t('Access denied'),
            $ctx->app->t('Account "{name}" ({role}) does not have permission to access this page', [
                'name' => $ctx->displayName(),
                'role' => $ctx->app->t(Permissions::roleLabel($ctx->role())),
            ]),
        );
    }
}
