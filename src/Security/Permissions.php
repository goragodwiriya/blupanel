<?php
declare (strict_types = 1);

namespace Phpcp\Security;

/**
 * The role → permission map, per ARCHITECTURE §8
 *
 * This file is the single source of truth, used both at the middleware
 * (layer 1) and the agent (layer 2)
 *
 * Checking in two places is deliberate — layer 1 prevents rendering buttons
 * and blocks opening the URL directly, while layer 2 covers the case where
 * layer 1 has a bug that let something through
 *
 * A note kept honest with reality: the agent computes permissions from the
 * role the web tier sends it. If the web tier were fully compromised, an
 * attacker could forge the role — the genuine boundary is therefore the
 * capability list and its validator (SECURITY §2.4), which still holds even in that case
 */
final class Permissions
{
    public const SUPERADMIN = 'superadmin';
    public const SYSADMIN = 'sysadmin';
    public const WEBADMIN = 'webadmin';

    /** @return array<string,string> role => the name shown in the UI */
    public static function roleLabels(): array
    {
        return [
            self::SUPERADMIN => 'Administrator',
            self::SYSADMIN => 'Server admin',
            self::WEBADMIN => 'Website admin'
        ];
    }

    /**
     * @param string $role
     */
    public static function roleLabel(string $role): string
    {
        return self::roleLabels()[$role] ?? $role;
    }

    /** Every permission that exists in the system, with its description */
    public static function all(): array
    {
        return [
            'dashboard.view' => 'View the dashboard',

            // Hosting
            'site.view' => 'View websites',
            'site.create' => 'Create websites',
            'site.edit' => 'Edit websites',
            'site.suspend' => 'Suspend websites',
            'site.delete' => 'Delete websites',
            'domain.view' => 'View domains',
            'domain.manage' => 'Manage domains and DNS',
            'ssl.view' => 'View SSL certificates',
            'ssl.manage' => 'Install and renew SSL',
            'php.view' => 'View PHP information',
            'php.manage' => 'Install/remove PHP extensions and configure PHP',
            'db.view' => 'View databases',
            'db.manage' => 'Manage databases and database users',
            'file.view' => 'View files',
            'file.manage' => 'Edit files',
            'cron.view' => 'View scheduled jobs',
            'cron.manage' => 'Manage scheduled jobs',
            'mail.view' => 'View mailboxes',
            'mail.manage' => 'Manage mailboxes and forwarders',
            'backup.view' => 'View backups',
            'backup.manage' => 'Create and delete backups',
            'backup.restore' => 'Restore data',

            // Deliberately separate from backup.manage — `backup.manage` is a
            // **Hosting** permission a website admin holds (create/delete
            // their own website's backup files), while offsite destinations
            // and the schedule belong to the **whole machine**: one
            // destination receives every customer's backup files, and the
            // schedule runs on behalf of "the system" · they can't share a permission
            'backup.offsite' => 'Manage offsite backup destinations and the automatic backup schedule',

            // Customer
            'customer.view' => 'View customers',
            'customer.manage' => 'Manage customers (create/edit/delete)',

            // Server
            'server.view' => 'View the server overview',
            'service.view' => 'View service status',
            'service.control' => 'Control services (start/stop/restart/reload)',
            'security.view' => 'View the security center',
            'security.manage' => 'Edit security settings',
            'firewall.view' => 'View the firewall',
            'firewall.manage' => 'Manage firewall rules',
            'ssh.view' => 'View SSH settings',
            'ssh.manage' => 'Edit SSH settings',
            'log.view' => 'View logs',

            // Deliberately separate from domain.manage — domain.manage is a
            // **Hosting** permission a website admin holds (edits their own
            // domain's DNS records, which already automatically writes that
            // one domain's zone), while "resync every domain at once"
            // (`dns.reload`) touches every customer's zone simultaneously and
            // rewrites the whole named.conf.local file — a whole-machine
            // permission just like backup.offsite — they can't share a permission
            'dns.manage' => 'Resync every DNS zone with BIND9',
            'user.view' => 'View panel users',
            'user.manage' => 'Manage panel users',
            'settings.view' => 'View server settings',
            'settings.manage' => 'Edit server settings',
            'audit.view' => 'View the audit log',

            // SQLite database manager
            'sqlite.manage' => 'Manage and inspect the panel database',
        ];
    }

