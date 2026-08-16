<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Reads rate limiting's real status from fail2ban — PLAN-V2 Phase E5
 *
 * **Reads from fail2ban itself, not the panel's database**, because the two can
 * drift out of sync: an admin might run `fail2ban-client` themselves from the
 * command line · fail2ban might have failed to load the jail because of a bad file
 * · or the service could simply be stopped — in all three cases the database would
 * still say "enabled".
 *
 * Read-only like `service.status`, so it doesn't add an audit log entry every time someone opens the page.
 */
final class SiteRateLimitStatus extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_status';
    }

    public function permission(): string
    {
        return 'site.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read rate limit status';
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $manager = new Fail2banManager($executor);
        $status = $manager->status($site);

        return [
            'site_id' => $args['site_id'],
            'jail' => $manager->jailName($site),
            'status' => $status,
            // Fetches the IP list only when someone is actually banned — calling
            // fail2ban-client again every time for an empty list would waste time
            // on the most common case
            'banned_ips' => $status['banned'] > 0 ? $manager->bannedIps($site) : [],
        ];
    }
}
