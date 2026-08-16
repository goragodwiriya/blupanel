<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;
use Phpcp\Support\Validator;

/**
 * One site, with every path derived from its domain name
 *
 * Why path computation is consolidated here in one place: if each caller built its
 * own path, a vhost could end up pointing at one socket while the pool creates a
 * different one, with nobody noticing until the site starts returning 502s — a bug
 * class that's rare and painful to track down.
 *
 * The domain name is always validated before this object is constructed, so every
 * path derived from it is safe.
 */
final readonly class Site
{
    /** @param list<string> $aliases */
    public function __construct(
        public int $id,
        public string $domain,
        /** The site's owner — every path and uid for this site is derived from their account */
        public UserAccount $owner,
        public string $phpVersion,
        public string $sslMode = 'off',
        public string $status = 'active',
        public array $aliases = [],
        public int $memoryLimitMb = 256,
        public int $uploadLimitMb = 64,
        public int $maxChildren = 5,
        /** Empty = use <home>/public as normal */
        public string $docrootOverride = '',
        /** subdomain => target path */
        public array $subdomainPaths = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $aliases
     */
    public static function fromRow(
        array $row,
        array $aliases = [],
        array $subdomainPaths = [],
        ?UserAccount $owner = null,
    ): self {
        return new self(
            id: (int) $row['id'],
            domain: Validator::domain((string) $row['primary_domain']),
            owner: $owner ?? UserAccount::fromRow([
                'id' => $row['owner_user_id'] ?? 0,
                // A sites row that's already joined with users will have these
                // keys · without a UserAccount, this throws immediately instead of
                // building a path like /srv/phpcp/users//domains/…, which is wrong
                // but has nothing to flag it — this has actually reached the real
                // API before.
                'system_user' => $row['owner_system_user'] ?? null,
                'username' => $row['owner_username'] ?? '',
                // Layout and main domain must come along too, otherwise docroot
                // silently falls back to phpcp even when the owner set cpanel —
                // the vhost would point at the wrong place for the whole site.
                'site_layout' => $row['owner_site_layout'] ?? '',
                'main_domain' => $row['owner_main_domain'] ?? '',
            ]),
            phpVersion: Validator::phpVersion((string) $row['php_version']),
            sslMode: (string) ($row['ssl_mode'] ?? 'off'),
            status: (string) ($row['status'] ?? 'active'),
            aliases: array_map(Validator::wildcardDomain(...), $aliases),
            docrootOverride: (string) ($row['docroot_override'] ?? ''),
            subdomainPaths: $subdomainPaths,
        );
    }

    /**
     * The system account this site runs as — belongs to **the owner**, not the site
     *
     * This used to be `web_<siteId>`, one account per site · since migration 0006,
     * every site belonging to the same user shares the same account, which is why
     * the `sites.system_user` column was removed (its UNIQUE constraint directly
     * contradicted this new reality).
     */
    public function systemUser(): string
    {
        return $this->owner->username;
    }

    /** The site's own home — logs, tmp, and backups always live under this path, even when docroot points elsewhere */
    public function root(): string
    {
        return $this->owner->siteRoot($this->domain);
    }

    /**
     * The directory the web server actually serves
     *
     * The shape comes from **the owner's** layout ({@see SiteLayout}), not a fixed
     * formula — phpcp gets `<home>/domains/<domain>/public`, while cpanel gets
     * `<home>/public_html`.
     *
     * `docrootOverride` can be set to point a domain at a folder that already
     * exists (a Domain Pointer); which paths are allowed is always restricted by
     * `Config::docrootRoots()` at creation/edit time · the override always wins
     * over the layout, since it's the more explicit instruction.
     */
    public function docroot(): string
    {
        return $this->docrootOverride !== ''
            ? $this->docrootOverride
            : $this->owner->siteDocroot($this->domain);
    }

    /**
     * @return mixed
     */
    public function tmpDir(): string
    {
        return $this->root().'/tmp';
    }

    /** The pool's temp storage, shared with the owner's other sites */
    public function poolTmpDir(): string
    {
        return $this->owner->tmpDir();
    }

    /**
     * @return mixed
     */
    public function logDir(): string
    {
        return $this->owner->layout()->logDir($this->owner->home(), $this->domain);
    }

    /**
     * @return mixed
     */
    public function backupDir(): string
    {
        return $this->owner->backupDir();
    }

    /**
     * @return mixed
     */
    public function errorLog(): string
    {
        return $this->logDir().'/error.log';
    }

    /**
     * @return mixed
     */
    public function accessLog(): string
    {
        return $this->logDir().'/access.log';
    }

    /**
     * @return mixed
     */
    public function phpErrorLog(): string
    {
        return $this->owner->phpErrorLog($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function slowLog(): string
    {
        return $this->owner->phpSlowLog($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function suspendedPage(): string
    {
        return $this->root().'/__suspended.html';
    }

    /**
     * The FPM pool's socket — one per (owner × PHP version)
     *
     * Sites belonging to the same owner using the same PHP version therefore share
     * a socket and a pool — meaning a customer with 5 sites on PHP 8.4 uses one
     * pool, not 5.
     */
    public function fpmSocket(): string
    {
        return $this->owner->fpmSocket($this->phpVersion);
    }

    public function fpmPoolFile(): string
    {
        return $this->owner->fpmPoolFile($this->phpVersion);
    }

    /**
     * @param string $phpVersion
     */
    public function fpmPoolFileFor(string $phpVersion): string
    {
        return $this->owner->fpmPoolFile(Validator::phpVersion($phpVersion));
    }

    public function fpmUnit(): string
    {
        return ServiceCatalog::fpmUnit($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Every domain this vhost is responsible for */
    public function allDomains(): array
    {
        return array_values(array_unique([$this->domain, ...$this->aliases]));
    }

    /**
     * Whether this site accepts wildcard subdomains (PLAN-V2 Phase E7)
     *
     * Has two consequences: the certificate must be requested via DNS-01 (HTTP-01
     * doesn't work with wildcards), and the vhost must be read **last**, otherwise
     * it would swallow requests for a subdomain that another site has already
     * declared by its full name (see `ApacheDriver::vhostPath()`).
     */
    public function hasWildcard(): bool
    {
        foreach ($this->aliases as $alias) {
            if (str_starts_with($alias, '*.')) {
                return true;
            }
        }

        return false;
    }

    /** Whether this site shares its owner and PHP version with another site */
    public function sharesPoolWith(self $other): bool
    {
        return $this->owner->username === $other->owner->username
            && $this->phpVersion === $other->phpVersion;
    }

    public function withPhpVersion(string $phpVersion): self
    {
        return new self(
            id: $this->id,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: Validator::phpVersion($phpVersion),
            sslMode: $this->sslMode,
            status: $this->status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }

    /**
     * @param string $sslMode
     */
    public function withSslMode(string $sslMode): self
    {
        return new self(
            id: $this->id,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: $this->phpVersion,
            sslMode: self::assertSslMode($sslMode),
            status: $this->status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }

    /** The same values as the ssl_mode column's CHECK constraint in the database */
    public static function assertSslMode(string $mode): string
    {
        if (!in_array($mode, ['off', 'on', 'forced'], true)) {
            throw new \Phpcp\Agent\ValidationError('SSL mode must be off, on, or forced');
        }

        return $mode;
    }

    /**
     * @return mixed
     */
    public function usesSsl(): bool
    {
        return $this->sslMode !== 'off';
    }

    /**
     * @param string $status
     */
    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: $this->phpVersion,
            sslMode: $this->sslMode,
            status: $status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }
}
