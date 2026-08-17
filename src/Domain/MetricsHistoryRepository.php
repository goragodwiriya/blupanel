<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * Store and read historical metrics, resolution shrinking with age — PLAN-V2 Phase E6
 *
 * **Three tiers, each with its own retention** (full reasoning in
 * `db/migrations/0014_metrics_history.sql`): minute/24h → hour/30 days → day/365
 * days · together this stays a fixed ~2,500 rows per machine forever.
 *
 * **Combining always weights by `samples`, never averages of averages** — an hourly
 * row built from 60 samples and one built from 3 samples (the machine just booted)
 * must carry different weight when rolling up into the day tier, otherwise a period
 * with sparse data would pull the whole day's average toward itself.
 *
 * **`cpu_peak` is kept separate from `cpu_percent`** because averaging erases a short
 * spike entirely — which is exactly the root cause behind "the site is slow in
 * bursts," the very thing this historical graph exists to answer.
 */
final class MetricsHistoryRepository
{
    /**
     * tier => [bucket length in seconds, how long to retain in seconds]
     *
     * @var array<string,array{0:int,1:int}>
     */
    public const BUCKETS = [
        'minute' => [60, 86400], // 1 minute · keep 24h
        'hour' => [3600, 2592000], // 1 hour · keep 30 days
        'day' => [86400, 31536000]// 1 day · keep 365 days
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Record one sample into the minute tier, then roll it up into the higher tiers itself
     *
     * @param array<string,mixed> $metrics the result of the `system.metrics` capability
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
            'disk_used_bytes' => (int) ($metrics['disk']['used'] ?? 0)
        ];

        $bucketAt = $this->floorTo($now, self::BUCKETS['minute'][0]);
        $this->upsert('minute', $bucketAt, $sample, $now);

