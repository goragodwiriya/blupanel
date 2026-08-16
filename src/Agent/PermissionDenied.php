<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** The actor doesn't have permission to call this capability */
final class PermissionDenied extends AgentException
{
    public function code(): string
    {
        return 'permission_denied';
    }

    public function auditResult(): string
    {
        return 'denied';
    }
}
