<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Template;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Removes one subdomain or alias at a time, then rewrites the vhost
 *
 * The primary domain can't be removed this way — the whole website must be deleted instead.
 */
final class SiteRemoveDomain extends SiteCapability
{
    public static function name(): string
    {
        return 'site.remove_domain';
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
        return 'Remove a subdomain or alias from a website';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'domain' => Validator::domain(Validator::requireString($args, 'domain', 253)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $domain = $args['domain'];

        if ($domain === $site->domain) {
            throw new ValidationError('The primary domain cannot be removed — the whole website must be deleted');
        }

        $row = $context->db->first(
            "SELECT id, type FROM domains
             WHERE site_id = :id AND domain = :d AND type IN ('alias','subdomain')",
            ['id' => $site->id, 'd' => $domain],
        );

        if ($row === null) {
            throw new ValidationError("Domain {$domain} not found on this website");
        }

        $aliases = array_values(array_filter(
            $site->aliases,
            static fn (string $alias): bool => $alias !== $domain,
        ));

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
        );

        $provisioner = $this->provisioner($context);
        $transaction = new ConfigTransaction($executor);
        $provisioner->stageVhost($transaction, $updated, $executor);
        $transaction->commit(static fn (): array => $provisioner->webserver()->testConfig($executor));
        $provisioner->webserver()->reload($executor);

        // Deletes this domain's DNS records too — a leftover record is useless once the domain is gone
        $context->db->run('DELETE FROM dns_records WHERE domain_id = :id', ['id' => $row['id']]);
        // This domain's mail files must disappear along with it — the row goes
        // away on its own via CASCADE, but the files don't follow, leaving a
        // customer's mail sitting on disk forever with nothing referencing it
        // anymore (PLAN-MAIL M3)
        if ((int) ($row['mail_enabled'] ?? 0) === 1) {
            (new MailboxManager(new Template($context->config->paths->templates())))
                ->removeDomainDir($executor, (string) $row['domain']);
        }

        $context->db->run('DELETE FROM domains WHERE id = :id', ['id' => $row['id']]);

        if ($this->isLocalEnvironment($executor, $context)) {
            if (str_ends_with($domain, '.test')) {
                $this->updateHostsFile($executor, $domain, false);
            }
        }

        return [
            'site_id' => $site->id,
            'domain' => $domain,
            'message' => "Removed domain {$domain} from {$site->domain}",
        ];
    }
}
