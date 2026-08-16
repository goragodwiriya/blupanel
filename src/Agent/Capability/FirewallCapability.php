<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;

/**
 * Shared pieces every firewall capability uses
 *
 * The single most important rule here is "never lock yourself out" — the rules
 * that open the panel's port and the SSH port are the only way an admin can get
 * back in to fix a mistake. The logic protecting those two ports is gathered here
 * in one place, not scattered across every capability.
 */
abstract class FirewallCapability
{
    protected function driver(): UfwDriver
    {
        return new UfwDriver();
    }

    protected function guard(Context $context): RollbackGuard
    {
        return new RollbackGuard($context->db);
    }

    /** The port the panel is served on — the rule opening this port can never be deleted */
    protected function panelPort(Context $context): string
    {
        return (string) $context->config->int('panel.port', 8443);
    }

    /**
     * The SSH port actually in use, read from sshd_config rather than assumed to
     * be 22, because an admin may have already changed it through this very
     * panel's SSH page
     */
    protected function sshPort(Executor $executor): string
    {
        try {
            $values = (new SshManager())->read($executor);

            return (string) ($values['Port']['value'] ?? '22');
        } catch (\Throwable) {
            return '22';
        }
    }

    /**
     * Is this port a lifeline — handles both a single port and a range spanning it
     *
     * A range is checked too, because a rule like `deny 8000:9000/tcp` blocks
     * port 8443 right along with it, even though that number is never mentioned directly.
     */
    protected function covers(string $spec, string $port): bool
    {
        if ($spec === $port) {
            return true;
        }

        if (!str_contains($spec, ':')) {
            return false;
        }

        [$from, $to] = array_map('intval', explode(':', $spec, 2));
        $target = (int) $port;

        return $target >= $from && $target <= $to;
    }

    /** Never allow closing off your own way in — ARCHITECTURE §5.4 */
    protected function assertNotLifeline(string $port, Context $context, Executor $executor, string $what): void
    {
        $panel = $this->panelPort($context);

        if ($this->covers($port, $panel)) {
            throw new ValidationError(
                "Cannot {$what} port {$panel} — it's the port this web page is served on, "
                . 'doing so would leave no way back in to fix it',
            );
        }

        $ssh = $this->sshPort($executor);

        if ($this->covers($port, $ssh)) {
            throw new ValidationError(
                "Cannot {$what} port {$ssh} — it's the SSH port, the way back into the machine "
                . 'when the web page is unreachable — if this genuinely needs doing, do it directly on the machine',
            );
        }
    }

    protected function window(array $args): int
    {
        return isset($args['window'])
            ? max(30, min(900, (int) $args['window']))
            : RollbackGuard::DEFAULT_WINDOW;
    }

    /** A pending confirmation means nothing else can change until it's cleared */
    protected function assertNoPending(Context $context): void
    {
        if ($this->guard($context)->pending() !== null) {
            throw new ValidationError(
                'A change is waiting for confirmation — confirm it or let it roll back before making another',
            );
        }
    }

    protected function assertInstalled(Executor $executor): void
    {
        if (!$this->driver()->isInstalled($executor)) {
            throw new ValidationError('ufw was not found on this machine — install it with apt install ufw first');
        }
    }
}
