<?php

declare(strict_types=1);

/**
 * หยิบไฟล์ออกมาใช้จริง — ดาวน์โหลดไฟล์ใหญ่ และแตกไฟล์บีบอัดที่ระบบสร้างเอง
 *
 * ## ทำไมชุดนี้ถึงมีอยู่
 *
 * ตั้งแต่ PLAN-BACKUP-V2 ไฟล์สำรองอยู่ในบ้านของลูกค้า **เพื่อให้เขาหยิบไปเก็บเองได้** ·
 * แต่ตอนทดสอบบนเครื่องจริงพบว่าผ่านหน้าเว็บทำไม่ได้เลยสองทาง และทั้งคู่ล้มแบบที่
 * หน้าจอไม่ได้ผิดอะไร:
 *
 *   1. **ดาวน์โหลดติดเพดาน 2.5 MB** (ขนาดเฟรมของโปรโตคอล) แล้วบอกให้ "บีบอัดเป็น zip
 *      ก่อน" — คำแนะนำที่ใช้ไม่ได้กับไฟล์ที่บีบมาแล้วและใหญ่กว่านั้นเสมอ
 *   2. **เมนูแตกไฟล์ไม่ขึ้นกับ `.tar.gz`** เพราะรองรับแต่ `.zip` · ลูกค้าที่อยากได้
 *      ไฟล์เดียวคืนต้องกู้คืนทั้งเว็บทับของปัจจุบัน ซึ่งเป็นคำสั่งที่อันตรายที่สุดในระบบ
 *
 * ที่นี่ใช้ไฟล์จริงและคำสั่งจริง เพราะสิ่งที่ต้องพิสูจน์คือ "ไบต์ที่ออกมาตรงกับต้นฉบับไหม"
 * ซึ่งของปลอมตอบแทนไม่ได้
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\FileDownload;
use Phpcp\Agent\Capability\FileUnzip;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('รับไฟล์ออกจากเครื่อง — ดาวน์โหลดทีละก้อนและแตกไฟล์บีบอัด');

/**
 * บริบทของผู้ดูแลระดับเซิร์ฟเวอร์ + โฟลเดอร์ทดสอบ
 *
 * ใช้ขอบเขต `server` (ราก `/`) เพราะสิ่งที่ทดสอบคือการรับส่งไฟล์ ไม่ใช่ด่านขอบเขต
 * ซึ่งมีชุดเทสต์ของตัวเองอยู่แล้วใน FileManagerTest
 *
 * @return array{context:Context,dir:string}
 */
function transferFixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $dir = sys_get_temp_dir() . '/phpcp-transfer-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o750, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

    return $fixture = [
        'context' => new Context(
            new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
            Config::load(PHPCP_ROOT),
            migratedDb(),
        ),
        'dir' => $dir,
    ];
}

test('ไฟล์ที่ใหญ่กว่าหนึ่งก้อนต้องดาวน์โหลดได้ครบทุกไบต์', static function (): void {
    /*
     * เดิมไฟล์แบบนี้ถูกปฏิเสธไปเลย · ตอนนี้ผู้เรียกขอทีละช่วงแล้วต่อกันเอง — เทสต์นี้
     * เดินเส้นทางเดียวกับที่ชั้น HTTP เดิน แล้วเทียบไบต์กับต้นฉบับ
     *
     * ขนาดตั้งไว้ให้เกินหนึ่งก้อน **และไม่ลงตัวพอดี** เพื่อให้ก้อนสุดท้ายสั้นกว่าก้อนอื่น
     * ซึ่งเป็นจุดที่การคำนวณ offset ผิดจะโผล่ออกมา
     */
    $fixture = transferFixture();
    $path = $fixture['dir'] . '/big.bin';
    $original = random_bytes(FileCatalog::MAX_TRANSFER_BYTES * 2 + 12345);

    file_put_contents($path, $original);

    $capability = new FileDownload();
    $executor = new RealExecutor();
    $collected = '';
    $offset = 0;
    $rounds = 0;

    while (true) {
        $chunk = $capability->run(
            $capability->validate(['root' => 'server', 'path' => ltrim($path, '/'), 'offset' => $offset]),
            $executor,
            $fixture['context'],
        );

        $collected .= (string) base64_decode((string) $chunk['content'], true);
        $offset += (int) $chunk['bytes'];
        $rounds++;

        assertSame(strlen($original), (int) $chunk['size'], 'ทุกก้อนต้องรายงานขนาดทั้งไฟล์ตรงกัน');

        if ($chunk['eof'] === true) {
            break;
        }

        assertTrue($rounds < 10, 'ต้องจบภายในจำนวนก้อนที่คาดไว้ ไม่ใช่วนไม่รู้จบ');
    }

    assertSame(3, $rounds, 'ไฟล์ขนาดนี้ต้องใช้สามก้อน');
    assertSame(strlen($original), strlen($collected), 'ขนาดที่ต่อกันได้ต้องเท่าต้นฉบับ');
    assertSame(hash('sha256', $original), hash('sha256', $collected), 'เนื้อไฟล์ต้องตรงกันทุกไบต์');
});

