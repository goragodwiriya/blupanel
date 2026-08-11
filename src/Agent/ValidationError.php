<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** argument ไม่ผ่าน schema ของ capability — ถือเป็นการปฏิเสธ ไม่ใช่ความผิดพลาดของระบบ */
final class ValidationError extends AgentException
{
    public function code(): string
    {
        return 'validation_error';
    }

    public function auditResult(): string
    {
        return 'denied';
    }
}
