<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * ลบฐานข้อมูล — งานที่ย้อนกลับไม่ได้ จึงบังคับสำรองอัตโนมัติก่อนเสมอ
 *
 * ต่างจากการลบเว็บไซต์ที่ย้ายไฟล์ไปถังพักได้ ฐานข้อมูลที่ DROP แล้วหายทันที
 * ทางเดียวที่จะกู้คืนได้คือมีไฟล์ dump อยู่ก่อน — ระบบจึงสำรองให้เองโดยไม่ถาม
 * และจะยกเลิกการลบทั้งหมดถ้าสำรองไม่สำเร็จ
 */
final class DbDrop extends DbCapability
{
    public static function name(): string
    {
        return 'db.drop';
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
        return 'ลบฐานข้อมูล (สำรองอัตโนมัติก่อนลบเสมอ)';
    }

    public function validate(array $args): array
    {
        return [
            'name' => MariaDbManager::assertDatabaseName(Validator::requireString($args, 'name', 64)),
            'confirm' => Validator::requireString($args, 'confirm', 64),
            'drop_user' => ($args['drop_user'] ?? '') === '1' || ($args['drop_user'] ?? false) === true,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();
        $name = $args['name'];

        if ($args['confirm'] !== $name) {
            throw new ValidationError('ชื่อฐานข้อมูลที่ยืนยันไม่ตรงกับที่จะลบ — ยกเลิกเพื่อความปลอดภัย');
        }

        $this->assertOwnership($context, $name);

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('เครื่องนี้ยังไม่ได้ติดตั้ง MariaDB หรือ MySQL');
        }

        // สำรองก่อนเสมอ — ถ้าสำรองไม่ได้ก็ไม่ลบ
        $stamp = date('Ymd-His');
        $backupPath = '/var/lib/phpcp/backups/db-' . $name . '-' . $stamp . '.sql';
        $bytes = $manager->dump($executor, $name, $backupPath);

        $manager->dropDatabase($executor, $name);

        $users = $this->removeRecords($context, $name, $args['drop_user']);

        foreach ($users as $user) {
            // ลบผู้ใช้ที่ไม่มีฐานข้อมูลอื่นใช้อยู่แล้วเท่านั้น
            $manager->dropUser($executor, $user['username'], $user['host']);
        }

        $this->recordBackup($context, $name, $backupPath, $bytes);
        $this->invalidateSizesCache($executor, $context);

        return [
            'name' => $name,
            'backup' => $backupPath,
            'backup_bytes' => $bytes,
            'users_removed' => array_column($users, 'username'),
            'message' => sprintf(
                'ลบฐานข้อมูล %s แล้ว — สำรองไว้ที่ %s (%s ไบต์) ก่อนลบ',
                $name,
                basename($backupPath),
                number_format($bytes),
            ),
        ];
    }

    /**
     * ลบแถวใน panel แล้วคืนรายชื่อผู้ใช้ที่ควรลบตามไปด้วย
     *
     * @return list<array{username:string,host:string}>
     */
    private function removeRecords(Context $context, string $name, bool $dropUser): array
    {
        return $context->db->transaction(static function ($db) use ($name, $dropUser): array {
            $row = $db->first('SELECT id FROM databases_ WHERE db_name = :n', ['n' => $name]);

            if ($row === null) {
                return [];   // ไม่ได้อยู่ในความดูแลของ panel — ลบบนเครื่องอย่างเดียวพอ
            }

            $databaseId = (int) $row['id'];
            $orphans = [];

            if ($dropUser) {
                $candidates = $db->all(
                    'SELECT u.id, u.username, u.host FROM db_grants g
                     JOIN db_users u ON u.id = g.db_user_id WHERE g.db_id = :id',
                    ['id' => $databaseId],
                );

                foreach ($candidates as $candidate) {
                    // ผู้ใช้ที่ยังผูกกับฐานข้อมูลอื่นอยู่ต้องไม่ถูกลบ
                    $others = (int) $db->value(
                        'SELECT count(*) FROM db_grants WHERE db_user_id = :u AND db_id != :d',
                        ['u' => $candidate['id'], 'd' => $databaseId],
                        0,
                    );

                    if ($others === 0) {
                        $orphans[] = ['username' => (string) $candidate['username'], 'host' => (string) $candidate['host']];
                        $db->run('DELETE FROM db_users WHERE id = :id', ['id' => $candidate['id']]);
                    }
                }
            }

            $db->run('DELETE FROM databases_ WHERE id = :id', ['id' => $databaseId]);

            return $orphans;
        });
    }

    private function recordBackup(Context $context, string $name, string $path, int $bytes): void
    {
        $context->db->insert('backups', [
            'name' => 'ก่อนลบฐานข้อมูล ' . $name,
            'type' => 'database',
            'site_id' => null,
            'path' => $path,
            'size_bytes' => $bytes,
            'checksum' => null,
            'status' => 'ok',
            'created_at' => time(),
        ]);
    }
}
