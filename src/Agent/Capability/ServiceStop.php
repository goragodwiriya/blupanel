<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.stop — see ServiceAction for the security rules and execution path */
final class ServiceStop extends ServiceAction
{
    public static function name(): string
    {
        return 'service.stop';
    }

    public function summary(): string
    {
        return 'Stop system service';
    }

    protected function verb(): string
    {
        return 'stop';
    }
}
