<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.reload — see ServiceAction for the security rules and execution path */
final class ServiceReload extends ServiceAction
{
    public static function name(): string
    {
        return 'service.reload';
    }

    public function summary(): string
    {
        return 'Reload service configuration without stopping it';
    }

    protected function verb(): string
    {
        return 'reload';
    }
}
