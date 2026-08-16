<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Client;
use Phpcp\Kernel\Db;

/**
 * Watches whether the agent is still responding — PLAN-V2 Phase E6 (added after
 * testing on a real machine)
 *
 * **Why this has to be separate from `alert.check`:** `alert.check` is a capability
 * that runs **through the agent** — once the agent is dead, the check itself can't
 * even be called, so the monitoring system dies right along with the thing it's
 * supposed to watch, with no message going out at all · worse, `ServiceCatalog`
 * strips the panel's own unit out via `SelfProtection`, so `alert.check` could never
 * see `phpcp-agentd` in the first place regardless.
 *
 * This class is therefore called from the **scheduler**, a separate process that's
 * still running when the agent goes down.
 *
 * **Records state only, never sends a message** — `phpcp-scheduler.service` is
 * hardened with `RestrictAddressFamilies=AF_UNIX` (TCP can't be opened at all, so
 * it can't fire Telegram/webhook) and `NoNewPrivileges=yes` (blocks `postdrop`'s
 * setgid, so it can't enter the mail queue — confirmed from a real log:
 * `mail_queue_enter: Permission denied`) · both are correct hardening layers that
 * must never be relaxed just for the convenience of sending a notification (§7.1
 * item 2).
 *
 * The job of actually sending a message therefore belongs to `bin/phpcp-alert`
 * instead, which systemd invokes via `phpcp-agentd.service`'s own `OnFailure=` —
 * running as root, it can send through every channel, and knows the instant the
 * agent dies without waiting for the scheduler's next pass.
 *
 * Why this class still needs to exist: `OnFailure=` never fires on `systemctl
 * stop` (systemd treats that as an intentional stop) · the state recorded here is
 * the only thing that lets the `/api/v2/alerts` page report that the agent isn't
 * running right now, regardless of the reason.
 *
 * The consequence worth knowing: a dead agent means **everything stops**, not just
 * the web page becoming unusable — the firewall/SSH auto-rollback mechanism has
 * nobody left to trigger it, and an admin in the middle of editing the firewall
 * would be permanently locked out of the machine · this is why it's classified
 * critical from the very first time it's detected.
 */
final class AgentHealth
{
    /** This threshold's key in `alert_state` — uses the same anti-spam mechanism as every other threshold */
    public const ALERT_KEY = 'agent';

    public function __construct(
        private readonly Db $db,
        private readonly Client $client,
    ) {
    }

    /**
     * Check once and record the state
     *
     * **Never sends a message** — see the class docblock for the full reasoning
     * (the scheduler can't send over TCP or mail) · `AlertRules` is still called to
     * record state and deduplicate, and the returned result says whether this
     * round **should** notify, in case a future caller with enough privilege can
     * actually send it themselves.
     *
     * @return array{available:bool,changed:bool,reason:string}
     */
    public function check(?int $now = null): array
    {
        $available = $this->client->isAvailable();

        $decision = (new AlertRules($this->db))->evaluate(
            self::ALERT_KEY,
            $available ? null : 'critical',
            $available ? 1.0 : 0.0,
            $now,
        );

        return [
            'available' => $available,
            'changed' => $decision['notify'],
            'reason' => $decision['reason'],
        ];
    }
}
