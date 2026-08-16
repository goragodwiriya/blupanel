<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * Disables the firewall
 *
 * Doesn't need a timed confirmation like other commands on this page, because
 * disabling the firewall can never lock anyone out of the machine — it opens
 * access up, it doesn't narrow it.
 *
 * But it does reduce the whole machine's security, so the screen asks for
 * confirmation the same way a delete does, and the audit log records who gave the
 * order and when.
 */
final class FirewallDisable extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.disable';
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
        return 'Disable firewall';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();

        if (!$driver->status($executor)['active']) {
            return ['message' => 'The firewall is already disabled'];
        }

        $driver->disable($executor);

        return ['message' => 'Firewall disabled — the machine now accepts connections on every port with a listening service'];
    }
}
