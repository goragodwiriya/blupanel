<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\UserAccount;
use Phpcp\Support\Validator;

/**
 * The backup file list — read from the customer's real folder
 *
 * ## Why this has to go through the agent when it's just listing filenames
 *
 * `<home>/backup` belongs to the customer at mode 0750 — the web process
 * (www-data) can only read it once ownership and group are set up correctly ·
 * an account that was never re-provisioned, or a machine with `shared_owner`
 * set, would fail to read it and the screen would say "no backup files" even
 * though every one of them is there — a wrong answer that looks like a right one
 * · the agent runs as root, so it always sees the truth regardless of who owns
 * the file (the same reason as `file.list`).
 */
final class BackupList extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.list';
    }

    public function permission(): string
    {
        return 'backup.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read the backup file list from each account\'s folder';
    }

    public function validate(array $args): array
    {
        return [
            // 0 = every account the caller has permission to see
            'user_id' => Validator::optionalInt($args, 'user_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $accounts = $args['user_id'] > 0
            ? [$this->ownerAccount($context, $args['user_id'])]
            : $this->visibleAccounts($context);

        $files = [];
        $bytes = 0;

        foreach ($accounts as $account) {
            foreach ($this->filesOf($executor, $context, $account) as $file) {
                $files[] = $file;
                $bytes += $file['bytes'];
            }
        }

        // Newest first across accounts — an admin's screen sorts everything together, machine-wide
        usort($files, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return [
            'files' => $files,
            'count' => count($files),
            'bytes' => $bytes,
        ];
    }

    /**
     * One account's files, with the data the screen needs
     *
     * A folder that fails to read must never fail the whole list — one account
     * with broken permissions would leave the admin unable to see any other
     * account's backup files on the whole machine, which is worse than missing
     * one account's worth · so the error stays attached to that account's own row instead.
     *
     * @return list<array<string,mixed>>
     */
    private function filesOf(Executor $executor, Context $context, UserAccount $account): array
    {
        try {
            $files = BackupFiles::listFor($executor, $account, $this->domainsOf($context, $account->userId));
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $file): array => $file + [
                'user_id' => $account->userId,
                'username' => $account->username,
            ],
            $files,
        );
    }
}
