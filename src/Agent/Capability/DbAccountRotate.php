<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * หมุนรหัสผ่านบัญชี MariaDB ของตัวเอง
 *
 * รหัสนี้ไม่เคยผ่านตาผู้ใช้ การหมุนจึงไม่กระทบการใช้งานเลย — ต่างจากการเปลี่ยนรหัส
 * ฐานข้อมูลแบบเดิมที่ทำให้ต้องไปแก้ไฟล์ config ของเว็บทุกเว็บตามหลัง
 *
 * ใช้เมื่อสงสัยว่าไฟล์ `panel.db` หรือคีย์ใน `config.php` รั่ว: หมุนแล้วรหัสเดิม
 * ที่อาจหลุดไปพร้อมไฟล์จะใช้ไม่ได้อีก
 */
final class DbAccountRotate extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.account_rotate';
    }

    public function permission(): string
    {
        return 'db.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'หมุนรหัสผ่านบัญชีฐานข้อมูลของตัวเอง';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->currentUser($context);
        $account = $this->accountOf($user);

        $accounts = $this->dbAccounts($context);
        $accounts->rotate($executor, $account);
        $accounts->syncPrivileges($executor, $account, $this->isAdmin($user));

        // ไม่คืนรหัสใหม่ออกไป — ผู้ใช้ไม่ต้องรู้และไม่ต้องเอาไปทำอะไรต่อ
        // ใครต้องใช้จริงให้ขอผ่าน db.account_credentials ซึ่งเป็นเส้นทางเดียวที่คืนความลับ
        return [
            'user' => $account->username,
            'rotated' => true,
            'message' => "หมุนรหัสผ่านบัญชีฐานข้อมูลของ {$account->username} แล้ว",
        ];
    }
}
