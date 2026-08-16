<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Dns\BindZoneManager;

/**
 * Rewrites every domain's zone from scratch and tells BIND9 to reload once — PLAN-V2 Phase E3
 *
 * Used when: `dns.enabled` was just turned on for the first time (records added
 * earlier are sitting there, never yet exported), or someone edited BIND9's files
 * directly and the panel needs to overwrite them back to the state they should be in.
 *
 * **A machine-level permission, not a domain-level one** — unlike `dns.zone_write`
 * (which uses `domain.manage`, held by a site owner for their own domain), this
 * one overwrites the whole `named.conf.local` file and touches every customer's
 * zone at once in a single command, so it can only ever be a whole-machine permission.
 */
final class DnsReload implements Capability
{
    public static function name(): string
    {
        return 'dns.reload';
    }

    public function permission(): string
    {
        return 'dns.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Resync every domain zone with BIND9';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new BindZoneManager($executor, $context->config, $context->db);

        return $manager->reloadAll();
    }
}
