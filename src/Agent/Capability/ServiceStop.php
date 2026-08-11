<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.stop — ดูกฎความปลอดภัยและเส้นทางการทำงานที่ ServiceAction */
final class ServiceStop extends ServiceAction
{
    public static function name(): string
    {
        return 'service.stop';
    }

    public function summary(): string
    {
        return 'หยุดบริการของระบบ';
    }

    protected function verb(): string
    {
        return 'stop';
    }
}
