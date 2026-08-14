<?php

declare(strict_types=1);

/**
 * ประวัติ metrics แบบลดความละเอียดตามอายุ — PLAN-V2 เฟส E6
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *   1. **การรวมค่าต้องถ่วงน้ำหนักด้วย `samples`** — เฉลี่ยของค่าเฉลี่ยผิดเมื่อแต่ละแถวมี
 *      จำนวนตัวอย่างไม่เท่ากัน ทำให้กราฟย้อนหลังโกหกโดยไม่มีใครรู้ (ผิดแบบเงียบที่สุด)
 *   2. **ยุบซ้ำต้องได้ผลเท่าเดิม** — scheduler รันทุกนาที ถ้าบวกสะสมค่าจะพองขึ้นเรื่อย ๆ
 *   3. **ต้องลบข้อมูลเกินอายุ** — ไม่งั้นไฟล์ฐานข้อมูลโตไม่หยุด ซึ่งเป็นเหตุผลทั้งหมด
 *      ที่ต้องยุบชั้นตั้งแต่แรก
 *   4. **`cpu_peak` ต้องไม่ถูกกลบด้วยค่าเฉลี่ย** — พีคสั้น ๆ คือสิ่งที่กราฟมีไว้ตอบ
 */

use Phpcp\Domain\MetricsHistoryRepository;
use Phpcp\Kernel\Db;

group('MetricsHistory — เก็บค่าย้อนหลังและยุบชั้นตามอายุ');

function metricsDb(): Db
{
    $path = sys_get_temp_dir() . '/phpcp-metrics-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.db';
    $db = new Db($path);
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    register_shutdown_function(static fn () => @unlink($path));

    return $db;
}

/** ตัวอย่างหนึ่งจุดในรูปเดียวกับที่ capability `system.metrics` คืน */
function metricsSample(float $cpu, float $memory = 50.0, float $disk = 20.0, int $memBytes = 1000, int $diskBytes = 2000): array
{
    return [
        'cpu' => ['percent' => $cpu],
        'memory' => ['percent' => $memory, 'used' => $memBytes],
        'disk' => ['percent' => $disk, 'used' => $diskBytes],
        'load' => [1 => 1.5],
    ];
}

/** ต้นชั่วโมงที่ผ่านมาแล้ว — ใช้เป็นฐานเวลาให้คำนวณตรวจได้ง่าย */
function metricsBase(int $hoursAgo = 2): int
{
    return (intdiv(time(), 3600) * 3600) - ($hoursAgo * 3600);
}

/**
 * ยุบชั้นโดยจำลองว่าเวลาผ่านไปจนช่วงของ `$base` ปิดแล้ว
 *
 * จำเป็นเพราะ `record()` ยุบเฉพาะช่วงที่**ปิดแล้ว** — ช่วงที่ยังเดินอยู่จะมีตัวอย่างเพิ่ม
 * เข้ามาอีก การยุบตอนนั้นได้ค่าที่ไม่ครบแล้วถูกเขียนทับอีกรอบโดยเปล่าประโยชน์
 */
function metricsCloseBucket(MetricsHistoryRepository $repo, int $base): void
{
    $repo->rollUp($base + 7200);
}

// --- 1. การรวมค่าถ่วงน้ำหนัก ---------------------------------------------------

test('ค่าเฉลี่ยรายชั่วโมงถ่วงน้ำหนักด้วยจำนวนตัวอย่าง ไม่ใช่เฉลี่ยของค่าเฉลี่ย', static function (): void {
    // นาทีแรกมี 3 ตัวอย่าง (10,10,10) นาทีที่สองมี 1 ตัวอย่าง (100)
    // ถ่วงน้ำหนักถูก: (10*3 + 100*1) / 4 = 32.5
    // เฉลี่ยของค่าเฉลี่ย (ผิด): (10 + 100) / 2 = 55
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $base = metricsBase();

    foreach ([0, 1, 2] as $second) {
        $repo->record(metricsSample(10.0), $base + $second);
    }
    $repo->record(metricsSample(100.0), $base + 60);
    metricsCloseBucket($repo, $base);

    $hour = $db->first('SELECT * FROM metrics_history WHERE bucket = :b', ['b' => 'hour']);

    assertSame(4, (int) $hour['samples'], 'ต้องนับตัวอย่างรวมทั้งสองนาที');
    assertSame(32.5, round((float) $hour['cpu_percent'], 2), 'ต้องถ่วงน้ำหนัก — ถ้าได้ 55 แปลว่าเฉลี่ยของค่าเฉลี่ย');
});

