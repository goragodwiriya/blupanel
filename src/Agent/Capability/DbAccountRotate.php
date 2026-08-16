<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * Rotates the password of one's own MariaDB account
 *
 * This password never passes in front of a user, so rotating it never disrupts
 * anything — unlike changing a regular database password, which means going and
 * editing every affected site's config file afterward.
 *
 * Used when `panel.db` or a key inside `config.php` is suspected to have leaked:
 * after rotating, whatever old password might have leaked along with that file
 * stops working.
 */
final class DbAccountRotate extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.account_rotate';
    }

    public function permission(): string
    {
        return 'db.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Rotate own database account password';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->currentUser($context);
        $account = $this->accountOf($user);

        $accounts = $this->dbAccounts($context);
        $accounts->rotate($executor, $account);
        $accounts->syncPrivileges($executor, $account, $this->isAdmin($user));

        // Never returns the new password — the user doesn't need to know it and
        // has nothing to do with it. Anyone who genuinely needs it should ask
        // through db.account_credentials, the one path that returns the secret.
        return [
            'user' => $account->username,
            'rotated' => true,
            'message' => "Rotated the database account password for {$account->username}",
        ];
    }
}
