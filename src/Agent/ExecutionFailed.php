<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** The command was allowed and ran, but didn't succeed */
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
