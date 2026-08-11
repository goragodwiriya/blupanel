<?php

declare(strict_types=1);

/**
 * ตัวอัปเดตตัวเอง — เกณฑ์รับงานเฟส 5
 *
 * ตัวอัปเดตของโปรแกรมที่รันด้วยสิทธิ์ root คือช่องทางยึดเครื่องที่ตรงที่สุดที่มี
 * ถ้าโค้ดชุดนี้ผิด ผู้โจมตีได้ root บนทุกเครื่องที่ติดตั้ง panel ไว้พร้อมกัน
 * เทสต์ทุกข้อในไฟล์นี้จึงเป็นการยืนยันว่า "ปฏิเสธ" ไม่ใช่ "ยอมรับ"
 */

use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Updater;

group('Updater — ตัวอัปเดตต้องปฏิเสธทุกอย่างที่พิสูจน์ไม่ได้');

/** @return array{0:Updater,1:string} [updater ที่ฝังกุญแจสาธารณะแล้ว, กุญแจส่วนตัว] */
function updaterPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        new Updater(base64_encode(sodium_crypto_sign_publickey($pair))),
        sodium_crypto_sign_secretkey($pair),
    ];
}

test('build ที่ไม่ได้ฝังกุญแจต้องปิด self-update ทั้งหมด', static function (): void {
    // ค่าเริ่มต้นต้องเป็น "ใช้ไม่ได้" ไม่ใช่ "ข้ามการตรวจ" —
    // การแถมกุญแจตัวอย่างที่ใครก็มีคู่ส่วนตัวคือการเปิดประตูทิ้งไว้
    $updater = new Updater('');

    assertTrue(!$updater->isConfigured(), 'ค่าเริ่มต้นต้องถือว่ายังไม่ได้ตั้งกุญแจ');

    assertRejects(
        ValidationError::class,
        static fn () => $updater->verify('ข้อมูล', base64_encode(str_repeat("\0", 64)), '9.9.9', '0.1.0'),
        'ไม่มีกุญแจต้องปฏิเสธ ไม่ใช่ผ่าน',
    );

    assertSame('', Updater::PUBLIC_KEY, 'ห้ามฝังกุญแจตัวอย่างไว้ในโค้ดที่แจกจ่าย');
});

test('ลายเซ็นที่ถูกต้องต้องผ่าน', static function (): void {
    [$updater, $secret] = updaterPair();
    $archive = 'เนื้อไฟล์แพ็กเกจจำลอง';

    $updater->verify(
        $archive,
        base64_encode(sodium_crypto_sign_detached($archive, $secret)),
        '0.2.0',
        '0.1.0',
    );

    TestRunner::$assertions++;
});

test('แพ็กเกจที่ถูกแก้แม้ไบต์เดียวต้องถูกปฏิเสธ', static function (): void {
    [$updater, $secret] = updaterPair();
    $archive = 'เนื้อไฟล์แพ็กเกจจำลอง';
    $signature = base64_encode(sodium_crypto_sign_detached($archive, $secret));

    assertRejects(
        ValidationError::class,
        static fn () => $updater->verify($archive . ' ', $signature, '0.2.0', '0.1.0'),
        'เนื้อไฟล์ที่ต่างไปต้องทำให้ลายเซ็นไม่ผ่าน',
    );
});

test('ลายเซ็นจากกุญแจอื่นต้องถูกปฏิเสธ', static function (): void {
    // กรณีที่ผู้โจมตีส่งทั้งแพ็กเกจและลายเซ็นของตัวเองมาคู่กัน —
    // กันได้ก็เพราะกุญแจสาธารณะฝังอยู่ในโค้ด ไม่ได้ดาวน์โหลดมาพร้อมแพ็กเกจ
    [$updater] = updaterPair();
    $attacker = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $archive = 'แพ็กเกจของผู้โจมตี';

    assertRejects(
        ValidationError::class,
        static fn () => $updater->verify(
            $archive,
            base64_encode(sodium_crypto_sign_detached($archive, $attacker)),
            '0.2.0',
            '0.1.0',
        ),
        'ลายเซ็นจากกุญแจที่ไม่ใช่ของผู้เผยแพร่ต้องไม่ผ่าน',
    );
});

test('ลายเซ็นที่ผิดรูปแบบต้องถูกปฏิเสธก่อนถึงการตรวจจริง', static function (): void {
    [$updater] = updaterPair();

    foreach (['', 'ไม่ใช่ base64', base64_encode('สั้นเกินไป'), 'AAAA'] as $signature) {
        assertRejects(
            ValidationError::class,
            static fn () => $updater->verify('ข้อมูล', $signature, '0.2.0', '0.1.0'),
            'ลายเซ็นผิดรูปแบบต้องถูกปฏิเสธ: ' . var_export($signature, true),
        );
    }
});