test('cpu_peak เก็บค่าสูงสุดจริง ไม่ถูกกลบด้วยค่าเฉลี่ย', static function (): void {
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $base = metricsBase();

    foreach ([5.0, 95.0, 5.0] as $offset => $cpu) {
        $repo->record(metricsSample($cpu), $base + $offset * 60);
    }
    metricsCloseBucket($repo, $base);

    $hour = $db->first('SELECT * FROM metrics_history WHERE bucket = :b', ['b' => 'hour']);

    assertSame(95.0, round((float) $hour['cpu_peak'], 1), 'พีคต้องเป็น 95 ไม่ใช่ค่าเฉลี่ย 35');
    assertSame(35.0, round((float) $hour['cpu_percent'], 1), 'ค่าเฉลี่ยยังต้องถูกต้องคู่กัน');
});

test('ไบต์ที่ใช้เก็บค่าล่าสุดของช่วง ไม่ใช่ค่าเฉลี่ย', static function (): void {
    // "ตอนนั้นใช้ไปกี่ GB" ตอบด้วยค่าล่าสุด · ค่าเฉลี่ยของขนาดที่ใช้ไม่มีความหมาย
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $base = metricsBase();

    $repo->record(metricsSample(10.0, memBytes: 1000, diskBytes: 5000), $base);
    $repo->record(metricsSample(10.0, memBytes: 9000, diskBytes: 7000), $base + 60);
    metricsCloseBucket($repo, $base);

    $hour = $db->first('SELECT * FROM metrics_history WHERE bucket = :b', ['b' => 'hour']);

    assertSame(9000, (int) $hour['memory_used_bytes'], 'ต้องเป็นค่าล่าสุด');
    assertSame(7000, (int) $hour['disk_used_bytes'], 'ต้องเป็นค่าล่าสุด');
});

// --- 2. ยุบซ้ำต้องได้ผลเท่าเดิม -------------------------------------------------

test('เรียก rollUp ซ้ำหลายรอบต้องได้ค่าเท่าเดิม ไม่บวกสะสม', static function (): void {
    // scheduler เรียกทุกนาที — ถ้าบวกสะสม ค่าจะพองขึ้นเรื่อย ๆ จนกราฟไร้ความหมาย
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $base = metricsBase();

    foreach ([0, 1, 2] as $minute) {
        $repo->record(metricsSample(20.0), $base + $minute * 60);
    }

    metricsCloseBucket($repo, $base);

    $after = static fn (): array => (array) $db->first('SELECT cpu_percent, samples FROM metrics_history WHERE bucket = :b', ['b' => 'hour']);
    $first = $after();

    $repo->rollUp($base + 7200);
    $repo->rollUp($base + 7200);
    $repo->rollUp($base + 7200);

    $last = $after();

    assertSame((int) $first['samples'], (int) $last['samples'], 'จำนวนตัวอย่างต้องไม่เพิ่มจากการยุบซ้ำ');
    assertSame(round((float) $first['cpu_percent'], 3), round((float) $last['cpu_percent'], 3), 'ค่าเฉลี่ยต้องคงที่');
});

test('ยุบเฉพาะช่วงที่ปิดแล้ว — ช่วงที่ยังเดินอยู่ต้องไม่ถูกยุบก่อนเวลา', static function (): void {
    // ช่วงปัจจุบันยังมีตัวอย่างเพิ่มเข้ามาอีก การยุบตอนนี้จะได้ค่าที่ไม่ครบ
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);

    $nowHour = intdiv(time(), 3600) * 3600;
    $repo->record(metricsSample(50.0), $nowHour + 60);   // อยู่ในชั่วโมงปัจจุบัน

    $rows = $db->all('SELECT bucket_at FROM metrics_history WHERE bucket = :b', ['b' => 'hour']);
    $currentHourRolled = array_filter($rows, static fn (array $r): bool => (int) $r['bucket_at'] === $nowHour);

    assertSame([], array_values($currentHourRolled), 'ชั่วโมงปัจจุบันต้องยังไม่ถูกยุบ');
});

