<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserRepository;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Adds one subdomain or alias at a time, without overwriting the existing list
 *
 * Accepts either a bare label (shop → shop.example.com) or a full FQDN, then
 * rewrites the vhost with the complete set of ServerAlias entries.
 */
final class SiteAddDomain extends SiteCapability
{
    private const MAX_DOMAINS = 50;

    public static function name(): string
    {
        return 'site.add_domain';
    }

    public function permission(): string
    {
        return 'domain.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Add subdomain or alias to a website';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $path = Validator::optionalString($args, 'path', '', 253);
        $path = trim($path);
        if ($path !== '') {
            $path = '/'.ltrim($path, '/');
            if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $path) === 1 || str_contains($path, '..')) {
                throw new ValidationError('Invalid destination path format');
            }
        }
        $host = Validator::requireString($args, 'host', 253);

        // Wildcard has to be identified as a distinct type right here (PLAN-V2
        // Phase E7), because it changes three things: how the certificate is
        // requested (DNS-01 only) · the vhost file's ordering (must come last) ·
        // and the security implications the screen has to warn about
        //
        // Can't point at a subpath — `*.example.com` covers names nobody has
        // registered yet, so binding it to a single folder means nothing, and
        // subdomainPaths only ever looks things up by full name anyway
        if (str_starts_with(trim(strtolower($host)), '*.')) {
            if ($path !== '' && $path !== '/') {
                throw new ValidationError('A wildcard domain cannot point at a subpath — only the website root is usable');
            }

            return [
                'site_id' => Validator::requireInt($args, 'site_id', 1),
                'host' => $host,
                'path' => '',
                'type' => 'wildcard',
            ];
        }

        $type = ($path === '' || $path === '/') ? 'alias' : 'subdomain';

        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'host' => $host,
            'path' => $path,
            'type' => $type
        ];
    }

    /**
     * Checks the owning customer's quota before adding a subdomain/alias
     *
     * MAX_DOMAINS above is a system-level safety ceiling per site, unrelated to
     * a customer's package — this is the real package quota
     * (quota_subdomains/quota_aliases).
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $siteId, string $type): void
    {
        $site = $context->db->first('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);
        if ($site === null) {
            return;
        }

        $ownerUserId = (int) ($site['owner_user_id'] ?? 0);

        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, $type, 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $domain = self::resolveHost((string) $args['host'], $site->domain);

        if ($domain === $site->domain) {
            throw new ValidationError('The primary domain is already in the list — no need to add it again');
        }

        if (in_array($domain, $site->aliases, true)) {
            throw new ValidationError("Domain {$domain} is already bound to this website");
        }

        if (count($site->aliases) >= self::MAX_DOMAINS) {
            throw new ValidationError('This website already has the maximum number of subdomains/aliases');
        }

        $this->assertQuota($context, $site->id, $args['type']);

        $owner = $context->db->first('SELECT site_id FROM domains WHERE domain = :d', ['d' => $domain]);
        if ($owner !== null && (int) $owner['site_id'] !== $site->id) {
            throw new ValidationError("Domain {$domain} is already in use by another website");
        }

        $aliases = [ ...$site->aliases, $domain];
        $subdomainPaths = $site->subdomainPaths;
        if ($args['type'] === 'subdomain') {
            $subdomainPaths[$domain] = $args['path'];
        }

        $updated = new Site(
            id: $site->id,
            domain: $site->domain,
            owner: $site->owner,
            phpVersion: $site->phpVersion,
            sslMode: $site->sslMode,
            status: $site->status,
            aliases: $aliases,
            memoryLimitMb: $site->memoryLimitMb,
            uploadLimitMb: $site->uploadLimitMb,
            maxChildren: $site->maxChildren,
            docrootOverride: $site->docrootOverride,
            subdomainPaths: $subdomainPaths,
        );

        $provisioner = $this->provisioner($context);

        // If it's a subdomain with a path specified, create the folder ahead of time
        if ($args['type'] === 'subdomain') {
            $subdoc = $site->docroot().'/'.ltrim($args['path'], '/');
            if (!$executor->exists($executor->path($subdoc))) {
                $executor->makeDirectory($executor->path($subdoc), 0750);

                $owner = $site->systemUser().':'.$provisioner->webserver()->runAsGroup();
                $executor->exec([
                    '/usr/bin/chown',
                    '-R',
                    $owner,
                    $executor->path($subdoc)
                ], timeout: 20);
            }
        }

        $transaction = new ConfigTransaction($executor);
        $provisioner->stageVhost($transaction, $updated, $executor);
        $transaction->commit(static fn(): array=> $provisioner->webserver()->testConfig($executor));
        $provisioner->webserver()->reload($executor);

        $context->db->insert('domains', [
            'site_id' => $site->id,
            'domain' => $domain,
            'type' => $args['type'],
            'redirect_target' => $args['type'] === 'subdomain' ? $args['path'] : null,
            'created_at' => time()
        ]);

        if ($this->isLocalEnvironment($executor, $context)) {
            if (str_ends_with($domain, '.test')) {
                $this->updateHostsFile($executor, $domain, true);
            }
        }

        $label = $args['type'] === 'subdomain' ? 'subdomain' : 'alias';

        return [
            'site_id' => $site->id,
            'domain' => $domain,
            'type' => $args['type'],
            'message' => "Added {$label} {$domain} to {$site->domain}"
        ];
    }

    /**
     * A bare label → appended to the primary domain · already has a dot → used as an FQDN
     */
    public static function resolveHost(string $host, string $primary): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new ValidationError('A subdomain name must be specified');
        }

        // `*.example.com` — the remainder is checked with the same rules as a
        // normal domain, then reassembled · Validator::domain()'s rules are
        // never loosened, since that value gets used to build filenames elsewhere too
        if (str_starts_with($host, '*.')) {
            return '*.' . Validator::domain(substr($host, 2));
        }

        if (str_contains($host, '.')) {
            return Validator::domain($host);
        }

        // A single label, like www, shop, api
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host) !== 1) {
            throw new ValidationError('A subdomain name can only contain letters, numbers, or hyphens');
        }

        return Validator::domain($host.'.'.$primary);
    }
}
