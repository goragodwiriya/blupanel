<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;
use Phpcp\Kernel\Logger;

/**
 * ตัวตัดสินว่างานไหนถึงเวลาแล้ว และสั่งงานนั้นผ่าน agent — เฟส A1 ของ PLAN-V2
 *
 * แยกออกจาก bin/phpcp-scheduler เพื่อให้ทดสอบได้โดยไม่ต้องมี agent จริง:
 * ตัวสั่งงานถูกฉีดเข้ามาเป็น closure เทสต์จึงส่งตัวปลอมที่บันทึกไว้ว่าถูกเรียกด้วยอะไร
 *
 * หลักการที่ห้ามหลุด: ที่นี่ไม่มีสิทธิ์พิเศษใด ๆ ทั้งสิ้น ทุกอย่างที่แตะระบบจริง
 * เดินผ่าน Agent\Client เหมือนที่ชั้นเว็บทำ — scheduler จึงเป็นแค่ "ผู้กดปุ่มตามเวลา"
 * ไม่ใช่ทางลัดที่ข้ามชั้นความปลอดภัยไปสั่งงานเอง
 */
final class Scheduler
{
    /** ไล่ย้อนหลังได้ไกลสุด 1 วัน — เครื่องที่ดับข้ามคืนไม่ควรรันงานรายวันซ้ำหลายรอบตอนกลับมา */
    public const CATCH_UP_SECONDS = 86400;

    /** @param \Closure(string,array<string,mixed>):array<string,mixed> $dispatch */
    public function __construct(
        private readonly Db $db,
        private readonly \Closure $dispatch,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * รันทุกงานที่ถึงเวลาแล้วหนึ่งรอบ
     *
     * งานหนึ่งล้มต้องไม่ทำให้งานที่เหลือไม่ได้รัน — รอบนี้อาจเป็นรอบเดียวในนาทีนี้
     *
     * @return list<array{name:string,status:string,message:string,duration_ms:int}>
     */
    public function runDue(?int $now = null): array
    {
        $now ??= time();
        $jobs = new ScheduledJobRepository($this->db);
        $results = [];

        foreach ($jobs->enabled() as $job) {
            $schedule = (string) $job['schedule'];
            $name = (string) $job['name'];
            $lastRunAt = $job['last_run_at'] === null ? null : (int) $job['last_run_at'];

            try {
                $due = CronSchedule::isDue($schedule, $now, $lastRunAt, self::CATCH_UP_SECONDS);
            } catch (\Throwable $e) {
                // ตารางเวลาที่ผิดรูปแบบต้องดังพอให้เห็น ไม่ใช่ถูกข้ามเงียบ ๆ ทุกนาทีตลอดไป
                $jobs->recordRun((int) $job['id'], 'error', 'ตารางเวลาไม่ถูกต้อง: ' . $e->getMessage(), $now);
                $results[] = $this->result($name, 'error', $e->getMessage(), 0);
                $this->log('error', "งาน {$name} มีตารางเวลาไม่ถูกต้อง: " . $e->getMessage());

                continue;
            }

            if (!$due) {
                continue;
            }

            $results[] = $this->run($jobs, $job, $now);
        }

        return $results;
    }

    /**
     * บังคับรันงานหนึ่งทันทีโดยไม่สนตารางเวลา — ใช้จากบรรทัดคำสั่งตอนตรวจสอบ
     *
     * @return array{name:string,status:string,message:string,duration_ms:int}
     */
    public function runNow(string $name): array
    {
        $jobs = new ScheduledJobRepository($this->db);
        $job = $jobs->find($name);

        if ($job === null) {
            return $this->result($name, 'error', "ไม่พบงานชื่อ {$name}", 0);
        }

        return $this->run($jobs, $job, time(), force: true);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{name:string,status:string,message:string,duration_ms:int}
     */
    private function run(ScheduledJobRepository $jobs, array $job, int $now, bool $force = false): array
    {
        $id = (int) $job['id'];
        $name = (string) $job['name'];
        $capability = (string) $job['capability'];
        $args = json_decode((string) $job['args_json'], true);
        $args = is_array($args) ? $args : [];

        if (!$force && !$this->hasWork($capability)) {
            // ข้ามอย่างตั้งใจ ยังบันทึก last_run_at เพราะ "ตรวจแล้วไม่มีอะไรต้องทำ" คือการทำงานหนึ่งรอบ
            $jobs->recordRun($id, 'skipped', '', $now);

            return $this->result($name, 'skipped', 'ไม่มีงานค้าง', 0);
        }

        $startedAt = hrtime(true);

        try {
            $data = ($this->dispatch)($capability, $args);
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            $message = is_string($data['message'] ?? null) ? $data['message'] : 'สำเร็จ';

            $jobs->recordRun($id, 'ok', '', $now);
            $this->log('info', sprintf('งาน %s สำเร็จใน %d ms — %s', $name, $durationMs, $message));

            return $this->result($name, 'ok', $message, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $jobs->recordRun($id, 'error', $e->getMessage(), $now);
            $this->log('error', sprintf('งาน %s ล้มเหลว: %s', $name, $e->getMessage()));

            return $this->result($name, 'error', $e->getMessage(), $durationMs);
        }
    }

    /**
     * มีอะไรให้ทำจริงไหม — ถามก่อนสั่งงานที่รันถี่มาก
     *
     * เหตุผลที่ต้องมี: ทุกคำสั่งที่เปลี่ยนแปลงระบบถูกบันทึก audit สองแถวต่อครั้ง
     * `rollback.run` ที่รันทุกนาทีจึงเติม audit log ประมาณ 2,880 แถวต่อวันโดยที่
     * เกือบทั้งหมดคือ "ตรวจแล้วไม่มีอะไร" — audit log ที่เต็มไปด้วยแถวไร้ความหมาย
     * ทำให้ตามหาสิ่งที่เกิดขึ้นจริงย้อนหลังไม่ได้ ซึ่งทำลายเหตุผลที่มันมีอยู่
     *
     * เงื่อนไขที่ใช้ตัดสินต้องเป็นแหล่งความจริงเดียวกับที่ capability ใช้เท่านั้น
     * (คิวรีเดียวกับ RollbackGuard::expired()) และถ้าถามไม่ได้ต้องตอบว่า "มีงาน"
     * เสมอ — ตัดสินผิดทางนี้แค่เปลืองแถว audit แต่ผิดอีกทางคือ rollback ไม่ทำงาน
     */
    private function hasWork(string $capability): bool
    {
        if ($capability !== 'rollback.run') {
            return true;
        }

        try {
            return (int) $this->db->value(
                'SELECT count(*) FROM pending_rollbacks WHERE expires_at <= :now',
                ['now' => time()],
                0,
            ) > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return array{name:string,status:string,message:string,duration_ms:int} */
    private function result(string $name, string $status, string $message, int $durationMs): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger === null) {
            return;
        }

        match ($level) {
            'error' => $this->logger->error($message),
            default => $this->logger->info($message),
        };
    }
}
