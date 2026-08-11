<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ลบไฟล์สำรองที่เก่าเกินนโยบาย — PLAN-V2 เฟส E1
 *
 * **ถูกออกแบบให้ทำงานโดยไม่มีคนดู** (scheduler เรียกทุกวัน) กฎทุกข้อจึงเอียงไปทาง
 * "เก็บไว้ก่อน" เมื่อไม่แน่ใจ:
 *
 *   1. **เก็บ N ชุดล่าสุดเสมอ** แม้จะเกินจำนวนวันไปแล้ว — เครื่องที่ไม่ได้สำรองมานาน
 *      ต้องไม่ตื่นมาแล้วพบว่าไฟล์สำรองถูกลบเกลี้ยงเพราะทุกไฟล์ "เก่าเกิน 30 วัน"
 *   2. **ไม่ลบไฟล์ที่ยังส่งออกนอกเครื่องไม่สำเร็จ** — ไฟล์นั้นคือสำเนาเดียวที่มีอยู่
 *   3. **ไม่ลบไฟล์ที่ยังไม่มีปลายทาง** ถ้าปลายทางถูกตั้งไว้แล้วในระบบ · การลบสำเนา
 *      ในเครื่องทิ้งโดยที่ยังไม่มีสำเนานอกเครื่อง คือการทำให้ข้อมูลหายด้วยมือตัวเอง
 *
 * ลบทั้งสองที่: ไฟล์ในเครื่องตามนโยบายของระบบ และไฟล์ที่ปลายทางตามนโยบายของปลายทางนั้น
 */
final class BackupPrune implements Capability
{
    /** ค่าปริยายเมื่อไม่ได้ระบุ — ตรงกับค่าปริยายของคอลัมน์ในตารางปลายทาง */
    private const DEFAULT_DAYS = 30;
    private const DEFAULT_KEEP = 7;

    public static function name(): string
    {
        return 'backup.prune';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ลบไฟล์สำรองที่เก่าเกินนโยบายที่ตั้งไว้';
    }

    public function validate(array $args): array
    {
        return [
            // 0 = ใช้ค่าจากปลายทางแต่ละที่ · ระบุมาเพื่อบังคับค่าเดียวกันทั้งหมดได้
            'days' => Validator::optionalInt($args, 'days', 0, 0),
            'keep' => Validator::optionalInt($args, 'keep', 0, 0),
            'dry_run' => (bool) ($args['dry_run'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $factory = new DestinationFactory($destinations, $context->config->paths->backups());
        $manager = new BackupManager($context->config->paths->backups());

        $hasDestinations = $destinations->enabled() !== [];
        $removed = [];
        $kept = [];

        // นับลำดับความใหม่ของแต่ละกลุ่ม **ต่อการเรียกหนึ่งครั้ง**
        //
        // เคยเขียนเป็น `static` ในเมธอดตรวจ ซึ่งเป็นบั๊กที่ร้ายแรงเงียบ ๆ: agent เป็น
        // โปรเซสที่รันค้างเป็นเดือน ตัวนับจึงสะสมข้ามการเรียก แล้วรอบที่สองเป็นต้นไป
        // จะเห็นว่าทุกกลุ่ม "มีของครบโควตาแล้ว" ตั้งแต่แถวแรก แล้วลบไฟล์ที่ต้องเก็บไว้
        $seen = [];

        foreach ($this->candidates($context) as $row) {
            $days = $args['days'] > 0 ? $args['days'] : (int) ($row['retention_days'] ?? self::DEFAULT_DAYS);
            $keep = $args['keep'] > 0 ? $args['keep'] : (int) ($row['retention_count'] ?? self::DEFAULT_KEEP);

            // นับแยกตามชนิดและเว็บไซต์ — "เก็บ 7 ชุดล่าสุด" ต้องหมายถึง 7 ชุดของสิ่งนั้น
            // ไม่ใช่ 7 ชุดรวมทั้งระบบ ซึ่งจะทำให้เว็บที่สำรองบ่อยกินโควตาของเว็บอื่นจนหมด
            $bucket = $row['type'] . ':' . ($row['site_id'] ?? 0);
            $seen[$bucket] = ($seen[$bucket] ?? 0) + 1;

            $reason = $this->keepReason($row, $days, $keep, $hasDestinations, $seen[$bucket]);

            if ($reason !== null) {
                $kept[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'reason' => $reason];
                continue;
            }

            if ($args['dry_run']) {
                $removed[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'simulated' => true];
                continue;
            }

            // ลบที่ปลายทางก่อน แล้วค่อยลบในเครื่อง
            //
            // ถ้าทำกลับกันแล้วการลบที่ปลายทางล้ม จะเหลือไฟล์กำพร้าที่ปลายทางซึ่งไม่มี
            // แถวไหนในฐานข้อมูลอ้างถึงอีกแล้ว — กินพื้นที่ไปเรื่อย ๆ โดยไม่มีใครเห็น
            if (($row['remote_path'] ?? null) !== null && ($row['destination_id'] ?? null) !== null) {
                $destination = $destinations->find((int) $row['destination_id']);

                if ($destination !== null) {
                    $factory->make($destination)->delete($executor, (string) $row['remote_path']);
                }
            }

            $manager->delete($executor, (string) $row['path']);
            $context->db->run('DELETE FROM backups WHERE id = :id', ['id' => (int) $row['id']]);

            $removed[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'bytes' => (int) $row['size_bytes']];
        }

        $bytes = array_sum(array_column($removed, 'bytes'));

        return [
            'removed' => $removed,
            'removed_count' => count($removed),
            'kept_count' => count($kept),
            'freed_bytes' => $bytes,
            'dry_run' => $args['dry_run'],
            'message' => $args['dry_run']
                ? sprintf('จะลบ %d รายการ คืนพื้นที่ %s ไบต์', count($removed), number_format($bytes))
                : sprintf('ลบ %d รายการ คืนพื้นที่ %s ไบต์', count($removed), number_format($bytes)),
        ];
    }

    /**
     * ไฟล์สำรองพร้อมนโยบายของปลายทางที่มันถูกส่งไป
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(Context $context): array
    {
        return $context->db->all(
            'SELECT b.*, d.retention_days, d.retention_count
               FROM backups b
          LEFT JOIN backup_destinations d ON d.id = b.destination_id
              ORDER BY b.created_at DESC',
        );
    }

    /**
     * เหตุผลที่ต้องเก็บไฟล์นี้ไว้ — null แปลว่าลบได้
     *
     * @param array<string,mixed> $row
     * @param int $rank ลำดับความใหม่ในกลุ่มของตัวเอง — 1 คือใหม่ที่สุด
     */
    private function keepReason(array $row, int $days, int $keep, bool $hasDestinations, int $rank): ?string
    {
        if ($keep > 0 && $rank <= $keep) {
            return 'อยู่ใน ' . $keep . ' ชุดล่าสุด';
        }

        if ($days > 0 && (time() - (int) $row['created_at']) < $days * 86400) {
            return 'ยังไม่เกิน ' . $days . ' วัน';
        }

        // ข้อที่กันข้อมูลหายจริง ๆ สองข้อ
        if (($row['status'] ?? '') === 'running') {
            return 'กำลังสร้างอยู่';
        }

        if ($hasDestinations && ($row['offsite_status'] ?? 'none') !== 'ok') {
            return 'ยังไม่มีสำเนานอกเครื่อง';
        }

        return null;
    }
}
