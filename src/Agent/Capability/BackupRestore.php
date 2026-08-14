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
 * กู้คืนข้อมูลจากไฟล์สำรอง — งานที่เสี่ยงที่สุดในระบบสำรองข้อมูล
 *
 * เสี่ยงเพราะเป็นการ "เขียนทับของที่ใช้งานอยู่" ด้วยข้อมูลเก่า มาตรการที่ใช้:
 *   1. ตรวจ checksum ก่อน — ไฟล์เสียหายบางส่วนอันตรายกว่าไม่มีไฟล์
 *   2. สำรองสถานะปัจจุบันอัตโนมัติก่อนเขียนทับ — กู้ผิดตัวแล้วยังย้อนได้
 *   3. แตกไฟล์ลงที่ชั่วคราวแล้วค่อยสลับ — ล้มกลางทางไม่เหลือไฟล์ปนกัน
 *   4. ต้องพิมพ์ชื่อโดเมนยืนยัน — กันการกดผิดรายการ
 */
final class BackupRestore implements Capability
{
    public static function name(): string
    {
        return 'backup.restore';
    }

    public function permission(): string
    {
        return 'backup.restore';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'กู้คืนข้อมูลจากไฟล์สำรอง (ตรวจ checksum และสำรองของเดิมก่อนเสมอ)';
    }

    public function validate(array $args): array
    {
        return [
            'backup_id' => Validator::requireInt($args, 'backup_id', 1),
            'confirm' => Validator::requireString($args, 'confirm', 253),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $backup = $context->db->first('SELECT * FROM backups WHERE id = :id', ['id' => $args['backup_id']]);

        if ($backup === null) {
            throw new ValidationError('ไม่พบข้อมูลสำรองที่ระบุ');
        }

        if ($backup['status'] !== 'ok') {
            throw new ValidationError('ข้อมูลสำรองนี้สร้างไม่สำเร็จ จึงกู้คืนไม่ได้');
        }

        if ($backup['type'] !== 'site') {
            // การกู้คืนฐานข้อมูลและค่าตั้งระบบมีขั้นตอนต่างออกไปมาก
            // ทำครึ่ง ๆ กลาง ๆ อันตรายกว่าไม่ทำ จึงยังไม่เปิดจนกว่าจะทำครบ
            throw new ValidationError(
                'ตอนนี้กู้คืนได้เฉพาะไฟล์เว็บไซต์ — การกู้คืนฐานข้อมูลและค่าตั้งระบบยังไม่เปิดใช้งาน',
            );
        }

        $site = (new SiteRepository($context->db))->load((int) $backup['site_id']);

        if ($site === null) {
            throw new ValidationError('ไม่พบเว็บไซต์ปลายทางของข้อมูลสำรองนี้ อาจถูกลบไปแล้ว');
        }

        $this->assertAccess($context, $site->id);

        if ($args['confirm'] !== $site->domain) {
            throw new ValidationError('ชื่อโดเมนที่ยืนยันไม่ตรงกับเว็บไซต์ปลายทาง — ยกเลิกเพื่อความปลอดภัย');
        }

        $manager = new BackupManager($context->config->paths->backups());

        /*
         * เจ้าของที่ไฟล์ต้องเป็นหลังกู้คืน — ชุดเดียวกับที่ `site.reset_owner` ใช้
         *
         * ไม่ส่งค่านี้ไปแปลว่าไฟล์ที่กู้มาจะเป็นของ root (ไดเรกทอรี) และของ uid ต้นทาง
         * (ไฟล์ข้างใน) ซึ่งพังทันทีเมื่อกู้ข้ามเครื่อง · ดูเหตุผลเต็มใน restoreSite()
         *
         * shared_owner แปลว่า filesystem เก็บเจ้าของไฟล์ไม่ได้อยู่แล้ว — ส่งค่าว่าง
         * เพื่อข้าม chown แทนที่จะให้มันล้มแล้วยกเลิกการกู้คืนทั้งครั้ง
         */
        $owner = '';

        if (!$context->config->sharedOwner()) {
            $group = SiteCapability::provisionerFor($context)->webserver()->runAsGroup();
            $owner = $site->systemUser() . ':' . $group;
        }

        $result = $manager->restoreSite(
            $executor,
            $site,
            (string) $backup['path'],
            (string) ($backup['checksum'] ?? ''),
            $owner,
        );

        // บันทึกไฟล์สำรองอัตโนมัติที่เกิดขึ้นระหว่างกู้คืน ให้เห็นในรายการด้วย
        $safetyId = $context->db->insert('backups', [
            'name' => 'ก่อนกู้คืน ' . $site->domain,
            'type' => 'site',
            'site_id' => $site->id,
            'path' => $result['safety'],
            'size_bytes' => (int) ($executor->stat($executor->path($result['safety']))['size'] ?? 0),
            'checksum' => @hash_file('sha256', $executor->path($result['safety'])) ?: null,
            'status' => 'ok',
            'created_at' => time(),
        ]);

        return [
            'backup_id' => (int) $backup['id'],
            'site_id' => $site->id,
            'domain' => $site->domain,
            'entries' => $result['entries'],
            'safety_backup_id' => $safetyId,
            'message' => sprintf(
                'กู้คืน %s จากข้อมูลสำรองแล้ว (%d รายการ) — สำรองสถานะก่อนกู้คืนไว้ให้แล้วเช่นกัน',
                $site->domain,
                $result['entries'],
            ),
        ];
    }

    private function assertAccess(Context $context, int $siteId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        if (!(new SiteRepository($context->db))->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์กู้คืนข้อมูลของเว็บไซต์นี้');
        }
    }
}
