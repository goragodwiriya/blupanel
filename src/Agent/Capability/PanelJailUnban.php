<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Unbans one IP from the login page's jail
 *
 * **The emergency exit for a feature that can ban the whole machine** — fail2ban's
 * ban commands the firewall, which blocks every port, so an admin who mistyped their
 * password past the threshold can't reach the control panel at all. If they still
 * have another machine or another network, they need to be able to unban from the
 * screen without having to find SSH access first.
 */
final class PanelJailUnban implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail_unban';
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
        return 'Unban an IP from login brute-force protection';
    }

    public function validate(array $args): array
    {
        $ip = Validator::requireString($args, 'ip', 45);

        // This value becomes an argument to fail2ban-client — it must be a real IP only
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('Invalid IP format');
        }

        /*
         * The jail name comes from the combined view, which lists several jails —
         * empty means the login page's jail, as before.
         *
         * **Must start with the panel's own prefix** — this value becomes an
         * argument to `fail2ban-client`. Accept any name at all, and anyone holding
         * security.manage could unban a jail an admin wrote by hand (the distro's
         * `sshd` jail, for instance) from the web UI — outside the panel's scope, and
         * reversing protection the panel never set up.
         */
        $jail = Validator::optionalString($args, 'jail', '', 64);

        if ($jail === '') {
            $jail = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        if (!Fail2banManager::isOwnJail($jail)) {
            throw new ValidationError('Only jails the panel manages can be unbanned this way');
        }

        return ['ip' => $ip, 'jail' => $jail];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new Fail2banManager($executor);

        $manager->unbanFrom($args['jail'], $args['ip']);

        return [
            'ip' => $args['ip'],
            'jail' => $args['jail'],
            'status' => $manager->statusOf($args['jail']),
            'message' => sprintf('Unbanned %s', $args['ip']),
        ];
    }
}
