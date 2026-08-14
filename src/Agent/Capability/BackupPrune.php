<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ลบไฟล์สำรองที่เก่าเกินนโยบาย ออกจากโฟลเดอร์ของแต่ละบัญชี
 *
 * **ถูกออกแบบให้ทำงานโดยไม่มีคนดู** (cron เรียกทุกวัน) กฎทุกข้อจึงเอียงไปทาง
 * "เก็บไว้ก่อน" เมื่อไม่แน่ใจ:
 *
 *   1. **เก็บ N ชุดล่าสุดเสมอ** แม้จะเกินจำนวนวันไปแล้ว — บัญชีที่ไม่ได้สำรองมานาน
 *      ต้องไม่ตื่นมาแล้วพบว่าไฟล์สำรองถูกลบเกลี้ยงเพราะทุกไฟล์ "เก่าเกิน 30 วัน"
 *   2. **นับแยกตามเว็บและชนิด** — "เก็บ 7 ชุดล่าสุด" ต้องหมายถึง 7 ชุดของสิ่งนั้น
 *      ไม่ใช่ 7 ชุดรวมทั้งบัญชี ซึ่งจะทำให้เว็บที่สำรองบ่อยกินโควตาของเว็บอื่นจนหมด
 *   3. **ไม่แตะไฟล์ที่จับคู่กับเว็บไม่ได้** — ไฟล์ที่ลูกค้าคัดลอกเข้ามาเองหรือเปลี่ยน
 *      ชื่อเอง ไม่ใช่ของที่ระบบสร้าง จึงไม่ใช่ของที่ระบบจะลบทิ้งตามนโยบายของตัวเอง
 *
 * ## ทำไมไม่ตรวจ "มีสำเนานอกเครื่องหรือยัง" อีกแล้ว
 *
 * กฎเดิมข้อนั้นอ่านจากคอลัมน์ `offsite_status` ในตาราง `backups` ซึ่งเลิกเป็นแหล่ง
 * ความจริงไปแล้ว (ข้อ B4) · ความจริงใหม่คือ **ไฟล์อยู่ในบ้านของลูกค้า** เขาลบเองได้
 * ทุกเมื่ออยู่แล้ว การให้ตัวเก็บกวาดยึดสถานะที่บันทึกไว้เมื่อวานจึงไม่ได้กันอะไรจริง
 * · สิ่งที่กันข้อมูลหายในระบบใหม่คือข้อ 1 กับข้อ 2 ซึ่งอ่านจากไฟล์ที่มีอยู่จริงเดี๋ยวนั้น
 */
final class BackupPrune extends BackupCapability implements Capability
{
    /** ค่าปริยายเมื่อไม่ได้ระบุ */
    private const DEFAULT_DAYS = 30;
    private const DEFAULT_KEEP = 7;

    public static function name(): string
    {
        return 'backup.prune';
    }

