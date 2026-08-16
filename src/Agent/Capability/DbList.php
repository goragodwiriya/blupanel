<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Security\Permissions;

/**
 * The database list, with real users and sizes — read-only
 *
 * Merges two sources and states clearly which is which:
 *   panel  — rows in databases_ that the panel itself created and bound to a website
 *   server — databases that genuinely exist on MariaDB
 *
 * A database that exists on the machine but is unknown to the panel gets flagged
 * "not managed by the panel" instead of hidden — an admin should see the whole
 * truth of the machine.
 */
final class DbList extends DbCapability
{
    public static function name(): string
    {
        return 'db.list';
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
        return 'Read the list of databases and users';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();
        $installed = $manager->isInstalled($executor);

        $sizes = [];
        $onServer = [];
        $error = '';

        if ($installed) {
            try {
                $sizes = $this->cachedSizes($executor, $context);
                $onServer = $manager->databases($executor);
            } catch (\Throwable $e) {
                // Couldn't reach MariaDB — the panel's own data can still be shown, but this must be reported
                $error = $e->getMessage();
            }
        }

        $isAdmin = in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);

        /*
         * `databases_` has no `owner_user_id` column at all — only a nullable
         * `site_id`. A database created without a website link (the "Not
         * linked to a website" choice the create form deliberately offers)
         * is real and owned by whoever created it, but has no site to derive
         * that ownership from.
         *
         * Found on a real report (2026-08-16): a webadmin's own unlinked
         * database was completely invisible in this list, while the "Open
         * phpMyAdmin" button still opened it fine — because that button
         * checks ownership through a different path entirely
         * (`db.account_credentials`, which resolves access via the actor's
         * own `db_accounts` row and its MariaDB grants, never touching
         * `sites`). This EXISTS clause matches that same path: a database
         * this actor's own dedicated MariaDB user has been granted access to
         * is theirs, regardless of whether it's linked to a site.
         *
         * `db_accounts.mysql_user` is unique system-wide (SECURITY §2.6 —
         * one dedicated user per database owner, never shared), so matching
         * on username alone here can't accidentally surface another
         * customer's database.
         */
        $where = $isAdmin ? '' : ' WHERE s.owner_user_id = :user
             OR EXISTS (
                 SELECT 1 FROM db_grants g
                 JOIN db_users u ON u.id = g.db_user_id
                 JOIN db_accounts a ON a.mysql_user = u.username
                 WHERE g.db_id = d.id AND a.user_id = :user2
             )';
        $params = $isAdmin ? [] : ['user' => $context->actor->userId, 'user2' => $context->actor->userId];

        $rows = $context->db->all(
            'SELECT d.*, s.primary_domain
             FROM databases_ d LEFT JOIN sites s ON s.id = d.site_id' . $where . '
             ORDER BY d.db_name',
            $params,
        );

        // Fetches every user once, then groups by db_id — avoids N+1 when there are many databases
        $usersByDb = $this->usersByDatabase($context);

        $databases = [];
        $known = [];

        foreach ($rows as $row) {
            $name = (string) $row['db_name'];
            $dbId = (int) $row['id'];
            $known[] = $name;

            $databases[] = [
                'name' => $name,
                'site_id' => (int) ($row['site_id'] ?? 0),
                'site' => $row['primary_domain'] ?? null,
                'charset' => $row['charset'] ?? 'utf8mb4',
                'size' => $sizes[$name] ?? (int) $row['size_bytes'],
                'created_at' => (int) $row['created_at'],
                'exists_on_server' => !$installed || in_array($name, $onServer, true),
                'managed' => true,
                'users' => $usersByDb[$dbId] ?? [],
            ];
        }

        // Databases that exist on the machine but the panel doesn't know about — shown to the admin too
        if ($isAdmin) {
            foreach ($onServer as $name) {
                if (in_array($name, $known, true)) {
                    continue;
                }

                $databases[] = [
                    'name' => $name,
                    'site_id' => 0,
                    'site' => null,
                    'charset' => '',
                    'size' => $sizes[$name] ?? 0,
                    'created_at' => 0,
                    'exists_on_server' => true,
                    'managed' => false,
                    'users' => [],
                ];
            }
        }

        usort($databases, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'databases' => $databases,
            'total' => count($databases),
            'installed' => $installed,
            'error' => $error,
            'total_size' => array_sum(array_column($databases, 'size')),
        ];
    }

    /**
     * Every database's users, in one query
     *
     * @return array<int, list<array<string,mixed>>>
     */
    private function usersByDatabase(Context $context): array
    {
        $rows = $context->db->all(
            'SELECT g.db_id, u.username, u.host, g.privileges
             FROM db_grants g JOIN db_users u ON u.id = g.db_user_id
             ORDER BY u.username',
        );

        $grouped = [];
        foreach ($rows as $row) {
            $dbId = (int) $row['db_id'];
            $grouped[$dbId][] = [
                'username' => $row['username'],
                'host' => $row['host'],
                'privileges' => $row['privileges'],
            ];
        }

        return $grouped;
    }
}
