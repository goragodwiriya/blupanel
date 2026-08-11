<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * สร้างข้อมูลสำรอง — PROMPT.md รองรับ 4 ชนิด
 *
 * บันทึก checksum ลงฐานข้อมูลทุกครั้ง เพราะการกู้คืนจะปฏิเสธไฟล์ที่ไม่มี checksum
 * ไฟล์สำรองที่ยืนยันความครบถ้วนไม่ได้ ถือว่าใช้กู้คืนไม่ได้
 *
 * **`destination_id` ทำให้ "สร้างแล้วส่งออก" เป็นคำสั่งเดียว** (เฟส E1) — จำเป็นกับงาน
 * ตามเวลา เพราะ scheduler เรียก capability ได้ทีละตัวต่อหนึ่งงาน · ถ้าต้องแยกสองขั้น
 * จะมีช่วงที่ไฟล์สำรองอยู่บนดิสก์ก้อนเดียวกับข้อมูลจริงโดยไม่มีอะไรพามันออกไป
 */
final class BackupCreate implements Capability
{
    public static function name(): string
    {
        return 'backup.create';
    }

    public function permission(): string
    {
        return 'backup.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'สร้างข้อมูลสำรองของเว็บไซต์ ฐานข้อมูล หรือค่าตั้งระบบ';
    }

