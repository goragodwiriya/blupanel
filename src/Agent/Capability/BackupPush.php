<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ส่งไฟล์สำรองที่มีอยู่แล้วออกไปยังปลายทางนอกเครื่อง — PLAN-V2 เฟส E1
 *
 * **ตรวจ checksum ก่อนส่งเสมอ** · การส่งไฟล์ที่เสียแล้วออกไปเก็บไว้ ทำให้ผู้ดูแลมี
 * "ไฟล์สำรองนอกเครื่อง" ที่กู้ไม่ได้จริง ซึ่งแย่กว่าไม่มีไฟล์นั้นเลย เพราะมันปิดโอกาส
 * ที่จะมีใครสังเกตว่าระบบสำรองพังอยู่
 *
 * **จำกัดที่ผู้ดูแลระดับเซิร์ฟเวอร์** — ปลายทางเป็นทรัพยากรของทั้งเครื่อง และการเลือก
 * ปลายทางได้เองเท่ากับเลือกได้ว่าจะส่งข้อมูลของเว็บไซต์ออกไปที่ไหน
 */
final class BackupPush implements Capability
{
    public static function name(): string
    {
        return 'backup.push';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ส่งไฟล์สำรองออกไปเก็บที่ปลายทางนอกเครื่อง';
    }

    public function validate(array $args): array
    {
        return [
            'backup_id' => Validator::requireInt($args, 'backup_id', 1),
            'destination_id' => Validator::requireInt($args, 'destination_id', 1),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)
            && $context->actor->userId !== 0) {
            throw new PermissionDenied('การส่งไฟล์สำรองออกนอกเครื่องต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        $backup = $context->db->first('SELECT * FROM backups WHERE id = :id', ['id' => $args['backup_id']]);

        if ($backup === null) {
            throw new ValidationError('ไม่พบไฟล์สำรองที่ระบุ');
        }

        if (($backup['status'] ?? '') !== 'ok') {
            throw new ValidationError('ไฟล์สำรองนี้สร้างไม่สำเร็จ จึงส่งออกไม่ได้');
        }

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $destinations->find($args['destination_id']);

        if ($row === null) {
            throw new ValidationError('ไม่พบปลายทางที่ระบุ');
        }

        if ((int) ($row['enabled'] ?? 0) !== 1) {
            throw new ValidationError('ปลายทางนี้ถูกปิดใช้งานอยู่');
        }

        // ตรวจก่อนส่ง — ไฟล์เสียที่ถูกส่งออกไปคือไฟล์สำรองปลอมที่ไม่มีใครรู้ว่าปลอม
        (new BackupManager($context->config->paths->backups()))
            ->assertIntact($executor, (string) $backup['path'], (string) ($backup['checksum'] ?? ''));

        $destination = (new DestinationFactory($destinations, $context->config->paths->backups()))->make($row);
        $remoteName = basename((string) $backup['path']);

        try {
            $remotePath = $destination->push($executor, (string) $backup['path'], $remoteName);
        } catch (\Throwable $e) {
            $context->db->update('backups', [
                'offsite_status' => 'failed',
                'offsite_error' => mb_substr($e->getMessage(), 0, 500),
                'destination_id' => $args['destination_id'],
            ], ['id' => $args['backup_id']]);

            $destinations->recordResult($args['destination_id'], false, $e->getMessage());

            throw $e instanceof ExecutionFailed ? $e : new ExecutionFailed($e->getMessage());
        }

        $context->db->update('backups', [
            'destination_id' => $args['destination_id'],
            'remote_path' => $remotePath,
            'offsite_status' => 'ok',
            'offsite_at' => time(),
            'offsite_error' => null,
        ], ['id' => $args['backup_id']]);

        $destinations->recordResult($args['destination_id'], true);

        return [
            'backup_id' => $args['backup_id'],
            'destination_id' => $args['destination_id'],
            'destination' => $row['name'],
            'remote_path' => $remotePath,
            'bytes' => (int) $backup['size_bytes'],
            'message' => sprintf('ส่งไฟล์สำรองไปที่ "%s" แล้ว', $row['name']),
        ];
    }
}
