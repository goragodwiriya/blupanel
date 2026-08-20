<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * Deletes a hosting account, with a separate say over its files and its databases
 *
 * ## Why this capability had to exist at all
 *
 * Deleting a user used to be a bare `DELETE FROM users` in the web tier that
 * never went near the agent. Everything the account owned on the actual machine
 * outlived it: the Linux account, the home directory with every file in it, the
 * MariaDB databases, and the phpMyAdmin login. Nothing said so, and nothing
 * cleaned it up later.
 *
 * The part that made it dangerous rather than merely untidy: creating the same
 * username again **adopts all of it**. `AccountProvisioner` proves ownership of
 * a system account from the signature the panel stamps into `/etc/passwd`, so
 * the leftover account passes that check, the same uid comes back, and the new
 * customer signs in over SFTP into the previous customer's files.
 *
 * ## Why the choice is two switches and not one
 *
 * Deleting an account is not one intent. The three real ones need different
 * combinations, and a single "also delete the data?" cannot express them:
 *
 *   - **Finished with the customer** — delete both.
 *   - **Renaming an account** — keep both, create the new name, move the files
 *     across with the file manager (which runs as root and can cross homes).
 *   - **Reinstalling on top of the same data** — delete the files, keep the
 *     databases · a fresh application on the data that was already there.
 *
 * ## What "keep" means, precisely
 *
 * **Kept files keep the Linux account too, and that is the feature.** The name
 * stays taken, which is the only thing standing between those files and the
 * next person to be given that username — `CustomerCreate` refuses it while the
 * leftovers are there and says where they are. Want the name back? Delete with
 * the files. Want the files under a different name? The file manager moves them.
 *
 * **Kept databases keep their dedicated MariaDB users, passwords intact** —
 * that is the whole point of the reinstall case: whatever is in `wp-config.php`
 * still connects. `databases_.owner_user_id` becomes NULL through the foreign
 * key, so they show up as belonging to the machine rather than to a customer
 * who no longer exists.
 *
 * ## Nothing here is destroyed on the spot
 *
 * Files move to the holding area (`SiteDelete` does the same, for the same
 * reason), and each database is dumped before it is dropped — by `db.drop`,
 * which already refuses to drop anything it could not back up first. The dumps
 * land in the account's own backup folder, so they must run **before** the home
 * is moved: then they travel to the holding area along with everything else,
 * and one folder holds the entire account.
 */
