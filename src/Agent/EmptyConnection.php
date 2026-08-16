<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * A connection came in and closed without sending anything at all — this is a
 * health check confirming the agent can still accept work
 *
 * Kept separate from {@see TransportError} because the two must be handled
 * completely differently: a transport error is a command that was sent and broke,
 * which has to be logged for the admin to see. This one is an "are you still
 * there?" check succeeding exactly as intended every time — logging it would
 * create a line of noise per minute that buries real failures until they can't be
 * found.
 *
 * No special `code()` needed, since this is never sent back to a caller — the
 * other end has already closed.
 */
final class EmptyConnection extends AgentException
{
    public function __construct()
    {
        parent::__construct('The connection closed before a command was sent');
    }

    public function code(): string
    {
        return 'empty_connection';
    }
}
