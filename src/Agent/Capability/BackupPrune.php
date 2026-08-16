<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * Deletes backup files older than policy allows, from each account's folder
 *
 * **Designed to run with nobody watching** (cron calls it daily), so every rule
 * leans toward "keep it" whenever unsure:
 *
 *   1. **Always keeps the N most recent copies**, even past the day limit — an
 *      account that hasn't backed up in a while must never wake up to find
 *      every backup file deleted because all of them were "older than 30 days"
 *   2. **Counted separately per site and per type** — "keep the 7 most recent"
 *      has to mean 7 copies of that specific thing, not 7 across the whole
 *      account, which would let a frequently backed-up site eat another site's
 *      allowance entirely
 *   3. **Never touches a file that can't be matched to a site** — a file a
 *      customer copied in themselves, or renamed, wasn't created by the
 *      system, so it isn't something the system will delete under its own policy
 *
 * ## Why "does an offsite copy exist yet" is no longer checked
 *
 * That old rule read from the `offsite_status` column in the `backups` table,
 * which stopped being a source of truth (item B4) · the new truth is that
 * **the file lives in the customer's own home**, and they can already delete it
 * themselves at any time — so having the cleanup job trust yesterday's recorded
 * status guarded against nothing real · what actually guards against data loss
 * in the new system is rules 1 and 2, which read from files that genuinely exist right now.
 */
final class BackupPrune extends BackupCapability implements Capability
{
    /** Defaults when not specified */
    private const DEFAULT_DAYS = 30;
    private const DEFAULT_KEEP = 7;

    public static function name(): string
    {
        return 'backup.prune';
    }

    /**
     * A **whole-machine** permission, not one from the Hosting category
     *
     * This walks every account's folder in one pass and deletes customer files
     * according to policy an admin has set · `backup.manage` is a permission a
     * site owner holds themselves — using that permission here would mean a
     * single customer could trigger a machine-wide cleanup cycle.
     */
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
        return 'Delete backup files older than the configured policy';
    }

    public function validate(array $args): array
    {
        return [
            'days' => Validator::optionalInt($args, 'days', self::DEFAULT_DAYS, 0),
            'keep' => Validator::optionalInt($args, 'keep', self::DEFAULT_KEEP, 0),
            // 0 = every account
            'user_id' => Validator::optionalInt($args, 'user_id', 0, 0),
            'dry_run' => (bool) ($args['dry_run'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $accounts = $args['user_id'] > 0
            ? [$this->ownerAccount($context, $args['user_id'])]
            : $this->visibleAccounts($context);

        $manager = new BackupManager();
        $removed = [];
        $kept = 0;

        foreach ($accounts as $account) {
            // Recency rank counted **per call and per account**
            //
            // This used to be a static variable inside the check method, a
            // silently severe bug: the agent is a process that runs for months
            // at a stretch, so the counter would accumulate across calls, and
            // from the second run onward, every group would look like it
            // already had its full quota starting from the very first row
            $seen = [];

            foreach ($this->filesOf($executor, $context, $account) as $file) {
                // A file with no way to tell which site it belongs to = not created by the system, so not the system's to delete
                if ($file['domain'] === '') {
                    $kept++;
                    continue;
                }

                $bucket = $file['type'] . ':' . $file['domain'];
                $seen[$bucket] = ($seen[$bucket] ?? 0) + 1;

                if ($this->keepReason($file, $args['days'], $args['keep'], $seen[$bucket]) !== null) {
                    $kept++;
                    continue;
                }

                if (!$args['dry_run']) {
                    $manager->delete($executor, $account->backupDir(), $file['path']);
                    $this->deleteOffsite($executor, $context, $account, $file['name']);
                }

                $removed[] = [
                    'user_id' => $account->userId,
                    'file' => $file['name'],
                    'bytes' => $file['bytes'],
                    'simulated' => $args['dry_run'],
                ];
            }
        }

        $bytes = array_sum(array_column($removed, 'bytes'));

        return [
            'removed' => $removed,
            'removed_count' => count($removed),
            'kept_count' => $kept,
            'freed_bytes' => $bytes,
            'dry_run' => $args['dry_run'],
            'message' => sprintf(
                $args['dry_run'] ? 'Would delete %d file(s), freeing %s bytes' : 'Deleted %d file(s), freed %s bytes',
                count($removed),
                number_format($bytes),
            ),
        ];
    }

    /**
     * Deletes the offsite copy of a file that was just deleted — the
     * destination name can be computed, never needs to be remembered
     *
     * `backup.push` always names the destination file `<account name>-<filename>`
     * · so the retention policy takes effect in both places with no table
     * needed to remember which file went where — the kind of table that would
     * drift out of sync the moment a customer deleted their own file, the same
     * way the `backups` table already had before.
     *
     * **A failure here never fails the whole cycle** · an unreachable
     * destination lets the cleanup of the remaining accounts continue · a
     * file left behind at the destination wastes space, but never loses anyone's data.
     */
    private function deleteOffsite(Executor $executor, Context $context, UserAccount $account, string $file): void
    {
        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $factory = new DestinationFactory($destinations, $account->backupDir());

        foreach ($destinations->enabled() as $row) {
            try {
                $config = is_array($row['config'] ?? null) ? $row['config'] : [];
                $base = rtrim((string) ($config['path'] ?? ''), '/');
                $name = $account->username . '-' . $file;

                $factory->make($row)->delete($executor, $base === '' ? $name : $base . '/' . $name);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * One account's files, newest first — a folder that can't be read must never fail the whole cycle
     *
     * @return list<array<string,mixed>>
     */
    private function filesOf(Executor $executor, Context $context, UserAccount $account): array
    {
        try {
            return BackupFiles::listFor($executor, $account, $this->domainsOf($context, $account->userId));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The reason this file has to be kept — null means it can be deleted
     *
     * @param array<string,mixed> $file
     * @param int $rank this file's recency rank within its own group — 1 is the newest
     */
    private function keepReason(array $file, int $days, int $keep, int $rank): ?string
    {
        if ($keep > 0 && $rank <= $keep) {
            return 'within the ' . $keep . ' most recent';
        }

        if ($days > 0 && (time() - (int) $file['modified_at']) < $days * 86400) {
            return 'not yet past ' . $days . ' days';
        }

        return null;
    }
}
