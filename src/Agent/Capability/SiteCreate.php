<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DnsZoneDefaults;
use Phpcp\Domain\ServerAddress;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Support\Validator;

/**
 * Creates a new website — ARCHITECTURE §11
 *
 * Does 6 things as a single unit — a failure partway through must revert everything:
 *   1. Reserve a database row to get an id (the system username is web_<id>)
 *   2. Create the website's system user
 *   3. Create its directories with 750 permissions and set ownership
 *   4. Write the FPM pool and vhost into a transaction
 *   5. Validate both services' config, and only then reload
 *   6. Save the outcome to the database
 *
 * Step 5 is what stops a bad vhost from taking down every site on the machine.
 */
final class SiteCreate extends SiteCapability
{
    public static function name(): string
    {
        return 'site.create';
    }

    public function permission(): string
    {
        return 'site.create';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Create a new website with a system user, FPM pool, and vhost';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $domain = Validator::domain(Validator::requireString($args, 'domain', 253));
        $phpVersion = self::assertPhpVersion(Validator::requireString($args, 'php_version', 8));

        $aliases = [];
        if (isset($args['aliases'])) {
            foreach (Validator::requireStringList($args, 'aliases', maxItems: 20, maxLength: 253) as $alias) {
                $alias = Validator::domain($alias);

                if ($alias === $domain) {
                    throw new ValidationError('An alias domain must not duplicate the primary domain');
                }

                $aliases[] = $alias;
            }
        }

        /*
         * `www.<domain>` is added as an alias automatically — every control panel does this.
         *
         * **Not a convenience — a consistency requirement**: the system
         * already creates a `www` DNS record when the site is created
         * ({@see \Phpcp\Domain\DnsZoneDefaults}); if it isn't also added as
         * an alias, that name resolves to this machine and falls through to
         * the web server's default vhost — a visitor sees nginx's welcome
         * page instead of the customer's site (found on the real server,
         * 2026-08-14) · DNS promises it works, so the vhost has to keep that promise.
         *
         * Skipped when the primary domain already starts with `www.` — `www.www.example.com` means nothing.
         */
        if (!str_starts_with($domain, 'www.')) {
            $aliases[] = 'www.'.$domain;
        }

        return [
            'domain' => $domain,
            'php_version' => $phpVersion,
            'aliases' => array_values(array_unique($aliases)),
            // Empty = use <home>/public · a short name or a full path is assembled/checked in run()
            'docroot' => Validator::optionalString($args, 'docroot', '', 4096),
            'pointer_root' => Validator::optionalString($args, 'pointer_root', '', 4096),
            'owner_user_id' => isset($args['owner_user_id'])
                ? Validator::requireInt($args, 'owner_user_id', 0)
                : 0
        ];
    }

