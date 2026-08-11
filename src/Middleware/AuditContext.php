<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ผูก request id เข้ากับทุกสิ่งที่เกิดขึ้นในคำขอนี้ และบันทึกคำขอที่เปลี่ยนแปลงข้อมูล
 *
 * request id ทำให้ตามรอยได้ว่า audit log หลายบรรทัดมาจากการกดปุ่มครั้งเดียวกัน
 * ซึ่งจำเป็นตอนสืบหาสาเหตุย้อนหลัง เพราะหนึ่งการกระทำอาจเรียก capability หลายตัว
 */
final class AuditContext implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        // ส่ง request id กลับไปด้วยเพื่อให้ผู้ใช้แจ้งปัญหาแล้วอ้างอิงได้ตรงจุด
        $response->withHeader('X-Request-Id', $request->requestId);

        if ($request->isMutating() && $ctx->isAuthenticated()) {
            $ctx->app->logger()->info('คำขอที่เปลี่ยนแปลงข้อมูล', [
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
