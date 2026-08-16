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
 * "not managed by the panel" instead of hidden — an admin sees every such
 * database, a customer sees only the ones their own account's name prefix
 * matches (created outside the panel — phpMyAdmin, the mysql client — against
 * their own dedicated account is still genuinely theirs).
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

        // A database created without a website link (the "Not linked to a
        // website" choice the create form deliberately offers) has no site
        // to derive ownership from — `owner_user_id` is recorded directly on
        // `databases_` at creation for exactly that reason (see migration
        // 0025_database_owner_id.sql for why joining through `sites` alone
        // used to hide these).
        $where = $isAdmin ? '' : ' WHERE d.owner_user_id = :user';
        $params = $isAdmin ? [] : ['user' => $context->actor->userId];

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

        /*
         * Databases that exist on the machine but the panel doesn't know
         * about — an admin sees every one. A non-admin only sees the ones
         * whose name starts with their own account's prefix (the same
         * `<system_user>_` DbCreate always qualifies names with) — real on a
         * report (2026-08-16): a customer's database created directly through
         * phpMyAdmin, never through `db.create`, so `databases_` never got a
         * row for it at all. `owner_user_id` (migration 0025) only covers
         * databases the panel itself recorded — this covers the ones it didn't.
         */
        if ($isAdmin) {
            foreach ($onServer as $name) {
                if (in_array($name, $known, true)) {
                    continue;
                }

                $databases[] = $this->unmanagedRow($name, $sizes);
            }
        } else {
            $prefix = (string) $context->db->value(
                'SELECT system_user FROM users WHERE id = :id',
                ['id' => $context->actor->userId],
                '',
            );

            if ($prefix !== '') {
                $webadmins = $context->db->all(
                    "SELECT id, system_user FROM users WHERE role = 'webadmin' AND system_user IS NOT NULL",
                );

                foreach ($onServer as $name) {
                    if (in_array($name, $known, true)) {
                        continue;
                    }

                    // The longest matching account prefix wins — the same
                    // rule DbDrop::ownerAccountFor() uses, so "alice" and
                    // "alice_bob" (both real accounts) never both claim
                    // "alice_bob_secret" as theirs.
                    if ($this->bestPrefixOwner($webadmins, $name) === $context->actor->userId) {
                        $databases[] = $this->unmanagedRow($name, $sizes);
                    }
                }
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

    /** @return array{name:string,site_id:int,site:null,charset:string,size:int,created_at:int,exists_on_server:bool,managed:bool,users:array<never>} */
    private function unmanagedRow(string $name, array $sizes): array
    {
        return [
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

    /**
     * The webadmin whose account prefix most specifically matches this
     * database name — mirrors `DbDrop::ownerAccountFor()`'s tie-break so the
     * two paths can never disagree about who an unmanaged database belongs to
     *
     * @param list<array{id:int,system_user:string}> $webadmins
     */
    private function bestPrefixOwner(array $webadmins, string $name): ?int
    {
        $best = null;
        $bestPrefixLen = 0;

        foreach ($webadmins as $row) {
            $prefix = $row['system_user'] . '_';

            if (str_starts_with($name, $prefix) && strlen($prefix) > $bestPrefixLen) {
                $best = (int) $row['id'];
                $bestPrefixLen = strlen($prefix);
            }
        }

        return $best;
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
