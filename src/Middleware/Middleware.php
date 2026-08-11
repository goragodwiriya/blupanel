<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ชั้นกรองหนึ่งชั้นในเส้นทางของคำขอ
 *
 * ลำดับการเรียกกำหนดไว้ตายตัวใน HttpKernel และสำคัญมาก:
 * ตรวจ rate limit ก่อนแตะฐานข้อมูลผู้ใช้ ตรวจ session ก่อนตรวจ CSRF
 * และตรวจ CSRF ก่อนตรวจ permission — สลับลำดับแล้วเปิดช่องโหว่ได้ทันที
 */
interface Middleware
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, Ctx $ctx, callable $next): Response;
}
