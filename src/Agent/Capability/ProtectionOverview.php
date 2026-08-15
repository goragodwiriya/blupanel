<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Security\Fail2banManager;

/**
 * The whole machine's protection picture in one response — every jail the panel
 * manages, plus every IP currently banned
 *
 * **Why this is combined into one view** — banned IPs used to be scattered across
 * each site's own page. An admin told "the customer can't reach the site" had to open
 * one page at a time to find which jail an address was stuck in. The real question is
 * "who is this machine banning right now", which is a machine-level question, not a
 * per-site one.
 *
 * **Read from fail2ban every time, never from what the panel remembers** — the two
 * can disagree: an admin can run `fail2ban-client` by hand at any time, and a jail
 * might not have loaded because some other config broke. `drifted` answers the case
 * where the stored value says on but the real thing isn't running, which is worse
 * than off, because the admin believes they're protected and stops looking further.
 */
final class ProtectionOverview implements Capability
{
    public static function name(): string
    {
        return 'security.protection';
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
        return 'Protection overview and banned IP list';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new Fail2banManager($executor);

        $master = $settings->bool('security.fail2ban.enabled');
        $running = $manager->isRunning();

        $jails = [];
        $bans = [];

        // --- the panel's own login jail -----------------------------------------
        $panelMode = $settings->get('security.panel_jail.mode', Fail2banManager::MODE_OFF);
        $panelStatus = $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL);

        $jails[] = $this->row(
            Fail2banManager::PANEL_LOGIN_JAIL,
            'Panel login page',
            $settings->bool('security.panel_jail.enabled') ? $panelMode : Fail2banManager::MODE_OFF,
            $panelStatus,
            '/server/security',
        );

        $panelBlocks = $settings->bool('security.panel_jail.enabled') && $panelMode === Fail2banManager::MODE_BAN;

        foreach ($this->bansOf($manager, Fail2banManager::PANEL_LOGIN_JAIL, $panelStatus) as $ban) {
            $bans[] = $ban + [
                'jail_label' => 'Panel login page',
                'blocks' => $panelBlocks,
                'state_label' => $panelBlocks ? 'Actually blocked' : 'Detected, not blocked',
            ];
        }

        // --- per-site jails -------------------------------------------------------
        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT * FROM site_rate_limits WHERE enabled = 1') as $rateLimit) {
            $site = $sites->load((int) $rateLimit['site_id']);

            if ($site === null) {
                continue;
            }

            $name = $manager->jailName($site);
            $status = $manager->statusOf($name);

            $jails[] = $this->row(
                $name,
                'Request rate limit — ' . $site->domain,
                (string) ($rateLimit['mode'] ?? Fail2banManager::MODE_BAN),
                $status,
                '/site?id=' . $site->id,
            );

            $blocks = (string) ($rateLimit['mode'] ?? Fail2banManager::MODE_BAN) === Fail2banManager::MODE_BAN;

            foreach ($this->bansOf($manager, $name, $status) as $ban) {
                $bans[] = $ban + [
                    'jail_label' => $site->domain,
                    'blocks' => $blocks,
                    'state_label' => $blocks ? 'Actually blocked' : 'Detected, not blocked',
                ];
            }
        }

        return [
            'fail2ban_enabled' => $master,
            'fail2ban_running' => $running,
            // The switch being off while the service still runs is normal (the SSH
            // jail comes from the distro) — but "switch on, service not running" is
            // protection that doesn't actually exist
            'drifted' => $master && !$running,
            'never_ban_ips' => $settings->get('security.never_ban_ips'),
            'jails' => $jails,
            'bans' => $bans,
            'banned_total' => count($bans),
            'memory_mb' => $manager->memoryUsageMb(),
        ];
    }

    /**
     * @param array{active:bool,banned:int,total_banned:int,failed:int} $status
     * @return array<string,mixed>
     */
    private function row(string $jail, string $label, string $mode, array $status, string $manageUrl): array
    {
        return [
            'jail' => $jail,
            'label' => $label,
            'mode' => $mode,
            'mode_label' => match ($mode) {
                Fail2banManager::MODE_BAN => 'Automatic ban',
                Fail2banManager::MODE_NOTIFY => 'Notify only',
                default => 'Off',
            },
            'mode_tone' => match ($mode) {
                Fail2banManager::MODE_BAN => 'danger',
                Fail2banManager::MODE_NOTIFY => 'warn',
                default => 'muted',
            },
            'active' => $status['active'],
            'banned' => $status['banned'],
            'failed' => $status['failed'],
            /*
             * **Notify mode still counts as "banned" inside fail2ban** — measured on
             * a real machine: issuing banip while in notify mode produces `Currently
             * banned: 1`, while the firewall stays empty because the action has no
             * command touching it at all.
             *
             * Showing that number as "banned" would be a lie — a reader would
             * understand it as someone actually cut off from the machine when nobody
             * was. The screen has to name what actually happens in that mode.
             */
            'blocks' => $mode === Fail2banManager::MODE_BAN,
            'count_label' => $mode === Fail2banManager::MODE_BAN ? 'Currently banned' : 'Detected',
            // Mode set but fail2ban doesn't know this jail = what the screen is
            // advertising doesn't actually exist
            'drifted' => $mode !== Fail2banManager::MODE_OFF && !$status['active'],
            'manage_url' => $manageUrl,
        ];
    }

    /**
     * @param array{active:bool,banned:int,total_banned:int,failed:int} $status
     * @return list<array<string,mixed>>
     */
    private function bansOf(Fail2banManager $manager, string $jail, array $status): array
    {
        if (!$status['active'] || $status['banned'] === 0) {
            return [];
        }

        return array_map(
            static fn (string $ip): array => ['ip' => $ip, 'jail' => $jail],
            $manager->bannedIpsOf($jail),
        );
    }
}
