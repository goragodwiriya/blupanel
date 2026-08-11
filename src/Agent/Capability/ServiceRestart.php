<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.restart — ดูกฎความปลอดภัยและเส้นทางการทำงานที่ ServiceAction */
final class ServiceRestart extends ServiceAction
{
    public static function name(): string
    {
        return 'service.restart';
    }

    public function summary(): string
    {
        return 'รีสตาร์ตบริการของระบบ';
    }

    protected function verb(): string
    {
        return 'restart';
    }
}
