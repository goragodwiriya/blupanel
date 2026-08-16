<?php

declare(strict_types=1);

namespace Phpcp\Driver\Db;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Security\Secret;

/**
 * A user's own MariaDB account — the go-between for a real MariaDB user and the db_accounts table
 *
 * Kept separate from any capability, because there are two groups of caller
 * that think about this differently:
 *   - `db.account.*` operates on **the actor's own** account (a user clicking to open phpMyAdmin)
 *   - `db.create` operates on **the website owner's** account, who may not
 *     be the one who clicked create (an admin creating a database for a customer)
 *
 * If this logic lived in the first group's capability base class, the
 * second group's callers would have to inherit from it too and end up with
 * a `currentAccount()` method they should never have — exactly the kind of
 * thing a future reader would most easily misuse by accident.
 */
final class DbAccountManager
{
    /** Long, since nobody ever has to type this password — the panel fills it in entirely */
    private const PASSWORD_LENGTH = 32;

    public function __construct(
        private readonly MariaDbManager $manager,
        private readonly DbAccountRepository $accounts,
        private readonly Secret $secret,
    ) {
    }

    /**
     * This user's account must genuinely exist, and its stored password must match the real one on the machine
     *
     * Idempotent · returns the password only when one was just freshly set
     * (null = the existing one is still valid) — a caller with no need for
     * the password never has to hold that secret in a variable unnecessarily.
     */
    public function ensure(Executor $executor, UserAccount $account): ?string
    {
        $stored = $this->accounts->find($account->userId);

        if ($stored !== null
            && $this->manager->userExists($executor, (string) $stored['mysql_user'], (string) $stored['host'])
        ) {
            return null;
        }

        return $this->rotate($executor, $account);
    }

    /**
     * Sets a fresh password for the account, creating the user first if it doesn't exist yet
     *
     * Doesn't use `CREATE USER IF NOT EXISTS`, because this has to handle
     * the case where the row in panel.db is gone but the MariaDB user still
     * exists (e.g. an old database was restored) — the outcome that
     * actually matters is "the stored password matches the real one",
     * regardless of which state it started from.
     */
    public function rotate(Executor $executor, UserAccount $account): string
    {
        $password = $this->randomPassword();
        $host = 'localhost';

        if ($this->manager->userExists($executor, $account->username, $host)) {
            $this->manager->setPassword($executor, $account->username, $host, $password);
        } else {
            $this->manager->createUser($executor, $account->username, $host, $password);
        }

        $this->accounts->store(
            $account->userId,
            $account->username,
            $host,
            $this->secret->encrypt($password),
        );

        return $password;
    }

    /**
     * Adjusts server-level privileges to match the user's current role
     *
     * **Must be called every time credentials are issued, not just at
     * account creation** — a role can change later, and if privileges were
     * only ever granted at creation, an admin demoted to a customer would
     * keep the ability to see every database forever — the same class of
     * bug that once happened with quota (state derived from a role, but
     * never re-derived when the role changes).
     *
     * An admin gets machine-wide privileges so nobody ever has to set a
     * password on MariaDB's own root account, which uses `unix_socket` and
     * can only ever be touched by the operating system's own root.
     */
    public function syncPrivileges(Executor $executor, UserAccount $account, bool $isAdmin): void
    {
        $stored = $this->accounts->find($account->userId);

        if ($stored === null) {
            return;
        }

        $this->manager->setGlobalPrivileges(
            $executor,
            (string) $stored['mysql_user'],
            (string) $stored['host'],
            $isAdmin,
        );
    }

    /**
     * The password this account genuinely logs in with — created if it doesn't exist yet
     *
     * @return array{user:string,host:string,password:string,provisioned:bool}
     */
    public function credentials(Executor $executor, UserAccount $account): array
    {
        $fresh = $this->ensure($executor, $account);

        if ($fresh !== null) {
            return [
                'user' => $account->username,
                'host' => 'localhost',
                'password' => $fresh,
                'provisioned' => true,
            ];
        }

        $stored = $this->accounts->find($account->userId);

        return [
            'user' => (string) $stored['mysql_user'],
            'host' => (string) $stored['host'],
            'password' => $this->secret->decrypt((string) $stored['password_enc']),
            'provisioned' => false,
        ];
    }

    /**
     * Grants this account access to a database just created
     *
     * Must happen every time a new database is created, not when phpMyAdmin
     * is opened — otherwise a user would open phpMyAdmin and not see the
     * database they just created a moment ago, which looks like the system is broken.
     */
    public function grantDatabase(Executor $executor, UserAccount $account, string $database): void
    {
        $this->manager->grant($executor, $database, $account->username, 'localhost', 'full');
    }

    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