    /**
     * Checks the customer's quota before creating a website
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $ownerUserId): void
    {
        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, 'domain', 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    /**
     * Resolves the owner and the system account name this site will run as
     *
     * A user who has never had a site yet won't have a system account —
     * the account name is the username, checked again with the same rule
     * used at user creation time, because the database row might have been
     * edited by hand beforehand, and this value is about to become a real
     * folder name and a real Linux account name.
     *
     * @throws ValidationError
     */
    private function resolveOwner(Context $context, int $ownerUserId): UserAccount
    {
        if ($ownerUserId <= 0) {
            throw new ValidationError('A website owner must be specified (owner_user_id)');
        }

        $user = (new UserRepository($context->db))->find($ownerUserId);

        if ($user === null) {
            throw new ValidationError("User {$ownerUserId} was not found to be the website's owner");
        }

        try {
            return UserAccount::fromRow($user);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * Records that this user now has a system account, with the uid/gid the OS actually assigned
     *
     * The uid is saved so the health check and disk quota (phase E2) can reference it without asking the OS again.
     *
     * @param array{uid:int,gid:int} $identity
     */
    private function rememberSystemUser(Context $context, UserAccount $owner, array $identity): void
    {
        $context->db->update('users', [
            'system_user' => $owner->username,
            'uid' => $identity['uid'],
            'gid' => $identity['gid'],
            'updated_at' => time(),
        ], ['id' => $owner->userId]);
    }

    /**
     * Locks an account's primary domain to its first website — decides who gets `public_html`
     *
     * **Only ever written when still empty**, because this value is the
     * file path of the website currently being served, in the cpanel
     * layout · overwriting it every time a new site is created would
     * silently move the original site from `public_html` to
     * `<home>/<domain>`, and a site that used to work would 404
     * immediately, with nobody having asked for anything to happen to it.
     *
     * Only meaningful for the cpanel layout, but recorded for every layout
     * anyway, because an account that switches to cpanel later needs the
     * answer already sitting there, instead of having to be guessed retroactively at that point.
     */
    private function rememberMainDomain(Context $context, UserAccount $owner, string $domain): void
    {
        if ($owner->mainDomain !== '') {
            return;
        }

        $context->db->run(
            "UPDATE users SET main_domain = :d, updated_at = :t WHERE id = :id AND main_domain = ''",
            ['d' => $domain, 't' => time(), 'id' => $owner->userId],
        );
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        if ($repository->domainExists($args['domain'])) {
            throw new ValidationError("Domain {$args['domain']} is already in use in the system");
        }

        foreach ($args['aliases'] as $alias) {
            if ($repository->domainExists($alias)) {
                throw new ValidationError("Alias domain {$alias} is already in use in the system");
            }
        }

        if (!$provisioner->fpm()->isVersionInstalled($executor, $args['php_version'])) {
            throw new ValidationError("This machine does not have PHP {$args['php_version']} installed");
        }

        // Domain Pointer — accepts either a short folder name or a full
        // path, and forces it to stay within the scope declared in config
        // only, or else an admin could point a vhost at /etc or /root
        $docroot = Validator::resolvePointerDocroot(
            $args['docroot'],
            $context->config->docrootRoots(),
            $context->config->pointerRoots(),
            $args['pointer_root'] ?? '',
        );

        // Every site has had to have an owner since migration 0006 — a
        // site's entire set of file paths is derived from its owner's home,
        // so creating a site without knowing the owner leaves no way to assemble a path at all
        $owner = $this->resolveOwner($context, $args['owner_user_id']);

        $this->assertQuota($context, $owner->userId);

        $siteId = $repository->reserve(
            $args['domain'],
            $args['php_version'],
            $owner->userId,
            $docroot,
        );

        $site = new Site(
            id: $siteId,
            domain: $args['domain'],
            owner: $owner,
            phpVersion: $args['php_version'],
            aliases: $args['aliases'],
            docrootOverride: $docroot,
        );

        $transaction = new ConfigTransaction($executor);

        try {
            // The system account is created lazily — a user's first site is
            // what triggers it, and subsequent sites can call this again
            // with no side effect, since ensure() is idempotent
            $identity = $provisioner->account()->ensure($executor, $owner);
            $this->rememberSystemUser($context, $owner, $identity);
            $this->rememberMainDomain($context, $owner, $site->domain);

            $provisioner->createDirectories($executor, $site);

            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($owner->userId, $site->phpVersion),
            );
            $transaction->commit(static fn(): array=> $provisioner->validate($executor, $site));

            $provisioner->reload($executor, $site);

            $repository->completeProvisioning($site);
            $this->recordDomains($context, $site);

            // Must come after recordDomains — a row in `domains` has to exist before a record can be attached to it
            $dns = $this->seedDnsZone($executor, $context, $site);

            if ($this->isLocalEnvironment($executor, $context)) {
                if (str_ends_with($site->domain, '.test')) {
                    $this->updateHostsFile($executor, $site->domain, true);
                }
                foreach ($site->aliases as $alias) {
                    if (str_ends_with($alias, '.test')) {
                        $this->updateHostsFile($executor, $alias, true);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Reverted at every layer, or a leftover system user or database
            // row would make it impossible to ever create the same domain again.
            // Only reverts what this command itself created — **the system
            // account is never deleted**, since the owner might have another
            // site still using that same account, and deleting it would
            // immediately take down a perfectly healthy site along with it.
            $transaction->rollback();
            $repository->delete($siteId);

            throw $e instanceof ExecutionFailed || $e instanceof ValidationError
                ? $e
                : new ExecutionFailed('Failed to create website: '.$e->getMessage());
        }

        return [
            'site_id' => $siteId,
            'domain' => $site->domain,
            'php_version' => $site->phpVersion,
            'system_user' => $site->systemUser(),
            'docroot' => $site->docroot(),
            'fpm_socket' => $site->fpmSocket(),
            'vhost' => $provisioner->webserver()->vhostPath($site),
            'aliases' => $site->aliases,
            'dns' => $dns,
            'message' => "Created website {$site->domain}"
                . (($dns['seeded'] ?? false)
                    ? sprintf(' · created a DNS zone (%d record(s) pointing to %s)', count($dns['records'] ?? []), $dns['ip'] ?? '')
                    : ' · did not create a DNS zone: ' . ($dns['reason'] ?? 'unknown reason')),
        ];
    }

    /** Records the primary domain and any alias domains into the domains table */
    private function recordDomains(Context $context, Site $site): void
    {
        $now = time();

        $context->db->insert('domains', [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'type' => 'primary',
            'created_at' => $now
        ]);

        foreach ($site->aliases as $alias) {
            $context->db->insert('domains', [
                'site_id' => $site->id,
                'domain' => $alias,
                'type' => 'alias',
                'created_at' => $now
            ]);
        }
    }

    /**
     * Creates a zone that works immediately for the new domain — never a blank DNS page
     *
     * **Creating a site used to never create any DNS record at all** — an
     * admin had to type every single line by hand, and the zone file
     * didn't exist until the first one was added · unlike every control
     * panel a user might be migrating from, where creating a domain gets a
     * zone that can already answer queries.
     *
     * Never throws on failure — the site was already created successfully
     * and works normally · DNS not being ready yet (`dns.enabled` isn't
     * turned on · no public IP could be found · BIND rejected the zone) is
     * not a reason to fail the whole site-creation job and revert
     * everything · it's reported back in the response instead.
     *
     * @return array<string,mixed>
     */
    private function seedDnsZone(Executor $executor, Context $context, Site $site): array
    {
        if (!$context->config->dnsEnabled()) {
            return ['seeded' => false, 'reason' => 'The BIND9 connection is not turned on yet'];
        }

        $ip = ServerAddress::detect($executor, $context->config->string('server.public_ip'));

        if ($ip === '') {
            return [
                'seeded' => false,
                'reason' => "Could not find the machine's public IP — set server.public_ip, then create the zone again from the DNS page",
            ];
        }

        $row = $context->db->first(
            'SELECT id FROM domains WHERE site_id = :s AND type = :t',
            ['s' => $site->id, 't' => 'primary'],
        );

        if ($row === null) {
            return ['seeded' => false, 'reason' => "This site's primary domain was not found"];
        }

        try {
            /*
             * A domain that falls under a zone this machine already manages
             * has to become **a record inside that zone**, never a separate
             * zone file of its own — see the full reasoning at
             * DnsZoneDefaults::parentZone()
             */
            $parent = DnsZoneDefaults::parentZone($context->db, $site->domain, (int) $row['id']);

            if ($parent !== null) {
                $created = DnsZoneDefaults::seedSubdomain($context->db, $parent['id'], $parent['label'], $ip);
                $zoneId = $parent['id'];
            } else {
                $created = DnsZoneDefaults::seed(
                    $context->db,
                    (int) $row['id'],
                    $site->domain,
                    $ip,
                    $context->config->dnsNameservers(),
                );
                $zoneId = (int) $row['id'];
            }

            $write = (new BindZoneManager($executor, $context->config, $context->db))
                ->writeZone($context->db->first('SELECT * FROM domains WHERE id = :id', ['id' => $zoneId]));

            return [
                'seeded' => true,
                'ip' => $ip,
                'records' => $created,
                'zone' => $parent['domain'] ?? $site->domain,
                'pushed' => $write['pushed'] ?? false,
            ];
        } catch (\Throwable $e) {
            return ['seeded' => false, 'ip' => $ip, 'reason' => $e->getMessage()];
        }
    }
}
