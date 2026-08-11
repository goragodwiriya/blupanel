<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * มอบเว็บไซต์ที่ผู้ดูแลถืออยู่ให้บัญชีโฮสติ้ง
 *
 * ทำงาน:
 * - ตรวจว่าเว็บมีอยู่จริงและยังไม่ได้เป็นของลูกค้ารายอื่น (ถ้าเป็น ต้องใช้คำสั่งย้ายเจ้าของ)
 * - ตรวจสถานะบริการและโควตาของผู้รับ รวมของที่ติดมากับเว็บทั้งก้อน
 * - ตั้ง sites.owner_user_id ซึ่งเป็นแหล่งความจริงเดียวของความเป็นเจ้าของตั้งแต่ migration 0005
 */
final class CustomerSiteAttach extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.site_attach';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เชื่อมเว็บไซต์ให้ลูกค้าเป็นเจ้าของ';
    }

    public function validate(array $args): array
    {
        $userId = Validator::requireInt($args, 'user_id', 1);

        // ตรวจสอบ site_id (สามารถเป็น array เพื่อเชื่อมหลาย site)
        if (isset($args['site_ids']) && is_array($args['site_ids'])) {
            $siteIds = [];
            foreach ($args['site_ids'] as $id) {
                $siteIds[] = Validator::requireInt(['id' => $id], 'id', 1);
            }
            $siteIds = array_unique($siteIds);
        } else {
            $siteId = Validator::requireInt($args, 'site_id', 1);
            $siteIds = [$siteId];
        }

        // ตรวจสอบว่ามี site_id อย่างน้อย 1 ตัว
        if (empty($siteIds)) {
            throw new ValidationError('ต้องระบุอย่างน้อย 1 เว็บไซต์ที่จะเชื่อม');
        }

        return [
            'user_id' => $userId,
            'site_ids' => $siteIds,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $quotaChecker = $this->quotaChecker($context);
        $owner = $this->loadHostingAccount($context, $args['user_id']);

        $results = [];
        $attachedCount = 0;
        $alreadyAttachedCount = 0;
        $siteNotFoundCount = 0;
        $quotaExceededCount = 0;

        foreach ($args['site_ids'] as $siteId) {
            $site = $context->db->first('SELECT * FROM sites WHERE id = :id', ['id' => $siteId]);

            if ($site === null) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'not_found',
                    'message' => 'ไม่พบเว็บไซต์',
                ];
                $siteNotFoundCount++;
                continue;
            }

            // เจ้าของอยู่ที่ sites.owner_user_id ที่เดียวแล้ว — เดิมความเป็นเจ้าของถูกเก็บ
            // ทั้งใน customer_sites และ owner_user_id ซึ่งในฐานข้อมูลจริงขัดกันเองอยู่
            //
            // และเว็บทุกแห่งมีเจ้าของเสมอ (ฐานข้อมูลบังคับไว้) · "เว็บที่ยังไม่ได้ขาย" จึงหมายถึง
            // เว็บที่ผู้ดูแลถือไว้ ไม่ใช่เว็บที่ไม่มีใครเป็นเจ้าของ
            $currentOwner = (int) ($site['owner_user_id'] ?? 0);

            if ($currentOwner === $args['user_id']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'already_attached',
                    'message' => 'เว็บไซต์นี้เป็นของบัญชีนี้อยู่แล้ว',
                ];
                $alreadyAttachedCount++;
                continue;
            }

            // ดึงเว็บจากลูกค้ารายอื่นด้วยคำสั่งนี้ไม่ได้ — ต้องเป็นคำสั่งย้ายเจ้าของที่ตั้งใจ
            // เพราะตั้งแต่เฟส M3 การย้ายเจ้าของหมายถึงย้ายไฟล์ข้ามบ้านและเปลี่ยน uid ทั้งต้นไม้
            // (เว็บที่ผู้ดูแลถืออยู่ไม่เข้าเงื่อนไขนี้ — การมอบให้ลูกค้าคือหน้าที่ของคำสั่งนี้)
            $currentRole = (string) $context->db->value(
                'SELECT role FROM users WHERE id = :id',
                ['id' => $currentOwner],
                '',
            );

            if ($currentRole === Permissions::WEBADMIN) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'already_attached',
                    'message' => 'เว็บไซต์นี้เป็นของลูกค้ารายอื่นอยู่แล้ว ต้องย้ายเจ้าของก่อน',
                ];
                continue;
            }

            $service = $this->users($context)->checkService($args['user_id']);
            if (!$service['ok']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'customer_suspended',
                    'message' => $service['message'],
                ];
                continue;
            }

            // เว็บที่จะเชื่อมมีโดเมนย่อย/alias/ฐานข้อมูลติดมาอยู่แล้ว ต้องนับเข้าโควตาก่อนเชื่อม
            // ไม่ใช่ปล่อยให้ทะลุแล้วค่อยไปเจอตอนลูกค้าเพิ่มโดเมนถัดไปไม่ได้โดยไม่รู้สาเหตุ
            // ตรวจสดทุกรอบ จึงนับรวมเว็บอื่นที่เพิ่งเชื่อมไปในคำสั่งเดียวกันนี้ด้วย
            $quotaCheck = $this->checkAttachQuota($context, $args['user_id'], $siteId);
            if (!$quotaCheck['ok']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'quota_exceeded',
                    'message' => $quotaCheck['message'],
                ];
                $quotaExceededCount++;
                continue;
            }

            $context->db->update('sites', [
                'owner_user_id' => $args['user_id'],
                'updated_at' => time(),
            ], ['id' => $siteId]);

            $results[] = [
                'site_id' => $siteId,
                'status' => 'attached',
                'message' => "เชื่อมเว็บไซต์ {$site['primary_domain']} ให้ {$owner['username']} แล้ว",
            ];
            $attachedCount++;
        }

        // audit log บันทึกโดย Dispatcher ให้แล้วรอบ run() ทุกคำสั่ง (ARCHITECTURE §4.1)
        $message = $attachedCount > 0
            ? "เชื่อมเว็บไซต์ {$attachedCount} แห่งให้ {$owner['username']} แล้ว"
            : 'ไม่มีเว็บไซต์ที่เชื่อมใหม่';

        // เว็บที่ถูกข้ามเพราะโควตาเต็มต้องอยู่ในข้อความสรุป ไม่ใช่ซ่อนอยู่ใน results
        // ผู้ดูแลที่เลือก 5 เว็บแล้วเชื่อมได้ 2 ต้องรู้ทันทีว่าทำไมอีก 3 ไม่เข้า
        if ($quotaExceededCount > 0) {
            $message .= " (ข้าม {$quotaExceededCount} แห่งเพราะเกินโควตา)";
        }

        return [
            'user_id' => $owner['id'],
            'username' => $owner['username'],
            'attached_count' => $attachedCount,
            'already_attached_count' => $alreadyAttachedCount,
            'not_found_count' => $siteNotFoundCount,
            'quota_exceeded_count' => $quotaExceededCount,
            'results' => $results,
            'message' => $message,
        ];
    }
}
