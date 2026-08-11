<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Support\Validator;

/**
 * เปิด SFTP ให้บัญชีโฮสติ้ง พร้อมตั้งรหัสผ่าน — PLAN-V2 เฟส E4
 *
 * ใช้เปลี่ยนรหัสผ่านด้วย (เรียกซ้ำได้) จึงไม่มี capability `sftp.password` แยกต่างหาก —
 * การเปิดใช้งานกับการตั้งรหัสผ่านใหม่คือการกระทำเดียวกันในทางเทคนิค และการมีสองเส้นทาง
 * ที่ทำสิ่งเดียวกันคือที่มาของกรณีที่ทางหนึ่งลืมตรวจสิ่งที่อีกทางตรวจ
 *
 * **สิทธิ์ `customer.manage` (superadmin/sysadmin) ไม่ใช่สิทธิ์ของลูกค้าเอง** — การเปิด SFTP
 * คือการเปิดช่องเข้าถึงเครื่องผ่าน sshd ซึ่งไม่มี rate limit และ 2FA แบบที่ panel มี
 * จึงเป็นการตัดสินใจของผู้ดูแลเซิร์ฟเวอร์ ไม่ใช่ของเจ้าของบัญชี
 *
 * **บัญชีระบบต้องมีอยู่ก่อน** — สร้างแบบ lazy ตอนสร้างเว็บแรก บัญชีที่ยังไม่มีเว็บเลย
 * จึงเปิด SFTP ไม่ได้ ซึ่งถูกต้องเพราะยังไม่มีไฟล์อะไรให้เข้าถึง
 */
final class SftpEnable extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'sftp.enable';
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
        return 'เปิดใช้งาน SFTP และตั้งรหัสผ่านให้บัญชีโฮสติ้ง';
    }

    public function validate(array $args): array
    {
        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'password' => Validator::requireString($args, 'password', 128),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);

        // โควตา 0 = แพ็กเกจนี้ไม่รวม SFTP · -1 หรือ >0 = เปิดได้ (ดูเหตุผลที่ migration 0013)
        if (Quota::isDisabled((int) ($user['quota_ftp_users'] ?? 0))) {
            throw new ValidationError(
                'บัญชีนี้ถูกตั้งโควตา FTP/SFTP เป็น 0 จึงเปิดใช้งานไม่ได้ — '
                . 'แก้โควตาก่อนถ้าต้องการให้ใช้ได้',
            );
        }

        if (($user['system_user'] ?? null) === null) {
            throw new ValidationError(
                'บัญชีนี้ยังไม่มีบัญชีระบบ (สร้างให้อัตโนมัติตอนสร้างเว็บไซต์แรก) — '
                . 'สร้างเว็บไซต์ก่อนจึงจะเปิด SFTP ได้',
            );
        }

        $account = UserAccount::fromRow($user);
        $result = (new SftpAccessManager($executor))->enable($account, $args['password']);

        $context->db->update('users', [
            'sftp_enabled' => 1,
            'sftp_enabled_at' => time(),
            'updated_at' => time(),
        ], ['id' => $args['user_id']]);

        // รหัสผ่านไม่อยู่ในคำตอบ — Dispatcher::redact() ปิดบังใน audit ให้อยู่แล้ว
        // แต่คำตอบที่ส่งกลับหน้าเว็บก็ไม่ควรมีซ้ำอีกที่หนึ่ง
        return $result + [
            'user_id' => (int) $args['user_id'],
            'message' => sprintf('เปิด SFTP ให้ %s แล้ว', $account->username),
        ];
    }
}
