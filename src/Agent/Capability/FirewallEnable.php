<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * Enables the firewall
 *
 * Of every command in the system, this is the one that locks admins out most
 * often: ufw's default policy denies all inbound traffic, so running enable
 * while there's no rule yet opening the SSH port cuts the connection instantly,
 * with no way back in without a real console.
 *
 * So the system always opens the panel's port and the SSH port first, then
 * enables — and on top of that still forces a timed confirmation, in case the
 * rules are right but something else goes wrong.
 */
final class FirewallEnable extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.enable';
    }

    public function permission(): string
    {
        return 'firewall.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Enable firewall (auto-reverts if not confirmed in time)';
    }

    public function validate(array $args): array
    {
        return ['window' => $this->window($args)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();
        $status = $driver->status($executor);

        if ($status['active']) {
            return ['rollback_id' => 0, 'opened' => [], 'message' => 'The firewall is already enabled'];
        }

        $opened = [];

        // Always opens the lifeline before enabling — order matters here, reversed means an instant lockout
        foreach ([$this->panelPort($context), $this->sshPort($executor)] as $port) {
            if ($this->alreadyAllowed($status['rules'], $port)) {
                continue;
            }

            $driver->rule($executor, 'allow', $port, 'tcp', '', 'phpcp lifeline');
            $opened[] = $port;
        }

        $driver->enable($executor);

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.enable',
            description: 'Enable firewall',
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            // Only reverts enabling — never deletes the lifeline rules just
            // opened, since those rules cause no harm and are useful the next
            // time the firewall gets enabled
            undo: [['type' => 'ufw.disable']],
        );

        $note = $opened === []
            ? ''
            : ' (opened port(s) ' . implode(', ', $opened) . ' first, so the machine stays reachable)';

        return [
            'rollback_id' => $rollbackId,
            'opened' => $opened,
            'window' => $args['window'],
            'message' => sprintf(
                'Firewall enabled%s — confirm it still works within %d seconds, '
                . 'or the system will automatically disable it again',
                $note,
                $args['window'],
            ),
        ];
    }

    /** @param list<array<string,mixed>> $rules */
    private function alreadyAllowed(array $rules, string $port): bool
    {
        foreach ($rules as $rule) {
            if ($rule['action'] === 'ALLOW' && $rule['direction'] === 'IN' && $this->covers((string) $rule['port'], $port)) {
                return true;
            }
        }

        return false;
    }
}
