<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * A capability died mid-flight from an unexpected error — the agent itself is still fine
 *
 * **Must stay clearly separate from `TransportError`**, even though the two look
 * similar to a reader:
 *
 *   TransportError = couldn't reach the agent · transient · **retrying can fix it** · 503
 *   InternalError  = the agent received the command and code inside it broke ·
 *                     **retrying changes nothing** · 500
 *
 * Originally the `internal_error` code fell through to the `default` case of
 * `Client::exceptionFor()`, which is `TransportError` · so the page showed
 * AGENT_UNAVAILABLE at the exact moment some SQL in there referenced a column a
 * migration had already dropped — the admin went chasing the socket and running
 * `systemctl restart phpcp-agentd`, when the agent never had a problem at all, and
 * the real answer was sitting in a single line of the agent's own log.
 */
final class InternalError extends AgentException
{
    public function code(): string
    {
        return 'internal_error';
    }
}
