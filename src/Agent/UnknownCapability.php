<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** ไม่มี capability ชื่อนี้ในทะเบียน — ปฏิเสธเป็นค่าเริ่มต้น ไม่มี fallback ไม่มี dynamic dispatch */
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
