<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;

/**
 * Status of login brute-force protection
 *
 * **Answered from fail2ban itself, not from what the panel remembers** — the two can
 * genuinely disagree: an admin might delete the jail file by hand, or fail2ban might
 * not have loaded that jail because some other config broke. The question the screen
 * has to answer is "is this actually protecting right now", not "was the switch ever
 * clicked".
 *
 * The stored settings are sent along too, so the form can fill in its previous
 * values, and so the two can be seen disagreeing when they do.
 */
final class PanelJailStatus implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail';
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'View login brute-force protection status';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new Fail2banManager($executor);

        $status = $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL);
        $enabled = $settings->bool('security.panel_jail.enabled');

        return [
            'enabled' => $enabled,
            // Mode is what the screen chooses; enabled stays for older callers that
            // only know about on/off
            'mode' => $enabled
                ? $settings->get('security.panel_jail.mode', Fail2banManager::MODE_BAN)
                : Fail2banManager::MODE_OFF,
            'jail' => Fail2banManager::PANEL_LOGIN_JAIL,
            'max_retry' => $settings->int('security.panel_jail.max_retry'),
            'find_seconds' => $settings->int('security.panel_jail.find_seconds'),
            'ban_seconds' => $settings->int('security.panel_jail.ban_seconds'),
            'ignore_ips' => $settings->get('security.panel_jail.ignore_ips'),
            // The machine-wide list — its form lives on the same page, so send it along
            'never_ban_ips' => $settings->get('security.never_ban_ips'),
            'active' => $status['active'],
            'banned' => $status['banned'],
            'total_banned' => $status['total_banned'],
            'failed' => $status['failed'],
            'banned_ips' => $status['active'] ? $manager->bannedIpsOf(Fail2banManager::PANEL_LOGIN_JAIL) : [],
            // Set to on but fail2ban doesn't know this jail = the protection the
            // screen is advertising doesn't actually exist — this has to be said
            // plainly, not just shown as "on"
            'drifted' => $enabled && !$status['active'],
        ];
    }
}
