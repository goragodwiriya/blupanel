<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Agent\Executor\Executor;

/**
 * The single unit of work the agent will perform — ARCHITECTURE §4.2
 *
 * Rules that must never be broken when writing a new capability:
 *   1. Never accept a "command" from the user — only "data", validated field by field
 *   2. validate() must return a clean array — run() must never touch the raw $args
 *   3. Every path/unit/user accepted as input must go through SelfProtection
 *   4. Any value that ends up in an argv must come from an allowlist, or pass a
 *      regex stricter than seems necessary
 *   5. Never call exec/proc_open/file_put_contents directly — always go through the Executor
 */
interface Capability
{
    /** The name used to call this over the protocol, e.g. "service.restart" */
    public static function name(): string;

    /** The permission the actor needs — checked before validate() */
    public function permission(): string;

    /**
     * Does this change system state?
     * false = dryrun mode will run the real thing, so the user sees the machine's actual state
     */
    public function isMutating(): bool;

    /** A short English summary for the audit log, e.g. "restart service" */
    public function summary(): string;

    /**
     * Validates and cleans up the argument
     *
     * @param array<string,mixed> $args raw values from the web side — not to be trusted
     * @return array<string,mixed> the validated values
     * @throws ValidationError|ProtectedResource
     */
    public function validate(array $args): array;

    /**
     * Does the actual work — $args is only ever the result of validate()
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed> the data to send back to the web side
     */
    public function run(array $args, Executor $executor, Context $context): array;
}
