<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Sets the never-ban address list — one machine-wide list, applied to every jail
 *
 * **The problem this solves:** a customer that is a school shares one outbound IP for
 * the whole school. One student's infected machine scanning automatically would lock
 * every teacher and student out of the school's own site — and out of every other
 * customer's site on the same machine too, because fail2ban commands the firewall,
 * which knows nothing about vhosts.
 *
 * **Saving the value alone isn't enough — the jail files have to be rewritten too** —
 * `ignoreip` is baked into the file at the moment a jail gets written. Save only the
 * value and stop there, and the new list only applies to jails written after this
 * point; a jail already running keeps banning the school exactly as before, while the
 * screen claims the exemption is registered — the same class of false security this
 * system has been closing off throughout.
 */
final class NeverBanSet implements Capability
{
    public static function name(): string
    {
        return 'security.never_ban_set';
    }

    public function permission(): string
    {
        return 'security.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Set the never-ban address list';
    }

    public function validate(array $args): array
    {
        return [
            'ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        $settings->save(['security.never_ban_ips' => $args['ips']]);

        $manager = (new Fail2banManager($executor))
            ->withNeverBan($args['ips'])
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));
        $rewritten = [];

        // The login jail — only rewritten while it's on, since writing it while off
        // would be the same as turning it on without the admin asking for that
        if ($settings->bool('security.panel_jail.enabled')) {
            $manager->applyPanelLogin($context->config->paths->logFile('audit'), [
                // **The existing mode must be sent along** — leave it out and it
                // falls back to the default (ban). Editing the exempt list would
                // then turn a jail set to "notify" into one that starts actually
                // banning people, with the admin never having asked for that and
                // nothing telling them it happened.
                'mode' => $settings->get('security.panel_jail.mode', Fail2banManager::MODE_BAN),
                'max_retry' => $settings->int('security.panel_jail.max_retry'),
                'find_seconds' => $settings->int('security.panel_jail.find_seconds'),
                'ban_seconds' => $settings->int('security.panel_jail.ban_seconds'),
                'ignore_ips' => $settings->get('security.panel_jail.ignore_ips'),
            ]);

            $rewritten[] = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        // Per-site jails that are on — read each site's existing values and rewrite the whole set
        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT * FROM site_rate_limits WHERE enabled = 1') as $row) {
            $site = $sites->load((int) $row['site_id']);

            if ($site === null) {
                continue;   // The site has already been deleted but the row is still there — not this command's error
            }

            $manager->apply($site, [
                'mode' => (string) ($row['mode'] ?? Fail2banManager::MODE_BAN),
                'max_requests' => (int) $row['max_requests'],
                'window_seconds' => (int) $row['window_seconds'],
                'ban_seconds' => (int) $row['ban_seconds'],
                'ignore_ips' => (string) $row['ignore_ips'],
            ]);

            $rewritten[] = $manager->jailName($site);
        }

        return [
            'ips' => $args['ips'],
            'rewritten' => $rewritten,
            'message' => $rewritten === []
                ? 'Never-ban list saved — no jail is on yet, so this list will be used once one is'
                : sprintf('Saved, and rewrote the files of %d jail(s) currently on, effective immediately', count($rewritten)),
        ];
    }
}
