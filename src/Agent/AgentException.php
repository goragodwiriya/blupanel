<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * The root of every error in the agent layer
 *
 * Every one carries a short code that crosses the protocol, which the web side
 * uses to rebuild the same exception type and choose how to display it. The
 * message itself is written in English and passed through `HttpKernel::t()`
 * before it reaches a reader, same as any other user-facing string — see
 * `HttpKernel::handleException()`.
 */
abstract class AgentException extends \RuntimeException
{
    abstract public function code(): string;

    /** What result this should be recorded as in the audit log */
    public function auditResult(): string
    {
        return 'error';
    }
}
