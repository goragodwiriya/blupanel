<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * เก็บและอ่าน metrics ย้อนหลังแบบลดความละเอียดตามอายุ — PLAN-V2 เฟส E6
 *
 * **สามชั้นและอายุของแต่ละชั้น** (ดูเหตุผลเต็มใน `db/migrations/0014_metrics_history.sql`):
 * นาที/24 ชม. → ชั่วโมง/30 วัน → วัน/365 วัน · รวมแล้วคงที่ ~2,500 แถวต่อเครื่องตลอดกาล
 *
 * **การรวมค่าถ่วงน้ำหนักด้วย `samples` เสมอ ไม่ใช่เฉลี่ยของค่าเฉลี่ย** — แถวรายชั่วโมงที่
 * รวมมาจาก 60 ตัวอย่าง กับแถวที่รวมมาจาก 3 ตัวอย่าง (เครื่องเพิ่งบูต) ต้องมีน้ำหนักต่างกัน
 * ตอนยุบขึ้นชั้นวัน มิฉะนั้นช่วงที่เก็บข้อมูลได้น้อยจะดึงค่าเฉลี่ยทั้งวันไปหาตัวมันเอง
 *
 * **`cpu_peak` แยกจาก `cpu_percent`** เพราะค่าเฉลี่ยกลบพีคสั้น ๆ จนมองไม่เห็น — ซึ่งเป็น
 * ต้นเหตุของอาการ "เว็บช้าเป็นช่วง ๆ" ที่กราฟย้อนหลังมีไว้เพื่อตอบโดยเฉพาะ
 */
