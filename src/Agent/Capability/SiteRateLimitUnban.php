<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Unbans an IP rate-limited on one website — PLAN-V2 Phase E5
 *
 * **A necessity, not a nice-to-have** — fail2ban's ban acts on the firewall, which
 * knows nothing about a vhost, so a banned IP can't reach **any site on the
 * machine**, including the panel itself · an admin who tested their own site a
 * bit too hard and got banned would have no way back into the web page at all
 * without this button (they'd have to find some other way onto the machine, which
 * might not exist).
 */
final class SiteRateLimitUnban extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_unban';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Unban a rate-limited IP';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // Format checked right here — never let it reach fail2ban-client's own arguments
            'ip' => Validator::ipAddress(Validator::requireString($args, 'ip', 45)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $manager = new Fail2banManager($executor);

        $manager->unban($site, $args['ip']);

        return [
            'site_id' => $args['site_id'],
            'ip' => $args['ip'],
            'status' => $manager->status($site),
            'message' => sprintf('Unbanned %s', $args['ip']),
        ];
    }
}
