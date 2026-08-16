<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\Capability;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Mode;

/**
 * Picks an Executor for a capability based on the mode the system is set to — the
 * one place in the system that decides about mode
 *
 * The detail that makes dryrun actually useful: a read-only capability gets a
 * RealExecutor, so the user sees the machine's real state on screen, alongside the
 * list of commands the system "would" run if this were for real.
 */
final class ExecutorFactory
{
    private ?SandboxExecutor $sandbox = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function for(Capability $capability): Executor
    {
        return match ($this->config->mode) {
            Mode::Production => new RealExecutor(),

            // Reuses the same instance within the process so simulatedCommands() accumulates across the whole request
            Mode::Sandbox => $this->sandbox ??= new SandboxExecutor($this->config->sandboxPrefix()),

            Mode::DryRun => $capability->isMutating()
                ? new DryRunExecutor()
                : new RealExecutor(),
        };
    }
}
