<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.start — ดูกฎความปลอดภัยและเส้นทางการทำงานที่ ServiceAction */
final class ServiceStart extends ServiceAction
{
    public static function name(): string
    {
        return 'service.start';
    }

    public function summary(): string
    {
        return 'เริ่มบริการของระบบ';
    }

    protected function verb(): string
    {
        return 'start';
    }
}
