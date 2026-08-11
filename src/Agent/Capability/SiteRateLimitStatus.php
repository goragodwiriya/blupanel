<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * อ่านสถานะจริงของการจำกัดอัตราจาก fail2ban — PLAN-V2 เฟส E5
 *
 * **อ่านจากตัว fail2ban ไม่ใช่จากฐานข้อมูลของ panel** เพราะสองอย่างนี้ไม่ตรงกันได้:
 * ผู้ดูแลอาจสั่ง `fail2ban-client` เองจากบรรทัดคำสั่ง · fail2ban อาจไม่ได้โหลด jail
 * เพราะไฟล์ผิด · หรือบริการถูกหยุดไปเลย — ทั้งสามกรณีฐานข้อมูลยังบอกว่า "เปิดอยู่"
 *
 * อ่านอย่างเดียวเหมือน `service.status` จึงไม่เติม audit log ทุกครั้งที่มีคนเปิดหน้าเว็บ
 */
final class SiteRateLimitStatus extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_status';
    }

    public function permission(): string
    {
        return 'site.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านสถานะการจำกัดอัตราคำขอ';
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $manager = new Fail2banManager($executor);
        $status = $manager->status($site);

        return [
            'site_id' => $args['site_id'],
            'jail' => $manager->jailName($site),
            'status' => $status,
            // ดึงรายการ IP เฉพาะเมื่อมีคนถูกแบนอยู่จริง — เรียก fail2ban-client เพิ่มอีกครั้ง
            // ทุกรอบทั้งที่รายการว่างเป็นการเสียเวลาเปล่าในกรณีที่พบบ่อยที่สุด
            'banned_ips' => $status['banned'] > 0 ? $manager->bannedIps($site) : [],
        ];
    }
}