test('ห้ามลดเวอร์ชัน แม้ลายเซ็นจะถูกต้องทุกประการ', static function (): void {
    // แพ็กเกจเก่าที่เคยเซ็นไว้ยังมีลายเซ็นที่ถูกต้องตลอดไป ผู้โจมตีที่ดักการเชื่อมต่อได้
    // จึงส่งเวอร์ชันเก่าที่มีช่องโหว่ซึ่งแก้ไปแล้วกลับมาให้ติดตั้งซ้ำได้
    [$updater, $secret] = updaterPair();
    $archive = 'แพ็กเกจเวอร์ชันเก่าที่มีช่องโหว่';
    $signature = base64_encode(sodium_crypto_sign_detached($archive, $secret));

    assertRejects(
        ValidationError::class,
        static fn () => $updater->verify($archive, $signature, '0.1.0', '0.5.0'),
        'เวอร์ชันที่เก่ากว่าต้องถูกปฏิเสธ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $updater->assertUpgrade('0.5.0', '0.5.0'),
        'เวอร์ชันเดียวกันต้องไม่ถือว่าเป็นการอัปเดต',
    );

    $updater->assertUpgrade('0.5.1', '0.5.0');
    TestRunner::$assertions++;
});

test('หมายเลขเวอร์ชันที่ผิดรูปแบบต้องถูกปฏิเสธ', static function (): void {
    [$updater] = updaterPair();

    foreach (['', 'latest', '1.0', 'v1.0.0', '1.0.0; rm -rf /', '../../etc'] as $version) {
        assertRejects(
            ValidationError::class,
            static fn () => $updater->assertUpgrade($version, '0.1.0'),
            "เวอร์ชัน '{$version}' ต้องถูกปฏิเสธ",
        );
    }
});

test('ที่อยู่ที่ไม่ใช่ https ต้องถูกปฏิเสธก่อนเชื่อมต่อ', static function (): void {
    [$updater] = updaterPair();

    foreach ([
        'http://example.com/x.tar.gz',
        'ftp://example.com/x.tar.gz',
        'file:///etc/passwd',
        '/etc/passwd',
        'HTTPS://example.com/x',   // ตัวพิมพ์ใหญ่ต้องไม่ผ่านการเทียบแบบตรงตัว
    ] as $url) {
        assertRejects(
            ValidationError::class,
            static fn () => $updater->fetch($url),
            "ที่อยู่ '{$url}' ต้องถูกปฏิเสธ",
        );
    }
});

test('manifest ที่ขาดฟิลด์สำคัญต้องถูกปฏิเสธ', static function (): void {
    [$updater] = updaterPair();

    foreach ([
        'ไม่ใช่ json',
        '[]',
        '{"version":"1.0.0"}',
        '{"version":"1.0.0","url":"https://x/y"}',
        '{"version":"","url":"https://x/y","signature":"a"}',
    ] as $json) {
        assertRejects(
            ValidationError::class,
            static fn () => $updater->parseManifest($json),
            'manifest ที่ไม่ครบต้องถูกปฏิเสธ: ' . mb_substr($json, 0, 40),
        );
    }

    $ok = $updater->parseManifest('{"version":"1.0.0","url":"https://x/y","signature":"a","notes":"n"}');
    assertSame('1.0.0', $ok['version'], 'manifest ที่ครบต้องอ่านได้');
});

test('ตัวอัปเดตต้องตรวจลายเซ็นก่อนเขียนไฟล์ลงดิสก์', static function (): void {
    // ลำดับสำคัญ: ถ้าเขียนไฟล์ก่อนแล้วค่อยตรวจ ผู้โจมตีก็วางไฟล์ไว้บนเครื่องได้แล้ว
    // แม้การตรวจจะล้มเหลวในภายหลัง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Cli/Application.php');

    $verifyAt = strpos($source, '$updater->verify(');
    $writeAt = strpos($source, 'file_put_contents($target');

    assertTrue($verifyAt !== false && $writeAt !== false, 'ต้องมีทั้งการตรวจลายเซ็นและการเขียนไฟล์');
    assertTrue($verifyAt < $writeAt, 'ต้องตรวจลายเซ็นก่อนเขียนไฟล์ลงดิสก์');
});

test('ไม่ตาม redirect — กัน https ที่เด้งไป http', static function (): void {
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Updater.php');

    assertTrue(
        str_contains($source, 'CURLOPT_FOLLOWLOCATION => false'),
        'ต้องปิดการตาม redirect ไม่งั้นการบังคับ https จะถูกข้ามด้วย 302 ไป http',
    );
    assertTrue(
        str_contains($source, 'CURLOPT_SSL_VERIFYPEER => true'),
        'ต้องตรวจใบรับรองของเซิร์ฟเวอร์',
    );
});
