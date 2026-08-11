<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Support\Validator;

/**
 * ปิด SFTP ของบัญชีโฮสติ้ง — PLAN-V2 เฟส E4
 *
 * เอาออกจากกลุ่ม `phpcp-sftp` แล้วล็อกรหัสผ่านของบัญชีระบบ · **ไม่ลบบัญชีระบบและไม่ลบไฟล์**
 * เพราะเว็บของผู้ใช้ยังต้องรันด้วย uid นั้นต่อไป — การปิด SFTP คือการปิดช่องทางเข้าถึง
 * ไม่ใช่การยกเลิกบริการ (นั่นคือ `service_status = suspended` ซึ่งเป็นคนละแกน)
 *
 * เรียกซ้ำได้โดยไม่ล้ม — บัญชีที่ปิดอยู่แล้วถือว่าได้ผลลัพธ์ที่ต้องการแล้ว
 */
final class SftpDisable extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'sftp.disable';
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
        return 'ปิดการใช้งาน SFTP ของบัญชีโฮสติ้ง';
    }

    public function validate(array $args): array
    {
        return ['user_id' => Validator::requireInt($args, 'user_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);

        // ไม่มีบัญชีระบบ = ไม่เคยเปิดได้อยู่แล้ว · แค่ล้างสถานะในฐานข้อมูลให้ตรงความจริง
        if (($user['system_user'] ?? null) !== null) {
            (new SftpAccessManager($executor))->disable(UserAccount::fromRow($user));
        }

        $context->db->update('users', [
            'sftp_enabled' => 0,
            'updated_at' => time(),
        ], ['id' => $args['user_id']]);

        return [
            'user_id' => (int) $args['user_id'],
            'username' => (string) $user['username'],
            'message' => sprintf('ปิด SFTP ของ %s แล้ว', (string) $user['username']),
        ];
    }
}
