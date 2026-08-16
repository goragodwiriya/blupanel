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
 * Deletes one backup file from an account's own folder
 *
 * Accepts a **filename**, not a table row id, because the file itself is the
 * source of truth now · a customer can already delete this same file themselves
 * over SFTP, so this button is only a more convenient path, not the only one —
 * and because of that, "the file is already gone" is a normal state, not a
 * system error.
 *
 * Deletion is bounded by two layers: the name must be a bare filename
 * (`BackupFiles::assertName()`), and the path, once symlinks are resolved, must
 * still fall under that account's own folder (`BackupManager::delete()`).
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
        return 'Delete a backup file from an account folder';
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
            'message' => 'Deleted backup file "' . $args['file'] . '"',
        ];
    }
}