test('ขอเลยท้ายไฟล์ต้องได้ก้อนว่างที่บอกว่าจบแล้ว ไม่ใช่ข้อผิดพลาด', static function (): void {
    // ผู้เรียกที่คำนวณ offset เกินไปหนึ่งก้อน (เช่นไฟล์ถูกตัดสั้นลงระหว่างดาวน์โหลด)
    // ต้องได้คำตอบที่เดินต่อได้ ไม่ใช่ 500 ที่ทำให้ไฟล์ที่โหลดมาแล้วเสียเปล่า
    $fixture = transferFixture();
    $path = $fixture['dir'] . '/small.txt';
    file_put_contents($path, 'สั้นมาก');

    $capability = new FileDownload();
    $result = $capability->run(
        $capability->validate(['root' => 'server', 'path' => ltrim($path, '/'), 'offset' => 99999]),
        new RealExecutor(),
        $fixture['context'],
    );

    assertSame(0, $result['bytes'], 'ต้องไม่มีไบต์ไหนถูกส่งกลับมา');
    assertSame(true, $result['eof'], 'ต้องบอกว่าจบแล้ว');
});

test('แตก .tar.gz ต้องได้ไฟล์ข้างในครบ', static function (): void {
    $fixture = transferFixture();
    $source = $fixture['dir'] . '/payload';

    mkdir($source . '/sub', 0o750, true);
    file_put_contents($source . '/index.php', '<?php echo "ของจริง";');
    file_put_contents($source . '/sub/config.php', 'ของในโฟลเดอร์ย่อย');

    $archive = $fixture['dir'] . '/site.tar.gz';
    exec(sprintf(
        'tar --create --gzip --file %s --directory %s payload',
        escapeshellarg($archive),
        escapeshellarg($fixture['dir']),
    ));

    $capability = new FileUnzip();
    $result = $capability->run(
        $capability->validate(['root' => 'server', 'path' => ltrim($archive, '/'), 'destination' => 'out']),
        new RealExecutor(),
        $fixture['context'],
    );

    assertTrue($result['entries'] > 0, 'ต้องรายงานจำนวนรายการที่แตก');
    assertSame(
        '<?php echo "ของจริง";',
        (string) file_get_contents($fixture['dir'] . '/out/payload/index.php'),
        'ไฟล์ข้างในต้องออกมาครบและเนื้อตรง',
    );
    assertTrue(is_file($fixture['dir'] . '/out/payload/sub/config.php'), 'โฟลเดอร์ย่อยต้องมาด้วย');
});