    /**
     * Each role's permissions
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            self::SUPERADMIN => array_keys(self::all()),

            // All of Server + view-only Hosting + Customer
            self::SYSADMIN => [
                'dashboard.view',
                'site.view', 'domain.view', 'ssl.view', 'php.view', 'php.manage',
                'db.view', 'file.view', 'cron.view', 'backup.view', 'mail.view',
                'customer.view', 'customer.manage',
                'server.view',
                'service.view', 'service.control',
                'security.view', 'security.manage',
                'firewall.view', 'firewall.manage',
                'ssh.view', 'ssh.manage',
                'log.view',
                'user.view',
                'settings.view',
                'audit.view',
                'backup.offsite',
                'dns.manage',
                'sqlite.manage'
            ],

            /*
             * Only their own websites — not a single SERVER-category permission
             *
             * **No `cron.*`** — a customer's scheduled jobs end up in the
             * **system's** crontab, a whole-machine resource, not something
             * that belongs to any one website · a command that runs on a
             * schedule with nobody watching is the most direct path from a
             * hosting account to running code on the machine repeatedly, so
             * it's something an admin sets up, not something a customer sets up themselves
             *
             * **No `backup.*`** — the backup files page is an admin tool ·
             * importantly, **the customer loses none of their own access to
             * their own copies**, since the files live at their own
             * `<home>/backup` (PLAN-BACKUP-V2 item B1) — opening, downloading,
             * and deleting them works through the file manager and SFTP,
             * which has been that plan's core agreement from the start (item
             * B4: "the file itself is the truth, because the customer can
             * delete it themselves") · the `file.view`/`file.manage`
             * permissions below are what keeps that agreement true
             *
             * Creating a backup file has always been `backup.offsite` (the
             * admin's) from the start — a customer has never been able to
             * click to create one themselves, see {@see \Phpcp\Agent\Capability\BackupCreate}
             */
            self::WEBADMIN => [
                'dashboard.view',
                /*
                 * **`site.create` is deliberately here** — a customer adds
                 * their own websites, the way cPanel's Addon Domains work
                 *
                 * Without it, an account created with no domain (the form
                 * leaves it optional) had no way to ever get one: the
                 * customer holds `domain.manage`, but that only adds a
                 * subdomain or an alias *under a website that already
                 * exists*, and they had none · so a package sold with
                 * `quota_domains = 10` could deliver zero of them unless an
                 * admin created the first site by hand.
                 *
                 * **What keeps this from being a hole:**
                 *   - `SitesController::store()` overrides `owner_user_id`
                 *     with the caller's own id for this role, so a customer
                 *     can never create a website belonging to somebody else
                 *   - the same method refuses `docroot`/`pointer_root` from
                 *     this role — Domain Pointer serves files from outside
                 *     the home, an admin-only decision
                 *   - `SiteCreate::assertQuota()` checks `quota_domains`,
                 *     the service status, and the disk quota, so "within the
                 *     package" is enforced at the capability, not by the
                 *     screen that happens to be showing the button
                 */
                'site.view', 'site.create', 'site.edit',
                'domain.view', 'domain.manage',
                'ssl.view', 'ssl.manage',
                'php.view',
                'db.view', 'db.manage',
                'file.view', 'file.manage',
                'mail.view', 'mail.manage'
            ],

            default => [],
        };
    }

    /**
     * @param string $role
     * @param string $permission
     */
    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }

    /**
     * @param string $role
     */
    public static function isValidRole(string $role): bool
    {
        return array_key_exists($role, self::roleLabels());
    }

    /**
     * The roles that see the SERVER category's menu — used when deciding
     * how to render the sidebar, always matches the permission since it's computed from the same source
     */
    public static function seesServerSection(string $role): bool
    {
        return self::roleHas($role, 'server.view');
    }

    /**
     * Roles that see every website, not only their own
     *
     * Checked straight from the role, not from a permission, because `site.view` sits
     * on both sides — a website admin also "views websites", just scoped to their own.
     * What differs is **scope**, and no permission stands in for that.
     *
     * Used both to filter the web-tier list and to re-check on the agent side — write
     * the role list in two places and one day a new role will pass one gate but not
     * the other.
     */
    public static function seesAllSites(string $role): bool
    {
        return in_array($role, [self::SUPERADMIN, self::SYSADMIN], true);
    }
}
