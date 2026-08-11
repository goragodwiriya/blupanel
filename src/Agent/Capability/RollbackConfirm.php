<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Support\Validator;

/**
 * ยืนยันว่ายังเชื่อมต่อได้ — ยกเลิกการคืนค่าอัตโนมัติ
 *
 * ข้อเท็จจริงที่ทำให้กลไกนี้ทำงาน: การที่คำขอนี้ "เดินทางมาถึง" ได้
 * คือหลักฐานว่าการเชื่อมต่อยังใช้งานได้จริงหลังการเปลี่ยนแปลง
 */
final class RollbackConfirm implements Capability
{
    public static function name(): string
    {
        return 'rollback.confirm';
    }

    public function permission(): string
    {
        return 'security.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ยืนยันการเปลี่ยนแปลงที่รอการยืนยัน';
    }

    public function validate(array $args): array
    {
        return ['rollback_id' => Validator::requireInt($args, 'rollback_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $row = (new RollbackGuard($context->db))->confirm($args['rollback_id']);

        return [
            'rollback_id' => $args['rollback_id'],
            'action' => $row['action'],
            'message' => 'ยืนยันแล้ว — การเปลี่ยนแปลงถูกบันทึกถาวร ระบบจะไม่คืนค่าเดิม',
        ];
    }
}