        return [
            'bucket_at' => $bucketAt,
            'rolled_up' => $this->rollUp($now)
        ];
    }

    /**
     * Roll finer tiers up into coarser ones, then delete anything past its tier's retention
     *
     * Safe to call as often as needed — rolling up the same period twice produces the
     * same result, because it's recomputed fully from the lower tier every time,
     * never added on top of the previous result (which would drift further wrong
     * with every repeated call).
     *
     * @return int number of rows deleted for exceeding retention
     */
    public function rollUp(?int $now = null): int
    {
        $now ??= time();

        $this->rollUpInto('minute', 'hour', $now);
        $this->rollUpInto('hour', 'day', $now);

        return $this->prune($now);
    }

    /**
     * Read historical data for the given tier
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

    /**
     * Collapse the rows read in down to one point per `$step`-second interval
     *
     * **Differs from `rollUp()` in that it never touches the database at all** —
     * `rollUp()` changes what gets *stored*, based on data age; this changes what
     * gets *sent to the screen*, based on the range the user picked · so the same
     * tier can answer multiple ranges (7 days and 30 days both read from the hour
     * tier, but need 14 points and 30 points respectively).
     *
     * Weighted by `samples` for the same reason as `rollUpInto()` — a period with
     * few recorded samples (the machine just booted, or the scheduler missed a few
     * minutes) must not pull the whole interval's average toward itself ·
     * `cpu_peak` takes the maximum, since a short spike is exactly what this graph
     * exists to answer.
     *
     * @param list<array<string,mixed>> $rows already sorted by bucket_at
     * @return list<array<string,mixed>>
     */
    public function summarise(array $rows, int $step): array
    {
        if ($rows === [] || $step <= 0) {
            return $rows;
        }

        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->floorTo((int) $row['bucket_at'], $step)][] = $row;
        }

        ksort($groups);

        $summary = [];

        foreach ($groups as $bucketAt => $group) {
            // Total samples across the whole interval — can't practically be zero, but never divide by it if it is
            $weight = array_sum(array_map(static fn(array $r): int => max(1, (int) $r['samples']), $group));

            $average = function (string $column) use ($group, $weight): float {
                $total = 0.0;

                foreach ($group as $row) {
                    $total += (float) $row[$column] * max(1, (int) $row['samples']);
                }

                return $total / $weight;
            };

            $last = $group[count($group) - 1];

            $summary[] = [
                'bucket_at' => $bucketAt,
                'cpu_percent' => $average('cpu_percent'),
                'cpu_peak' => max(array_map(static fn(array $r): float => (float) $r['cpu_peak'], $group)),
                'memory_percent' => $average('memory_percent'),
                'disk_percent' => $average('disk_percent'),
                'load1' => $average('load1'),
                // Bytes use the interval's most recent value, not an average — answers "how much was in use at that moment"
                'memory_used_bytes' => (int) $last['memory_used_bytes'],
                'disk_used_bytes' => (int) $last['disk_used_bytes'],
                'samples' => $weight
            ];
        }

        return $summary;
    }

    /** The tier that fits a requested time range — chosen automatically so the screen never needs to know this rule */
    public static function bucketForRange(int $seconds): string
    {
        return match (true) {
            $seconds <= 86400 => 'minute',
            $seconds <= 2592000 => 'hour',
            default => 'day',
        };
    }

    /**
     * @param string $bucket
     * @return mixed
     */
    public function assertBucket(string $bucket): string
    {
        if (!isset(self::BUCKETS[$bucket])) {
            throw new \InvalidArgumentException(
                'Invalid tier — valid values: '.implode(', ', array_keys(self::BUCKETS)),
            );
        }

        return $bucket;
    }

    /**
     * Write or merge a sample into that interval's row
     *
     * Uses `ON CONFLICT DO UPDATE` to compute the new average from the old value +
     * the new value **in a single statement**, instead of reading then writing — so
     * two processes recording at the same time (the scheduler and a manual call)
     * never overwrite each other's value.
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
                'now' => $now
            ],
        );
    }

    /**
     * Roll one tier up into the next — always recomputed for the whole interval, never accumulated
     *
     * Only rolls up intervals that are **already closed** (ended before the current
     * one), since an interval still in progress will keep getting more samples —
     * rolling it up now would produce an incomplete value that gets overwritten again
     * for no benefit.
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
                -- Always weighted by samples — averaging averages is wrong when
                -- each row carries a different sample count (e.g. a period right
                -- after the machine booted)
                SUM(cpu_percent    * samples) / SUM(samples),
                MAX(cpu_peak),
                SUM(memory_percent * samples) / SUM(samples),
                SUM(disk_percent   * samples) / SUM(samples),
                SUM(load1          * samples) / SUM(samples),
                -- Bytes use the most recent value of the interval, not an average -- answers how much was in use at that moment
                (SELECT memory_used_bytes FROM metrics_history i
                  WHERE i.bucket = :from2 AND (i.bucket_at / :size2) * :size2 = (o.bucket_at / :size3) * :size3
                  ORDER BY i.bucket_at DESC LIMIT 1),
                (SELECT disk_used_bytes FROM metrics_history i
                  WHERE i.bucket = :from3 AND (i.bucket_at / :size4) * :size4 = (o.bucket_at / :size5) * :size5
                  ORDER BY i.bucket_at DESC LIMIT 1),
                SUM(samples),
                :now
             FROM metrics_history o
             -- CAST is necessary, not decoration: PDO binds parameters as TEXT by
             -- default, and SQLite always orders INTEGER before TEXT for type
             -- affinity purposes -- comparing the result of an **expression**
             -- (which has no column type affinity to help convert it) against a
             -- TEXT parameter is therefore always true, silently rolling up the
             -- still-open current interval early with no error (a statement that
             -- compares a column directly, like `bucket_at < :cutoff`, does not
             -- have this problem)
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
                'now' => $now
            ],
        );
    }

    /** Delete rows past each tier's retention */
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

    /** Round a timestamp down to the start of its interval */
    private function floorTo(int $timestamp, int $size): int
    {
        return intdiv($timestamp, $size) * $size;
    }
}
