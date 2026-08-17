<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Binds a request id to everything that happens during this request, and logs requests that change data
 *
 * The request id makes it possible to trace multiple audit log lines back to
 * the same button click — necessary when investigating a cause after the
 * fact, since a single action can call several capabilities
 */
final class AuditContext implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        // Sends the request id back too, so a user reporting a problem can reference it precisely
        $response->withHeader('X-Request-Id', $request->requestId);

        if ($request->isMutating() && $ctx->isAuthenticated()) {
            $ctx->app->logger()->info('Data-changing request', [
                'request_id' => $request->requestId,
                'method' => $request->method,
                'path' => $request->path,
                'status' => $response->status(),
                'user' => $ctx->username(),
                'ip' => $request->ip,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);
        }

        return $response;
    }
}
