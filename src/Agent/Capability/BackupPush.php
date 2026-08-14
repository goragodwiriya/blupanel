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
use Phpcp\Domain\BackupFiles;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ส่งไฟล์สำรองที่มีอยู่แล้วออกไปยังปลายทางนอกเครื่อง
 *
 * **ตรวจ checksum ก่อนส่งเสมอ** · การส่งไฟล์ที่เสียแล้วออกไปเก็บไว้ ทำให้ผู้ดูแลมี
 * "ไฟล์สำรองนอกเครื่อง" ที่กู้ไม่ได้จริง ซึ่งแย่กว่าไม่มีไฟล์นั้นเลย เพราะมันปิดโอกาส
 * ที่จะมีใครสังเกตว่าระบบสำรองพังอยู่ · checksum คำนวณจากไฟล์เดี๋ยวนั้น ไม่ใช่อ่าน
 * จากตาราง — โฟลเดอร์เป็นของลูกค้า ไฟล์ในนั้นเปลี่ยนได้ระหว่างสองครั้งที่เรามอง
 *
 * **จำกัดที่ผู้ดูแลระดับเซิร์ฟเวอร์** — ปลายทางเป็นทรัพยากรของทั้งเครื่อง และการเลือก
 * ปลายทางได้เองเท่ากับเลือกได้ว่าจะส่งข้อมูลของเว็บไซต์ออกไปที่ไหน
 *
 * ชื่อไฟล์ที่ปลายทางเติมชื่อบัญชีนำหน้า — ปลายทางเดียวรับไฟล์จากทุกบัญชีบนเครื่อง
 * และสองบัญชีมีเว็บชื่อเดียวกันไม่ได้ก็จริง แต่ไฟล์ที่ลูกค้าเปลี่ยนชื่อเองแล้วชนกันได้
 */
final class BackupPush extends BackupCapability implements Capability
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
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'file' => BackupFiles::assertName(Validator::requireString($args, 'file', 255)),
            'destination_id' => Validator::requireInt($args, 'destination_id', 1),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!self::isAdmin($context->actor->role) && $context->actor->userId !== 0) {
            throw new PermissionDenied('การส่งไฟล์สำรองออกนอกเครื่องต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        $owner = $this->ownerAccount($context, $args['user_id']);
        $path = BackupFiles::resolve($owner, $args['file']);

        $this->assertFileExists($executor, $path);

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $destinations->find($args['destination_id']);

        if ($row === null) {
            throw new ValidationError('ไม่พบปลายทางที่ระบุ');
        }

        if ((int) ($row['enabled'] ?? 0) !== 1) {
            throw new ValidationError('ปลายทางนี้ถูกปิดใช้งานอยู่');
        }

        $checksum = @hash_file('sha256', $executor->path($path));

        if ($checksum === false) {
            throw new ExecutionFailed('อ่านไฟล์สำรองเพื่อตรวจสอบก่อนส่งไม่ได้');
        }

        // ตรวจซ้ำผ่านด่านเดียวกับที่การกู้คืนใช้ — ไฟล์ที่หายไประหว่างนี้ต้องถูกจับ
        (new BackupManager())->assertIntact($executor, $path, $checksum);

        $destination = (new DestinationFactory($destinations, $owner->backupDir()))->make($row);
        $remoteName = $owner->username . '-' . $args['file'];

        try {
            $remotePath = $destination->push($executor, $path, $remoteName);
        } catch (\Throwable $e) {
            $destinations->recordResult($args['destination_id'], false, $e->getMessage());

            throw $e instanceof ExecutionFailed ? $e : new ExecutionFailed($e->getMessage());
        }

        $destinations->recordResult($args['destination_id'], true);

        $bytes = (int) ($executor->stat($executor->path($path))['size'] ?? 0);

        return [
            'user_id' => $owner->userId,
            'file' => $args['file'],
            'destination_id' => $args['destination_id'],
            'destination' => $row['name'],
            'remote_path' => $remotePath,
            'bytes' => $bytes,
            'message' => sprintf('ส่ง %s ไปที่ "%s" แล้ว', $args['file'], $row['name']),
        ];
    }
}
