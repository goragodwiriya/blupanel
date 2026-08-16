<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.restart — see ServiceAction for the security rules and execution path */
final class ServiceRestart extends ServiceAction
{
    public static function name(): string
    {
        return 'service.restart';
    }

    public function summary(): string
    {
        return 'Restart system service';
    }

    protected function verb(): string
    {
        return 'restart';
    }
}
