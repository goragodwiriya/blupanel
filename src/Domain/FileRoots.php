<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Agent\Actor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Paths;
use Phpcp\Security\Permissions;

/**
 * The file scopes each role can access — the whole system's file access policy lives in this one file
 *
 * Policy:
 *   Superadmin / sysadmin → the whole machine (/), with frequently used shortcuts
 *   Website admin         → only the directories of the sites they own
 *
 * It has to be said plainly: granting access to the whole machine through the web
 * page is effectively giving anyone who can log in as an admin something close to a
 * root shell. Everything else here is therefore about "catching mistakes" and
 * "leaving a trail," not actually restricting privilege:
 *
 *   - The control panel's own files can't be touched, neither read nor written
 *     (prevents the system from destroying itself beyond recovery, and prevents
 *     reading panel.db, which holds password hashes and sessions)
 *   - /proc /sys /dev are open for reading but not writing, since they are the
 *     kernel's own virtual filesystems
 *   - Every action is recorded to an audit log built as a hash chain, so any
 *     after-the-fact edit is detectable
 *
 * Because of this, admin accounts must have 2FA enabled and should restrict IPs per
 * SECURITY §5.
 */
final class FileRoots
{
    /** Open for reading but not writing, even for a superadmin */
    private const READ_ONLY_PREFIXES = ['/proc', '/sys', '/dev'];

    /**
     * Every scope this actor can open, in the order they should appear in the menu
     *
     * @return array<string,FileScope>
     */
    public static function forActor(Actor $actor, Db $db): array
    {
        $scopes = [];

        if (self::isServerAdmin($actor)) {
            $scopes['sites'] = FileScope::forServer('sites', 'All websites', Paths::usersDir());
            $scopes['server'] = FileScope::forServer('server', 'Whole server', '/');
            /*
             * There is no longer a `backups` scope — backup files live in the
             * customer's own `<home>/backup`, which already sits under the
             * `sites` scope and each site's own scope · a customer can browse
             * their own files without anyone needing to grant any extra
             * privilege, which is all PLAN-BACKUP-V2 required · the old scope
             * pointed into the panel's own space and needed a hole punched into
             * SelfProtection to open it at all — that hole has since been
             * filled back in.
             */
            $scopes['etc'] = FileScope::forServer('etc', 'System configuration', '/etc');
            $scopes['varlog'] = FileScope::forServer('varlog', 'System logs', '/var/log');
            $scopes['varwww'] = FileScope::forServer('varwww', 'System webroot', '/var/www');
            $scopes['home'] = FileScope::forServer('home', 'User homes', '/home');
            $scopes['tmp'] = FileScope::forServer('tmp', 'Temporary files', '/tmp');
        }

        foreach (self::visibleSites($actor, $db) as $site) {
            // The site's real files come before its home — a site using a Domain
            // Pointer stores files outside its home; the user comes to this page
            // to edit site files, not to browse logs/tmp/backups.
            $pointer = FileScope::forSiteDocroot($site);
            if ($pointer !== null) {
                $scopes[$pointer->key] = $pointer;
            }

            $scope = FileScope::forSite($site);
            $scopes[$scope->key] = $scope;
        }

        return $scopes;
    }

    /**
     * Select a scope by key, with a permission check
     *
     * The check here is the single guard that decides who can open what —
     * every capability must go through this.
     */
    public static function require(Actor $actor, Db $db, string $key): FileScope
    {
        $scopes = self::forActor($actor, $db);

        if (!isset($scopes[$key])) {
            throw new PermissionDenied('You do not have access to this file scope');
        }

        return $scopes[$key];
    }

    /** The scope that should be shown first when opening the file manager */
    public static function defaultKey(Actor $actor, Db $db): string
    {
        $scopes = self::forActor($actor, $db);

        if ($scopes === []) {
            throw new PermissionDenied('Your account has no accessible file scope yet');
        }

        // An admin starts at the site list, since that's the most frequent task ·
        // a website admin starts at their own site, the only scope they have.
        return isset($scopes['sites']) ? 'sites' : (string) array_key_first($scopes);
    }

    /**
     * Check whether this path can be modified — used on every write command, not just when displaying it
     */
    public static function assertWritable(string $absolutePath): void
    {
        SelfProtection::assertPath($absolutePath);

        foreach (self::READ_ONLY_PREFIXES as $prefix) {
            if ($absolutePath === $prefix || str_starts_with($absolutePath, $prefix.'/')) {
                throw new ValidationError("{$prefix} is a kernel filesystem and cannot be modified");
            }
        }
    }

    /** The panel's own path is closed to both reading and writing, since panel.db holds password hashes and sessions */
    public static function assertReadable(string $absolutePath): void
    {
        SelfProtection::assertPath($absolutePath);
    }

    /**
     * @param Actor $actor
     */
    private static function isServerAdmin(Actor $actor): bool
    {
        return in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);
    }

    /** @return list<array<string,mixed>> */
    private static function visibleSites(Actor $actor, Db $db): array
    {
        // A site's file path is derived from its owner's home, so the users table
        // must always be joined in. A site whose owner has no system account yet
        // (system_user is NULL) means provisioning hasn't finished — it must not
        // be shown in the file manager, since the folder doesn't actually exist yet.
        // The layout and main domain must come along too — FileScope builds its
        // path directly from this row; missing them would make a cpanel owner get
        // treated as phpcp and opened to a folder that doesn't exist.
        $select = "SELECT s.id, s.primary_domain, s.docroot_override, s.owner_user_id,
                          u.system_user, u.site_layout, u.main_domain
                   FROM sites s JOIN users u ON u.id = s.owner_user_id
                   WHERE u.system_user IS NOT NULL";

        if (self::isServerAdmin($actor)) {
            return $db->all($select.' ORDER BY s.primary_domain');
        }

        // A system actor (CLI/cron) has no userId — not tied to any site.
        if ($actor->userId === 0) {
            return [];
        }

        return $db->all(
            $select.' AND s.owner_user_id = :u ORDER BY s.primary_domain',
            ['u' => $actor->userId],
        );
    }
}
