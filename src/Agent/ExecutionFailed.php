<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** คำสั่งถูกอนุญาตและรันแล้ว แต่ทำงานไม่สำเร็จ */
final class ExecutionFailed extends AgentException
{
    public function __construct(
        string $message,
        public readonly int $exitCode = 1,
        public readonly string $stderr = '',
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return 'execution_failed';
    }
}
