<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** site.suspend — ดูรายละเอียดที่ SiteSetStatus */
final class SiteSuspend extends SiteSetStatus
{
    public static function name(): string
    {
        return 'site.suspend';
    }

    public function summary(): string
    {
        return 'ระงับเว็บไซต์ชั่วคราว (ไฟล์และฐานข้อมูลยังอยู่ครบ)';
    }

    protected function targetStatus(): string
    {
        return 'suspended';
    }
}