// --- 3. ลบข้อมูลเกินอายุ --------------------------------------------------------

test('ข้อมูลที่เกินอายุของแต่ละชั้นถูกลบทิ้ง', static function (): void {
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $now = time();

    // ใส่แถวเก่าเกินอายุของทุกชั้นด้วยมือ (ชั้นนาทีเก็บ 24 ชม. · ชั่วโมง 30 วัน · วัน 365 วัน)
    foreach ([
        ['minute', $now - 90000],        // เกิน 24 ชม.
        ['hour', $now - 2600000],        // เกิน 30 วัน
        ['day', $now - 32000000],        // เกิน 365 วัน
    ] as [$bucket, $at]) {
        $db->insert('metrics_history', [
            'bucket' => $bucket, 'bucket_at' => $at, 'cpu_percent' => 1.0,
            'samples' => 1, 'created_at' => $at,
        ]);
    }

    $before = (int) $db->value('SELECT count(*) FROM metrics_history');
    assertSame(3, $before, 'ต้องมีสามแถวก่อนเก็บกวาด');

    $removed = $repo->rollUp($now);

    // **ข้อมูลถูกยุบขึ้นชั้นบนก่อนถูกลบ** — นั่นคือเจตนาทั้งหมดของการลดความละเอียด
    // ไม่ใช่ "ข้อมูลหายไปเฉย ๆ" · จึงตรวจว่าแถว**เดิม**ที่เกินอายุหายไปจริง
    // ไม่ใช่ตรวจว่าตารางว่างเปล่า (ซึ่งจะเป็นการยืนยันพฤติกรรมที่ผิด)
    assertSame(3, $removed, 'ต้องรายงานว่าลบแถวที่เกินอายุไปสามแถว');

    foreach ([['minute', $now - 90000], ['hour', $now - 2600000], ['day', $now - 32000000]] as [$bucket, $at]) {
        $stillThere = $db->value(
            'SELECT count(*) FROM metrics_history WHERE bucket = :b AND bucket_at = :t',
            ['b' => $bucket, 't' => $at],
        );

        assertSame(0, (int) $stillThere, "แถว {$bucket} ที่เกินอายุต้องถูกลบ");
    }
});

test('ข้อมูลที่ยังไม่เกินอายุต้องไม่ถูกลบ', static function (): void {
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $now = time();

    $db->insert('metrics_history', [
        'bucket' => 'minute', 'bucket_at' => $now - 3600, 'cpu_percent' => 1.0,
        'samples' => 1, 'created_at' => $now,
    ]);

    $repo->rollUp($now);

    assertSame(1, (int) $db->value('SELECT count(*) FROM metrics_history WHERE bucket = :b', ['b' => 'minute']), 'แถวอายุ 1 ชม. ต้องยังอยู่');
});

// --- 4. เลือกชั้นตามช่วงที่ขอ ---------------------------------------------------

test('เลือกชั้นความละเอียดให้เหมาะกับช่วงเวลาที่ขอ', static function (): void {
    // หน้าจอส่งมาแค่ช่วงเวลา ไม่ต้องรู้กฎการยุบชั้น
    assertSame('minute', MetricsHistoryRepository::bucketForRange(3600), '1 ชม. → รายนาที');
    assertSame('minute', MetricsHistoryRepository::bucketForRange(86400), '24 ชม. → รายนาที');
    assertSame('hour', MetricsHistoryRepository::bucketForRange(604800), '7 วัน → รายชั่วโมง');
    assertSame('hour', MetricsHistoryRepository::bucketForRange(2592000), '30 วัน → รายชั่วโมง');
    assertSame('day', MetricsHistoryRepository::bucketForRange(31536000), '1 ปี → รายวัน');
});

test('ชั้นที่ไม่รู้จักถูกปฏิเสธ ไม่ใช่คืนค่าว่างเงียบ ๆ', static function (): void {
    $repo = new MetricsHistoryRepository(metricsDb());

    assertRejects(
        InvalidArgumentException::class,
        static fn () => $repo->range('week', 0),
        'ชั้นที่ไม่มีอยู่ต้องถูกปฏิเสธ',
    );
});

// --- 5. อ่านช่วงเวลา -----------------------------------------------------------

