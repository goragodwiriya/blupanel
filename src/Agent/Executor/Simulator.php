<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * A simulator for one kind of command in sandbox mode
 *
 * Adding a new command means adding a class that implements this interface and
 * registering it in SandboxExecutor. A command with no simulator gets sent to run
 * for real (with its path already mapped into the sandbox prefix) — which is
 * deliberate: `apache2ctl -t`, for instance, has to run for real to check the
 * vhost files the system just generated.
 */
interface Simulator
{
    public function handles(string $binary): bool;

    /**
     * @param list<string> $argv
     * @param string|null  $stdin data fed to stdin — needed for commands that take
     *                            their input over stdin, such as the MariaDB client
     */
    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult;
}
