<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;

/**
 * Adds one firewall rule
 *
 * An allow rule can never lock anyone out of the machine once added, so it takes
 * effect immediately with no confirmation needed. A deny rule genuinely can close
 * off access, so it goes through the same timed-confirmation mechanism as editing SSH.
 *
 * This difference is deliberately visible on screen — not every action forces a
 * countdown, because if even opening port 80 required confirming, users would
 * start clicking confirm without reading.
 */
final class FirewallRuleAdd extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.rule_add';
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
        return 'Add firewall rule';
    }

    public function validate(array $args): array
    {
        $action = UfwDriver::assertAction((string) ($args['action'] ?? 'allow'));
        $protocol = (string) ($args['protocol'] ?? 'tcp');

        // The form only offers these two values — 'any', which the driver
        // accepts for existing rules, isn't accepted here
        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new ValidationError('Protocol must be tcp or udp');
        }

        return [
            'action' => $action,
            'port' => UfwDriver::assertPort(trim((string) ($args['port'] ?? ''))),
            'protocol' => $protocol,
            'source' => UfwDriver::assertSource(trim((string) ($args['source'] ?? ''))),
            'comment' => trim((string) ($args['comment'] ?? '')),
            'window' => $this->window($args),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);

        $driver = $this->driver();
        $guarded = $args['action'] === 'deny';

        if ($guarded) {
            $this->assertNoPending($context);
            $this->assertNotLifeline($args['port'], $context, $executor, 'block');
        }

        $driver->rule($executor, $args['action'], $args['port'], $args['protocol'], $args['source'], $args['comment']);

        $where = $args['source'] === '' ? 'anywhere' : $args['source'];
        $label = sprintf(
            '%s %s/%s from %s',
            $args['action'] === 'allow' ? 'allow' : 'block',
            $args['port'],
            $args['protocol'],
            $where,
        );

        if (!$guarded) {
            return [
                'rollback_id' => 0,
                'rule' => $label,
                'message' => 'Rule added: ' . $label,
            ];
        }

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.rule_add',
            description: 'Add firewall rule: ' . $label,
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            undo: [[
                'type' => 'ufw.rule_remove',
                'action' => $args['action'],
                'port' => $args['port'],
                'protocol' => $args['protocol'],
                'source' => $args['source'],
            ]],
        );

        return [
            'rollback_id' => $rollbackId,
            'rule' => $label,
            'window' => $args['window'],
            'message' => sprintf(
                'Block rule added: %s — confirm it still works within %d seconds, '
                . 'or the system will automatically remove this rule',
                $label,
                $args['window'],
            ),
        ];
    }
}
