<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * Operating mode per ARCHITECTURE §6
 *
 * The mode is read once at bootstrap and turned into the matching Executor — no
 * other code, capabilities least of all, may branch on the mode itself
 * (ARCHITECTURE §6.2).
 */
enum Mode: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';
    case DryRun = 'dryrun';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /** Does this mode need the persistent warning banner at the top of every page? */
    public function needsBanner(): bool
    {
        return $this !== self::Production;
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Sandbox => 'Sandbox',
            self::DryRun => 'Dry Run',
        };
    }
}
