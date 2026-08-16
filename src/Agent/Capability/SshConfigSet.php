<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;

/**
 * Edits SSH configuration with an automatic rollback mechanism — ARCHITECTURE §5.4
 *
 * This is the most dangerous change that can be made through the web page:
 * a wrong port, disabling password auth before a key is in place, or the
 * firewall not opening the new port — any of these can lock someone out of the
 * machine for good if there's no other way in.
 *
 * So it never allows "change and done" — it requires confirming the connection
 * still works within a set time. No confirmation = the system reverts it
 * automatically, the same way `netplan try` works for network configuration.
 */
final class SshConfigSet implements Capability
{
    public static function name(): string
    {
        return 'ssh.config_set';
    }

    public function permission(): string
    {
        return 'ssh.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Edit SSH configuration (auto-reverts if not confirmed in time)';
    }

    public function validate(array $args): array
    {
        $changes = [];

        foreach (SshManager::keys() as $key) {
            if (!isset($args[$key]) || $args[$key] === '') {
                continue;
            }

            $changes[$key] = SshManager::assertValue($key, (string) $args[$key]);
        }

        if ($changes === []) {
            throw new ValidationError('No values to change');
        }

        $window = isset($args['window'])
            ? max(30, min(900, (int) $args['window']))
            : RollbackGuard::DEFAULT_WINDOW;

        return ['changes' => $changes, 'window' => $window];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new SshManager();
        $guard = new RollbackGuard($context->db);

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('No SSH configuration file was found on this machine');
        }

        if ($guard->pending() !== null) {
            throw new ValidationError(
                'A change is already waiting for confirmation — confirm it or let it roll back before making another',
            );
        }

        $before = $manager->read($executor);
        $applied = $manager->apply($executor, $args['changes']);

        // Validates the file with sshd itself — revert immediately if it fails, no need to wait out the timer
        [$ok, $output] = $manager->testConfig($executor);

        if (!$ok) {
            $executor->writeFile($executor->path(SshManager::CONFIG), $applied['original'], 0600);

            throw new ExecutionFailed("The generated SSH configuration failed validation and was reverted\n\n" . trim($output));
        }

        $rollbackId = $guard->arm(
            action: 'ssh.config_set',
            description: 'Edit SSH configuration: ' . implode(', ', array_map(
                static fn (string $k, string $v): string => SshManager::label($k) . ' = ' . $v,
                array_keys($args['changes']),
                array_values($args['changes']),
            )),
            files: [SshManager::CONFIG => $applied['original']],
            reloadUnits: ['ssh'],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        // Reloads only after the pending-confirmation record is saved — if the
        // connection drops right here, the record is still in the database and
        // will be reverted once time runs out
        $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'ssh'], timeout: 30);

        return [
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'changes' => $args['changes'],
            'before' => array_map(static fn (array $v): string => $v['value'], $before),
            'message' => sprintf(
                'SSH configuration changed — confirm the connection still works within %d seconds, '
                . 'or the system will automatically revert it',
                $args['window'],
            ),
        ];
    }
}
