<?php

declare(strict_types=1);

/**
 * การเขียนพร้อมกันจากหลายโปรเซสต้องไม่ล้ม
 *
 * **บั๊กจริงที่เทสต์นี้กันไว้ (พบ 2026-08-07 ตอนทำเฟส C):** `Db::transaction()` เคยใช้
 * `PDO::beginTransaction()` ซึ่งเป็น `BEGIN DEFERRED` — ยังไม่จองล็อกอะไรเลย
 * พอคำสั่งแรกในบล็อกเป็นการ**อ่าน** การเชื่อมต่อจะได้ล็อกอ่าน แล้วเมื่อจะ**เขียน**
 * ต้องยกระดับล็อก ถ้าตอนนั้นมีการเชื่อมต่ออื่นถือล็อกเขียนอยู่ SQLite คืน
 * `SQLITE_BUSY` **ทันทีโดยไม่รอตาม `busy_timeout` เลย** เพราะการรอคือ deadlock
 *
 * `RateLimiter::allow()` และ `AuditLog::write()` ใช้ transaction และทำงาน**ทุกคำขอ**
 * ทั้งคู่อ่านก่อนเขียน · ยิงพร้อมกัน 8 คำขอแล้วล้ม 7 ด้วย "database is locked"
 * ทั้งบน ext4 ของเซิร์ฟเวอร์จริงและบน FUSE ของเครื่องพัฒนา
 *
 * **ทำไมไม่มีใครเจอมาก่อน:** UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า · SPA ของเฟส C
 * ยิงหลายก้อนพร้อมกันต่อหนึ่งหน้า อาการจึงโผล่ทันทีที่เปิดหน้าแรก
 *
 * เทสต์นี้ต้องใช้**หลายโปรเซสจริง** — การเชื่อมต่อสองตัวในโปรเซสเดียวกันไม่เกิด
 * การแย่งล็อกแบบเดียวกัน จึงพิสูจน์อะไรไม่ได้
 */

use Phpcp\Kernel\Db;

group('Db — การเขียนพร้อมกันจากหลายโปรเซส');

test('transaction ที่อ่านก่อนเขียน ต้องไม่ล้มเมื่อมีโปรเซสอื่นเขียนพร้อมกัน', static function (): void {
    $file = sys_get_temp_dir() . '/phpcp-concurrency-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.db';

    $db = new Db($file);
    $db->pdo()->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, worker TEXT, v INTEGER)');

    // ตัวเขียนหนึ่งตัว: อ่านแล้วเขียนใน transaction เดียวกัน ซ้ำหลายรอบ
    // (รูปแบบเดียวกับ RateLimiter::allow() ที่รันทุกคำขอ)
    $worker = <<<'PHP'
        <?php
        require getenv('PHPCP_ROOT') . '/bootstrap.php';
        $db = new Phpcp\Kernel\Db($argv[1]);
        $failed = 0;
        for ($i = 0; $i < 25; $i++) {
            try {
                $db->transaction(static function ($d) use ($argv, $i): void {
                    $d->value('SELECT count(*) FROM probe');
                    $d->run('INSERT INTO probe (worker, v) VALUES (:w, :v)', ['w' => $argv[2], 'v' => $i]);
                });
            } catch (Throwable $e) {
                $failed++;
            }
        }
        exit($failed);
        PHP;

    $script = sys_get_temp_dir() . '/phpcp-concurrency-worker-' . getmypid() . '.php';
    file_put_contents($script, $worker);

    $processes = [];
    for ($n = 0; $n < 4; $n++) {
        $processes[] = proc_open(
            [PHP_BINARY, $script, $file, 'w' . $n],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHPCP_ROOT' => PHPCP_ROOT] + getenv(),
        );
    }

    $failures = 0;
    foreach ($processes as $process) {
        if (is_resource($process)) {
            $failures += proc_close($process);
        }
    }

    $written = (int) $db->value('SELECT count(*) FROM probe', [], 0);

    @unlink($script);
    @unlink($file);
    @unlink($file . '-wal');
    @unlink($file . '-shm');

    assertSame(0, $failures, "มีการเขียนที่ล้ม {$failures} ครั้งจาก 100 ครั้ง — ตรวจว่า transaction() ยังใช้ BEGIN IMMEDIATE อยู่หรือไม่");
    assertSame(100, $written, 'ต้องเขียนครบ 100 แถวจาก 4 โปรเซส × 25 รอบ');
});

test('transaction ซ้อนกันใช้ transaction ของชั้นนอก ไม่เปิดใหม่', static function (): void {
    // SQLite ไม่มี nested transaction จริง — การสั่ง BEGIN ซ้อนจะโยน error
    // ถ้าโค้ดชั้นในเผลอเปิดใหม่ ทั้งบล็อกจะพังทันทีที่มีใครเรียกซ้อน
    $file = sys_get_temp_dir() . '/phpcp-nested-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.db';

    $db = new Db($file);
    $db->pdo()->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, v INTEGER)');

    $result = $db->transaction(static fn (Db $outer): int => $outer->transaction(
        static function (Db $inner): int {
            $inner->run('INSERT INTO probe (v) VALUES (:v)', ['v' => 1]);

            return 42;
        },
    ));

    $written = (int) $db->value('SELECT count(*) FROM probe', [], 0);

    @unlink($file);
    @unlink($file . '-wal');
    @unlink($file . '-shm');

    assertSame(42, $result, 'ค่าที่คืนจากบล็อกชั้นในต้องส่งผ่านออกมาได้');
    assertSame(1, $written, 'การเขียนในบล็อกซ้อนต้องถูก commit');
});
