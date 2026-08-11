<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * ตั้งรหัสผ่านใหม่ให้ผู้ใช้ฐานข้อมูล
 *
 * ใช้เมื่อรหัสผ่านหลุดหรือหาย — ระบบสุ่มให้ใหม่และแสดงครั้งเดียวเหมือนตอนสร้าง
 * เพราะ panel ไม่เก็บรหัสผ่านฐานข้อมูลไว้เลย
 */
final class DbUserPassword extends DbCapability
{
    public static function name(): string
    {
        return 'db.user_password';
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
        return 'ตั้งรหัสผ่านใหม่ให้ผู้ใช้ฐานข้อมูล';
    }

    public function validate(array $args): array
    {
        return [
            'username' => MariaDbManager::assertUserName(Validator::requireString($args, 'username', 32)),
            'host' => Validator::requireEnum(
                ['host' => $args['host'] ?? 'localhost'],
                'host',
                ['localhost', '127.0.0.1', '%'],
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('เครื่องนี้ยังไม่ได้ติดตั้ง MariaDB หรือ MySQL');
        }

        // ผู้ใช้ต้องผูกกับฐานข้อมูลที่ผู้สั่งงานมีสิทธิ์อย่างน้อยหนึ่งรายการ
        $databases = $context->db->all(
            'SELECT d.db_name FROM db_grants g
             JOIN db_users u ON u.id = g.db_user_id
             JOIN databases_ d ON d.id = g.db_id
             WHERE u.username = :u AND u.host = :h',
            ['u' => $args['username'], 'h' => $args['host']],
        );

        if ($databases === []) {
            throw new ValidationError('ไม่พบผู้ใช้ฐานข้อมูลนี้ในระบบ');
        }

        foreach ($databases as $row) {
            $this->assertOwnership($context, (string) $row['db_name']);
        }

        $password = self::randomPassword();
        $manager->setPassword($executor, $args['username'], $args['host'], $password);

        return [
            'username' => $args['username'],
            'host' => $args['host'],
            'password' => $password,
            'databases' => array_column($databases, 'db_name'),
            'message' => "ตั้งรหัสผ่านใหม่ให้ {$args['username']}@{$args['host']} แล้ว",
        ];
    }
}
