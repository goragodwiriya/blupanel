<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DbAccountRepository;

/**
 * คืนบัญชี MariaDB ของผู้ใช้ที่กำลังล็อกอินอยู่ เพื่อเปิด phpMyAdmin ให้โดยไม่ต้องพิมพ์รหัส
 *
 * **คำสั่งนี้คืนความลับออกไป** จึงมีข้อจำกัดที่ตั้งใจไว้สามข้อ:
 *   1. ไม่รับ argument ใด ๆ เลย — ทำงานกับบัญชีของ actor เท่านั้น
 *      จึงไม่มีทางขอรหัสของคนอื่นได้แม้จะแก้ payload ที่ส่งมา
 *   2. `isMutating() === false` แต่ยังสร้างบัญชีให้ถ้ายังไม่มี — เพราะการมีบัญชี
 *      MariaDB ไม่ได้เปลี่ยนอะไรที่ผู้ใช้มองเห็น มันเป็นแค่การเตรียมของที่ควรมีอยู่แล้ว
 *   3. รหัสผ่านที่คืนไป **ไม่ถูกบันทึกลง audit log** — Dispatcher ปิดบัง argument ให้แล้ว
 *      และผลลัพธ์ของคำสั่งนี้ต้องไม่ถูกเก็บ ซึ่งเป็นเหตุผลที่แยกเป็น capability ของตัวเอง
 *      แทนที่จะพ่วงไปกับ db.list
 */
final class DbAccountCredentials extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.account_credentials';
    }

    public function permission(): string
    {
        return 'db.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ขอบัญชีฐานข้อมูลของตัวเองสำหรับเข้า phpMyAdmin';
    }

    public function validate(array $args): array
    {
        // ตั้งใจไม่รับอะไรเลย — ดู DbAccountCapability
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->currentUser($context);
        $account = $this->accountOf($user);
        $isAdmin = $this->isAdmin($user);

        $accounts = $this->dbAccounts($context);
        $credentials = $accounts->credentials($executor, $account);

        // ปรับสิทธิ์ให้ตรงกับบทบาท**ทุกครั้งที่ออกบัตร** ไม่ใช่แค่ตอนสร้างบัญชี —
        // ผู้ดูแลที่ถูกลดบทบาทเป็นลูกค้าต้องเสียสิทธิ์เห็นทุกฐานข้อมูลทันทีที่เปิดครั้งถัดไป
        $accounts->syncPrivileges($executor, $account, $isAdmin);

        return $credentials + [
            'prefix' => DbAccountRepository::prefixFor($account->username),
            // หน้าจอใช้บอกผู้ใช้ว่ากำลังจะเข้าไปด้วยสิทธิ์ระดับไหน
            'global_privileges' => $isAdmin,
        ];
    }
}
