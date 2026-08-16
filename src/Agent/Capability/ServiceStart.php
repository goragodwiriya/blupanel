<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.start — see ServiceAction for the security rules and execution path */
final class ServiceStart extends ServiceAction
{
    public static function name(): string
    {
        return 'service.start';
    }

    public function summary(): string
    {
        return 'Start system service';
    }

    protected function verb(): string
    {
        return 'start';
    }
}
