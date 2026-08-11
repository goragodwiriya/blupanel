<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** ติดต่อ agent ไม่ได้ หรือได้ข้อความที่ผิดรูปแบบโปรโตคอล */
final class TransportError extends AgentException
{
    public function code(): string
    {
        return 'transport_error';
    }
}
