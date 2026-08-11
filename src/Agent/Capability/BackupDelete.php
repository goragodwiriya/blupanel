<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/** ลบไฟล์สำรอง — ลบได้เฉพาะไฟล์ในไดเรกทอรีสำรองเท่านั้น */
final class BackupDelete implements Capability
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
        return 'ลบไฟล์ข้อมูลสำรอง';
    }

    public function validate(array $args): array
    {
        return ['backup_id' => Validator::requireInt($args, 'backup_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $backup = $context->db->first('SELECT * FROM backups WHERE id = :id', ['id' => $args['backup_id']]);

        if ($backup === null) {
            throw new ValidationError('ไม่พบข้อมูลสำรองที่ระบุ');
        }

        $siteId = (int) ($backup['site_id'] ?? 0);
        $actor = $context->actor;

        if ($siteId > 0
            && $actor->userId !== 0
            && !in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)
            && !(new SiteRepository($context->db))->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์ลบข้อมูลสำรองนี้');
        }

        (new BackupManager($context->config->paths->backups()))->delete($executor, (string) $backup['path']);
        $context->db->run('DELETE FROM backups WHERE id = :id', ['id' => (int) $backup['id']]);

        return [
            'backup_id' => (int) $backup['id'],
            'message' => 'ลบข้อมูลสำรอง "' . $backup['name'] . '" แล้ว',
        ];
    }
}
