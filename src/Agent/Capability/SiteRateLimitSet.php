<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Sets one website's request rate limit — PLAN-V2 phase E5
 *
 * Enforced through fail2ban, which reads that site's access log and tells the
 * firewall to ban an IP (the reason a web server module isn't used lives in
 * `Driver\Security\Fail2banManager`).
 *
 * **The database is always written after the file succeeds** — save it first and
 * have fail2ban fail, and the screen would say protection is on while nothing is
 * enforcing anything at all, more dangerous than not having the feature, because the
 * admin believes the site is protected.
 */
final class SiteRateLimitSet extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_set';
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
        return 'Configure a website\'s request rate limit';
    }

    public function validate(array $args): array
    {
        // `mode` (off|notify|ban) replaces the old on/off `enabled` — still accepted for compatibility
        $mode = Validator::optionalString($args, 'mode', '', 16);

        if ($mode === '') {
            $mode = Validator::requireBool($args, 'enabled')
                ? Fail2banManager::MODE_BAN
                : Fail2banManager::MODE_OFF;
        }

        if (!in_array($mode, Fail2banManager::modes(), true)) {
            throw new \Phpcp\Agent\ValidationError('Invalid jail mode — must be off, notify, or ban');
        }

        // Numeric fields are only checked while turning it on — someone turning it off
        // shouldn't be forced to fill in values that are about to go unused
        if ($mode === Fail2banManager::MODE_OFF) {
            return ['site_id' => Validator::requireInt($args, 'site_id', 1), 'mode' => $mode, 'enabled' => false];
        }

        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'mode' => $mode,
            'enabled' => true,
            // The range matches the CHECK in migration 0016 — checked twice because
            // the database is the last line of defence with no message for the user,
            // while this layer can say exactly what's wrong and what range is accepted
            'max_requests' => Validator::requireInt($args, 'max_requests', 10, 100_000),
            'window_seconds' => Validator::requireInt($args, 'window_seconds', 10, 3600),
            'ban_seconds' => Validator::requireInt($args, 'ban_seconds', 60, 86_400),
            'ignore_ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ignore_ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        // The machine-wide never-ban list has to be injected into every jail written
        // here — otherwise a customer that's a school (the whole school sharing one
        // outbound IP) gets the whole organisation banned over one site
        $manager = (new Fail2banManager($executor))
            ->withNeverBan((new SettingsRepository($context->db))->get('security.never_ban_ips'))
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));
        $now = time();

        if ($args['mode'] === Fail2banManager::MODE_OFF) {
            $manager->remove($site);

            $context->db->run(
                'UPDATE site_rate_limits SET enabled = 0, updated_at = :t WHERE site_id = :id',
                ['t' => $now, 'id' => $args['site_id']],
            );

            return [
                'site_id' => $args['site_id'],
                'enabled' => false,
                'message' => sprintf('Request rate limit turned off for %s', $site->domain),
            ];
        }

        $settings = [
            'mode' => $args['mode'],
            'max_requests' => $args['max_requests'],
            'window_seconds' => $args['window_seconds'],
            'ban_seconds' => $args['ban_seconds'],
            'ignore_ips' => $args['ignore_ips'],
        ];

        // File first, database after — throw here and there's no row claiming this is "on"
        $manager->apply($site, $settings);

        $context->db->run(
            'INSERT INTO site_rate_limits
                (site_id, enabled, mode, max_requests, window_seconds, ban_seconds, ignore_ips, created_at, updated_at)
             VALUES (:id, 1, :mode, :max, :window, :ban, :ignore, :t, :t)
             ON CONFLICT(site_id) DO UPDATE SET
                enabled = 1, mode = :mode, max_requests = :max, window_seconds = :window,
                ban_seconds = :ban, ignore_ips = :ignore, updated_at = :t',
            [
                'id' => $args['site_id'],
                'mode' => $settings['mode'],
                'max' => $settings['max_requests'],
                'window' => $settings['window_seconds'],
                'ban' => $settings['ban_seconds'],
                'ignore' => $settings['ignore_ips'],
                't' => $now,
            ],
        );

        return [
            'site_id' => $args['site_id'],
            'enabled' => true,
            'jail' => $manager->jailName($site),
            'status' => $manager->status($site),
            'message' => sprintf(
                'Rate limit turned on for %s — more than %d requests within %d seconds results in a %d-minute ban',
                $site->domain,
                $settings['max_requests'],
                $settings['window_seconds'],
                (int) round($settings['ban_seconds'] / 60),
            ),
        ];
    }
}
