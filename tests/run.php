<?php

declare (strict_types = 1);

/**
 * ตัวรันเทสต์ขนาดเล็ก — ไม่มี PHPUnit เพราะโปรเจกต์ไม่มี Composer (ARCHITECTURE D6)
 *
 * ใช้: php tests/run.php [ชื่อกลุ่ม]
 *
 * เทสต์ความปลอดภัยทั้งหมดรันในโหมด dryrun/sandbox ได้ จึงใส่ใน CI ที่ไม่มีสิทธิ์ root ได้
 */

require dirname(__DIR__).'/bootstrap.php';

final class TestRunner
{
    /** @var list<array{group:string,name:string,fn:callable}> */
    public static array $tests = [];

    public static int $assertions = 0;

    /**
     * @param string $name
     * @param $fn
     */
    public static string $currentGroup = '';
}

function group(string $name): void
{
    TestRunner::$currentGroup = $name;
}

function test(string $name, callable $fn): void
{
    TestRunner::$tests[] = ['group' => TestRunner::$currentGroup, 'name' => $name, 'fn' => $fn];
}

/**
 * @param bool $condition
 * @param string $message
 */
function assertTrue(bool $condition, string $message): void
{
    TestRunner::$assertions++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param bool $condition
 * @param string $message
 */
function assertFalse(bool $condition, string $message): void
{
    assertTrue(!$condition, $message);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 * @param string $message
 */
function assertSame(mixed $expected, mixed $actual, string $message): void
{
    assertTrue(
        $expected === $actual,
        $message.sprintf(' (คาดว่า %s ได้ %s)', var_export($expected, true), var_export($actual, true)),
    );
}

/**
 * ฐานข้อมูลชั่วคราวที่ผ่าน migration ครบแล้ว — ลบตัวเองทิ้งเมื่อโปรเซสจบ
 *
 * เทสต์ที่แตะฐานข้อมูลต้องใช้ของจริง ไม่ใช่ mock: ตรรกะที่สำคัญที่สุดหลายอย่าง
 * (โควตา, งานตามเวลา, การเชื่อมเว็บไซต์) อยู่ที่การอ่าน/เขียนจริงและข้อจำกัดของตาราง
 * ซึ่ง mock จะซ่อนความผิดพลาดไปทั้งหมด · ไม่ต้องใช้ root จึงรันใน CI ได้
 */
function migratedDb(): \Phpcp\Kernel\Db
{
    $file = sys_get_temp_dir().'/phpcp-test-'.getmypid().'-'.bin2hex(random_bytes(4)).'.db';

    register_shutdown_function(static function () use ($file): void {
        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            @unlink($path);
        }
    });

    $db = new \Phpcp\Kernel\Db($file);
    $db->migrate(PHPCP_ROOT.'/db/migrations');

    return $db;
}

/** ยืนยันว่าโค้ดต้องโยน exception ชนิดที่กำหนด — ใช้ตรวจว่าระบบ "ปฏิเสธ" จริง */
function assertRejects(string $exceptionClass, callable $fn, string $message): void
{
    TestRunner::$assertions++;

    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException($message.' — ปฏิเสธด้วย '.$e::class.' แทนที่จะเป็น '.$exceptionClass);
    }

    throw new RuntimeException($message.' — ไม่ถูกปฏิเสธเลย');
}

// --- โหลดไฟล์เทสต์ ---------------------------------------------------------

$filter = $argv[1] ?? '';
$files = array_merge(
    glob(__DIR__.'/security/*.php') ?: [],
    // เทสต์ contract ของ REST API — แยกไดเรกทอรีตาม PLAN-V2 §3.2 แต่รันด้วยตัวรันเดียวกัน
    glob(__DIR__.'/api/*.php') ?: [],
);
sort($files);

foreach ($files as $file) {
    require $file;
}

// --- รัน --------------------------------------------------------------------

$colors = function_exists('posix_isatty') && @posix_isatty(STDOUT);
$green = $colors ? "\033[32m" : '';
$red = $colors ? "\033[31m" : '';
$dim = $colors ? "\033[90m" : '';
$off = $colors ? "\033[0m" : '';

$passed = 0;
$failed = 0;
$lastGroup = '';
$startedAt = hrtime(true);

foreach (TestRunner::$tests as $entry) {
    if ($filter !== '' && !str_contains(strtolower($entry['group']), strtolower($filter))) {
        continue;
    }

    if ($entry['group'] !== $lastGroup) {
        printf("\n%s\n", $entry['group']);
        $lastGroup = $entry['group'];
    }

    try {
        ($entry['fn'])();
        $passed++;
        printf("  %s✔%s %s\n", $green, $off, $entry['name']);
    } catch (Throwable $e) {
        $failed++;
        printf("  %s✘%s %s\n      %s%s%s\n", $red, $off, $entry['name'], $dim, $e->getMessage(), $off);
    }
}

$ms = (int) ((hrtime(true) - $startedAt) / 1_000_000);

printf(
    "\n%s ผ่าน %d · ไม่ผ่าน %d · ยืนยัน %d ครั้ง · %d ms%s\n\n",
    $failed === 0 ? $green : $red,
    $passed,
    $failed,
    TestRunner::$assertions,
    $ms,
    $off,
);

exit($failed === 0 ? 0 : 1);
