<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * The shape of files under a user's home — **the one place in the system that answers
 * what lives where**
 *
 * The answer used to be scattered across `UserAccount` and `Site` as a single hardcoded
 * formula. Adding an alternative would have meant editing both places to stay exactly in
 * sync, an easy condition to get wrong, and wrong with nothing to flag it — the vhost
 * pointing at one place while the quota counts a different one.
 *
 * ## Two layouts
 *
 * **phpcp** (original · default) — everything for one site lives in a single box
 * ```
 * <home>/domains/<domain>/public     site files
 * <home>/domains/<domain>/logs       this site's logs
 * <home>/domains/<domain>/backups
 * ```
 *
 * **cpanel** — the shape people migrating from cPanel/DirectAdmin already know
 * ```
 * <home>/public_html                 the primary domain's files
 * <home>/<domain>                    files for a domain added afterward
 * <home>/logs/<domain>               logs split per domain
 * <home>/backups/<domain>
 * ```
 *
 * ## Why cpanel needs to know which domain is the primary one
 *
 * There's only one `public_html` per account, but one account can have many sites ·
 * "which one gets public_html" can't be answered from the data alone — id ordering
 * isn't a promise, and guessing from ordering would mean the path of a currently
 * live site could change on its own when someone else's site gets deleted · so it's
 * stored directly on `users.main_domain` instead (see migration 0020).
 *
 * ## What both layouts share
 *
 * `tmp` always lives at the user level, never the site level — one FPM pool serves
 * every site belonging to the same owner, and a per-site tmp would produce a
 * directory the pool can't actually write into.
 */
enum SiteLayout: string
{
    /** Original — `<home>/domains/<domain>/public` */
    case Phpcp = 'phpcp';

    /** cPanel-style — `<home>/public_html` for the primary domain */
    case Cpanel = 'cpanel';

    /**
     * The value used when nobody has chosen anything
     *
     * **Deliberately cpanel** — `<home>/public_html` is the shape every Unix admin
     * and every hosting customer already expects · `phpcp`'s three levels of nesting
     * (`domains/<domain>/public`) matches nobody's expectation and forces every
     * migrating customer's deploy script to be rewritten.
     *
     * The original layout can still be chosen per account, for machines that have
     * already migrated and aren't ready to move their files yet.
     */
    public const DEFAULT = self::Cpanel;

    /**
     * This machine's default, for accounts that have never chosen one themselves
     *
     * The actual value is stored in {@see \Phpcp\Kernel\Paths}, for the same reason
     * as `usersDir` — `UserAccount` is constructed as a value object from dozens of
     * places throughout the system, and threading a Config through every one of them
     * would change every signature for no benefit · (a PHP enum can't hold its own property).
     */
    public static function systemDefault(): self
    {
        return self::tryFrom(\Phpcp\Kernel\Paths::siteLayout()) ?? self::DEFAULT;
    }

    /**
     * Parse a value from the database/settings, without failing on an unrecognized one
     *
     * An empty value means "follow the system default," which has a meaningfully
     * different meaning from 'phpcp': an account that has never chosen one moves
     * along automatically when the admin changes the system default, while an
     * account that explicitly chose 'phpcp' stays put · a caller that needs to
     * distinguish these two cases must look at the raw value itself, not call this.
     */
    public static function parse(string $value, ?self $fallback = null): self
    {
        return self::tryFrom(trim($value)) ?? $fallback ?? self::DEFAULT;
    }

    /** Display name shown on screen */
    public function label(): string
    {
        return match ($this) {
            self::Phpcp => 'phpcp — <home>/domains/<domain>/public',
            self::Cpanel => 'cPanel — <home>/public_html',
        };
    }

    /**
     * The directory the web server serves
     *
     * `$isMain` comes from comparing the domain against `users.main_domain`, not from
     * ordering or a guess.
     */
    public function docroot(string $home, string $domain, bool $isMain): string
    {
        return match ($this) {
            self::Phpcp => $home . '/domains/' . $domain . '/public',
            self::Cpanel => $isMain ? $home . '/public_html' : $home . '/' . $domain,
        };
    }

