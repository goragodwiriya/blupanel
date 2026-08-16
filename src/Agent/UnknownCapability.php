<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** No capability by this name in the registry — denied by default, no fallback, no dynamic dispatch */
final class UnknownCapability extends AgentException
{
    public function code(): string
    {
        return 'unknown_capability';
    }

    public function auditResult(): string
    {
        return 'denied';
    }
}
