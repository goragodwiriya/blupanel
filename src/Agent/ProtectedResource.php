<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** Tried to touch a resource belonging to the control panel itself — ARCHITECTURE §5.3 */
final class ProtectedResource extends AgentException
{
    public function code(): string
    {
        return 'protected_resource';
    }

    public function auditResult(): string
    {
        return 'denied';
    }
}