    /**
     * A site's own storage for **things that aren't site files** — logs, backups, the
     * suspended-service page
     *
     * Deliberately separate from docroot · in the cpanel layout, site files live at
     * `public_html`, a directory the customer uploads their entire file set into —
     * putting logs in there would mean logs could leak out to the site immediately
     * without a rule preventing it, and the customer could delete them by accident.
     */
    public function stateDir(string $home, string $domain): string
    {
        return match ($this) {
            self::Phpcp => $home . '/domains/' . $domain,
            // A leading dot so this never collides with a domain name the customer
            // adds themselves under the same home.
            self::Cpanel => $home . '/.phpcp/' . $domain,
        };
    }

    /** One site's logs */
    public function logDir(string $home, string $domain): string
    {
        return match ($this) {
            self::Phpcp => $this->stateDir($home, $domain) . '/logs',
            // cPanel keeps logs grouped together under `logs/`, where a migrating
            // customer would look first.
            self::Cpanel => $home . '/logs/' . $domain,
        };
    }

    /**
     * Where backup copies are stored — **the account's, not the site's** — and the
     * same across every layout
     *
     * A backup file belongs to the customer, so it lives in the customer's own home:
     * it counts toward their quota, and they can download it themselves through SFTP
     * and the file manager they already have access to (see PLAN-BACKUP-V2 §2 item
     * B1) · it used to live at `/var/lib/phpcp/backups`, the panel's own space — the
     * customer could never get their own copy out of there, and its size counted
     * against nobody's cost.
     *
     * **Deliberately doesn't take a domain name** — one account has a single folder,
     * with the domain name living in the filename instead
     * (`<domain>-files-<timestamp>.tar.gz`) · a per-domain folder would mean the
     * customer has to open each one in turn to find the latest copy, and it would
     * turn "list this account's backup files" into a question that has to combine
     * results from several places, when it's really one single question.
     */
    public function backupDir(string $home): string
    {
        return $home . '/backup';
    }

    /**
     * Every directory the **account itself** needs, before it owns a single site
     *
     * A hosting account is usable before its first site exists — SFTP can be
     * switched on at creation time (`customer.create` with a password), and the
     * file manager offers the home as a scope · what that customer finds when
     * they connect is whatever this list created, so a layout's landing place
     * has to be here and not wait for a site:
     *
     *   - **cpanel** — `public_html` is where the first site's files go
     *     ({@see docroot()} returns it for the primary domain, and an account
     *     with no `main_domain` yet treats its first domain as primary) ·
     *     connecting over SFTP to a home with no `public_html` in it looks like
     *     a broken account to anyone arriving from cPanel/DirectAdmin
     *   - **phpcp** — `domains/`, the tier every site is created underneath
     *
     * `logs` and `backup` are shared by every site the account owns
     * ({@see logDir()} splits per domain *inside* `logs` in the cpanel layout),
     * so they belong to the account too, never to whichever site happened to be
     * created first.
     *
     * @return array<string,int> path => mode
     */
    public function accountDirectories(string $home): array
    {
        $primary = match ($this) {
            // Not `docroot()` with a made-up domain — this is the tier sites are
            // created under, and it has no domain of its own
            self::Phpcp => $home . '/domains',
            self::Cpanel => $home . '/public_html',
        };

        return [
            $primary => 0750,
            $home . '/logs' => 0750,
            $this->backupDir($home) => 0750,
        ];
    }

    /**
     * Every directory that must actually exist before a site can work, with the permissions each needs
     *
     * Gathered here because whoever creates the account and whoever creates the site
     * are different code paths, and they've drifted out of sync before — the layout
     * is the one place that actually knows everything its own shape requires.
     *
     * **Deliberately only this site's own directories**, never the account's
     * ({@see accountDirectories()}) — this list is also what `site.reset_owner`
     * chowns (see `SiteProvisioner::ownershipTargets()`), and a site's repair
     * button reaching into a *sibling* site's docroot would break the
     * one-site-at-a-time rule that makes a recursive chown safe to press.
     *
     * @return array<string,int> path => mode
     */
    public function requiredDirectories(string $home, string $domain, bool $isMain): array
    {
        return [
            $this->docroot($home, $domain, $isMain) => 0750,
            $this->logDir($home, $domain) => 0750,
            $this->backupDir($home) => 0750,
            $this->stateDir($home, $domain) => 0750,
        ];
    }
}
