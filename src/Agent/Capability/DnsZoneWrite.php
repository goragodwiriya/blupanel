<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Support\Validator;

/**
 * Writes one domain's zone file from its current `dns_records`, then tells BIND9
 * to reload — PLAN-V2 Phase E3
 *
 * Called after every place that edits that domain's `dns_records` (adding or
 * removing a record) — the whole zone file is rebuilt from the database every
 * time, never patched record by record, so it can never conflict even under rapid
 * repeated calls.
 *
 * Uses the same permission as `domain.manage` on purpose — a site owner can
 * already edit their own domain's DNS; pushing that value out to the real BIND9
 * is a natural consequence of the permission they already have, not a new one
 * (unlike `dns.reload`, which touches every domain at once — see the reasoning at
 * `Permissions::all()`).
 */
final class DnsZoneWrite extends DomainCapability
{
    public static function name(): string
    {
        return 'dns.zone_write';
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
        return 'Write domain zone file and reload BIND9';
    }

    public function validate(array $args): array
    {
        return ['domain_id' => Validator::requireInt($args, 'domain_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $this->loadDomain($context, $args['domain_id']);

        $manager = new BindZoneManager($executor, $context->config, $context->db);

        return $manager->writeZone($domain);
    }
}
