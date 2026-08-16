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
 * Tests whether a backup destination genuinely works — PLAN-V2 Phase E1
 *
 * **Tests by writing a real file and reading it back, not just connecting** · the
 * most common problem at first setup is ssh succeeding but having no write
 * permission in that directory, which a "can it connect" test would report as
 * passing, only to actually break at 3am with nobody watching.
 *
 * The result is always recorded to `last_ok_at` / `last_error`, so the screen can
 * say when each destination last worked — a destination silently broken is the
 * root of every problem in this area.
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
        // Writes a real test file to the real destination, so this counts as
        // changing the system — must go through audit and never run for real in dryrun mode
        return true;
    }

    public function summary(): string
    {
        return 'Test that a backup destination genuinely writes and reads back';
    }

    public function validate(array $args): array
    {
        return ['destination_id' => Validator::requireInt($args, 'destination_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            throw new PermissionDenied('Configuring backup destinations requires server admin permission');
        }

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $destinations->find($args['destination_id']);

        if ($row === null) {
            throw new ValidationError('The specified destination was not found');
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
            'message' => sprintf('Destination "%s" writes and reads back normally', $row['name']),
        ];
    }
}
