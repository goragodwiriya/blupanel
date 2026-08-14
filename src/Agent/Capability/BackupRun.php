<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * รอบสำรองอัตโนมัติของทั้งเครื่อง — **กระบวนการเดียวทั้งระบบ** (ข้อ B6, B10)
 *
 * ## ทำไมเป็นตัวเดียว ไม่ใช่ตารางเวลาหลายชุด
 *
 * ของเดิมเป็นแถวใน `scheduled_jobs` หนึ่งแถวต่อหนึ่งเว็บหนึ่งชนิด ผู้ดูแลสร้างเอง ·
 * เครื่องที่มีลูกค้าห้าสิบรายต้องมีตารางเวลาเป็นร้อยชุดที่ต้องดูแลด้วยมือ และ**เว็บที่
 * สร้างใหม่จะไม่ถูกสำรองเลย**จนกว่าจะมีใครนึกได้ว่าต้องไปเพิ่มตารางเวลาให้มัน —
 * ความล้มเหลวที่เงียบสนิทจนถึงวันที่ต้องใช้ไฟล์สำรอง
 *
 * ตอนนี้เป็นสวิตช์บนบัญชี (`users.backup_files`, `users.backup_database`) แล้วตัวนี้
 * เดินตามสวิตช์ในทุกรอบ · เว็บใหม่ของบัญชีที่เปิดไว้เข้ารอบเองทันที
 *
 * ## ล้มหนึ่งบัญชีต้องไม่ล้มทั้งรอบ
 *
 * เว็บเดียวที่ดิสก์เต็มหรือฐานข้อมูลล่มต้องไม่ทำให้อีกสี่สิบเก้าบัญชีไม่ได้สำรองในคืนนั้น
 * · ความล้มเหลวจึงถูกเก็บเป็นรายการแล้วรายงานกลับ ไม่ใช่โยนออกไปหยุดทั้งกระบวนการ
 * — แต่ต้อง**นับและรายงานให้เห็น** ไม่ใช่กลืนเงียบ ๆ ซึ่งเป็นความผิดพลาดตรงข้ามที่แย่พอกัน
 */
final class BackupRun extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.run';
    }

    /**
     * สิทธิ์ของ**ทั้งเครื่อง** — รอบนี้สร้างไฟล์ในบ้านของลูกค้าทุกรายที่ถูกเปิดสวิตช์ไว้
     * และหักโควตาของพวกเขา · เป็นการตัดสินใจระดับผู้ดูแลเซิร์ฟเวอร์ ไม่ใช่ของลูกค้า
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
        return 'สำรองข้อมูลของทุกบัญชีที่ผู้ดูแลเปิดไว้ แล้วเก็บกวาดไฟล์เก่า';
    }

    public function validate(array $args): array
    {
        return [
            // 0 = ใช้ปลายทางที่เปิดใช้งานอยู่ (ถ้ามี) · ระบุมาเพื่อบังคับปลายทางเดียว
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
            'prune' => (bool) ($args['prune'] ?? true),
            'days' => Validator::optionalInt($args, 'days', 30, 0),
            'keep' => Validator::optionalInt($args, 'keep', 7, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $destinationId = $args['destination_id'] > 0
            ? $args['destination_id']
            : $this->defaultDestination($context);

        $create = new BackupCreate();
        $done = [];
        $failed = [];
        $bytes = 0;

        foreach ($this->targets($context) as $target) {
            foreach ($target['types'] as $type) {
                try {
                    $result = $create->run([
                        'type' => $type,
                        'site_id' => $target['site_id'],
                        'database' => '',
                        'destination_id' => $destinationId,
                    ], $executor, $context);

                    $bytes += (int) ($result['bytes'] ?? 0);
                    $done[] = [
                        'site_id' => $target['site_id'],
                        'domain' => $target['domain'],
                        'type' => $type,
                        'bytes' => (int) ($result['bytes'] ?? 0),
                    ];
                } catch (\Throwable $e) {
                    $failed[] = [
                        'site_id' => $target['site_id'],
                        'domain' => $target['domain'],
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $pruned = $args['prune']
            ? (new BackupPrune())->run(
                ['days' => $args['days'], 'keep' => $args['keep'], 'user_id' => 0, 'dry_run' => false],
                $executor,
                $context,
            )
            : ['removed_count' => 0, 'freed_bytes' => 0];

        return [
            'created' => $done,
            'created_count' => count($done),
            'failed' => $failed,
            'failed_count' => count($failed),
            'bytes' => $bytes,
            'pruned_count' => (int) ($pruned['removed_count'] ?? 0),
            'freed_bytes' => (int) ($pruned['freed_bytes'] ?? 0),
            'message' => sprintf(
                'สำรอง %d รายการ (%s ไบต์)%s · เก็บกวาด %d ไฟล์',
                count($done),
                number_format($bytes),
                $failed === [] ? '' : sprintf(' · ล้มเหลว %d รายการ', count($failed)),
                (int) ($pruned['removed_count'] ?? 0),
            ),
        ];
    }

    /**
     * เว็บที่ต้องสำรองในรอบนี้ พร้อมชนิดที่เจ้าของถูกเปิดสวิตช์ไว้
     *
     * เว็บที่ถูกระงับบริการยังถูกสำรองต่อ — บัญชีที่หยุดจ่ายเป็นบัญชีที่**ต้องการ**
     * ไฟล์สำรองมากที่สุด เพราะขั้นถัดไปของมันคือการถูกลบ
     *
     * ชนิด `database` ถูกใส่มาเฉพาะเว็บที่มีฐานข้อมูลจริง · ใส่ให้ทุกเว็บแปลว่าเว็บ
     * สแตติกทุกแห่งจะรายงานว่าล้มเหลวทุกคืน แล้วรายการที่ล้มจริงจะจมหายไปในนั้น
     *
     * @return list<array{site_id:int,domain:string,types:list<string>}>
     */
    private function targets(Context $context): array
    {
        $rows = $context->db->all(
            "SELECT s.id, s.primary_domain, u.backup_files, u.backup_database,
                    (SELECT COUNT(*) FROM databases_ d WHERE d.site_id = s.id) AS databases
               FROM sites s JOIN users u ON u.id = s.owner_user_id
              WHERE u.system_user IS NOT NULL
                AND (u.backup_files = 1 OR u.backup_database = 1)
              ORDER BY u.username, s.primary_domain",
        );

        $targets = [];

        foreach ($rows as $row) {
            $types = [];

            if ((int) $row['backup_files'] === 1) {
                $types[] = 'site';
            }

            // เว็บที่มีหลายฐานข้อมูลข้ามไป — `backup.create` ปฏิเสธการเดาว่าจะสำรองฐานไหน
            // และรอบอัตโนมัติต้องไม่ตัดสินใจแทนในสิ่งที่คนยังไม่ได้ตัดสินใจ
            if ((int) $row['backup_database'] === 1 && (int) $row['databases'] === 1) {
                $types[] = 'database';
            }

            if ($types !== []) {
                $targets[] = [
                    'site_id' => (int) $row['id'],
                    'domain' => (string) $row['primary_domain'],
                    'types' => $types,
                ];
            }
        }

        return $targets;
    }

    /** ปลายทางนอกเครื่องที่เปิดใช้งานอยู่ — 0 = ไม่มี เก็บไว้ในเครื่องอย่างเดียว */
    private function defaultDestination(Context $context): int
    {
        $enabled = (new BackupDestinationRepository($context->db, new Secret($context->config->secretKey())))
            ->enabled();

        return $enabled === [] ? 0 : (int) $enabled[0]['id'];
    }
}
