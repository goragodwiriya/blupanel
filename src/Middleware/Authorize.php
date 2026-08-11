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
 * ตรวจ permission ของเส้นทาง — ชั้นที่ 1 ของการตรวจสองชั้น
 *
 * ชั้นที่ 2 อยู่ที่ agent (Dispatcher) ซึ่งตรวจซ้ำอีกรอบ
 * ที่ต้องมีสองชั้นเพราะชั้นนี้กันคนเปิด URL ตรง ๆ ส่วนชั้นนั้นกันกรณีที่ชั้นนี้มีบั๊ก
 *
 * ผลลัพธ์ที่ต้องการตาม PROMPT.md: ผู้ดูแลเว็บไซต์เปิด /server/services ตรง ๆ ต้องได้ 403
 * ไม่ใช่แค่มองไม่เห็นเมนู
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
            return ApiProblem::Forbidden->response('คุณไม่มีสิทธิ์เข้าถึงส่วนนี้');
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้'], 403);
        }

        return ErrorPage::response(
            403,
            'ไม่มีสิทธิ์เข้าถึง',
            'บัญชี "'.$ctx->displayName().'" ('
                .Permissions::roleLabel($ctx->role()).') ไม่มีสิทธิ์เข้าถึงหน้านี้',
        );
    }
}
