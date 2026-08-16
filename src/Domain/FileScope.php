<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;

/**
 * A single scope the file manager can open
 *
 * There are two kinds, differing in which privileges file operations run with:
 *
 *   site   — a site's own directory, runs with the site owner's privileges
 *            (web_<id>) · a path-escape bug, if one existed, could therefore only
 *            ever reach that one site's own scope
 *
 *   server — a machine-level path, runs with the agent's own privileges (root, on
 *            a real server) · open only to server-level admins, and every action
 *            gets recorded to the audit log
 *
 * Splitting this out as an object instead of passing a site_id everywhere means a
 * single set of capabilities works for both kinds without ever needing to know
 * which one it's operating on.
 */
final readonly class FileScope
{
    public const KIND_SITE = 'site';
    public const KIND_SERVER = 'server';

    /**
     * @param string $key
     * @param string $label
     * @param string $root
     * @param string $kind
     * @param string $group
     * @param string $systemUser
     * @param int $siteId
     * @param bool $writable
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $root,
        public string $kind,
        public string $group,
        /** The system user to drop privileges to — null = run with the agent's own privileges */
        public ?string $systemUser = null,
        public int $siteId = 0,
        public bool $writable = true,
    ) {
    }

    /**
     * One site's own home — has lived under its owner's home since migration 0006
     *
     * @param array<string,mixed> $site a row already joined with users, so it has the owner's system_user
     */
    private static function siteRoot(array $site): string
    {
        return self::owner($site)->siteRoot((string) $site['primary_domain']);
    }

    /**
     * The site's owner, with its layout intact — **must always carry the layout, never construct a bare UserAccount**
     *
     * This used to be built with `new UserAccount(0, $systemUser)`, which always
     * carried the system default layout along with it · an owner who had set
     * cpanel would therefore get treated as phpcp in this one place, and the file
     * manager would open to `<home>/domains/<domain>`, which doesn't actually
     * exist — the user would see an empty folder with nothing explaining why.
     *
     * @param array<string,mixed> $site a row already joined with users
     */
    private static function owner(array $site): UserAccount
    {
        return new UserAccount(
            (int) ($site['owner_user_id'] ?? 0),
            (string) $site['system_user'],
            SiteLayout::tryFrom(trim((string) ($site['site_layout'] ?? ''))),
            trim((string) ($site['main_domain'] ?? '')),
        );
    }

    /**
     * @param array $site
     */
    public static function forSite(array $site): self
    {
        return new self(
            key: 'site-'.$site['id'],
            label: (string) $site['primary_domain'],
            root: self::siteRoot($site),
            kind: self::KIND_SITE,
            group: 'Websites',
            systemUser: (string) $site['system_user'],
            siteId: (int) $site['id'],
        );
    }

    /**
     * The scope for a site's "real files" when it uses a Domain Pointer
     *
     * A site with `docroot_override` set stores its files outside its own home
     * (e.g. pointing at an existing project that predates being brought into the
     * panel). Without this scope, the file manager could only open the home
     * itself, which only has backups/logs/tmp and `__suspended.html` — the site's
     * own files would be completely unreachable.
     *
     * Returns null when no pointer is set, or the docroot is already under the
     * home anyway — the latter case can already be opened directly from the home
     * scope, so there's no need for a duplicate entry to cause confusion.
     *
     * @param array $site
     */
    public static function forSiteDocroot(array $site): ?self
    {
        $docroot = rtrim((string) ($site['docroot_override'] ?? ''), '/');

        if ($docroot === '') {
            return null;
        }

        $home = rtrim(self::siteRoot($site), '/');

        if ($docroot === $home || str_starts_with($docroot.'/', $home.'/')) {
            return null;
        }

        return new self(
            key: 'site-'.$site['id'].'-docroot',
            label: $site['primary_domain'].' (site files)',
            root: $docroot,
            kind: self::KIND_SITE,
            group: 'Websites',
            systemUser: (string) $site['system_user'],
            siteId: (int) $site['id'],
        );
    }

    /**
     * @param string $key
     * @param string $label
     * @param string $path
     * @param bool $writable
     */
    public static function forServer(string $key, string $label, string $path, bool $writable = true): self
    {
        return new self(
            key: $key,
            label: $label,
            root: $path,
            kind: self::KIND_SERVER,
            group: 'Server',
            writable: $writable,
        );
    }

    /**
     * @return mixed
     */
    public function isSite(): bool
    {
        return $this->kind === self::KIND_SITE;
    }

    /** @return array<string,mixed> the data sent to the web page */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'root' => $this->root,
            'kind' => $this->kind,
            'group' => $this->group,
            'site_id' => $this->siteId,
            'writable' => $this->writable,
            'runs_as' => $this->systemUser ?? 'root'
        ];
    }
}
