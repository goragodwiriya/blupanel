<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\RollbackGuard;

/**
 * คืนค่ารายการที่หมดเวลายืนยัน
 *
 * ถูกเรียกสองทาง: จาก cron ของ panel ทุกนาที และเมื่อหน้าเว็บโหลดแล้วพบว่ามีรายการค้าง
 * ทางแรกจำเป็นเพราะกรณีที่ต้องกู้คือกรณีที่ผู้ใช้หลุดไปแล้ว — จะไม่มีคำขอเข้ามาให้กระตุ้น
 */
final class RollbackRun implements Capability
{
    public static function name(): string
    {
        return 'rollback.run';
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'คืนค่าการเปลี่ยนแปลงที่หมดเวลายืนยัน';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $done = (new RollbackGuard($context->db))->rollbackExpired($executor);

        return [
            'rolled_back' => $done,
            'count' => count($done),
            'message' => $done === []
                ? 'ไม่มีรายการที่ต้องคืนค่า'
                : sprintf('คืนค่าการเปลี่ยนแปลงที่หมดเวลา %d รายการแล้ว', count($done)),
        ];
    }
}
