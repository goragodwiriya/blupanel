<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Kernel\Db;

/**
 * Confirm within a window, or the previous state comes back — ARCHITECTURE §5.4
 *
 * Used for changes that could cut off their own access: the SSH port,
 * turning off password auth, a firewall rule, turning the firewall on for
 * the first time.
 *
 * Order:
 *   1. Back up the current config + record a restore deadline
 *   2. Write the new value + apply it
 *   3. User confirms within the window → the pending entry is deleted, treated as permanent
 *   4. Not confirmed in time → the entry expires, the system restores the previous state automatically
 *
 * The timer has to live on the server, never in the browser, because the
 * exact case this needs to recover from is the user having "already lost
 * the connection" — at which point the browser never gets a chance to run anything.
 *
 * The actual restore is triggered two ways: whenever any request comes in
 * and finds an expired entry, and when `phpcp rollback:run` is called from
 * the panel's cron — the second path is necessary because if the user
 * genuinely lost the connection, no request would ever come in to trigger it at all.
 */
final class RollbackGuard
{
    /** The default window given to confirm — enough to open a new window and try to connect */
    public const DEFAULT_WINDOW = 120;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Records a pending entry, returning the id used to confirm or cancel it
     *
     * @param array<string,string>        $files file path => original content (null-safe via '')
     * @param list<string>                $reloadUnits services that need reloading on restore
     * @param list<array<string,mixed>>   $undo typed reversal operations (see applyUndo)
     */
    public function arm(
        string $action,
        string $description,
        array $files,
        array $reloadUnits,
        int $window = self::DEFAULT_WINDOW,
        int $actorId = 0,
        array $undo = [],
    ): int {
        $window = max(30, min($window, 900));

        return $this->db->insert('pending_rollbacks', [
            'action' => $action,
            'description' => $description,
            'payload_json' => json_encode(
                ['files' => $files, 'units' => $reloadUnits, 'undo' => $undo],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'actor_user_id' => $actorId > 0 ? $actorId : null,
            'created_at' => time(),
            'expires_at' => time() + $window,
        ]);
    }

    /** The user confirmed the connection still works — cancels the restore */
    public function confirm(int $id): array
    {
        $row = $this->db->first('SELECT * FROM pending_rollbacks WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            throw new ValidationError('This pending entry was not found — it may have already been restored after expiring');
        }

        if ((int) $row['expires_at'] <= time()) {
            throw new ValidationError('The confirmation window has expired — the system is restoring the previous state');
        }

        $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => $id]);

        return $row;
    }

    /** @return array<string,mixed>|null the entry still waiting to be confirmed */
    public function pending(): ?array
    {
        return $this->db->first(
            'SELECT * FROM pending_rollbacks WHERE expires_at > :now ORDER BY id DESC LIMIT 1',
            ['now' => time()],
        );
    }

    /** @return list<array<string,mixed>> entries that have expired and need restoring */
    public function expired(): array
    {
        return $this->db->all(
            'SELECT * FROM pending_rollbacks WHERE expires_at <= :now ORDER BY id',
            ['now' => time()],
        );
    }

    /**
     * Restores every entry that has expired
     *
     * @return list<array{id:int,action:string,files:int,undo:int}>
     */
    public function rollbackExpired(Executor $executor): array
    {
        $done = [];

        foreach ($this->expired() as $row) {
            $payload = json_decode((string) $row['payload_json'], true);

            if (!is_array($payload)) {
                $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => (int) $row['id']]);
                continue;
            }

            $restored = 0;
            $undone = 0;

            foreach (($payload['files'] ?? []) as $path => $original) {
                $resolved = $executor->path((string) $path);

                if ($original === null || $original === false) {
                    // This file didn't exist originally — deleting it restores that state
                    if ($executor->exists($resolved)) {
                        $executor->removePath($resolved);
                    }
                } else {
                    $executor->writeFile($resolved, (string) $original, 0644);
                }

                $restored++;
            }

            foreach (($payload['undo'] ?? []) as $operation) {
                if (!is_array($operation)) {
                    continue;
                }

                // A failure here must not stop the loop — the rest of the restore work matters more than halting on the first error
                try {
                    $this->applyUndo($executor, $operation);
                    $undone++;
                } catch (\Throwable) {
                }
            }

            foreach (($payload['units'] ?? []) as $unit) {
                // A failure here must not stop the loop — restoring the files matters more than a successful reload
                $executor->exec(
                    [$executor->path('/usr/bin/systemctl'), 'reload-or-restart', (string) $unit],
                    timeout: 30,
                );
            }

            $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => (int) $row['id']]);

            $done[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'files' => $restored,
                'undo' => $undone,
            ];
        }

        return $done;
    }

    /**
     * Runs a reversal that isn't a file — currently only firewall
     *
     * Some changes don't live in a file that can simply be copied back —
     * firewall rules are the clearest example: ufw keeps state across
     * several files and also has iptables rules loaded into the kernel
     * itself, so reversing it has to go through ufw itself, never by overwriting a file.
     *
     * A value read from the database is validated again with the exact
     * same validator used at creation time — even if someone could edit the
     * database row directly, they still couldn't inject a command through this path.
     *
     * @param array<string,mixed> $operation
     */
    private function applyUndo(Executor $executor, array $operation): void
    {
        $ufw = new UfwDriver();
        $type = (string) ($operation['type'] ?? '');

        switch ($type) {
            case 'ufw.enable':
                $ufw->enable($executor);
                break;

            case 'ufw.disable':
                $ufw->disable($executor);
                break;

            case 'ufw.rule_add':
                $ufw->rule(
                    $executor,
                    (string) ($operation['action'] ?? 'allow'),
                    (string) ($operation['port'] ?? ''),
                    (string) ($operation['protocol'] ?? 'tcp'),
                    (string) ($operation['source'] ?? ''),
                    (string) ($operation['comment'] ?? ''),
                );
                break;

            case 'ufw.rule_remove':
                $ufw->removeRule(
                    $executor,
                    (string) ($operation['action'] ?? 'allow'),
                    (string) ($operation['port'] ?? ''),
                    (string) ($operation['protocol'] ?? 'tcp'),
                    (string) ($operation['source'] ?? ''),
                );
                break;

            default:
                throw new ValidationError("Unrecognized reversal operation type: {$type}");
        }
    }

    /** How many seconds are left to confirm */
    public static function remaining(array $row): int
    {
        return max(0, (int) $row['expires_at'] - time());
    }
}
