<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupFiles;
use Phpcp\Driver\BackupManager;
use Phpcp\Support\Validator;

/**
 * ลบไฟล์สำรองหนึ่งไฟล์ในโฟลเดอร์ของบัญชี
 *
 * รับ**ชื่อไฟล์** ไม่ใช่รหัสแถวในตาราง เพราะตัวไฟล์คือแหล่งความจริงแล้ว · ลูกค้าลบ
 * ไฟล์เดียวกันนี้เองผ่าน SFTP ได้อยู่แล้ว ปุ่มนี้จึงเป็นแค่ทางที่สะดวกกว่า ไม่ใช่
 * ทางเดียว — และด้วยเหตุนั้น "ไฟล์ไม่อยู่แล้ว" จึงเป็นสภาพปกติ ไม่ใช่ข้อผิดพลาดของระบบ
 *
 * ขอบเขตการลบถูกจำกัดสองชั้น: ชื่อต้องเป็นชื่อล้วน (`BackupFiles::assertName()`) และ
 * เส้นทางที่คลาย symlink แล้วต้องยังอยู่ใต้โฟลเดอร์ของบัญชีนั้น (`BackupManager::delete()`)
 */
final class BackupDelete extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.delete';
    }

    public function permission(): string
    {
        return 'backup.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ลบไฟล์สำรองในโฟลเดอร์ของบัญชี';
    }

    public function validate(array $args): array
    {
        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'file' => BackupFiles::assertName(Validator::requireString($args, 'file', 255)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $owner = $this->ownerAccount($context, $args['user_id']);
        $path = BackupFiles::resolve($owner, $args['file']);

        $this->assertFileExists($executor, $path);

        (new BackupManager())->delete($executor, $owner->backupDir(), $path);

        return [
            'user_id' => $owner->userId,
            'file' => $args['file'],
            'message' => 'ลบไฟล์สำรอง "' . $args['file'] . '" แล้ว',
        ];
    }
}
