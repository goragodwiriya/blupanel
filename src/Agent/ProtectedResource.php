<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** พยายามแตะทรัพยากรของ Control Panel เอง — ARCHITECTURE §5.3 */
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
