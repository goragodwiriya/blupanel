<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/** An argument failed the capability's schema — treated as a rejection, not a system error */
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
