<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Firewall\UfwDriver;

/**
 * Firewall status and rules — read-only
 *
 * Also flags the rule that allows the panel's own port, since that rule can't be
 * deleted, and the screen has to explain why clearly, not just gray out the button.
 */
final class FirewallStatus implements Capability
{
    public static function name(): string
    {
        return 'firewall.status';
    }

    public function permission(): string
    {
        return 'firewall.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read firewall status and rules';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $driver = new UfwDriver();
        $status = $driver->status($executor);
        $panelPort = (string) $context->config->int('panel.port', 8443);

        foreach ($status['rules'] as $index => $rule) {
            // The rule that opens the panel's own port — delete it and the web page becomes unreachable
            $status['rules'][$index]['is_panel_port'] = $rule['port'] === $panelPort;
        }

        return [
            'installed' => $status['installed'],
            'active' => $status['active'],
            'readable' => $status['readable'],
            'note' => $status['note'],
            'rules' => $status['rules'],
            'total' => count($status['rules']),
            'panel_port' => $panelPort,
        ];
    }
}
