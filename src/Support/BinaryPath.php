<?php

declare(strict_types=1);

namespace Phpcp\Support;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * Finds the first program file that genuinely exists from a list of accepted paths
 *
 * **Why this exists:** the same tool lives in a different place depending on
 * the distro — `named-checkzone` is at `/usr/bin` on Debian/Ubuntu but
 * `/usr/sbin` on RHEL · hardcoding one path made every call fail entirely on the other distro (genuinely hit this in phase E3 — see PLAN-V2 §6)
 *
 * **Never call a program by bare name and let the system search `PATH`
 * itself** — the agent runs as root, so relying on `PATH` would let a fake
 * program planted in a directory that comes first gain root immediately ·
 * this class therefore only accepts full paths specified in advance, with no open-ended search
 *
 * `tests/security/BinaryPathTest.php` pins down that every constant a driver
 * uses must point to a file that genuinely exists on the machine running the tests (when that program is installed)
 */
final class BinaryPath
{
    /**
     * @param list<string> $candidates full paths accepted, in the order they should be tried
     * @param string $package the package name to install if none is found — put in the message so the admin can act on it
     *
     * @throws ExecutionFailed when none of them are found
     */
    public static function resolve(Executor $executor, array $candidates, string $package): string
    {
        foreach ($candidates as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                return $candidate;
            }
        }

        throw new ExecutionFailed(sprintf(
            "The required program was not found (looked for %s)\n\nInstall it with `apt install %s` and try again",
            implode(' and ', $candidates),
            $package,
        ));
    }
}
