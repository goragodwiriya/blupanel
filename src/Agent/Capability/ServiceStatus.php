<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Support\Validator;

/**
 * อ่านสถานะ service จาก systemd — อ่านอย่างเดียว
 *
 * รับเป็นรายการเพื่อให้หน้าที่ต้องแสดงหลายบริการใช้ round trip เดียว
 *
 * ความปลอดภัย: ชื่อ unit มาจาก allowlist ใน ServiceCatalog เท่านั้น
 * ต่อให้ผู้โจมตีส่ง "apache2; rm -rf /" มา in_array() ก็ปฏิเสธก่อนถึง executor
 * และต่อให้หลุดไปได้ argv array ก็ไม่ผ่าน shell อยู่ดี — ป้องกันสองชั้นโดยโครงสร้าง
 */
final class ServiceStatus implements Capability
{
    private const MAX_UNITS = 32;

    public static function name(): string
    {
        return 'service.status';
    }

    public function permission(): string
    {
        return 'service.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านสถานะบริการของระบบ';
    }

    public function validate(array $args): array
    {
        // ไม่ระบุมา = เอาทั้งหมดที่จัดการได้
        $requested = isset($args['services'])
            ? Validator::requireStringList($args, 'services', maxItems: self::MAX_UNITS, maxLength: 64)
            : ServiceCatalog::units();

        $clean = [];
        foreach ($requested as $unit) {
            $unit = strtolower($unit);

            SelfProtection::assertUnit($unit);

            if (!ServiceCatalog::isAllowed($unit)) {
                throw new ValidationError("ไม่รู้จักบริการ: {$unit}");
            }

            $clean[] = $unit;
        }

        if ($clean === []) {
            throw new ValidationError('ต้องระบุบริการอย่างน้อยหนึ่งรายการ');
        }

        return ['services' => $clean];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $result = [];

        foreach ($args['services'] as $unit) {
            $result[$unit] = ServiceProbe::read($executor, $unit);
        }

        return ['services' => $result];
    }
}
