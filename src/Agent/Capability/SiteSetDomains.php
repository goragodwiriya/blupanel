<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Sets a website's whole set of subdomains and aliases at once
 *
 * Accepts "the entire list this should now be", not "add one at a time",
 * because the vhost has to be rewritten as a whole file anyway — accepting the
 * whole set keeps the database and the file always in sync, with no way to end up
 * with an addition that succeeded while the vhost never updated.
 *
 * An added domain must not already be in use by another website — checked before touching any file.
 */
final class SiteSetDomains extends SiteCapability
{
    private const MAX_DOMAINS = 50;

    public static function name(): string
    {
        return 'site.set_domains';
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
        return 'Set website subdomains and aliases';
    }

    public function validate(array $args): array
    {
        $siteId = Validator::requireInt($args, 'site_id', 1);

        $domains = [];
        foreach (Validator::requireStringList($args, 'domains', self::MAX_DOMAINS, 253) as $entry) {
            $domains[] = Validator::domain($entry);
        }

        return [
            'site_id' => $siteId,
            'domains' => array_values(array_unique($domains)),
            'type' => Validator::requireEnum(
                ['type' => $args['type'] ?? 'alias'],
                'type',
                ['alias', 'subdomain'],
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);

        foreach ($args['domains'] as $domain) {
            if ($domain === $site->domain) {
                throw new ValidationError('The primary domain is already in the list — no need to add it again');
            }

            // The domain must genuinely be free, or already belong to this website under the type being set
            $owner = $context->db->first(
                'SELECT site_id, type FROM domains WHERE domain = :d',
                ['d' => $domain],
            );

            if ($owner === null) {
                continue;
            }

            if ((int) $owner['site_id'] !== $site->id) {
                throw new ValidationError("Domain {$domain} is already in use by another website");
            }

            if ($owner['type'] !== $args['type'] && $owner['type'] !== 'primary') {
                throw new ValidationError(
                    "Domain {$domain} is already a {$owner['type']} — remove it first to change its type",
                );
            }
        }

        // Merges in other domain types that weren't touched this round, into the
        // vhost too — otherwise saving aliases would drop subdomains from ServerAlias
        $kept = $context->db->all(
            "SELECT domain FROM domains
             WHERE site_id = :id AND type IN ('alias','subdomain') AND type != :type
             ORDER BY domain",
            ['id' => $site->id, 'type' => $args['type']],
        );
        $allAliases = array_values(array_unique([
            ...array_map(static fn (array $row): string => (string) $row['domain'], $kept),
            ...$args['domains'],
        ]));

        $updated = new \Phpcp\Domain\Site(
            id: $site->id,
            domain: $site->domain,
            owner: $site->owner,
            phpVersion: $site->phpVersion,
            sslMode: $site->sslMode,
            status: $site->status,
            aliases: $allAliases,
            memoryLimitMb: $site->memoryLimitMb,
            uploadLimitMb: $site->uploadLimitMb,
            maxChildren: $site->maxChildren,
            docrootOverride: $site->docrootOverride,
        );

        $transaction = new ConfigTransaction($executor);
        $provisioner->stageVhost($transaction, $updated, $executor);

        $transaction->commit(static fn (): array => $provisioner->webserver()->testConfig($executor));
        $provisioner->webserver()->reload($executor);

        $this->syncDomainRows($context, $site->id, $args['domains'], $args['type']);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'domains' => $allAliases,
            'count' => count($allAliases),
            'message' => sprintf(
                'Set %s\'s domain list to %d entries',
                $site->domain,
                count($allAliases),
            ),
        ];
    }

    /** @param list<string> $domains */
    private function syncDomainRows(Context $context, int $siteId, array $domains, string $type): void
    {
        $context->db->transaction(static function ($db) use ($siteId, $domains, $type): void {
            // Deletes only the type currently being set — never touches the primary domain, redirects, or other types
            $db->run(
                'DELETE FROM domains WHERE site_id = :id AND type = :type',
                ['id' => $siteId, 'type' => $type],
            );

            $now = time();
            foreach ($domains as $domain) {
                $db->insert('domains', [
                    'site_id' => $siteId,
                    'domain' => $domain,
                    'type' => $type,
                    'created_at' => $now,
                ]);
            }
        });
    }
}
