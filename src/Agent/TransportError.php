<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** Couldn't reach the agent, or got a reply that doesn't match the protocol */
final class TransportError extends AgentException
{
    public function code(): string
    {
        return 'transport_error';
    }
}
