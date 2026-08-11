<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ข้อมูลเครื่องและสุขภาพของ panel — `/api/v2/system`
 *
 * `GET /system/health` **ไม่เรียก agent** โดยเจตนา — มันมีไว้ตอบคำถามว่า
 * "ติดต่อ agent ได้ไหม" ถ้าตัวมันเองต้องพึ่ง agent มันจะล้มพร้อมกันแล้วตอบอะไรไม่ได้เลย
 * ในจังหวะที่ต้องการคำตอบที่สุด · SPA เรียกอันนี้เพื่อขึ้นแถบเตือนเมื่อ agent ล่ม
 */
final class SystemController extends ApiController
{
    /** ข้อมูลเครื่อง: distro, kernel, CPU, RAM, เวลาที่เปิดเครื่อง */
    public function info(Request $request): Response
    {
        return $this->ok($this->agent()->data('system.info', [], $this->ctx->actor($request)));
    }

    /** สถานะของ panel เอง — ตอบได้แม้ agent ล่ม */
    public function health(Request $request): Response
    {
        $agent = $this->agent();

        return $this->ok([
            'agent_available' => $agent->isAvailable(),
            'socket' => $agent->socketPath(),
            'mode' => $this->app->config->mode->value,
            'version' => PHPCP_VERSION,
            'time' => time(),
        ]);
    }
}
