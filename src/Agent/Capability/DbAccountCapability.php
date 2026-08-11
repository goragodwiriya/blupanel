<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;
use Phpcp\Driver\Db\DbAccountManager;
use Phpcp\Security\Secret;

/**
 * ฐานร่วมของ capability ที่ดูแลบัญชี MariaDB ประจำผู้ใช้
 *
 * **กฎที่ห้ามละเมิดของ capability กลุ่มนี้: ทำงานกับบัญชีของ actor เองเท่านั้น**
 * ไม่มีตัวไหนรับ `user_id` เป็น argument เลย — ทุกตัวอ่านจาก `$context->actor->userId`
 * ซึ่งมาจาก session ที่ผ่านการยืนยันตัวตนแล้ว · ถ้ารับ id เป็น argument
 * แล้วค่อยตรวจสิทธิ์ทีหลัง จะเหลือช่องให้พลาดได้เสมอ (ลืมตรวจ ตรวจผิดตัวแปร
 * ตรวจหลังใช้งานไปแล้ว) แต่การไม่มี argument ให้ส่งมาตั้งแต่แรกทำให้พลาดไม่ได้เลย
 *
 * ผู้ดูแลระบบที่ต้องเข้าฐานข้อมูลของลูกค้าให้ใช้ root ผ่าน unix_socket บนเครื่อง
 * ซึ่งเป็นเส้นทางที่มี audit ของตัวเองอยู่แล้ว ไม่ใช่สวมบัญชีของลูกค้า
 */
abstract class DbAccountCapability extends DbCapability
{
    protected function dbAccounts(Context $context): DbAccountManager
    {
        return new DbAccountManager(
            $this->manager(),
            new DbAccountRepository($context->db),
            new Secret($context->config->secretKey()),
        );
    }

    /**
     * แถวของ actor ที่กำลังเรียก
     *
     * @return array<string,mixed>
     *
     * @throws ValidationError
     */
    protected function currentUser(Context $context): array
    {
        $userId = $context->actor->userId;

        if ($userId <= 0) {
            throw new ValidationError('คำสั่งนี้ต้องเรียกในนามผู้ใช้ที่ล็อกอินอยู่ ไม่ใช่ในนามระบบ');
        }

        $user = (new UserRepository($context->db))->find($userId);

        if ($user === null) {
            throw new ValidationError('ไม่พบผู้ใช้ที่กำลังใช้งานอยู่');
        }

        return $user;
    }

    /**
     * บัญชีระบบของ actor ที่กำลังเรียก — เป็นชื่อเดียวกับที่ใช้เป็นผู้ใช้ MariaDB
     *
     * @param array<string,mixed> $user
     *
     * @throws ValidationError
     */
    protected function accountOf(array $user): UserAccount
    {
        try {
            return UserAccount::fromRow($user);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * ผู้ใช้คนนี้ควรได้สิทธิ์ฐานข้อมูลระดับทั้งเครื่องหรือไม่
     *
     * **เฉพาะ superadmin เท่านั้น** — ผูกกับบทบาทโดยตรง ถอนบทบาทเมื่อไรสิทธิ์หาย
     * ทันทีที่เปิด phpMyAdmin ครั้งถัดไป ต่างจากการให้รหัส root ที่ให้ไปแล้วเอาคืนไม่ได้
     *
     * **ทำไมไม่รวม sysadmin ทั้งที่เป็นผู้ดูแลเหมือนกัน:** sysadmin มี `db.view`
     * แต่ไม่มี `db.manage` — ให้สิทธิ์ทั้งเครื่องใน MariaDB เท่ากับเปิดทางให้ทำผ่าน
     * phpMyAdmin ได้มากกว่าที่ panel ยอมให้ทำ ซึ่งทำให้ตาราง permission กลายเป็น
     * ของประดับ · สิทธิ์ในฐานข้อมูลต้องไม่เกินสิทธิ์ใน panel เด็ดขาด
     *
     * @param array<string,mixed> $user
     */
    protected function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === Permissions::SUPERADMIN;
    }
}