    /**
     * สิทธิ์ของ**ทั้งเครื่อง** ไม่ใช่ของหมวด Hosting
     *
     * ตัวนี้เดินโฟลเดอร์ของทุกบัญชีในรอบเดียวและลบไฟล์ของลูกค้าตามนโยบายที่ผู้ดูแล
     * ตั้งไว้ · `backup.manage` เป็นสิทธิ์ที่ผู้ดูแลเว็บไซต์มีติดตัว การใช้สิทธิ์นั้น
     * แปลว่าลูกค้ารายหนึ่งสั่งรอบเก็บกวาดของทั้งเครื่องได้
     */
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
            'days' => Validator::optionalInt($args, 'days', self::DEFAULT_DAYS, 0),
            'keep' => Validator::optionalInt($args, 'keep', self::DEFAULT_KEEP, 0),
            // 0 = ทุกบัญชี
            'user_id' => Validator::optionalInt($args, 'user_id', 0, 0),
            'dry_run' => (bool) ($args['dry_run'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $accounts = $args['user_id'] > 0
            ? [$this->ownerAccount($context, $args['user_id'])]
            : $this->visibleAccounts($context);

        $manager = new BackupManager();
        $removed = [];
        $kept = 0;

        foreach ($accounts as $account) {
            // นับลำดับความใหม่ **ต่อการเรียกหนึ่งครั้งและต่อหนึ่งบัญชี**
            //
            // เคยเขียนเป็น static ในเมธอดตรวจ ซึ่งเป็นบั๊กที่ร้ายแรงเงียบ ๆ: agent
            // เป็นโปรเซสที่รันค้างเป็นเดือน ตัวนับจึงสะสมข้ามการเรียก แล้วรอบที่สอง
            // เป็นต้นไปจะเห็นว่าทุกกลุ่ม "มีของครบโควตาแล้ว" ตั้งแต่แถวแรก
            $seen = [];

            foreach ($this->filesOf($executor, $context, $account) as $file) {
                // ไฟล์ที่ไม่รู้ว่าเป็นของเว็บไหน = ไม่ใช่ของที่ระบบสร้าง จึงไม่ใช่ของที่ระบบลบ
                if ($file['domain'] === '') {
                    $kept++;
                    continue;
                }

                $bucket = $file['type'] . ':' . $file['domain'];
                $seen[$bucket] = ($seen[$bucket] ?? 0) + 1;

                if ($this->keepReason($file, $args['days'], $args['keep'], $seen[$bucket]) !== null) {
                    $kept++;
                    continue;
                }

                if (!$args['dry_run']) {
                    $manager->delete($executor, $account->backupDir(), $file['path']);
                    $this->deleteOffsite($executor, $context, $account, $file['name']);
                }

                $removed[] = [
                    'user_id' => $account->userId,
                    'file' => $file['name'],
                    'bytes' => $file['bytes'],
                    'simulated' => $args['dry_run'],
                ];
            }
        }

        $bytes = array_sum(array_column($removed, 'bytes'));

        return [
            'removed' => $removed,
            'removed_count' => count($removed),
            'kept_count' => $kept,
            'freed_bytes' => $bytes,
            'dry_run' => $args['dry_run'],
            'message' => sprintf(
                $args['dry_run'] ? 'จะลบ %d ไฟล์ คืนพื้นที่ %s ไบต์' : 'ลบ %d ไฟล์ คืนพื้นที่ %s ไบต์',
                count($removed),
                number_format($bytes),
            ),
        ];
    }

    /**
     * ลบสำเนานอกเครื่องของไฟล์ที่เพิ่งลบไป — ชื่อที่ปลายทางคำนวณได้ ไม่ต้องจำ
     *
     * `backup.push` ตั้งชื่อไฟล์ปลายทางเป็น `<ชื่อบัญชี>-<ชื่อไฟล์>` เสมอ · นโยบายเก็บ
     * ไฟล์จึงมีผลทั้งสองที่โดยไม่ต้องมีตารางคอยจำว่าไฟล์ไหนไปอยู่ที่ไหน — ซึ่งเป็นตาราง
     * ที่จะเพี้ยนทันทีที่ลูกค้าลบไฟล์ของตัวเอง เหมือนที่ตาราง `backups` เพี้ยนมาแล้ว
     *
     * **ล้มแล้วไม่ทำให้ทั้งรอบล้ม** · ปลายทางที่ติดต่อไม่ได้คืนงานเก็บกวาดของบัญชีที่
     * เหลือให้ทำต่อได้ · ไฟล์ที่ค้างอยู่ปลายทางกินพื้นที่ แต่ไม่ทำให้ข้อมูลใครหาย
     */
    private function deleteOffsite(Executor $executor, Context $context, UserAccount $account, string $file): void
    {
        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $factory = new DestinationFactory($destinations, $account->backupDir());

        foreach ($destinations->enabled() as $row) {
            try {
                $config = is_array($row['config'] ?? null) ? $row['config'] : [];
                $base = rtrim((string) ($config['path'] ?? ''), '/');
                $name = $account->username . '-' . $file;

                $factory->make($row)->delete($executor, $base === '' ? $name : $base . '/' . $name);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * ไฟล์ของบัญชีเดียว เรียงใหม่สุดก่อน — โฟลเดอร์ที่อ่านไม่ได้ต้องไม่ทำให้ทั้งรอบล้ม
     *
     * @return list<array<string,mixed>>
     */
    private function filesOf(Executor $executor, Context $context, UserAccount $account): array
    {
        try {
            return BackupFiles::listFor($executor, $account, $this->domainsOf($context, $account->userId));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * เหตุผลที่ต้องเก็บไฟล์นี้ไว้ — null แปลว่าลบได้
     *
     * @param array<string,mixed> $file
     * @param int $rank ลำดับความใหม่ในกลุ่มของตัวเอง — 1 คือใหม่ที่สุด
     */
    private function keepReason(array $file, int $days, int $keep, int $rank): ?string
    {
        if ($keep > 0 && $rank <= $keep) {
            return 'อยู่ใน ' . $keep . ' ชุดล่าสุด';
        }

        if ($days > 0 && (time() - (int) $file['modified_at']) < $days * 86400) {
            return 'ยังไม่เกิน ' . $days . ' วัน';
        }

        return null;
    }
}