final class MetricsHistoryRepository
{
    /**
     * ชั้น => [ความยาวช่วงเป็นวินาที, เก็บนานกี่วินาที]
     *
     * @var array<string,array{0:int,1:int}>
     */
    public const BUCKETS = [
        'minute' => [60, 86400],          // 1 นาที · เก็บ 24 ชม.
        'hour' => [3600, 2592000],        // 1 ชม. · เก็บ 30 วัน
        'day' => [86400, 31536000],       // 1 วัน · เก็บ 365 วัน
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * บันทึกตัวอย่างหนึ่งจุดลงชั้นนาที แล้วยุบขึ้นชั้นบนให้เอง
     *
     * @param array<string,mixed> $metrics ผลลัพธ์ของ capability `system.metrics`
     * @return array{bucket_at:int,rolled_up:int}
     */
    public function record(array $metrics, ?int $now = null): array
    {
        $now ??= time();

        $sample = [
            'cpu_percent' => (float) ($metrics['cpu']['percent'] ?? 0),
            'memory_percent' => (float) ($metrics['memory']['percent'] ?? 0),
            'disk_percent' => (float) ($metrics['disk']['percent'] ?? 0),
            'load1' => (float) ($metrics['load'][1] ?? 0),
            'memory_used_bytes' => (int) ($metrics['memory']['used'] ?? 0),
            'disk_used_bytes' => (int) ($metrics['disk']['used'] ?? 0),
        ];

        $bucketAt = $this->floorTo($now, self::BUCKETS['minute'][0]);
        $this->upsert('minute', $bucketAt, $sample, $now);

        return [
            'bucket_at' => $bucketAt,
            'rolled_up' => $this->rollUp($now),
        ];
    }

    /**
     * ยุบชั้นละเอียดขึ้นชั้นหยาบ แล้วลบข้อมูลที่เกินอายุของแต่ละชั้น
     *
     * เรียกได้บ่อยเท่าที่ต้องการ — ยุบซ้ำช่วงเดิมได้ผลเท่าเดิมเพราะคำนวณจากชั้นล่างใหม่
     * ทั้งช่วงทุกครั้ง ไม่ใช่บวกสะสมเข้าไป (ซึ่งจะทำให้ค่าเพี้ยนขึ้นเรื่อย ๆ เมื่อเรียกซ้ำ)
     *
     * @return int จำนวนแถวที่ถูกลบเพราะเกินอายุ
     */
    public function rollUp(?int $now = null): int
    {
        $now ??= time();

        $this->rollUpInto('minute', 'hour', $now);
        $this->rollUpInto('hour', 'day', $now);

        return $this->prune($now);
    }

    /**
     * อ่านข้อมูลย้อนหลังของชั้นที่ระบุ
     *
     * @return list<array<string,mixed>>
     */
    public function range(string $bucket, int $since, ?int $until = null): array
    {
        $bucket = $this->assertBucket($bucket);
        $until ??= time();

        return $this->db->all(
            'SELECT bucket_at, cpu_percent, cpu_peak, memory_percent, disk_percent, load1,
                    memory_used_bytes, disk_used_bytes, samples
             FROM metrics_history
             WHERE bucket = :b AND bucket_at >= :since AND bucket_at <= :until
             ORDER BY bucket_at',
            ['b' => $bucket, 'since' => $since, 'until' => $until],
        );
    }

    /** ชั้นที่เหมาะกับช่วงเวลาที่ขอ — เลือกให้เองเพื่อไม่ให้หน้าจอต้องรู้กฎนี้ */
    public static function bucketForRange(int $seconds): string
    {
        return match (true) {
            $seconds <= 86400 => 'minute',
            $seconds <= 2592000 => 'hour',
            default => 'day',
        };
    }

    public function assertBucket(string $bucket): string
    {
        if (!isset(self::BUCKETS[$bucket])) {
            throw new \InvalidArgumentException(
                'ชั้นข้อมูลไม่ถูกต้อง — ใช้ได้: ' . implode(', ', array_keys(self::BUCKETS)),
            );
        }

        return $bucket;
    }

    /**
     * เขียนหรือรวมตัวอย่างเข้าแถวของช่วงนั้น
     *
     * ใช้ `ON CONFLICT DO UPDATE` คำนวณค่าเฉลี่ยใหม่จากค่าเดิม + ค่าใหม่ **ในคำสั่งเดียว**
     * แทนที่จะอ่านแล้วเขียน — สองโปรเซสที่บันทึกพร้อมกัน (scheduler กับการเรียกด้วยมือ)
     * จึงไม่ทับค่าของกันและกัน
     *
     * @param array<string,float|int> $sample
     */
    private function upsert(string $bucket, int $bucketAt, array $sample, int $now): void
    {
        $this->db->run(
            'INSERT INTO metrics_history
                (bucket, bucket_at, cpu_percent, cpu_peak, memory_percent, disk_percent, load1,
                 memory_used_bytes, disk_used_bytes, samples, created_at)
             VALUES (:b, :t, :cpu, :cpu, :mem, :disk, :load, :membytes, :diskbytes, 1, :now)
             ON CONFLICT(bucket, bucket_at) DO UPDATE SET
                cpu_percent    = (cpu_percent    * samples + :cpu)  / (samples + 1),
                memory_percent = (memory_percent * samples + :mem)  / (samples + 1),
                disk_percent   = (disk_percent   * samples + :disk) / (samples + 1),
                load1          = (load1          * samples + :load) / (samples + 1),
                cpu_peak       = MAX(cpu_peak, :cpu),
                memory_used_bytes = :membytes,
                disk_used_bytes   = :diskbytes,
                samples        = samples + 1',
            [
                'b' => $bucket,
                't' => $bucketAt,
                'cpu' => $sample['cpu_percent'],
                'mem' => $sample['memory_percent'],
                'disk' => $sample['disk_percent'],
                'load' => $sample['load1'],
                'membytes' => $sample['memory_used_bytes'],
                'diskbytes' => $sample['disk_used_bytes'],
                'now' => $now,
            ],
        );
    }

    /**
     * ยุบชั้นหนึ่งขึ้นอีกชั้น — คำนวณใหม่ทั้งช่วงทุกครั้ง ไม่บวกสะสม
     *
     * ยุบเฉพาะช่วงที่**ปิดแล้ว** (จบไปก่อนช่วงปัจจุบัน) เพราะช่วงที่ยังเดินอยู่จะมีตัวอย่าง
     * เพิ่มเข้ามาอีก การยุบตอนนี้จะได้ค่าที่ไม่ครบแล้วถูกเขียนทับอีกรอบโดยเปล่าประโยชน์
     */
    private function rollUpInto(string $from, string $to, int $now): void
    {
        $targetSize = self::BUCKETS[$to][0];
        $currentTarget = $this->floorTo($now, $targetSize);

        $this->db->run(
            'INSERT INTO metrics_history
                (bucket, bucket_at, cpu_percent, cpu_peak, memory_percent, disk_percent, load1,
                 memory_used_bytes, disk_used_bytes, samples, created_at)
             SELECT
                :to,
                (bucket_at / :size) * :size,
                -- ถ่วงน้ำหนักด้วย samples เสมอ — เฉลี่ยของค่าเฉลี่ยผิดเมื่อแต่ละแถวมี
                -- จำนวนตัวอย่างไม่เท่ากัน (เช่นช่วงที่เครื่องเพิ่งบูต)
                SUM(cpu_percent    * samples) / SUM(samples),
                MAX(cpu_peak),
                SUM(memory_percent * samples) / SUM(samples),
                SUM(disk_percent   * samples) / SUM(samples),
                SUM(load1          * samples) / SUM(samples),
                -- ไบต์ใช้ค่าล่าสุดของช่วง ไม่ใช่ค่าเฉลี่ย — ตอบคำถาม "ตอนนั้นใช้ไปเท่าไร"
                (SELECT memory_used_bytes FROM metrics_history i
                  WHERE i.bucket = :from2 AND (i.bucket_at / :size2) * :size2 = (o.bucket_at / :size3) * :size3
                  ORDER BY i.bucket_at DESC LIMIT 1),
                (SELECT disk_used_bytes FROM metrics_history i
                  WHERE i.bucket = :from3 AND (i.bucket_at / :size4) * :size4 = (o.bucket_at / :size5) * :size5
                  ORDER BY i.bucket_at DESC LIMIT 1),
                SUM(samples),
                :now
             FROM metrics_history o
             -- CAST จำเป็น ไม่ใช่ของประดับ: PDO ผูกพารามิเตอร์เป็น TEXT โดยปริยาย และ SQLite
             -- จัดลำดับชนิดโดย INTEGER มาก่อน TEXT เสมอ · การเทียบผลลัพธ์ของ **expression**
             -- (ซึ่งไม่มี type affinity ของคอลัมน์มาช่วยแปลงให้) กับพารามิเตอร์ที่เป็น TEXT
             -- จึงเป็นจริงเสมอ ทำให้ช่วงเวลาปัจจุบันที่ยังไม่ปิดถูกยุบก่อนเวลาโดยไม่มี error
             -- (คำสั่งที่เทียบคอลัมน์ตรง ๆ เช่น `bucket_at < :cutoff` ไม่มีปัญหานี้)
             WHERE bucket = :from AND (bucket_at / :size6) * :size6 < CAST(:currentTarget AS INTEGER)
             GROUP BY (bucket_at / :size7) * :size7
             ON CONFLICT(bucket, bucket_at) DO UPDATE SET
                cpu_percent    = excluded.cpu_percent,
                cpu_peak       = excluded.cpu_peak,
                memory_percent = excluded.memory_percent,
                disk_percent   = excluded.disk_percent,
                load1          = excluded.load1,
                memory_used_bytes = excluded.memory_used_bytes,
                disk_used_bytes   = excluded.disk_used_bytes,
                samples        = excluded.samples',
            [
                'to' => $to,
                'from' => $from, 'from2' => $from, 'from3' => $from,
                'size' => $targetSize, 'size2' => $targetSize, 'size3' => $targetSize,
                'size4' => $targetSize, 'size5' => $targetSize, 'size6' => $targetSize, 'size7' => $targetSize,
                'currentTarget' => $currentTarget,
                'now' => $now,
            ],
        );
    }

    /** ลบแถวที่เกินอายุของแต่ละชั้น */
    private function prune(int $now): int
    {
        $removed = 0;

        foreach (self::BUCKETS as $bucket => [, $keepSeconds]) {
            $removed += $this->db->run(
                'DELETE FROM metrics_history WHERE bucket = :b AND bucket_at < :cutoff',
                ['b' => $bucket, 'cutoff' => $now - $keepSeconds],
            )->rowCount();
        }

        return $removed;
    }

    /** ปัดเวลาลงให้ตรงจุดเริ่มของช่วง */
    private function floorTo(int $timestamp, int $size): int
    {
        return intdiv($timestamp, $size) * $size;
    }
}
