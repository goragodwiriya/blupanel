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
 * Pushes an existing backup file out to an offsite destination
 *
 * **Always checks the checksum before sending** · sending a corrupted file out to
 * be stored leaves an admin with an "offsite backup" that can't actually be
 * restored, which is worse than having no file at all, because it closes off any
 * chance of someone noticing the backup system is broken · the checksum is
 * computed from the file right at that moment, not read from the table — the
 * folder belongs to the customer, and the file inside it can change between the
 * two times it's looked at.
 *
 * **Limited to server-level admins** — a destination is a whole-machine resource,
 * and being able to choose one means being able to choose where a website's data
 * gets sent.
 *
 * The destination filename has the account name prepended — one destination
 * receives files from every account on the machine, and while two accounts can't
 * have a site with the same name, files a customer renamed themselves can still collide.
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
        return 'Push backup file to offsite destination';
    }

    public function validate(array $args): array
    {
        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'file' => BackupFiles::assertName(Validator::requireString($args, 'file', 255)),
            // 0 = the single enabled destination · a machine has only one active
            // destination (§4.2), so forcing the caller to specify one would just
            // be asking a question that already has one answer
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!self::isAdmin($context->actor->role)) {
            throw new PermissionDenied('Pushing a backup file offsite requires server admin permission');
        }

        $owner = $this->ownerAccount($context, $args['user_id']);
        $path = BackupFiles::resolve($owner, $args['file']);

        $this->assertFileExists($executor, $path);

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $args['destination_id'] > 0
            ? $destinations->find($args['destination_id'])
            : ($destinations->enabled()[0] ?? null);

        if ($row === null) {
            throw new ValidationError(
                $args['destination_id'] > 0
                    ? 'The specified destination was not found'
                    : 'This machine has no offsite destination configured yet — configure one before pushing files',
            );
        }

        if ((int) ($row['enabled'] ?? 0) !== 1) {
            throw new ValidationError('This destination is currently disabled');
        }

        $destinationId = (int) $row['id'];

        $checksum = @hash_file('sha256', $executor->path($path));

        if ($checksum === false) {
            throw new ExecutionFailed('Failed to read the backup file to verify it before sending');
        }

        // Re-checked through the same gate restore uses — a file that vanished in the meantime must be caught
        (new BackupManager())->assertIntact($executor, $path, $checksum);

        $destination = (new DestinationFactory($destinations, $owner->backupDir()))->make($row);
        $remoteName = $owner->username . '-' . $args['file'];

        try {
            $remotePath = $destination->push($executor, $path, $remoteName);
        } catch (\Throwable $e) {
            $destinations->recordResult($destinationId, false, $e->getMessage());

            throw $e instanceof ExecutionFailed ? $e : new ExecutionFailed($e->getMessage());
        }

        $destinations->recordResult($destinationId, true);

        $bytes = (int) ($executor->stat($executor->path($path))['size'] ?? 0);

        return [
            'user_id' => $owner->userId,
            'file' => $args['file'],
            'destination_id' => $destinationId,
            'destination' => $row['name'],
            'remote_path' => $remotePath,
            'bytes' => $bytes,
            'message' => sprintf('Pushed %s to "%s"', $args['file'], $row['name']),
        ];
    }
}