final class CustomerDelete extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.delete';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Delete a hosting account, its files and its databases';
    }

    public function validate(array $args): array
    {
        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            // Typed by hand, matched against the real name — the same second
            // layer `site.delete` and `db.drop` use · a list row is easy to
            // misclick, and this command reaches the machine
            'confirm_username' => Validator::requireString($args, 'confirm_username', 32),
            /*
             * **Both default to false — keeping data is the safe default**
             *
             * An API caller who does not think about the question gets the
             * outcome that can still be corrected. The screen asks outright and
             * sends both explicitly, so the default is never what an admin
             * actually gets.
             */
            'delete_files' => self::flag($args, 'delete_files'),
            'delete_databases' => self::flag($args, 'delete_databases'),
        ];
    }

    /** Accepts the shapes a checkbox arrives in — `"1"`, `true`, `1` — and nothing else */
    private static function flag(array $args, string $key): bool
    {
        $value = $args[$key] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        // loadHostingAccount() is the permission boundary: role=webadmin only,
        // so `customer.manage` can never reach an admin account through here
        $row = $this->loadHostingAccount($context, $args['user_id']);
        $username = (string) $row['username'];

        if ($args['confirm_username'] !== $username) {
            throw new ValidationError(
                'The confirmation username does not match the account being deleted — deletion cancelled for safety',
            );
        }

        /*
         * A website still standing means files being served, a vhost, an FPM
         * pool and a certificate — none of which this command knows how to take
         * down · `site.delete` does, and it has its own confirmation
         */
        $sites = $context->db->all(
            'SELECT primary_domain FROM sites WHERE owner_user_id = :u ORDER BY primary_domain',
            ['u' => $args['user_id']],
        );

        if ($sites !== []) {
            throw new ValidationError(sprintf(
                'This account still owns %d website(s) (%s) — delete them or move them to another owner first',
                count($sites),
                implode(', ', array_column($sites, 'primary_domain')),
            ));
        }

        $account = UserAccount::fromRow($row);

        // Databases first, while the home is still where the dumps go
        $databases = $this->databaseNames($context, $args['user_id']);
        $dropped = [];
        $failed = [];

        if ($args['delete_databases']) {
            [$dropped, $failed] = $this->dropDatabases($executor, $context, $databases);
            $this->dropPanelDbAccount($executor, $context, $account);
        }

        $trash = '';

        if ($args['delete_files']) {
            $trash = $this->moveHomeToTrash($executor, $account);

            // Only after the files are safely moved — a userdel that ran first
            // and then failed to move anything would leave a home owned by a
            // uid with no name, which the file manager cannot chown back
            $this->provisioner($context)->account()->remove($executor, $account);
        }

        $context->db->run('DELETE FROM users WHERE id = :id', ['id' => $args['user_id']]);

        return [
            'user_id' => $args['user_id'],
            'username' => $username,
            'files_deleted' => $args['delete_files'],
            'trash_path' => $trash,
            'home_kept' => $args['delete_files'] ? '' : $account->home(),
            'databases_deleted' => $args['delete_databases'],
            'databases_dropped' => $dropped,
            'databases_failed' => $failed,
            'databases_kept' => $args['delete_databases'] ? [] : $databases,
            'message' => $this->summarise($username, $args, $trash, $account, $databases, $dropped, $failed),
        ];
    }

    /** @return list<string> */
    private function databaseNames(Context $context, int $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['db_name'],
            $context->db->all(
                'SELECT db_name FROM databases_ WHERE owner_user_id = :u ORDER BY db_name',
                ['u' => $userId],
            ),
        );
    }

    /**
     * Drops each database through `db.drop` itself, rather than reaching for MariaDB directly
     *
     * That capability already holds everything that must not be skipped: the
     * pre-drop dump (and its refusal to drop anything it could not back up),
     * the dedicated user cleanup that spares a user still bound to another
     * database, and the size-cache invalidation. A second copy here would be
     * the copy that goes out of date.
     *
     * Called directly instead of through the Dispatcher on purpose — the
     * audited action is this one, `customer.delete`, and its own entry records
     * every database name. Routing each drop back through the Dispatcher would
     * re-check a permission the caller has already passed and scatter one
     * deletion across a dozen log entries.
     *
     * **One database failing must not abandon the rest half-done.** The account
     * row is going away either way; a database left behind because the one
     * before it failed would be a database nobody can find an owner for.
     *
     * @param list<string> $databases
     * @return array{0:list<string>,1:list<array{name:string,reason:string}>}
     */
    private function dropDatabases(Executor $executor, Context $context, array $databases): array
    {
        $capability = new DbDrop();
        $dropped = [];
        $failed = [];

        foreach ($databases as $name) {
            try {
                $capability->run(
                    $capability->validate(['name' => $name, 'confirm' => $name, 'drop_user' => true]),
                    $executor,
                    $context,
                );

                $dropped[] = $name;
            } catch (\Throwable $e) {
                $failed[] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return [$dropped, $failed];
    }

    /**
     * The account's phpMyAdmin login — the panel's own MariaDB user for this customer
     *
     * Its `db_accounts` row disappears with the users row through the foreign
     * key, but the MariaDB user itself would outlive both, holding grants on
     * databases that are now gone.
     *
     * Removed only when the databases go too. When they are kept, the grants
     * are still worth something: an admin can hand that same account to whoever
     * takes the data over, and `DbAccountManager::ensure()` will set a fresh
     * password on it the moment somebody does.
     *
     * Never fails the command — the account row is the thing being deleted, and
     * a leftover MariaDB user with no databases left to see is untidy, not unsafe.
     */
    private function dropPanelDbAccount(Executor $executor, Context $context, UserAccount $account): void
    {
        try {
            $manager = new MariaDbManager();

            if ($manager->isInstalled($executor) && $manager->userExists($executor, $account->username, 'localhost')) {
                $manager->dropUser($executor, $account->username, 'localhost');
            }
        } catch (\Throwable) {
            // Untidy, not unsafe
        }
    }

    /**
     * The home goes to the holding area, never straight to `rm -rf`
     *
     * Same rule and same folder as `SiteDelete::moveToTrash()`: an admin who
     * confirmed a deletion and then realised it was the wrong account has a
     * folder to go and get, right up until the holding area is cleared.
     *
     * Named for the account rather than a domain, since an account can be
     * deleted having never had one.
     */
    private function moveHomeToTrash(Executor $executor, UserAccount $account): string
    {
        $source = $executor->path($account->home());

        if (!$executor->exists($source)) {
            return '';
        }

        $target = $executor->path(
            '/var/lib/phpcp/trash/user-' . $account->username . '-' . date('Ymd-His'),
        );

        $executor->makeDirectory(dirname($target), 0750);

        if (!@rename($source, $target)) {
            // rename cannot cross a filesystem boundary — mv handles that case
            $executor->exec(['/usr/bin/mv', $source, $target], timeout: 300);
        }

        return $target;
    }

    private function provisioner(Context $context): \Phpcp\Driver\SiteProvisioner
    {
        return SiteCapability::provisionerFor($context);
    }

    /**
     * One sentence saying what is gone and what is still there
     *
     * The half that was kept matters more than the half that was deleted: it is
     * the half somebody has to come back for, and the only moment they are
     * certain to read about it is now.
     *
     * @param array<string,mixed> $args
     * @param list<string> $databases
     * @param list<string> $dropped
     * @param list<array{name:string,reason:string}> $failed
     */
    private function summarise(
        string $username,
        array $args,
        string $trash,
        UserAccount $account,
        array $databases,
        array $dropped,
        array $failed,
    ): string {
        $parts = ["Deleted hosting account {$username}"];

        $parts[] = $args['delete_files']
            ? ($trash === ''
                ? 'it had no files on disk'
                : 'its files moved to the holding area at ' . $trash . ', recoverable until cleared')
            : 'its files kept at ' . $account->home() . ' — the system account stays, so the name is still taken';

        if ($databases !== []) {
            $parts[] = $args['delete_databases']
                ? sprintf(
                    'dropped %d database(s) after backing each one up%s',
                    count($dropped),
                    $failed === [] ? '' : sprintf(' · %d could not be dropped: %s', count($failed), implode(', ', array_column($failed, 'name'))),
                )
                : sprintf('kept %d database(s) with their own users and passwords: %s', count($databases), implode(', ', $databases));
        }

        return implode(' · ', $parts);
    }
}
