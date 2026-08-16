<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Deletes a firewall rule — a change that always requires confirmation
 *
 * Deleting an allow rule = closing that port, which might be the very port
 * currently in use — so it always goes through the timed-confirmation
 * mechanism, no exceptions.
 *
 * The user picks a rule by the number shown on screen, but ufw's numbers can
 * shift at any time, so the rule's own signature text has to be sent along too,
 * to confirm the same rule that was intended is the one being deleted — this
 * guards against someone editing rules from another window while this page sits open.
 */
final class FirewallRuleDelete extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.rule_delete';
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
        return 'Delete firewall rule (auto-reverts if not confirmed in time)';
    }

    public function validate(array $args): array
    {
        $number = (int) ($args['number'] ?? 0);

        if ($number < 1) {
            throw new ValidationError('No rule specified to delete');
        }

        $expect = trim((string) ($args['expect'] ?? ''));

        if ($expect === '') {
            throw new ValidationError('No expected rule specified — reload the page and try again');
        }

        return ['number' => $number, 'expect' => $expect, 'window' => $this->window($args)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();
        $rules = $driver->status($executor)['rules'];
        $target = null;

        foreach ($rules as $rule) {
            if ($rule['number'] === $args['number']) {
                $target = $rule;
                break;
            }
        }

        if ($target === null) {
            throw new ValidationError('Rule number ' . $args['number'] . ' no longer exists — reload the page to see the latest list');
        }

        $signature = $this->signature($target);

        if ($signature !== $args['expect']) {
            throw new ValidationError(
                "Rule number {$args['number']} has changed (now \"{$signature}\") — "
                . 'someone edited the rules while this page was open — reload before deleting',
            );
        }

        if (!$target['manageable']) {
            throw new ValidationError('This rule is not in a shape the system can reconstruct, so it cannot be deleted from the web page');
        }

        $this->assertNotLifeline($target['port'], $context, $executor, 'delete the rule opening');

        $action = strtolower($target['action']);

        $driver->removeRule($executor, $action, $target['port'], $target['protocol'], $target['source_spec']);

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.rule_delete',
            description: 'Delete firewall rule: ' . $signature,
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            undo: [[
                'type' => 'ufw.rule_add',
                'action' => $action,
                'port' => $target['port'],
                'protocol' => $target['protocol'],
                'source' => $target['source_spec'],
            ]],
        );

        return [
            'rollback_id' => $rollbackId,
            'rule' => $signature,
            'window' => $args['window'],
            'message' => sprintf(
                'Rule deleted: %s — confirm it still works within %d seconds, '
                . 'or the system will automatically add this rule back',
                $signature,
                $args['window'],
            ),
        ];
    }

    /** A short text uniquely identifying a rule, used to check the screen and server still agree */
    public static function signature(array $rule): string
    {
        return sprintf('%s %s %s', $rule['action'], $rule['target'], $rule['source']);
    }
}
