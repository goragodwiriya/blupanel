<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
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
        $site = $this->ownerSite($context, $name);
        $backupPath = $this->safetyPath($context, $site, $name);

        $executor->makeDirectory($executor->path(dirname($backupPath)), 0750);

        $bytes = $manager->dump($executor, $name, $backupPath);

        // ยกไฟล์ให้เจ้าของข้อมูล — ไม่งั้นลูกค้าที่ลบผิดตัวต้องขอให้ผู้ดูแลไปหยิบ
        // ไฟล์ของเขาเองให้ ทั้งที่มันอยู่ในบ้านของเขาแล้ว
        $owner = $site === null ? '' : BackupCapability::ownerString($context, $site->owner);

        if ($owner !== '') {
            $executor->exec(['/usr/bin/chown', $owner, $executor->path($backupPath)], timeout: 60);
            $executor->changeMode($executor->path($backupPath), 0640);
        }

        $manager->dropDatabase($executor, $name);

        $users = $this->removeRecords($context, $name, $args['drop_user']);

        foreach ($users as $user) {
            // ลบผู้ใช้ที่ไม่มีฐานข้อมูลอื่นใช้อยู่แล้วเท่านั้น
            $manager->dropUser($executor, $user['username'], $user['host']);
        }

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

    /**
     * ไฟล์สำรองก่อนลบไปอยู่ที่ไหน — **ในบ้านของเจ้าของฐานข้อมูลนั้น**
     *
     * เดิมไปกองที่ `/var/lib/phpcp/backups` แล้วบันทึกแถวไว้ในตาราง · ลูกค้าที่ลบ
     * ฐานข้อมูลผิดตัวจึงต้องขอให้ผู้ดูแลไปหยิบไฟล์ให้ ทั้งที่มันเป็นข้อมูลของเขาเอง
     * · ตอนนี้ไฟล์ลงในโฟลเดอร์สำรองของเขา แล้วโผล่ในรายการเองเพราะรายการอ่านจาก
     * โฟลเดอร์จริง (PLAN-BACKUP-V2 ข้อ B4) — ไม่ต้องมีแถวไหนคอยชี้ให้
     *
     * ฐานข้อมูลที่ไม่ได้ผูกกับเว็บของ panel ไม่มีบ้านให้ไปอยู่ จึงตกไปที่ที่พักของ panel
     * ตามเดิม — ไม่ใช่เหตุผลที่จะข้ามการสำรอง
     */
    private function safetyPath(Context $context, ?Site $site, string $name): string
    {
        $stamp = date('Ymd-His');

        if ($site === null) {
            return $context->config->paths->backups() . '/db-' . $name . '-' . $stamp . '.sql.gz';
        }

        // ขึ้นต้นด้วยโดเมนเหมือนไฟล์สำรองอื่น ๆ เพื่อให้รายการที่อ่านจากโฟลเดอร์
        // จับคู่ไฟล์กับเว็บได้ (ดู BackupFiles::domainOf) · ASCII ล้วนเพราะชื่อนี้
        // ถูกส่งกลับมาเป็นส่วนหนึ่งของ URL ตอนกดลบจากหน้าจอ
        return sprintf('%s/%s-db-%s-before-drop-%s.sql.gz', $site->backupDir(), $site->domain, $name, $stamp);
    }

    /** เว็บที่เป็นเจ้าของฐานข้อมูลนี้ — null = ฐานที่ไม่ได้อยู่ในความดูแลของ panel */
    private function ownerSite(Context $context, string $name): ?Site
    {
        $row = $context->db->first(
            'SELECT s.id FROM databases_ d JOIN sites s ON s.id = d.site_id WHERE d.db_name = :n',
            ['n' => $name],
        );

        return $row === null ? null : (new SiteRepository($context->db))->load((int) $row['id']);
    }
}
