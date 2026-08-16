<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;

/**
 * One user's system account, with every path derived from their name
 *
 * Since migration 0006, the unit of privilege separation is **the user**, not the
 * site: one user = one uid = one home = many sites = one FPM pool per PHP version
 * actually in use.
 *
 * It used to be one site = one uid, which meant a customer with 5 sites got 5
 * completely unrelated Linux accounts despite being the same person — SFTP needed 5
 * separate accounts, disk quota was counted separately in a way that did not match
 * what was actually sold, and 5 pools ate memory without separating anything that
 * actually needed separating.
 *
 * **What this trades away:** sites belonging to the *same* user can read each
 * other's files and share a process queue. That is acceptable, since they are the
 * same person's own property, and it is the same model cPanel/Plesk/DirectAdmin use
 * · **separation between different users remains exactly as strict as before**,
 * which is the boundary that actually matters.
 */
final readonly class UserAccount
{
    public function __construct(
        public int $userId,
        public string $username,
        /**
         * The shape of files under this user's home — null = follow the system default
         *
         * Stored as nullable instead of resolving to a default value right here,
         * because "never chosen" and "chose phpcp" are different things: the former
         * must move along when the admin changes the system default, the latter must
         * not · see SiteLayout::parse()
         */
        public ?SiteLayout $layout = null,
        /** The domain that gets `public_html` in the cpanel layout — empty = no site yet */
        public string $mainDomain = '',
    ) {
    }

    /**
     * @param array<string,mixed> $row a row from the users table
     */
    public static function fromRow(array $row): self
    {
        // system_user can be null when the user has never had a site (the system
        // account is created lazily) — in that case, use the username, which
        // becomes the account name at actual provisioning time.
        $name = (string) ($row['system_user'] ?? '');

        return new self(
            userId: (int) $row['id'],
            username: self::assertSystemUser($name !== '' ? $name : (string) ($row['username'] ?? '')),
            layout: SiteLayout::tryFrom(trim((string) ($row['site_layout'] ?? ''))),
            mainDomain: trim((string) ($row['main_domain'] ?? '')),
        );
    }

    /** The layout actually in effect — falls back to the system default when the user has never chosen one */
    public function layout(): SiteLayout
    {
        return $this->layout ?? SiteLayout::systemDefault();
    }

    /**
     * Whether this domain is the account's primary domain — decides who gets `public_html`
     *
     * An account that has no `main_domain` yet (its first site is about to be
     * created) treats the domain being asked about as **the** primary domain —
     * otherwise a cpanel account's first site would land at `<home>/<domain>`, and
     * `public_html` would never get created at all.
     */
    public function isMainDomain(string $domain): bool
    {
        return $this->mainDomain === '' || $this->mainDomain === $domain;
    }

    /**
     * A system account name must be safe enough to be a Linux username, a folder
     * name, and a pool name all at once
     *
     * Validated again here even though `UserRepository::assertUsername()` already
     * validates it at creation time, because this value ends up in file paths and
     * config files that run with root privileges — a value that got in some other
     * way (e.g. a row edited by hand in the database) must be caught here before it
     * reaches its destination.
     */
    public static function assertSystemUser(string $user): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $user) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid system account name: {$user} — must be a-z 0-9 _ -, 3-32 characters, starting with a letter",
            );
        }

        return $user;
    }

    /** The user's home — everything belonging to this user lives under this path */
    public function home(): string
    {
        return Paths::usersDir().'/'.$this->username;
    }

    /**
     * The parent folder for every site — only exists in the phpcp layout
     *
     * The cpanel layout has no such tier (site files live at `public_html` and
     * `<domain>` directly under the home), so it just returns the home instead ·
     * a caller that wants "this site's own storage" must use `siteRoot()`, not
     * build a path from this value itself.
     */
    public function domainsDir(): string
    {
        return $this->layout() === SiteLayout::Phpcp ? $this->home().'/domains' : $this->home();
    }

    /** One site's own storage for things that are not site files — logs, backups, the suspended-service page */
    public function siteRoot(string $domain): string
    {
        return $this->layout()->stateDir($this->home(), $domain);
    }

    /** The directory the web server actually serves for this domain */
    public function siteDocroot(string $domain): string
    {
        return $this->layout()->docroot($this->home(), $domain, $this->isMainDomain($domain));
    }

    /** The pool's temp file storage — shared by every site belonging to this user */
    public function tmpDir(): string
    {
        return $this->home().'/tmp';
    }

    /**
     * Where this account's backup files are stored — one folder per account, shared by every site
     *
     * Lives in the home because a backup file belongs to the customer: it counts
     * toward their quota, and they can download it themselves · the shape doesn't
     * depend on the layout, see {@see SiteLayout::backupDir()}
     */
    public function backupDir(): string
    {
        return $this->layout()->backupDir($this->home());
    }

    /**
     * PHP-FPM's log
     *
     * Lives at the user level, not the site level, since one pool serves multiple
     * sites — splitting it per site would produce either a file the pool can't
     * actually write into, or an incomplete log.
     * (The web server's own log still stays split per site, since each site
     * genuinely has its own separate vhost.)
     */
    public function logDir(): string
    {
        return $this->home().'/logs';
    }

    public function sshDir(): string
    {
        return $this->home().'/.ssh';
    }

    public function authorizedKeys(): string
    {
        return $this->sshDir().'/authorized_keys';
    }

    /** The pool name inside FPM's config file */
    public function poolName(string $phpVersion): string
    {
        return $this->username.'-'.$phpVersion;
    }

    /** The pool's socket — one per PHP version this user actually uses */
    public function fpmSocket(string $phpVersion): string
    {
        return '/run/php/phpcp-'.$this->username.'-'.$phpVersion.'.sock';
    }

    public function fpmPoolFile(string $phpVersion): string
    {
        return '/etc/php/'.$phpVersion.'/fpm/pool.d/phpcp-'.$this->username.'.conf';
    }

    public function phpErrorLog(string $phpVersion): string
    {
        return $this->logDir().'/php-'.$phpVersion.'-error.log';
    }

    public function phpSlowLog(string $phpVersion): string
    {
        return $this->logDir().'/php-'.$phpVersion.'-slow.log';
    }
}
