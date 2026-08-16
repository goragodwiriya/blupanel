<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * A site in the panel's database
 *
 * A row in this table is "what the panel believes is true," while the vhost and pool
 * files are "what's true on the machine." These two must always agree, so every
 * command that changes one must change the other within the same transaction.
 */
final class SiteRepository
{
    /**
     * @param Db $db
     */
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    /**
     * A site's row, always joined with its owner's system account name
     *
     * Since migration 0006, a bare `sites` row can't build a file path at all,
     * because every path is derived from the owner's home · the join is therefore
     * part of "loading one site," not an optional extra — this was once forgotten,
     * and produced a docroot of `/srv/phpcp/users//domains/…` that reached the real
     * API with nothing to flag it.
     *
     * owner_user_id is NOT NULL and already has an FK, so the join never drops a row.
     */
    private const WITH_OWNER = 'SELECT s.*, u.username AS owner_username, u.system_user AS owner_system_user,
                    u.site_layout AS owner_site_layout, u.main_domain AS owner_main_domain
             FROM sites s JOIN users u ON u.id = s.owner_user_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(self::WITH_OWNER.' WHERE s.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByDomain(string $domain): ?array
    {
        return $this->db->first(self::WITH_OWNER.' WHERE s.primary_domain = :d', ['d' => $domain]);
    }

    /**
     * A site's row along with its owner's system account name
     *
     * Every path for a site is derived from its owner's name, so loading a site
     * without knowing its owner means no path can be built at all — use this query
     * whenever a Site value object is about to be constructed.
     *
     * @return array<string,mixed>|null
     */
    public function findWithOwner(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Load as a value object with aliases — used when building a vhost or a pool
     */
    public function load(int $id): ?Site
    {
        $row = $this->findWithOwner($id);

        if ($row === null) {
            return null;
        }

        /*
         * **Never construct UserAccount by hand here** — let Site::fromRow read it
         * from the same row instead
         *
         * This used to call `new UserAccount($id, $username)` with only two
         * arguments, which silently dropped the layout and main domain · the result
         * was `load()` returning a Site whose owner was always treated as the
         * default layout, even when the database said cpanel — the resulting path
         * was for the wrong layout, even though everything else in the system saw
         * it correctly.
         *
         * Found on a real server while testing a layout migration: the files
         * weren't moved, the vhost pointed at an empty directory, and the site
         * returned 404 even though every step reported success.
         */
        return Site::fromRow($row, $this->aliasesOf($id), $this->subdomainPathsOf($id));
    }

    public function subdomainPathsOf(int $siteId): array
    {
        $rows = $this->db->all(
            "SELECT domain, redirect_target FROM domains WHERE site_id = :id AND type = 'subdomain' AND redirect_target IS NOT NULL AND redirect_target != ''",
            ['id' => $siteId],
        );

        $paths = [];
        foreach ($rows as $row) {
            $paths[$row['domain']] = $row['redirect_target'];
        }

        return $paths;
    }

    /** @return list<string> */
    public function aliasesOf(int $siteId): array
    {
        $rows = $this->db->all(
            // wildcard is included too, since it must appear in ServerAlias and in
            // the certificate exactly like any other alias — the only difference
            // is how it's validated when requesting the certificate (DNS-01)
            "SELECT domain FROM domains WHERE site_id = :id AND type IN ('alias','subdomain','wildcard') ORDER BY domain",
            ['id' => $siteId],
        );

        return array_column($rows, 'domain');
    }

    /**
     * A list of sites with the numbers the screen needs — a single query, no N+1
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCounts(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        // Must always join users — every path for a site is derived from its
        // owner's home. Without the owner's name, Site would build a path like
        // /srv/phpcp/users//domains/…, which is wrong but has nothing to flag it
        // (this has actually reached the real API before).
        return $this->db->all(
            'SELECT s.*,
                    u.username     AS owner_username,
                    u.system_user  AS owner_system_user,
                    u.site_layout  AS owner_site_layout,
                    u.main_domain  AS owner_main_domain,
                    (SELECT count(*) FROM domains d WHERE d.site_id = s.id)      AS domain_count,
                    (SELECT count(*) FROM databases_ b WHERE b.site_id = s.id)   AS database_count,
                    (SELECT count(*) FROM cron_jobs c WHERE c.site_id = s.id)    AS cron_count,
                    (SELECT c.status FROM certificates c WHERE c.domain = s.primary_domain) AS cert_status,
                    (SELECT c.not_after FROM certificates c WHERE c.domain = s.primary_domain) AS cert_expires
             FROM sites s
             JOIN users u ON u.id = s.owner_user_id'.$where.' ORDER BY s.primary_domain',
            $params,
        );
    }

    /**
     * A short list of websites for building choices — id, domain, owner name
     *
     * Much lighter than {@see self::listWithCounts()} because it has no subqueries at
     * all — use it where a page only needs "what sites exist, whose are they", and
     * needs it often, such as the list of log sources rebuilt on every single log read.
     *
     * Sorted by owner before domain, so one customer's sites sit next to each other.
     *
     * @param int|null $ownerId null = every owner
     * @return list<array{id:int,domain:string,owner:string}>
     */
    public function listBrief(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        $rows = $this->db->all(
            'SELECT s.id, s.primary_domain, u.username AS owner_username
             FROM sites s JOIN users u ON u.id = s.owner_user_id'.$where.'
             ORDER BY u.username, s.primary_domain',
            $params,
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'domain' => (string) $row['primary_domain'],
            'owner' => (string) $row['owner_username'],
        ], $rows);
    }

    /**
     * @param string $domain
     * @return mixed
     */
    public function domainExists(string $domain): bool
    {
        $inSites = (int) $this->db->value('SELECT count(*) FROM sites WHERE primary_domain = :d', ['d' => $domain], 0);
        $inDomains = (int) $this->db->value('SELECT count(*) FROM domains WHERE domain = :d', ['d' => $domain], 0);

        return $inSites + $inDomains > 0;
    }

    /**
     * Reserve a row first to get an id for naming the system user (web_<id>)
     *
     * Must happen before the OS-level work, since the username must be both unique
     * and predictable. If the OS-level work fails, the caller is responsible for
     * deleting this row.
     */
    public function reserve(
        string $domain,
        string $phpVersion,
        int $ownerId,
        string $docrootOverride = '',
    ): int {
        $now = time();

        return $this->db->insert('sites', [
            'primary_domain' => $domain,
            'docroot' => '',
            'docroot_override' => $docrootOverride,
            'php_version' => $phpVersion,
            'status' => 'active',
            'owner_user_id' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /**
     * @param Site $site
     * @param int $uid
     * @param int $gid
     */
    /** Number of sites this user owns — used to decide whether their system account can be reclaimed yet */
    public function countOwnedBy(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT count(*) FROM sites WHERE owner_user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    /**
     * The Domain Pointer of every site sharing the same pool (same owner + same PHP version)
     *
     * A pool is shared across the whole account, so open_basedir must cover the
     * outside-home folder of every site using that pool, not just the one currently
     * being edited — otherwise editing one site would cut off another site's read access.
     *
     * @return list<string>
     */
    public function pointerDocrootsOwnedBy(int $userId, string $phpVersion): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT docroot_override FROM sites
             WHERE owner_user_id = :u AND php_version = :v AND docroot_override <> ''",
            ['u' => $userId, 'v' => $phpVersion],
        );

        return array_map(strval(...), array_column($rows, 'docroot_override'));
    }

    /**
     * The PHP versions this user actually has in use
     *
     * Used to decide which version's FPM pool file still needs to exist — a pool is
     * shared across the whole account, so deleting the file for a version that still
     * has a site using it = the sibling site goes down immediately.
     *
     * @return list<string>
     */
    public function phpVersionsOwnedBy(int $userId, int $exceptSiteId = 0): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT php_version FROM sites WHERE owner_user_id = :u AND id <> :except',
            ['u' => $userId, 'except' => $exceptSiteId],
        );

        return array_map(strval(...), array_column($rows, 'php_version'));
    }

    /**
     * Every site belonging to this user that uses this PHP version — used when writing the shared FPM pool file
     *
     * @return list<int>
     */
    public function idsOwnedBy(int $userId, ?string $phpVersion = null): array
    {
        $sql = 'SELECT id FROM sites WHERE owner_user_id = :u';
        $params = ['u' => $userId];

        if ($phpVersion !== null) {
            $sql .= ' AND php_version = :v';
            $params['v'] = $phpVersion;
        }

        return array_map(intval(...), array_column($this->db->all($sql.' ORDER BY id', $params), 'id'));
    }

    /**
     * Record the real path once provisioning has finished
     *
     * uid/gid no longer live on the site — they moved to the user since migration
     * 0006, since one system account now serves multiple sites of the same owner.
     */
    public function completeProvisioning(Site $site): void
    {
        $this->db->update('sites', [
            'docroot' => $site->docroot(),
            'updated_at' => time()
        ], ['id' => $site->id]);
    }

    /**
     * @param int $siteId
     * @param string $version
     */
    public function setPhpVersion(int $siteId, string $version): void
    {
        $this->db->update('sites', ['php_version' => $version, 'updated_at' => time()], ['id' => $siteId]);
    }

    /**
     * @param int $siteId
     * @param string $mode
     */
    public function setSslMode(int $siteId, string $mode): void
    {
        $this->db->update(
            'sites',
            ['ssl_mode' => Site::assertSslMode($mode), 'updated_at' => time()],
            ['id' => $siteId],
        );
    }

    /**
     * @param int $siteId
     * @param string $status
     */
    public function setStatus(int $siteId, string $status): void
    {
        $this->db->update('sites', ['status' => $status, 'updated_at' => time()], ['id' => $siteId]);
    }

    /**
     * @param int $siteId
     */
    public function delete(int $siteId): void
    {
        $this->db->run('DELETE FROM sites WHERE id = :id', ['id' => $siteId]);
    }

    /** @return array<string,int> PHP version => number of sites using it */
    public function countByPhpVersion(): array
    {
        $rows = $this->db->all('SELECT php_version, count(*) AS n FROM sites GROUP BY php_version');

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['php_version']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param int $siteId
     * @param int $userId
     */
    public function isOwnedBy(int $siteId, int $userId): bool
    {
        return (int) $this->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :u',
            ['id' => $siteId, 'u' => $userId],
            0,
        ) > 0;
    }
}
