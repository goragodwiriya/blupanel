<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DbAccountRepository;

/**
 * Returns the logged-in user's own MariaDB account, so phpMyAdmin can open without typing a password
 *
 * **This command returns a secret**, so it carries three deliberate restrictions:
 *   1. Accepts no arguments at all — only ever works on the actor's own account,
 *      so there's no way to request someone else's credentials even by tampering with the payload
 *   2. `isMutating() === false`, yet it still creates the account if one doesn't
 *      exist — because having a MariaDB account changes nothing the user can see,
 *      it's just preparing something that should already be there
 *   3. The returned password **is never written to the audit log** — Dispatcher
 *      already masks the argument, and this command's result must never be
 *      stored either, which is exactly why it's a separate capability instead of
 *      being tacked onto db.list
 */
final class DbAccountCredentials extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.account_credentials';
    }

    public function permission(): string
    {
        return 'db.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Request own database account for phpMyAdmin access';
    }

    public function validate(array $args): array
    {
        // Deliberately accepts nothing at all — see DbAccountCapability
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->currentUser($context);
        $account = $this->accountOf($user);
        $isAdmin = $this->isAdmin($user);

        $accounts = $this->dbAccounts($context);
        $credentials = $accounts->credentials($executor, $account);

        // Syncs privileges to match the role **every time credentials are issued**,
        // not just at account creation — an admin demoted to a customer must lose
        // visibility into every database the moment they next open this
        $accounts->syncPrivileges($executor, $account, $isAdmin);

        return $credentials + [
            'prefix' => DbAccountRepository::prefixFor($account->username),
            // Used by the screen to tell the user which privilege level they're about to enter with
            'global_privileges' => $isAdmin,
        ];
    }
}
