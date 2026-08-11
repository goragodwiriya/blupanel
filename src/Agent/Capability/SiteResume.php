<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** site.resume — ดูรายละเอียดที่ SiteSetStatus */
final class SiteResume extends SiteSetStatus
{
    public static function name(): string
    {
        return 'site.resume';
    }

    public function summary(): string
    {
        return 'เปิดใช้งานเว็บไซต์ที่ถูกระงับ';
    }

    protected function targetStatus(): string
    {
        return 'active';
    }
}
