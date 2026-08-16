<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;
use Phpcp\Driver\Db\DbAccountManager;
use Phpcp\Security\Secret;

/**
 * The shared base for capabilities managing a user's own MariaDB account
 *
 * **The rule this group of capabilities must never break: only ever works on
 * the actor's own account.** None of them accept `user_id` as an argument at
 * all — every one reads from `$context->actor->userId`, which comes from an
 * already-authenticated session · accepting an id as an argument and checking
 * permission afterward always leaves room for a mistake (forgetting the check,
 * checking the wrong variable, checking after the fact) — having no argument to
 * send in the first place makes that mistake impossible.
 *
 * An admin who needs into a customer's database should use root over the
 * machine's unix_socket, a path that already has its own audit trail — not
 * impersonate the customer's account.
 */
abstract class DbAccountCapability extends DbCapability
{
    protected function dbAccounts(Context $context): DbAccountManager
    {
        return new DbAccountManager(
            $this->manager(),
            new DbAccountRepository($context->db),
            new Secret($context->config->secretKey()),
        );
    }

    /**
     * The row of the actor currently calling
     *
     * @return array<string,mixed>
     *
     * @throws ValidationError
     */
    protected function currentUser(Context $context): array
    {
        $userId = $context->actor->userId;

        if ($userId <= 0) {
            throw new ValidationError('This command must be called as a logged-in user, not as the system');
        }

        $user = (new UserRepository($context->db))->find($userId);

        if ($user === null) {
            throw new ValidationError('The current user was not found');
        }

        return $user;
    }

    /**
     * The calling actor's own system account — the same name used as its MariaDB user
     *
     * @param array<string,mixed> $user
     *
     * @throws ValidationError
     */
    protected function accountOf(array $user): UserAccount
    {
        try {
            return UserAccount::fromRow($user);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * Should this user get machine-wide database privileges?
     *
     * **superadmin only** — bound directly to the role, so a revoked role loses
     * this privilege the moment phpMyAdmin is next opened, unlike handing out a
     * root password, which can never be taken back once given.
     *
     * **Why sysadmin isn't included, despite also being an admin:** sysadmin
     * holds `db.view` but not `db.manage` — granting machine-wide MariaDB
     * privileges would let them do more through phpMyAdmin than the panel itself
     * allows, turning the permission table into decoration · database privileges
     * must never exceed panel privileges, no exceptions.
     *
     * @param array<string,mixed> $user
     */
    protected function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === Permissions::SUPERADMIN;
    }
}
