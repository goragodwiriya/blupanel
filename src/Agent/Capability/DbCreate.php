<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
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
        return [
            // Left unqualified here on purpose — the owner's prefix is added in
            // run(), which is the only place that knows who the owner is
            'name' => MariaDbManager::assertDatabaseName(Validator::requireString($args, 'name', 64)),
            /*
             * **The dedicated user's name is never accepted from the caller**
             *
             * It used to be a free-text field on the form, which meant two things
             * that both went wrong: a caller could point a new database at an
             * existing MariaDB user belonging to a different customer, and the
             * default (`<name>_user`) was derived from the *unqualified* name —
             * so two customers who each created `shop` both landed on `shop_user`,
             * and the second one's CREATE USER failed with a name they never typed.
             *
             * It is derived from the qualified name instead (see run()).
             */
            'host' => Validator::requireEnum(
                ['host' => $args['host'] ?? 'localhost'],
                'host',
                ['localhost', '127.0.0.1', '%'],
            ),
            'privileges' => MariaDbManager::assertPrivilege(
                Validator::optionalString($args, 'privileges', 'readwrite', 16),
            ),
            /*
             * Empty = generate one · the form now arrives with a generated
             * password already filled in, but it stays *a field*, so an admin
             * moving an existing application onto this panel can keep the
             * password already written into its config file instead of having
             * to edit that file afterward
             */
            'password' => self::assertPassword(Validator::optionalString($args, 'password', '', 128)),
            'site_id' => isset($args['site_id']) ? Validator::requireInt($args, 'site_id', 0) : 0,
            // 0 = no hosting account chosen · the owner is then taken from the
            // site, and failing that the database belongs to the machine itself
            'owner_user_id' => Validator::optionalInt($args, 'owner_user_id', 0, 0),
            'charset' => Validator::optionalString($args, 'charset', 'utf8mb4', 32),
        ];
    }

    /**
     * The hosting account that will own this database — null = the machine's own,
     * not a customer's
     *
     * Decides two things: the prefix the database name gets, and which MariaDB
     * account is granted access to it so phpMyAdmin shows it.
     *
     * The caller may name the account directly (`owner_user_id`, which is what
     * the form sends), or leave it to be taken from the website the database is
     * bound to. Naming it directly is what lets a database exist without being
     * tied to any website at all — a customer's second database for a tool that
     * isn't a site yet still has to belong to somebody.
     */
    private function ownerAccount(Context $context, int $ownerUserId, int $siteId): ?UserAccount
    {
        $row = $ownerUserId > 0
            ? $context->db->first('SELECT * FROM users WHERE id = :id', ['id' => $ownerUserId])
            : ($siteId > 0
                ? $context->db->first(
                    'SELECT u.* FROM sites s JOIN users u ON u.id = s.owner_user_id WHERE s.id = :id',
                    ['id' => $siteId],
                )
                : null);

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
     * Refuses a website that belongs to somebody other than the chosen account
     *
     * Both values come from the same form, so they can disagree — and a database
     * recorded against another customer's website would show up in that
     * customer's list and get backed up on their round, with their quota paying
     * for it.
     *
     * @throws ValidationError
     */
    private function assertSiteBelongsTo(Context $context, int $siteId, ?UserAccount $owner): void
    {
        if ($siteId <= 0) {
            return;
        }

        $siteOwner = (int) $context->db->value(
            'SELECT owner_user_id FROM sites WHERE id = :id',
            ['id' => $siteId],
            0,
        );

        if ($owner === null || $siteOwner !== $owner->userId) {
            throw new ValidationError('The chosen website does not belong to that account');
        }
    }

    /**
     * The name of this database's own dedicated MariaDB user
     *
     * Derived from the **qualified** database name, so two customers who each
     * create `shop` get `alice_shop_user` and `bob_shop_user` instead of both
     * reaching for `shop_user` — where the second one would either fail with a
     * name they never typed, or (worse, had CREATE USER been forgiving) hand a
     * second customer's database to the first customer's user.
     *
     * MariaDB caps a username at 32 characters, so long names get trimmed, and a
     * trim can collide again. A name already taken on the machine therefore gets
     * a short random suffix rather than being reused — reuse is the one outcome
     * that silently crosses the line between two customers.
     */
    private function dedicatedUser(Executor $executor, string $database, string $host): string
    {
        $manager = $this->manager();
        $base = substr(preg_replace('/_db$/', '', $database) . '_user', 0, 32);

        if (!$manager->userExists($executor, $base, $host)) {
            return MariaDbManager::assertUserName($base);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = substr($base, 0, 32 - 7) . '_' . bin2hex(random_bytes(3));

            if (!$manager->userExists($executor, $candidate, $host)) {
                return MariaDbManager::assertUserName($candidate);
            }
        }

        throw new ValidationError('A free name for the database user could not be found — try a different database name');
    }

    /**
     * A customer may only ever choose themselves as the owner
     *
     * The account list the form shows is already narrowed down, but that list is
     * a convenience for the person filling in the form — it is not a gate. The
     * gate is here, where a customer who edits the request by hand and names
     * somebody else's account gets refused.
     *
     * @throws PermissionDenied
     */
    private function assertOwnerAccess(Context $context, ?UserAccount $owner): void
    {
        $actor = $context->actor;

        if ($owner === null
            || $actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        if ($owner->userId !== $actor->userId) {
            throw new PermissionDenied('A database can only be created for your own account');
        }
    }

    /**
     * Checks the owning account's quota before creating a database
     *
     * Used to be looked up from the website, which meant a database created
     * without one skipped the check entirely — a customer could fill the machine
     * with databases as long as they never bound any of them to a site.
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, ?UserAccount $owner): void
    {
        // Not a customer's database — the machine's own quota is the disk itself
        if ($owner === null) {
            return;
        }

        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($owner->userId, 'database', 1);
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

        // A customer's database is always prefixed with their account name —
        // MariaDB has one single namespace across the whole machine, so two
        // different customers couldn't both name a database `shop` without a
        // prefix (a database an admin creates for the machine itself, with no
        // account chosen, is left untouched)
        $owner = $this->ownerAccount($context, $args['owner_user_id'], $args['site_id']);

        $this->assertOwnerAccess($context, $owner);
        $this->assertSiteBelongsTo($context, $args['site_id'], $owner);

        // Checked against the account, not the website — a database that belongs
        // to an account but no site still counts against that account's quota
        $this->assertQuota($context, $owner);

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

        $args['username'] = $this->dedicatedUser($executor, $args['name'], $args['host']);
        $password = $args['password'] !== '' ? $args['password'] : self::randomPassword();

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

        $this->record($context, $args, $owner?->userId);
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
    private function record(Context $context, array $args, ?int $ownerUserId): void
    {
        $context->db->transaction(static function ($db) use ($args, $ownerUserId): void {
            $databaseId = $db->insert('databases_', [
                'db_name' => $args['name'],
                'site_id' => $args['site_id'] > 0 ? $args['site_id'] : null,
                'owner_user_id' => $ownerUserId,
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