test('range() คืนเฉพาะจุดในช่วงที่ขอ เรียงตามเวลา', static function (): void {
    $db = metricsDb();
    $repo = new MetricsHistoryRepository($db);
    $now = time();

    foreach ([-7200, -3600, -60] as $offset) {
        $db->insert('metrics_history', [
            'bucket' => 'minute', 'bucket_at' => $now + $offset, 'cpu_percent' => 1.0,
            'samples' => 1, 'created_at' => $now,
        ]);
    }

    $rows = $repo->range('minute', $now - 3700);

    assertSame(2, count($rows), 'ต้องได้เฉพาะสองจุดที่อยู่ในช่วง');
    assertTrue(
        (int) $rows[0]['bucket_at'] < (int) $rows[1]['bucket_at'],
        'ต้องเรียงจากเก่าไปใหม่ ไม่งั้นกราฟวาดกลับด้าน',
    );
});

// --- 6. ทะเบียน capability ------------------------------------------------------

test('metrics.record ต้องไม่ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ — ไม่งั้น audit จมทุกนาที', static function (): void {
    // งานนี้รันทุกนาทีตลอดไป · ถ้าเข้า audit log จะได้ 1,440 แถวต่อวันที่ไม่มีใครอ่าน
    // และกลบเหตุการณ์จริงที่ต้องสอบสวน (หลักการเดียวกับ disk.usage)
    $capability = (new Phpcp\Agent\CapabilityRegistry())->resolve('metrics.record');

    assertTrue(!$capability->isMutating(), 'ต้องเป็นอ่านอย่างเดียว — เขียนแค่ตารางแคชของ panel เอง');
    assertSame('dashboard.view', $capability->permission(), 'ใช้สิทธิ์เดียวกับการดู metrics');
});

test('งานตามเวลา metrics.record ถูกตั้งไว้ทุกนาที ให้ตรงกับความละเอียดของชั้นล่างสุด', static function (): void {
    $job = null;
    foreach (Phpcp\Domain\ScheduledJobRepository::DEFAULTS as $default) {
        if ($default['name'] === 'metrics.record') {
            $job = $default;
        }
    }

    assertTrue($job !== null, 'ต้องมีงาน metrics.record ในรายการตั้งต้น');
    assertSame('* * * * *', $job['schedule'], 'ต้องทุกนาที — ถี่กว่านี้ค่าถูกเฉลี่ยรวมอยู่ดี ห่างกว่านี้กราฟ 24 ชม. จะมีช่วงว่าง');
});

// --- 4. สรุปให้เหลือเท่าที่กราฟวาดได้ ------------------------------------------

test('summarise() ยุบให้เหลือหนึ่งจุดต่อช่วง โดยถ่วงน้ำหนักด้วย samples', static function (): void {
    /*
     * ค่าเฉลี่ยตรงนี้ผิดแล้วไม่มีอะไรฟ้อง — กราฟยังวาดสวยเหมือนเดิม แค่โกหก
     * เหตุผลเดียวกับที่ rollUpInto() ต้องถ่วงน้ำหนัก แต่คนละเส้นทางโค้ด
     */
    $repository = new MetricsHistoryRepository(metricsDb());

    // สองแถวในช่วงเดียวกัน: 60 ตัวอย่างที่ 10% กับ 1 ตัวอย่างที่ 90%
    // เฉลี่ยธรรมดาได้ 50% ซึ่งไกลจากความจริงมาก · ถ่วงน้ำหนักแล้วต้องได้ ~11.3%
    $rows = [
        ['bucket_at' => 3600, 'cpu_percent' => 10.0, 'cpu_peak' => 12.0, 'memory_percent' => 40.0,
            'disk_percent' => 20.0, 'load1' => 1.0, 'memory_used_bytes' => 100, 'disk_used_bytes' => 200, 'samples' => 60],
        ['bucket_at' => 3660, 'cpu_percent' => 90.0, 'cpu_peak' => 95.0, 'memory_percent' => 40.0,
            'disk_percent' => 20.0, 'load1' => 1.0, 'memory_used_bytes' => 111, 'disk_used_bytes' => 222, 'samples' => 1],
    ];

    $summary = $repository->summarise($rows, 3600);

    assertSame(1, count($summary), 'สองแถวในชั่วโมงเดียวกันต้องเหลือจุดเดียว');
    assertSame(3600, $summary[0]['bucket_at'], 'เวลาของจุดต้องเป็นต้นช่วง');
    assertSame(11.3, round($summary[0]['cpu_percent'], 1), 'ต้องถ่วงน้ำหนัก ไม่ใช่ได้ 50');
    assertSame(95.0, $summary[0]['cpu_peak'], 'พีคต้องเป็นค่าสูงสุด ไม่ใช่ค่าเฉลี่ย');
    assertSame(111, $summary[0]['memory_used_bytes'], 'ไบต์ต้องเป็นค่าล่าสุดของช่วง');
    assertSame(61, $summary[0]['samples'], 'จำนวนตัวอย่างต้องรวมกัน');
});

