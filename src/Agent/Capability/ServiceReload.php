<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** service.reload — ดูกฎความปลอดภัยและเส้นทางการทำงานที่ ServiceAction */
final class ServiceReload extends ServiceAction
{
    public static function name(): string
    {
        return 'service.reload';
    }

    public function summary(): string
    {
        return 'สั่งให้บริการโหลดค่าตั้งใหม่โดยไม่หยุดทำงาน';
    }

    protected function verb(): string
    {
        return 'reload';
    }
}
