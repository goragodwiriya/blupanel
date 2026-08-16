<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\Quota;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;

/**
 * Measures each hosting account's real disk usage, and warns when it's
 * approaching its quota (PLAN-V2 Phase E2)
 *
 * **Why this measures at the "account" level, not the "website" level like
 * `disk.usage`:** since migration 0006, disk quota lives on `users` (one
 * account = one home = many sites), and an account's home has files that don't
 * fall under any one site's docroot at all (`tmp/`, `logs/`, `.ssh/`) — running
 * `du` once on the whole home matches the number quota is actually meant to
 * limit, more precisely than summing per-site numbers would.
 *
 * **What's genuinely enforced at this phase:** the `disk_used_mb` figure this
 * class writes is used by
 * {@see \Phpcp\Domain\QuotaChecker::checkOwnerCanCreate()} to block
 * **creating new resources** through the panel once full — an
 * application-level enforcement, not a real filesystem-level one (XFS/ext4
 * project quota), which would block file writes everywhere, including from a
 * customer's own code. That path hasn't been built in this phase — see "What's
 * left" for Phase E2 in PLAN-V2.md for why.
 *
 * **Notifications don't spam:** the "highest level already notified" is kept
 * per account in `disk_quota_state` (through
 * {@see UserRepository::recordDiskQuotaThreshold()}), notifying only when the
 * level goes **higher** than what was already notified — unlike
 * `expiry_notifications`, which notifies once for a fixed value and is done,
 * because disk usage moves up and down constantly, unlike an expiry date.
 */
final class DiskQuotaCheck implements Capability
{
    private const DU = '/usr/bin/du';

    /** An account's home with several sites can be large — needs a ceiling to keep the next run from stacking up in a queue */
    private const TIMEOUT = 120;

    public static function name(): string
    {
        return 'quota.disk_check';
    }

    public function permission(): string
    {
        return 'customer.view';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Measure hosting account disk usage and warn when quota is nearly full';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = new UserRepository($context->db);
        $notifier = new Notifier($context->db);

        $results = [
            'checked' => 0,
            'measured' => 0,
            'failed' => 0,
            'notified' => 0,
            'accounts' => [],
        ];

        foreach ($users->hostingAccounts() as $row) {
            // An account with no site yet has no home to measure (created lazily when the first site is created)
            if (($row['system_user'] ?? null) === null) {
                continue;
            }

            $results['checked']++;
            $userId = (int) $row['id'];
            $account = UserAccount::fromRow($row);

            try {
                $usedMb = $this->measure($executor, $account);
            } catch (\Throwable $e) {
                // One account that can't be measured (folder gone, system user deleted) must never fail the whole cycle
                $results['failed']++;
                $results['accounts'][] = [
                    'user_id' => $userId,
                    'username' => $row['username'],
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $context->db->update('users', ['disk_used_mb' => $usedMb, 'updated_at' => time()], ['id' => $userId]);
            $results['measured']++;

            // disk_quota_mb can be NULL for an account created before this
            // column had a default (ALTER TABLE ADD COLUMN has no DEFAULT) —
            // has to be read as "unlimited", the same way
            // QuotaChecker::diskQuotaExceeded() does, not let (int) null turn
            // into 0, which would flag every account that never had a quota
            // set as "100% full" immediately
            $quotaMb = (int) ($row['disk_quota_mb'] ?? Quota::UNLIMITED);
            $threshold = $this->thresholdFor($usedMb, $quotaMb);
            $previous = $users->diskQuotaThreshold($userId);

            $entry = [
                'user_id' => $userId,
                'username' => $row['username'],
                'disk_used_mb' => $usedMb,
                'disk_quota_mb' => $quotaMb,
                'threshold' => $threshold,
            ];

            // Notifies only when the level rises above the previous run — dropping back below 80% needs no notification (nothing to act on)
            if ($threshold > 0 && $threshold > $previous) {
                $entry['notified'] = $notifier->send(
                    'quota',
                    sprintf('Disk quota for %s reached %d%%', $row['username'], $threshold),
                    sprintf(
                        "Account: %s (%s)\nUsed: %d MB of %d MB (%d%%)\n\n%s",
                        (string) $row['username'],
                        $row['username'],
                        $usedMb,
                        $quotaMb,
                        $threshold,
                        $threshold >= 100
                            ? 'Full — this account can no longer create new resources through the panel until '
                              . 'files are deleted or quota is increased (note: it can still write to existing '
                              . 'files as normal, since this phase does not yet enforce at the real filesystem level)'
                            : 'Nearly full — this is an advance warning before it reaches 100%',
                    ),
                    $threshold >= 100 ? 'danger' : 'warn',
                );
                $results['notified'] += $entry['notified'] ? 1 : 0;
            }

            $users->recordDiskQuotaThreshold($userId, $threshold);
            $results['accounts'][] = $entry;
        }

        $results['message'] = sprintf(
            'Measured disk usage for %d account(s) · notified %d account(s)',
            $results['measured'],
            $results['notified'],
        ) . ($results['failed'] > 0 ? sprintf(' · %d account(s) could not be measured', $results['failed']) : '');

        return $results;
    }

    /** The level used so far, compared to quota — 0 = not yet at 80%, or unlimited */
    private function thresholdFor(int $usedMb, int $quotaMb): int
    {
        if (Quota::isUnlimited($quotaMb)) {
            return 0;
        }

        // A 0 MB quota = no room at all — counts as full the instant any space is used
        if ($quotaMb <= 0) {
            return $usedMb > 0 ? 100 : 0;
        }

        $percent = ($usedMb / $quotaMb) * 100;

        return match (true) {
            $percent >= 100 => 100,
            $percent >= 90 => 90,
            $percent >= 80 => 80,
            default => 0,
        };
    }

    /**
     * An account's home's total size in MB
     *
     * Walks the files under the account owner's own privileges, per
     * ARCHITECTURE §4.4, the same way `DiskUsage` does for a website — root
     * never has to walk into a file tree the user controls themselves.
     */
    private function measure(Executor $executor, UserAccount $account): int
    {
        $path = $executor->path($account->home());

        if (!$executor->exists($path)) {
            throw new \RuntimeException('This account\'s home directory was not found');
        }

        $result = $executor->asUser($account->username, static function () use ($executor, $path): array {
            // -s a single summary total · -k in kilobytes · -x never crosses a filesystem
            $exec = $executor->exec([self::DU, '-sk', '-x', '--', $path], timeout: self::TIMEOUT);

            return ['ok' => $exec->ok(), 'out' => $exec->output(), 'err' => trim($exec->stderr)];
        });

        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException(mb_substr((string) ($result['err'] ?? 'Failed to measure size'), 0, 200));
        }

        $out = (string) ($result['out'] ?? '');

        if (preg_match('/^(\d+)/', $out, $m) !== 1) {
            throw new \RuntimeException('Failed to read du\'s output');
        }

        return (int) ceil(((int) $m[1]) / 1024);
    }
}
