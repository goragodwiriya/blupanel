<?php

declare(strict_types=1);

/**
 * ย้าย/คัดลอก "ทั้งโฟลเดอร์" — คำสั่งเดียวที่หน้าจอ "ย้ายไปที่.../คัดลอกไปที่..." ยืนอยู่บนมัน
 *
 * ## ทำไมชุดนี้ถึงมีอยู่
 *
 * `file.move` ทำงานกับไฟล์เดี่ยวมาตลอดโดยไม่มีเทสต์ของตัวเองเลย · พอหน้าเว็บเพิ่ม
 * ตัวเลือกโฟลเดอร์ปลายทางเข้ามา คำสั่งนี้ก็กลายเป็นทางหลักที่ผู้ใช้จะ**ย้ายทั้งต้นไม้**
 * ไปมา ซึ่งเป็นงานที่ผิดแล้วเจ็บที่สุดในตัวจัดการไฟล์: ข้อมูลหาย ทับของเดิม หรือ
 * เดินตาม symlink ออกนอกบ้านของเว็บไปคัดลอกไฟล์ระบบกลับเข้ามา
 *
 * ที่นี่จึงใช้ไฟล์จริงกับ RealExecutor ไม่ใช่ของจำลอง — สิ่งที่ต้องพิสูจน์คือ "หลังคำสั่งจบ
 * อะไรอยู่บนดิสก์จริง" ซึ่ง mock ตอบแทนไม่ได้
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\FileMove;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('ย้าย/คัดลอกโฟลเดอร์ทั้งต้นไม้');

/**
 * โฟลเดอร์ทดสอบใหม่ทุกครั้ง + บริบทของผู้ดูแลระดับเซิร์ฟเวอร์
 *
 * ใช้ขอบเขต `server` (ราก `/`) เพราะสิ่งที่ทดสอบคือ "ผลลัพธ์บนดิสก์" ไม่ใช่ด่านขอบเขต
 * ซึ่งมีชุดเทสต์ของตัวเองอยู่แล้วใน FileManagerTest
 *
 * @return array{context:Context,dir:string,relative:string}
 */
function moveFixture(): array
{
    static $context = null;

    $context ??= new Context(
        new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        migratedDb(),
    );

    $dir = sys_get_temp_dir().'/phpcp-move-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir.'/src/inner', 0o750, true);
    mkdir($dir.'/dest', 0o750, true);
    file_put_contents($dir.'/src/top.txt', 'top');
    file_put_contents($dir.'/src/inner/deep.txt', 'deep');

    register_shutdown_function(static fn () => exec('rm -rf '.escapeshellarg($dir)));

    return ['context' => $context, 'dir' => $dir, 'relative' => ltrim($dir, '/')];
}

/**
 * เรียกคำสั่งจริงผ่านทางเดียวกับที่ชั้น HTTP เดิน — validate() ก่อนเสมอ
 *
 * @param list<string> $items
 * @return array<string,mixed>
 */
function runMove(array $fixture, array $items, string $destination, bool $copy, bool $overwrite = false): array
{
    $capability = new FileMove();

    return $capability->run(
        $capability->validate([
            'root' => 'server',
            'items' => $items,
            'destination' => $destination,
            'copy' => $copy ? '1' : '',
            'overwrite' => $overwrite ? '1' : '',
        ]),
        new RealExecutor(),
        $fixture['context'],
    );
}

test('คัดลอกโฟลเดอร์ต้องได้ไฟล์ครบทุกชั้น และต้นฉบับต้องยังอยู่', static function (): void {
    // จุดที่พังบ่อยคือชั้นลึก: คัดลอกได้แค่ไฟล์ชั้นบนแล้วโฟลเดอร์ย่อยว่างเปล่า
    // ซึ่งดูเหมือนสำเร็จทุกอย่างจนกว่าจะมีคนเปิดเข้าไปดู
    $fixture = moveFixture();

    $result = runMove($fixture, [$fixture['relative'].'/src'], $fixture['relative'].'/dest', copy: true);

    assertSame(1, $result['count'], 'ต้องรายงานว่าทำไปหนึ่งรายการ');
    assertSame('deep', (string) @file_get_contents($fixture['dir'].'/dest/src/inner/deep.txt'), 'ไฟล์ในโฟลเดอร์ย่อยต้องมาด้วยครบ');
    assertTrue(is_file($fixture['dir'].'/src/top.txt'), 'คัดลอกแล้วต้นฉบับต้องยังอยู่');
});

