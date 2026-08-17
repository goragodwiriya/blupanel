<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * One filtering layer in a request's path
 *
 * The call order is fixed in HttpKernel and matters a great deal: check the
 * rate limit before touching the users table, check the session before
 * checking CSRF, and check CSRF before checking the permission — swap the order and a vulnerability opens immediately
 */
interface Middleware
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, Ctx $ctx, callable $next): Response;
}
