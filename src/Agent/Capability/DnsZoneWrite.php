<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * เขียน zone file ของโดเมนเดียวจาก `dns_records` ปัจจุบัน แล้วสั่ง BIND9 โหลดใหม่ — PLAN-V2 เฟส E3
 *
 * เรียกต่อจากทุกจุดที่แก้ `dns_records` ของโดเมนนั้น (เพิ่ม/ลบเรกคอร์ด) — zone ถูกสร้างใหม่
 * ทั้งไฟล์จากฐานข้อมูลทุกครั้ง ไม่ใช่ patch ทีละเรกคอร์ด จึงชนกันไม่ได้แม้เรียกถี่ ๆ
 *
 * ใช้สิทธิ์เดียวกับ `domain.manage` โดยตั้งใจ — ผู้ดูแลเว็บไซต์แก้ DNS ของโดเมนตัวเองได้
 * อยู่แล้ว การส่งค่านั้นไปให้ BIND9 จริงเป็นผลต่อเนื่องของสิทธิ์เดิม ไม่ใช่สิทธิ์ใหม่
 * (ต่างจาก `dns.reload` ที่กระทบทุกโดเมนพร้อมกัน — ดูเหตุผลที่ `Permissions::all()`)
 */
final class DnsZoneWrite implements Capability
{
    public static function name(): string
    {
        return 'dns.zone_write';
    }

    public function permission(): string
    {
        return 'domain.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เขียน zone file ของโดเมนแล้วสั่ง BIND9 โหลดใหม่';
    }

    public function validate(array $args): array
    {
        return ['domain_id' => Validator::requireInt($args, 'domain_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $context->db->first(
            'SELECT d.*, s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :id',
            ['id' => $args['domain_id']],
        );

        if ($domain === null) {
            throw new ValidationError('ไม่พบโดเมนที่ระบุ');
        }

        $this->assertDomainAccess($context, (int) $domain['owner_user_id']);

        $manager = new BindZoneManager($executor, $context->config, $context->db);

        return $manager->writeZone($domain);
    }

    /**
     * ผู้ดูแลเว็บไซต์แตะได้เฉพาะโดเมนของตัวเอง — ตรวจซ้ำที่ชั้นนี้แม้ web tier ตรวจไปแล้ว
     * เพราะ agent ต้องไม่เชื่อผู้เรียก (รูปแบบเดียวกับ `SiteCapability::assertSiteAccess()`)
     */
    private function assertDomainAccess(Context $context, int $ownerUserId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        if ($ownerUserId !== $actor->userId) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์กับโดเมนที่ระบุ');
        }
    }
}
