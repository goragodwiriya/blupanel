<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Capability\FirewallRuleDelete;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Firewall — `/api/v2/firewall`
 *
 * **Every command that narrows access to the machine must be confirmed within
 * a time window**, or the system reverts it automatically (ARCHITECTURE §5.4)
 * · so a response always attaches `pending_rollback` — without it, the SPA
 * has no way to know it needs to show a countdown for the user to confirm,
 * and the user would silently lose the rule they just set the moment time
 * runs out, with no idea why
 *
 * Each rule's `signature` is sent along with the list and must be sent back
 * when deleting — this guards against the rule number shifting because
 * someone edited it from another window while this page sat open, which
 * would otherwise delete the wrong rule
 */
final class FirewallController extends ApiController
{
    public function index(Request $request): Response
    {
        $status = $this->agent()->data('firewall.status', [], $this->ctx->actor($request));
        $canManage = $this->ctx->can('firewall.manage');

        foreach ($status['rules'] ?? [] as $index => $rule) {
            $status['rules'][$index]['signature'] = FirewallRuleDelete::signature($rule);
            // The delete button's condition in the table can only read values in the same row — so permission must travel with the row
            $status['rules'][$index]['can_manage'] = $canManage;
        }

        return $this->ok($status, ['pending_rollback' => $this->pendingRollback()]);
    }

    /**
     * The empty shell of the add-rule form, with the command to open its modal
     *
     * A ufw rule can't be edited (only added or deleted — a rule's number
     * shifts when an earlier one is deleted), so there's only a form for a
     * new one · the route still follows the same shape as other pages, so
     * every page does the same thing: fire the request and hand the response
     * to ResponseHandler
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            ['action' => 'allow', 'port' => '', 'protocol' => 'tcp', 'source' => ''],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'firewall-rule-form.html',
                'title' => '{LNG_Add rule}',
                'titleClass' => 'icon-fire',
            ]],
        );
    }

    /** Add a rule — must be confirmed within `window` seconds, or it's automatically rolled back */
    public function addRule(Request $request): Response
    {
        $result = $this->agent()->data('firewall.rule_add', [
            'action' => $request->payloadString('action'),
            'port' => $request->payloadString('port'),
            'protocol' => $request->payloadString('protocol'),
            'source' => $request->payloadString('source'),
            'comment' => $request->payloadString('comment'),
            'window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW),
        ], $this->ctx->actor($request));

        $message = (string) ($result['message'] ?? 'Rule added — confirm it before the automatic rollback runs out');

        /*
         * Closes the form, then signals the page with no table named — the rules
         * table is bound to the page's own data (`data-attr="data:rules"`)
         * rather than fetching on its own, so `phpcp:reload` on the card is what
         * brings it back up to date (see the `data-refresh-event` in
         * firewall.html) · the "pending confirmation" bar refreshes off the
         * `pending_rollback` in the body, which ui.js watches for
         */
        return $this->saved($message, '', $result + ['pending_rollback' => $this->pendingRollback()])
            ->withHeader('Location', '/api/v2/firewall');
    }

    /**
     * Delete a rule by its number
     *
     * `expect` is the rule's signature as the user saw it when clicking the
     * button — the agent always compares it before deleting, so a shifted
     * number never deletes the wrong rule
     */
    public function deleteRule(Request $request): Response
    {
        $this->agent()->data('firewall.rule_delete', [
            'number' => $request->paramInt('number'),
            'expect' => $request->payloadString('expect') ?: $request->get('expect'),
            'window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            'Rule deleted — confirm it before the automatic rollback runs out',
            extra: ['number' => $request->paramInt('number'), 'pending_rollback' => $this->pendingRollback()],
        );
    }

    /**
     * Turn the whole firewall on or off
     *
     * Turning it on for the first time is the most dangerous command on this
     * page — if the rules don't already cover the SSH port, an admin gets
     * locked out of the machine instantly · so it must also be confirmed
     * within a time window · **turning it off** needs no confirmation, since
     * that widens access rather than narrowing it
     */
    public function setEnabled(Request $request): Response
    {
        $enabled = $request->payload('enabled');
        $wantEnabled = in_array($enabled, [true, 1, '1', 'true'], true);

        $result = $this->agent()->data(
            $wantEnabled ? 'firewall.enable' : 'firewall.disable',
            $wantEnabled ? ['window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW)] : [],
            $this->ctx->actor($request),
        );

        return $this->refreshed(
            (string) ($result['message'] ?? ($wantEnabled ? 'Firewall enabled' : 'Firewall disabled')),
            extra: $result + ['enabled' => $wantEnabled, 'pending_rollback' => $this->pendingRollback()],
        );
    }

    /**
     * The change currently pending confirmation, with the time remaining
     *
     * @return array<string,mixed>|null
     */
    private function pendingRollback(): ?array
    {
        $pending = (new RollbackGuard($this->app->db()))->pending();

        if ($pending === null) {
            return null;
        }

        return [
            'id' => (int) $pending['id'],
            'action' => (string) $pending['action'],
            'description' => (string) $pending['description'],
            'expires_at' => (int) $pending['expires_at'],
            'remaining_seconds' => RollbackGuard::remaining($pending),
        ];
    }
}