test('tar ที่มีรายการชี้ออกนอกปลายทางต้องถูกปฏิเสธก่อนแตะดิสก์', static function (): void {
    /*
     * GNU tar ตัดเส้นทางสัมบูรณ์กับ `..` ทิ้งให้เองในทางปฏิบัติ — แต่นั่นเป็นพฤติกรรม
     * ของเครื่องมือที่เราไม่ได้ควบคุมและไม่ได้ประกาศเป็นสัญญากับใคร · ด่านของเราต้อง
     * เป็นของเราเอง (บทเรียนเดียวกับ `--exclude backup.json` ตอนกู้คืน)
     *
     * ปฏิเสธ**ทั้งไฟล์** ไม่ใช่ข้ามรายการ — archive ที่มีรายการแบบนี้คือ archive ที่
     * ตั้งใจร้าย ไม่ใช่ของปกติที่บังเอิญมีไฟล์แปลกปนมาหนึ่งไฟล์
     */
    $fixture = transferFixture();
    $evil = $fixture['dir'] . '/evil.tar';
    $victim = $fixture['dir'] . '/victim.txt';

    file_put_contents($victim, 'ของเดิมที่ห้ามถูกทับ');

    // -P เก็บเส้นทางสัมบูรณ์ไว้ในรายการ ซึ่งเป็นสิ่งที่ archive ตั้งใจร้ายทำ
    exec(sprintf('tar --create -P --file %s %s', escapeshellarg($evil), escapeshellarg($victim)));

    $capability = new FileUnzip();

    assertRejects(
        ExecutionFailed::class,
        static fn () => $capability->run(
            $capability->validate(['root' => 'server', 'path' => ltrim($evil, '/'), 'destination' => 'evilout']),
            new RealExecutor(),
            $fixture['context'],
        ),
        'ไฟล์ที่มีรายการเส้นทางสัมบูรณ์ต้องถูกปฏิเสธ',
    );

    assertSame(
        'ของเดิมที่ห้ามถูกทับ',
        (string) file_get_contents($victim),
        'ของเดิมต้องไม่ถูกแตะเลย',
    );
});

test('คลาย .gz ต้องได้ไฟล์ข้าง ๆ และต้นฉบับต้องยังอยู่', static function (): void {
    /*
     * `.sql.gz` ของฐานข้อมูลเป็นไฟล์เดียว ไม่ใช่ archive — คลายแล้วต้องได้ `.sql`
     * วางข้าง ๆ ไม่ใช่โฟลเดอร์ที่มีไฟล์เดียวข้างใน · และต้นฉบับต้องอยู่ต่อ เพราะคนกด
     * "แตกไฟล์" ไม่ได้ขอให้ลบสำเนาที่บีบไว้ทิ้ง
     */
    $fixture = transferFixture();
    $plain = $fixture['dir'] . '/dump.sql';
    file_put_contents($plain, "-- ข้อมูลจำลอง\nCREATE TABLE x (id INT);\n");
    exec('gzip ' . escapeshellarg($plain));

    assertTrue(is_file($plain . '.gz') && !is_file($plain), 'เตรียมไฟล์ .gz ให้พร้อมก่อน');

    $capability = new FileUnzip();
    $result = $capability->run(
        $capability->validate(['root' => 'server', 'path' => ltrim($plain . '.gz', '/')]),
        new RealExecutor(),
        $fixture['context'],
    );

    assertSame(1, $result['entries'], 'ไฟล์เดียวคือหนึ่งรายการ');
    assertTrue(str_contains((string) file_get_contents($plain), 'CREATE TABLE'), 'เนื้อไฟล์ต้องคลายออกมาถูก');
    assertTrue(is_file($plain . '.gz'), 'ต้นฉบับที่บีบไว้ต้องยังอยู่');

    // คลายซ้ำต้องไม่เขียนทับของที่มีอยู่ — ผู้ใช้อาจแก้ไฟล์นั้นไปแล้ว
    assertRejects(
        ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['root' => 'server', 'path' => ltrim($plain . '.gz', '/')]),
            new RealExecutor(),
            $fixture['context'],
        ),
        'ปลายทางที่มีอยู่แล้วต้องไม่ถูกเขียนทับเงียบ ๆ',
    );
});

test('นามสกุลที่ระบบไม่รู้จักต้องถูกปฏิเสธตั้งแต่ชั้นตรวจค่า', static function (): void {
    $capability = new FileUnzip();

    foreach (['note.txt', 'archive.rar', 'photo.jpg', 'noext'] as $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => $capability->validate(['root' => 'server', 'path' => $bad]),
            "ชื่อ '{$bad}' ต้องถูกปฏิเสธ",
        );
    }

    // และชนิดที่รองรับต้องแยกออกจากกันถูกต้อง — `.tar.gz` ต้องไม่ถูกมองเป็นไฟล์เดี่ยว
    assertSame('tar', FileUnzip::kindOf('site.tar.gz'), '.tar.gz คือ tar');
    assertSame('tar', FileUnzip::kindOf('site.tgz'), '.tgz คือ tar');
    assertSame('gz', FileUnzip::kindOf('dump.sql.gz'), '.sql.gz คือไฟล์เดี่ยวที่บีบไว้');
    assertSame('zip', FileUnzip::kindOf('files.ZIP'), 'นามสกุลตัวใหญ่ต้องรู้จักด้วย');
});
