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
 * Master switch — does this machine let the panel use fail2ban at all?
 *
 * **Exists because fail2ban isn't free** — measured at 55MB on a real machine, and it
 * grows with every jail (one thread + one log tail + an in-memory map per jail). On a
 * 1–2GB machine that still has to run MariaDB, Apache, Dovecot, and rspamd, that's a
 * real thing to be able to cut, especially once the most valuable protection is
 * {@see \Phpcp\Driver\WebServer\ProbeBlocklist}, which runs at the web server and
 * costs no extra memory at all.
 *
 * **Turning it off does not stop the fail2ban service itself** — the SSH jail ships
 * with the distro, not with the panel, so stopping the service would drop SSH
 * brute-force protection too, a side effect the admin didn't ask for and might not
 * notice. The command to stop the service is handed back for them to run themselves,
 * with that warning attached, rather than doing it on their behalf.
 */
final class Fail2banSet implements Capability
{
    public static function name(): string
    {
        return 'security.fail2ban_set';
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
        return 'Turn fail2ban use on or off for the panel';
    }

    public function validate(array $args): array
    {
        return ['enabled' => Validator::requireBool($args, 'enabled')];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        if ($args['enabled']) {
            $settings->save(['security.fail2ban.enabled' => '1']);

            return [
                'enabled' => true,
                'removed' => [],
                'message' => 'fail2ban use turned on — choose a mode for each item below',
            ];
        }

        /*
         * Off = no jail the panel manages may be left on the machine at all.
         *
         * Saving the setting alone isn't enough — any jail still standing keeps
         * working and keeps costing memory, while the screen would say it's off.
         */
        $manager = new Fail2banManager($executor);
        $removed = [];

        if ($settings->bool('security.panel_jail.enabled')) {
            $manager->removePanelLogin();
            $removed[] = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT site_id FROM site_rate_limits WHERE enabled = 1') as $row) {
            $site = $sites->load((int) $row['site_id']);

            if ($site === null) {
                continue;
            }

            $manager->remove($site);
            $removed[] = $manager->jailName($site);
        }

        // Each item's stored state is turned off along with it — otherwise turning
        // the master switch back on would have the screen claim those are still on
        // when their files are already gone
        $context->db->run('UPDATE site_rate_limits SET enabled = 0, updated_at = :t', ['t' => time()]);

        $settings->save([
            'security.fail2ban.enabled' => '0',
            'security.panel_jail.enabled' => '0',
            'security.panel_jail.mode' => Fail2banManager::MODE_OFF,
        ]);

        return [
            'enabled' => false,
            'removed' => $removed,
            /*
             * Says how to actually reclaim the memory, along with what's given up —
             * someone turning this off for RAM reasons needs a command they can run
             * right away, not just "the panel isn't using it anymore".
             */
            'stop_command' => 'sudo systemctl disable --now fail2ban',
            'message' => sprintf(
                'fail2ban use turned off for the panel — removed %d jail(s). '
                . 'The fail2ban service is **still running**, because the SSH jail ships with '
                . 'the distro, not the panel. To reclaim the memory (~55MB), run '
                . '`sudo systemctl disable --now fail2ban` yourself — but SSH brute-force '
                . 'protection goes with it.',
                count($removed),
            ),
        ];
    }
}