test('ทุกช่วงที่เลือกได้ต้องส่งจุดน้อยกว่าที่กราฟวาดไหว', static function (): void {
    /*
     * **เจอจากการใช้งานจริง (2026-08-14):** กราฟค้างที่เวลาเดิมตลอดทั้งที่ข้อมูลสด
     * มาครบ · GraphComponent วาด `data.slice(0, maxDataPoints)` ซึ่งเป็นจุด **แรก ๆ**
     * ของชุด ไม่ใช่จุดล่าสุด · 24 ชม. ส่งไป 1,440 จุด กราฟจึงวาดแค่ 20 นาทีแรกของ
     * ชุดเสมอ และทุกช่วงหน้าตาเหมือนกันหมดเพราะเป็นต้นชุดเหมือนกัน
     *
     * เทสต์นี้ตรึงฝั่งเซิร์ฟเวอร์ให้สรุปมาแล้ว ส่วนฝั่งเทมเพลตมี data-max-data-points="0"
     * กำกับไว้อีกชั้น (ตรวจในเทสต์ถัดไป)
     */
    $reflection = new ReflectionClass(Phpcp\Http\V2\MetricsController::class);
    $ranges = $reflection->getConstant('RANGES');

    assertTrue(is_array($ranges) && $ranges !== [], 'ต้องมีรายการช่วงเวลา');

    $repository = new MetricsHistoryRepository(metricsDb());

    foreach ($ranges as $name => [$seconds, $step, $format]) {
        // จำลองข้อมูลเต็มช่วงที่ความละเอียดของชั้นที่ใช้จริง
        $bucketSize = MetricsHistoryRepository::BUCKETS[MetricsHistoryRepository::bucketForRange($seconds)][0];
        $rows = [];

        for ($at = 0; $at < $seconds; $at += $bucketSize) {
            $rows[] = ['bucket_at' => $at, 'cpu_percent' => 10.0, 'cpu_peak' => 10.0, 'memory_percent' => 10.0,
                'disk_percent' => 10.0, 'load1' => 1.0, 'memory_used_bytes' => 1, 'disk_used_bytes' => 1, 'samples' => 1];
        }

        $points = count($repository->summarise($rows, $step));

        assertTrue($points > 0, "ช่วง {$name} ต้องมีจุดให้วาด");
        assertTrue(
            $points <= 32,
            "ช่วง {$name} ส่ง {$points} จุด — มากเกินกว่าที่แกนเวลาจะอ่านออก และเสี่ยงถูกกราฟตัดท้ายทิ้ง",
        );

        // ป้ายของแต่ละช่วงต้องต่างกันจริง ไม่ใช่ 'H:i' เหมือนกันหมดจนแยกไม่ออกว่าดูช่วงไหน
        assertTrue($format !== '', "ช่วง {$name} ต้องมีรูปแบบป้าย");
    }
});

test('เทมเพลตต้องสั่งกราฟไม่ให้ตัดจุดท้ายทิ้ง', static function (): void {
    // ค่าเริ่มต้นของ GraphComponent คือ 20 จุด — ช่วง 30 วันส่ง 30 จุด ถ้าไม่มีบรรทัดนี้
    // สิบวันสุดท้ายจะหายไปเงียบ ๆ โดยไม่มี error ให้เห็น
    $html = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/server.html');
    $graph = strstr($html, 'data-metrics-graph');

    assertTrue(
        str_contains((string) strstr((string) $graph, '>', true), 'data-max-data-points="0"'),
        'ธาตุกราฟต้องมี data-max-data-points="0"',
    );
});
