<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;

/**
 * ฐานร่วมของ capability ที่จัดการบัญชีโฮสติ้ง (customer.*)
 *
 * ตั้งแต่ migration 0005 "ลูกค้า" คือแถวใน users ที่มี role=webadmin ไม่ใช่ตารางแยก
 * อีกต่อไป — capability กลุ่มนี้จึงทำงานบน users โดยตรง
 *
 * **ขอบเขตสิทธิ์ที่บังคับไว้ที่นี่:** ทุกตัวโหลดบัญชีผ่าน loadHostingAccount() ซึ่งยอมรับ
 * เฉพาะแถวที่ role=webadmin เท่านั้น · ผลคือผู้ที่มีสิทธิ์ `customer.manage` (sysadmin)
 * แตะบัญชี superadmin/sysadmin ผ่านเส้นทางนี้ไม่ได้เลย แม้จะเดารหัสบัญชีถูก
 * การจัดการบัญชีผู้ดูแลต้องใช้สิทธิ์ `user.manage` ซึ่งเป็นคนละเส้นทาง
 * ถ้าไม่กันตรงนี้ การยุบสองตารางเป็นตารางเดียวจะกลายเป็นการยกระดับสิทธิ์ให้ sysadmin ทันที
 *
 * กฎค่าโควตา (-1 = ไม่จำกัด, 0 = ปิด) อยู่ที่คลาส Quota ที่เดียว ที่นี่แค่แปลงชนิด
 * exception ให้เป็น ValidationError เพื่อให้ agent ตอบว่า "ค่าที่ส่งมาไม่ถูกต้อง"
 * แทนที่จะเป็น "เกิดข้อผิดพลาดภายในระบบ"
 */
abstract class CustomerCapability
{
    protected function users(Context $context): UserRepository
    {
        return new UserRepository($context->db);
    }

    protected function quotaChecker(Context $context): QuotaChecker
    {
        return new QuotaChecker($this->users($context));
    }

    /**
     * ตรวจค่าโควตา — ข้ามค่า null (ไม่ได้ส่งมา = ไม่เปลี่ยน)
     *
     * @throws ValidationError
     */
    protected static function assertQuota(?int $value, string $type): void
    {
        if ($value === null) {
            return;
        }

        try {
            Quota::assertValue($value, $type);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * โหลดบัญชีโฮสติ้ง หรือปฏิเสธไปเลย
     *
     * บัญชีที่ไม่ใช่ webadmin ตอบว่า "ไม่พบ" เหมือนกับที่ไม่มีอยู่จริง ไม่ใช่ "ห้ามเข้าถึง" —
     * เพราะการบอกว่า "มีอยู่แต่คุณแตะไม่ได้" คือการยืนยันให้ผู้เรียกรู้ว่ารหัสไหนเป็นบัญชีผู้ดูแล
     *
     * @return array<string,mixed>
     *
     * @throws ValidationError
     */
    protected function loadHostingAccount(Context $context, int $userId): array
    {
        $user = $this->users($context)->find($userId);

        if ($user === null || $user['role'] !== Permissions::WEBADMIN) {
            throw new ValidationError("ไม่พบบัญชีโฮสติ้งรหัส {$userId}");
        }

        return $user;
    }

    /**
     * เว็บไซต์ที่จะโอนให้มีโดเมน/ฐานข้อมูลติดมาอยู่แล้ว เจ้าของใหม่ยังมีโควตาพอหรือไม่
     *
     * ต้องตรวจตรงนี้ ไม่ใช่แค่ตอนเพิ่มโดเมนทีละรายการ — เว็บหนึ่งเว็บอาจมี subdomain
     * และ alias ติดมาสิบกว่ารายการพร้อมกัน การโอนจึงทำให้ทะลุโควตาได้ในคำสั่งเดียว
     *
     * @return array{ok:bool,message:string}
     */
    protected function checkAttachQuota(Context $context, int $userId, int $siteId): array
    {
        $counts = [
            'domains' => (int) $context->db->value(
                'SELECT count(*) FROM domains WHERE site_id = :id',
                ['id' => $siteId],
                0,
            ),
            'subdomains' => (int) $context->db->value(
                "SELECT count(*) FROM domains WHERE site_id = :id AND type = 'subdomain'",
                ['id' => $siteId],
                0,
            ),
            'aliases' => (int) $context->db->value(
                "SELECT count(*) FROM domains WHERE site_id = :id AND type = 'alias'",
                ['id' => $siteId],
                0,
            ),
            'databases' => (int) $context->db->value(
                'SELECT count(*) FROM databases_ WHERE site_id = :id',
                ['id' => $siteId],
                0,
            ),
        ];

        $quotaChecker = $this->quotaChecker($context);

        foreach ($counts as $type => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $result = $quotaChecker->canCreate($userId, $type, $amount);

            if (!$result['ok']) {
                return ['ok' => false, 'message' => $result['message']];
            }
        }

        return ['ok' => true, 'message' => ''];
    }
}
