<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MetricsHistoryRepository;

/**
 * เก็บตัวอย่าง metrics หนึ่งจุดลงประวัติ แล้วยุบชั้นให้เอง — PLAN-V2 เฟส E6
 *
 * **ทำไมต้องมี capability แยก ไม่ให้ scheduler เรียก `system.metrics` แล้วเขียนเอง:**
 * scheduler เรียก capability ได้ทีละตัวต่อหนึ่งงาน (ข้อจำกัดเดียวกับที่ทำให้ `backup.create`
 * ต้องรับ `destination_id` ในเฟส E1) · การอ่านค่าและบันทึกจึงต้องอยู่ในคำสั่งเดียวกัน
 *
 * ทำเครื่องหมายว่า **อ่านอย่างเดียว** ด้วยเหตุผลเดียวกับ `disk.usage`: ไม่เปลี่ยนอะไรบนเครื่อง
 * เลย สิ่งที่เขียนคือค่าที่วัดได้ลงตารางของ panel เอง · ผลพลอยได้ที่สำคัญคือมันไม่เติม
 * audit log ทุกนาทีตลอดไป และได้ `Executor` จริงในโหมด dryrun จึงยังวัดค่าได้ถูกต้อง
 */
final class MetricsRecord implements Capability
{
    public static function name(): string
    {
        return 'metrics.record';
    }

    public function permission(): string
    {
        return 'dashboard.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'บันทึกค่าทรัพยากรลงประวัติย้อนหลัง';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        // เรียกตัวเก็บค่าตัวเดียวกับที่ `GET /metrics` และ SSE ใช้ — ตัวเลขในกราฟย้อนหลัง
        // จึงมาจากแหล่งเดียวกับที่หน้าจอแสดงสด ไม่มีทางเพี้ยนจากกัน
        $metrics = (new SystemMetrics())->run([], $executor, $context);

        $repository = new MetricsHistoryRepository($context->db);
        $result = $repository->record($metrics);

        return [
            'bucket_at' => $result['bucket_at'],
            'pruned' => $result['rolled_up'],
            'cpu_percent' => $metrics['cpu']['percent'] ?? 0,
            'memory_percent' => $metrics['memory']['percent'] ?? 0,
            'disk_percent' => $metrics['disk']['percent'] ?? 0,
            'message' => sprintf(
                'บันทึกค่าทรัพยากรแล้ว (CPU %.1f%% · RAM %.1f%% · ดิสก์ %.1f%%)',
                $metrics['cpu']['percent'] ?? 0,
                $metrics['memory']['percent'] ?? 0,
                $metrics['disk']['percent'] ?? 0,
            ),
        ];
    }
}
