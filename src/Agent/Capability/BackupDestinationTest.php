<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ทดสอบว่าปลายทางสำรองใช้งานได้จริง — PLAN-V2 เฟส E1
 *
 * **ทดสอบด้วยการเขียนไฟล์จริงแล้วอ่านกลับ ไม่ใช่แค่เชื่อมต่อผ่าน** · กรณีที่พบบ่อยที่สุด
 * ตอนตั้งค่าครั้งแรกคือ ssh ผ่านแต่ไม่มีสิทธิ์เขียนในไดเรกทอรีนั้น ซึ่งการทดสอบแบบ
 * "ต่อได้ไหม" จะรายงานว่าผ่าน แล้วไปพังจริงตอนตี 3 ที่ไม่มีใครดู
 *
 * ผลถูกบันทึกลง `last_ok_at` / `last_error` เสมอ เพื่อให้หน้าจอบอกได้ว่าปลายทางไหน
 * ใช้ได้ล่าสุดเมื่อไร — ปลายทางที่เสียเงียบคือรากของปัญหาทั้งหมดในเรื่องนี้
 */
final class BackupDestinationTest implements Capability
{
    public static function name(): string
    {
        return 'backup.destination_test';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        // เขียนไฟล์ทดสอบที่ปลายทางจริง จึงนับว่าเปลี่ยนแปลงระบบ — ต้องเข้า audit
        // และต้องไม่ทำงานจริงในโหมด dryrun
        return true;
    }

    public function summary(): string
    {
        return 'ทดสอบว่าปลายทางสำรองเขียนและอ่านกลับได้จริง';
    }

    public function validate(array $args): array
    {
        return ['destination_id' => Validator::requireInt($args, 'destination_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            throw new PermissionDenied('การตั้งค่าปลายทางสำรองต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $destinations->find($args['destination_id']);

        if ($row === null) {
            throw new ValidationError('ไม่พบปลายทางที่ระบุ');
        }

        $destination = (new DestinationFactory($destinations, $context->config->paths->backups()))->make($row);

        try {
            $details = $destination->test($executor);
        } catch (\Throwable $e) {
            $destinations->recordResult($args['destination_id'], false, $e->getMessage());

            throw $e;
        }

        $destinations->recordResult($args['destination_id'], true);

        return [
            'destination_id' => $args['destination_id'],
            'name' => $row['name'],
            'driver' => $row['driver'],
            'details' => $details,
            'message' => sprintf('ปลายทาง "%s" เขียนและอ่านกลับได้ปกติ', $row['name']),
        ];
    }
}
