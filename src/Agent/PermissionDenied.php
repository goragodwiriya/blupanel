<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** actor ไม่มีสิทธิ์เรียก capability นี้ */
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