test('ย้ายโฟลเดอร์ต้องทำให้ต้นทางหายไปจริง ไม่ใช่เหลือโครงว่าง', static function (): void {
    $fixture = moveFixture();

    runMove($fixture, [$fixture['relative'].'/src'], $fixture['relative'].'/dest', copy: false);

    assertFalse(is_dir($fixture['dir'].'/src'), 'ต้นทางต้องไม่เหลืออยู่');
    assertSame('deep', (string) @file_get_contents($fixture['dir'].'/dest/src/inner/deep.txt'), 'ของทั้งต้นไม้ต้องไปโผล่ที่ปลายทาง');
});

test('ย้ายโฟลเดอร์เข้าไปในตัวเองต้องถูกปฏิเสธ', static function (): void {
    /*
     * เคสนี้เลือกผิดได้ง่ายมากในกล่องเลือกโฟลเดอร์ปลายทาง เพราะโฟลเดอร์ที่กำลังจะย้าย
     * ก็โผล่อยู่ในต้นไม้ให้คลิกด้วย · ถ้าปล่อยผ่าน การคัดลอกจะวนคัดลอกสำเนาของตัวเอง
     * ไปเรื่อย ๆ จนดิสก์เต็ม (rename() ของ kernel ปฏิเสธเอง แต่ copy ไม่มีใครห้าม)
     */
    $fixture = moveFixture();

    assertRejects(
        ValidationError::class,
        static fn () => runMove(
            $fixture,
            [$fixture['relative'].'/src'],
            $fixture['relative'].'/src/inner',
            copy: true,
        ),
        'ย้ายโฟลเดอร์ลงไปในโฟลเดอร์ย่อยของตัวเองต้องไม่ผ่าน',
    );
});

test('ปลายทางที่มีชื่อซ้ำต้องไม่ถูกทับ ถ้าไม่ได้สั่งทับ', static function (): void {
    // ทับโฟลเดอร์คือการลบทั้งต้นไม้ของเดิมทิ้ง — ต้องเป็นสิ่งที่ผู้ใช้สั่งเท่านั้น ไม่ใช่ผลข้างเคียง
    $fixture = moveFixture();
    mkdir($fixture['dir'].'/dest/src', 0o750, true);
    file_put_contents($fixture['dir'].'/dest/src/keep.txt', 'keep');

    assertRejects(
        ValidationError::class,
        static fn () => runMove($fixture, [$fixture['relative'].'/src'], $fixture['relative'].'/dest', copy: true),
        'ชื่อซ้ำที่ปลายทางต้องถูกปฏิเสธ',
    );

    assertSame('keep', (string) @file_get_contents($fixture['dir'].'/dest/src/keep.txt'), 'ของเดิมที่ปลายทางต้องไม่ถูกแตะเลย');
});

test('symlink ที่ชี้ออกนอกโฟลเดอร์ต้องไม่ถูกคัดลอกตามไปด้วย', static function (): void {
    /*
     * ถ้าคัดลอกโดยเดินตาม symlink ผู้ใช้ที่ทำ `ln -s /etc secret` ไว้ในบ้านตัวเอง
     * จะสั่ง "คัดลอกโฟลเดอร์" แล้วได้สำเนาไฟล์ระบบทั้งชุดมาไว้ในที่ที่ตัวเองอ่านได้ —
     * ด่านขอบเขตทั้งหมดถูกข้ามด้วยคำสั่งเดียวที่ดูไม่มีพิษภัย
     */
    $fixture = moveFixture();
    $outside = $fixture['dir'].'/outside';
    mkdir($outside, 0o750, true);
    file_put_contents($outside.'/secret.txt', 'secret');
    symlink($outside, $fixture['dir'].'/src/link');

    runMove($fixture, [$fixture['relative'].'/src'], $fixture['relative'].'/dest', copy: true);

    assertFalse(
        file_exists($fixture['dir'].'/dest/src/link'),
        'ลิงก์ต้องไม่ถูกคัดลอกไปด้วย ไม่ว่าจะเป็นตัวลิงก์เองหรือของที่มันชี้ไป',
    );
    assertTrue(is_file($fixture['dir'].'/dest/src/top.txt'), 'ไฟล์ปกติในโฟลเดอร์เดียวกันต้องยังคัดลอกได้ตามปกติ');
});
