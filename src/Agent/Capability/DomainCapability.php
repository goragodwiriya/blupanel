<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;

/**
 * ฐานของ capability ที่ทำงานกับโดเมนเดียว — รวมกฎ "ใครแตะโดเมนไหนได้" ไว้ที่เดียว
 *
 * **agent ต้องไม่เชื่อผู้เรียก** ถึง web tier จะตรวจสิทธิ์ไปแล้วก็ตาม — รูปแบบเดียวกับ
 * `SiteCapability::assertSiteAccess()` · เดิมกฎนี้ถูกคัดลอกอยู่ใน `DnsZoneWrite`
 * ตัวเดียว ซึ่งพอมี capability ตัวที่สองที่ทำงานกับโดเมน การคัดลอกก็กลายเป็นความเสี่ยง
 * ที่แท้จริง: วันหนึ่งมีคนแก้กฎที่หนึ่งแล้วลืมอีกที่ แล้วช่องโหว่นั้นจะไม่มีอะไรฟ้อง
 */
abstract class DomainCapability implements Capability
{
    /**
     * โหลดโดเมนพร้อมตรวจสิทธิ์ — โยนออกทันทีเมื่อไม่มีสิทธิ์หรือไม่พบ
     *
     * @return array<string,mixed>
     */
    protected function loadDomain(Context $context, int $domainId): array
    {
        $domain = $context->db->first(
            'SELECT d.*, s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :id',
            ['id' => $domainId],
        );

        if ($domain === null) {
            throw new ValidationError('ไม่พบโดเมนที่ระบุ');
        }

        $actor = $context->actor;
        $ownerUserId = (int) ($domain['owner_user_id'] ?? 0);

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return $domain;
        }

        if ($ownerUserId !== $actor->userId) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์กับโดเมนที่ระบุ');
        }

        return $domain;
    }
}
