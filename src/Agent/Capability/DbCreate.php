<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * Creates a database with its own dedicated user — PROMPT.md
 *
 * Always creates a separate user per database, never a shared one, because a
 * website that gets hacked and has its credentials stolen must not be able to
 * read another site's database (SECURITY §2.6).
 *
 * The password is randomly generated and shown exactly once — the panel never
 * stores it, since there's no reason to need it again, and storing a
 * reversible password would only add to the damage if the panel's own database ever leaked.
 */
final class DbCreate extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.create';
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
        return 'Create new database with dedicated user';
    }

    public function validate(array $args): array
    {
        $name = MariaDbManager::assertDatabaseName(Validator::requireString($args, 'name', 64));

        // The default username is inferred from the database name, trimmed to fit the 32-character limit
        $user = Validator::optionalString($args, 'username', '', 32);
        if ($user === '') {
            $user = substr(preg_replace('/_db$/', '', $name) . '_user', 0, 32);
        }

        return [
            'name' => $name,
            'username' => MariaDbManager::assertUserName($user),
            'host' => Validator::requireEnum(
                ['host' => $args['host'] ?? 'localhost'],
                'host',
                ['localhost', '127.0.0.1', '%'],
            ),
            'privileges' => MariaDbManager::assertPrivilege(
                Validator::optionalString($args, 'privileges', 'readwrite', 16),
            ),
            'site_id' => isset($args['site_id']) ? Validator::requireInt($args, 'site_id', 0) : 0,
            'charset' => Validator::optionalString($args, 'charset', 'utf8mb4', 32),
        ];
    }

    /**
     * The account of the site owner this database will be bound to — null = not
     * bound to a customer's website
     *
     * Used both to determine the database name's prefix and to decide who to grant to
     */
    private function ownerAccount(Context $context, int $siteId): ?UserAccount
    {
        if ($siteId <= 0) {
            return null;
        }

        $row = $context->db->first(
            'SELECT u.* FROM sites s JOIN users u ON u.id = s.owner_user_id WHERE s.id = :id',
            ['id' => $siteId],
        );

        if ($row === null || $row['role'] !== Permissions::WEBADMIN || $row['system_user'] === null) {
            return null;
        }

        try {
            return UserAccount::fromRow($row);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Checks the customer's quota before creating a database
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $siteId): void
    {
        if ($siteId <= 0) {
            return; // No website = no quota to check
        }

        // Finds the website's owner
        $site = $context->db->first('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);
        if ($site === null) {
            return; // No owner found
        }

        $ownerUserId = (int) ($site['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            return; // No customer owner
        }

        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, 'database', 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('MariaDB or MySQL is not installed on this machine');
        }

        $this->assertSiteAccess($context, $args['site_id']);
        $this->assertQuota($context, $args['site_id']);

        // A customer's database is always prefixed with their account name —
        // MariaDB has one single namespace across the whole machine, so two
        // different customers couldn't both name a database `shop` without a
        // prefix (a database an admin creates without binding it to a
        // customer's site is left untouched)
        $owner = $this->ownerAccount($context, $args['site_id']);

        if ($owner !== null) {
            $qualified = DbAccountRepository::qualify($owner->username, $args['name']);

            // MariaDB caps a database name at 64 characters, and the prefix eats
            // into that budget too — letting this fail inside
            // assertDatabaseName would give the user a "name too long" message
            // with no idea a prefix they never typed had been added on
            if (strlen($qualified) > 64) {
                $available = 64 - strlen(DbAccountRepository::prefixFor($owner->username));

                throw new ValidationError(
                    "The database name is too long — the system automatically adds the prefix "
                    .DbAccountRepository::prefixFor($owner->username)
                    ." leaving {$available} character(s) for the name itself",
                );
            }

            $args['name'] = MariaDbManager::assertDatabaseName($qualified);
        }

        $existing = $context->db->first('SELECT id FROM databases_ WHERE db_name = :n', ['n' => $args['name']]);
        if ($existing !== null) {
            throw new ValidationError("A database named {$args['name']} already exists");
        }

        if (in_array($args['name'], $manager->databases($executor), true)) {
            throw new ValidationError("A database named {$args['name']} already exists on the machine");
        }

        $password = self::randomPassword();

        // Created on the machine first, then recorded in the panel — if the
        // step on the machine fails, no row is left behind in the panel's own
        // database pointing at something that doesn't actually exist
        $manager->createDatabase($executor, $args['name'], $args['charset']);

        try {
            $manager->createUser($executor, $args['username'], $args['host'], $password);
            $manager->grant($executor, $args['name'], $args['username'], $args['host'], $args['privileges']);

            // A site owner needs to see this database in phpMyAdmin immediately,
            // not the next time they open it — granting only when phpMyAdmin
            // opens instead would mean a user who just finished creating a
            // database opens it and doesn't see what they just made, which
            // looks like the system is broken
            //
            // The database's own dedicated user (whose password was shown once,
            // above) still exists as before, because a customer's application
            // should connect using an account limited to just its own database,
            // not one that sees every database the owner has
            if ($owner !== null) {
                $accounts = $this->dbAccounts($context);
                $accounts->ensure($executor, $owner);
                $accounts->grantDatabase($executor, $owner, $args['name']);
            }
        } catch (\Throwable $e) {
            $manager->dropDatabase($executor, $args['name']);

            throw $e;
        }

        $this->record($context, $args);
        $this->invalidateSizesCache($executor, $context);

        return [
            'name' => $args['name'],
            'username' => $args['username'],
            'host' => $args['host'],
            // Shown exactly once — the panel never stores this password anywhere
            'password' => $password,
            'privileges' => $args['privileges'],
            'message' => "Created database {$args['name']} with user {$args['username']}",
        ];
    }

    /** @param array<string,mixed> $args */
    private function record(Context $context, array $args): void
    {
        $context->db->transaction(static function ($db) use ($args): void {
            $databaseId = $db->insert('databases_', [
                'db_name' => $args['name'],
                'site_id' => $args['site_id'] > 0 ? $args['site_id'] : null,
                'charset' => $args['charset'],
                'size_bytes' => 0,
                'created_at' => time(),
            ]);

            $user = $db->first(
                'SELECT id FROM db_users WHERE username = :u AND host = :h',
                ['u' => $args['username'], 'h' => $args['host']],
            );

            $userId = $user !== null
                ? (int) $user['id']
                : $db->insert('db_users', ['username' => $args['username'], 'host' => $args['host']]);

            $db->insert('db_grants', [
                'db_id' => $databaseId,
                'db_user_id' => $userId,
                'privileges' => $args['privileges'],
            ]);
        });
    }
}