    public function validate(array $args): array
    {
        $type = BackupManager::assertType(Validator::requireString($args, 'type', 16));

        return [
            'type' => $type,
            'site_id' => isset($args['site_id']) ? Validator::requireInt($args, 'site_id', 0) : 0,
            'database' => Validator::optionalString($args, 'database', '', 64),
            'note' => Validator::optionalString($args, 'note', '', 120),
            // 0 = เก็บไว้ในเครื่องอย่างเดียว · ระบุมาแล้วจะส่งออกต่อทันทีหลังสร้างเสร็จ
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new BackupManager($context->config->paths->backups());
        $type = $args['type'];

        // ค่าตั้งระบบเป็นของทั้งเครื่อง จึงจำกัดไว้ที่ผู้ดูแลระดับเซิร์ฟเวอร์
        if (in_array($type, ['config', 'full'], true)
            && !in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)
            && $context->actor->userId !== 0) {
            throw new PermissionDenied('การสำรองค่าตั้งระบบต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        $created = [];

        if (in_array($type, ['site', 'full'], true)) {
            $created[] = $this->siteBackup($manager, $executor, $context, $args, $type);
        }

        if (in_array($type, ['database', 'full'], true)) {
            $created[] = $this->databaseBackup($manager, $executor, $context, $args, $type);
        }

        if (in_array($type, ['config', 'full'], true)) {
            $result = $manager->backupConfig($executor);
            $created[] = $this->record($context, 'config', 0, 'ค่าตั้งเซิร์ฟเวอร์', $result, $args['note']);
        }

        if ($created === []) {
            throw new ValidationError('ไม่มีอะไรให้สำรอง — ตรวจสอบชนิดและเป้าหมายที่เลือก');
        }

        $bytes = array_sum(array_column($created, 'bytes'));
        $offsite = $this->pushAll($created, $args['destination_id'], $executor, $context);

        return [
            'created' => $created,
            'count' => count($created),
            'bytes' => $bytes,
            'offsite' => $offsite,
            'message' => sprintf('สร้างข้อมูลสำรอง %d รายการ รวม %s ไบต์', count($created), number_format($bytes))
                . ($offsite === [] ? '' : sprintf(' · ส่งออกนอกเครื่อง %d รายการ', count(array_filter($offsite, static fn (array $r): bool => $r['ok'])))),
        ];
    }

    /**
     * ส่งไฟล์ที่เพิ่งสร้างออกไปยังปลายทาง — ขั้นที่ทำให้ "สำรองอัตโนมัติ" มีความหมายจริง
     *
     * **ส่งไม่สำเร็จไม่ทำให้ทั้งคำสั่งล้ม** โดยตั้งใจ · ไฟล์ในเครื่องสร้างเสร็จแล้วและ
     * ใช้กู้คืนได้อยู่ · การโยน error ทิ้งทั้งงานจะทำให้ผู้ใช้เข้าใจว่าไม่มีไฟล์สำรองเลย
     * ทั้งที่มี · สถานะการส่งถูกบันทึกลง `offsite_status` ให้หน้าจอกับตัวเก็บกวาดเห็นแทน
     * (ตัวเก็บกวาดจะไม่ลบไฟล์ที่ยังส่งออกไม่สำเร็จ ดู `BackupPrune`)
     *
     * @param list<array<string,mixed>> $created
     * @return list<array{backup_id:int,ok:bool,error:string}>
     */
    private function pushAll(array $created, int $destinationId, Executor $executor, Context $context): array
    {
        if ($destinationId < 1) {
            return [];
        }

        $push = new BackupPush();
        $results = [];

        foreach ($created as $backup) {
            $id = (int) $backup['id'];

            try {
                $push->run(['backup_id' => $id, 'destination_id' => $destinationId], $executor, $context);
                $results[] = ['backup_id' => $id, 'ok' => true, 'error' => ''];
            } catch (\Throwable $e) {
                $results[] = ['backup_id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return array<string,mixed> */
    private function siteBackup(
        BackupManager $manager,
        Executor $executor,
        Context $context,
        array $args,
        string $type,
    ): array {
        if ($args['site_id'] < 1) {
            throw new ValidationError('ต้องเลือกเว็บไซต์ที่จะสำรอง');
        }

        $site = (new SiteRepository($context->db))->load($args['site_id']);

        if ($site === null) {
            throw new ValidationError('ไม่พบเว็บไซต์ที่ระบุ');
        }

        $this->assertSiteAccess($context, $site->id);

        $result = $manager->backupSite($executor, $site);

        return $this->record($context, 'site', $site->id, $site->domain, $result, $args['note']);
    }

    /** @return array<string,mixed> */
    private function databaseBackup(
        BackupManager $manager,
        Executor $executor,
        Context $context,
        array $args,
        string $type,
    ): array {
        $database = $args['database'];

        // โหมด full ที่เลือกเว็บไซต์ไว้ ให้หาฐานข้อมูลที่ผูกกับเว็บนั้นให้เอง
        if ($database === '' && $args['site_id'] > 0) {
            $row = $context->db->first(
                'SELECT db_name FROM databases_ WHERE site_id = :id LIMIT 1',
                ['id' => $args['site_id']],
            );
            $database = (string) ($row['db_name'] ?? '');
        }

        if ($database === '') {
            throw new ValidationError('ต้องระบุฐานข้อมูลที่จะสำรอง');
        }

        $this->assertDatabaseAccess($context, $database);

        $result = $manager->backupDatabase($executor, $database);

        return $this->record($context, 'database', $args['site_id'], $database, $result, $args['note']);
    }

    /**
     * @param array{path:string,bytes:int,checksum:string} $result
     * @return array<string,mixed>
     */
    private function record(
        Context $context,
        string $type,
        int $siteId,
        string $label,
        array $result,
        string $note,
    ): array {
        $name = BackupManager::typeLabel($type) . ' — ' . $label;

        if ($note !== '') {
            $name .= ' (' . $note . ')';
        }

        $id = $context->db->insert('backups', [
            'name' => $name,
            'type' => $type,
            'site_id' => $siteId > 0 ? $siteId : null,
            'path' => $result['path'],
            'size_bytes' => $result['bytes'],
            'checksum' => $result['checksum'],
            'status' => 'ok',
            'created_at' => time(),
        ]);

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'path' => $result['path'],
            'bytes' => $result['bytes'],
            'checksum' => $result['checksum'],
        ];
    }

    private function assertSiteAccess(Context $context, int $siteId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        if (!(new SiteRepository($context->db))->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์สำรองข้อมูลของเว็บไซต์นี้');
        }
    }

    private function assertDatabaseAccess(Context $context, string $database): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM databases_ d JOIN sites s ON s.id = d.site_id
             WHERE d.db_name = :n AND s.owner_user_id = :u',
            ['n' => $database, 'u' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์สำรองฐานข้อมูลนี้');
        }
    }
}
