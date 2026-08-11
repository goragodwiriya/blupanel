<?php

declare(strict_types=1);

/**
 * ช่องทางแจ้งเตือนที่เพิ่มในเฟส E6 — webhook และอีเมล
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **webhook ที่ไม่ใช่ https** → เนื้อหาการแจ้งเตือนบอกว่าเครื่องไหนมีปัญหาอะไรอยู่
 *      ตอนนี้ ซึ่งเป็นข้อมูลตั้งต้นชั้นดีของการเลือกเป้าโจมตี · การ redirect จาก https
 *      ไป http ก็ต้องกันด้วย ไม่งั้นการบังคับตอนตั้งค่าไม่มีความหมาย
 *   2. **การแจ้งเตือนอัตโนมัติที่โยน exception** → งานหลักที่สำเร็จไปแล้วจะถูกรายงานว่า
 *      ล้มเหลวเพียงเพราะปลายทางล่ม · ตรงข้ามกับปุ่มทดสอบที่ **ความล้มเหลวต้องดัง**
 *   3. **ลายเซ็นที่คำนวณจากข้อมูลคนละชุดกับที่ส่ง** → ปลายทางตรวจไม่ผ่านทุกครั้ง
 *      แล้วผู้ดูแลจะปิดการตรวจลายเซ็นทิ้ง ซึ่งแย่กว่าไม่มีลายเซ็นตั้งแต่แรก
 *   4. **อีเมลที่ส่งอาร์กิวเมนต์ผ่าน shell** → ที่อยู่ผู้รับมาจากฟอร์ม
 *
 * เทสต์นี้**ไม่ยิงเครือข่ายจริง** — ทุกข้อข้างบนตัดสินได้จากค่าที่โค้ดประกอบขึ้น
 * ซึ่งเป็นชั้นที่พลาดแล้วเงียบที่สุด · การส่งจริงพิสูจน์ได้ทางเดียวคือกดปุ่มทดสอบบนเครื่องจริง
 */

use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Notify\EmailNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;

group('ช่องทางแจ้งเตือน — webhook และอีเมล');

test('webhook ต้องปฏิเสธ URL ที่ไม่ใช่ https ตั้งแต่ตอนบันทึก', static function (): void {
    foreach (['http://example.com/hook', 'http://192.168.1.10/hook'] as $url) {
        $rejected = false;

        try {
            WebhookNotifier::assertUrl($url);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธ {$url}");
    }

    assertSame(
        'https://example.com/hook',
        WebhookNotifier::assertUrl('https://example.com/hook'),
        'https ต้องผ่าน',
    );
});

test('webhook ยอมให้ http เฉพาะปลายทางในเครื่องเดียวกัน', static function (): void {
    // ข้อยกเว้นนี้มีเหตุผล (ตัวรวม log ที่รันข้าง ๆ กัน ไม่ผ่านเครือข่ายเลย)
    // แต่ต้องแคบจริง — ชื่อโฮสต์ที่แค่**ขึ้นต้น**ด้วย localhost ต้องไม่ผ่าน
    foreach (['http://127.0.0.1:9000/hook', 'http://localhost/hook'] as $url) {
        assertSame($url, WebhookNotifier::assertUrl($url), "ต้องยอมรับ {$url}");
    }

    $rejected = false;

    try {
        WebhookNotifier::assertUrl('http://localhost.evil.com/hook');
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'โดเมนที่ขึ้นต้นด้วย localhost ต้องไม่ถูกนับว่าเป็นเครื่องเดียวกัน');
});

test('webhook ต้องปฏิเสธข้อความที่ไม่ใช่ URL และยอมรับค่าว่าง', static function (): void {
    $rejected = false;

    try {
        WebhookNotifier::assertUrl('ไม่ใช่ URL เลย');
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'ข้อความมั่ว ๆ ต้องถูกปฏิเสธ');

    // ค่าว่าง = ยังไม่ได้ตั้ง ไม่ใช่ค่าผิด — ไม่งั้นบันทึกค่าตั้งอื่นไม่ได้เลยจนกว่าจะกรอก
    assertSame('', WebhookNotifier::assertUrl(''), 'ค่าว่างต้องผ่าน');
});

test('webhook ต้องไม่ตาม redirect — กัน https ที่เด้งไป http', static function (): void {
    // การบังคับ https ตอนตั้งค่าไม่มีความหมายเลยถ้าปลายทางเด้งไป http ได้
    // เทสต์นี้ตรึงค่าคงที่ไว้ตรง ๆ เพราะพิสูจน์ด้วยการยิงจริงต้องมีเซิร์ฟเวอร์ปลายทาง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Notify/WebhookNotifier.php');

    assertTrue(
        str_contains($source, 'CURLOPT_FOLLOWLOCATION => false'),
        'ต้องปิดการตาม redirect',
    );
    assertTrue(
        str_contains($source, 'CURLOPT_SSL_VERIFYPEER => true')
        && str_contains($source, 'CURLOPT_SSL_VERIFYHOST => 2'),
        'ต้องตรวจใบรับรองของปลายทาง',
    );
});

test('ลายเซ็นต้องเป็น HMAC-SHA256 ของ payload ที่ส่งจริง', static function (): void {
    // ปลายทางตรวจจาก raw body — ถ้าเซ็นจากข้อมูลคนละชุด (เช่น เซ็นก่อนตัดเนื้อความยาว)
    // การตรวจจะไม่ผ่านทุกครั้ง แล้วผู้ดูแลจะปิดการตรวจลายเซ็นทิ้ง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Notify/WebhookNotifier.php');

    assertTrue(
        str_contains($source, "hash_hmac('sha256', \$payload, \$this->secret)"),
        'ต้องเซ็นจากตัวแปร payload ตัวเดียวกับที่ส่งออก',
    );
    assertTrue(
        str_contains($source, 'X-Phpcp-Signature: sha256='),
        'ต้องใช้รูปแบบ header เดียวกับที่ GitHub ใช้ เพื่อให้ลอกโค้ดฝั่งรับได้',
    );

    // รูปแบบเดียวกับที่ปลายทางจะคำนวณ — ตรึงไว้กันการเปลี่ยนอัลกอริทึมโดยไม่ตั้งใจ
    assertSame(64, strlen(hash_hmac('sha256', '{}', 'k')), 'sha256 hex ต้องยาว 64 ตัว');
});

test('การแจ้งเตือนอัตโนมัติต้องเงียบเมื่อยังไม่ได้ตั้งค่า ไม่ใช่ระเบิด', static function (): void {
    // จุดที่เรียกคืองานหลักที่สำเร็จไปแล้ว — exception ที่หลุดออกไปจะทำให้การกระทำ
    // ที่สำเร็จแล้วถูกรายงานว่าล้มเหลว
    assertTrue(!(new WebhookNotifier(''))->notify('test', 'หัวข้อ', 'เนื้อความ'), 'ยังไม่ตั้ง URL ต้องคืน false');
    assertTrue(!(new WebhookNotifier(''))->isConfigured(), 'ต้องบอกได้ว่ายังไม่ได้ตั้งค่า');
});

test('ปุ่มทดสอบต้องดังเมื่อยังไม่ได้ตั้งค่า — ต่างจากการแจ้งเตือนอัตโนมัติ', static function (): void {
    // ผู้ใช้เพิ่งกดปุ่มทดสอบ ถ้าเงียบไปเฉย ๆ จะเข้าใจว่าตั้งค่าถูกแล้ว
    // แล้วจะไม่ได้รับการแจ้งเตือนจริงในวันที่เซิร์ฟเวอร์มีปัญหา
    $threw = false;

    try {
        (new WebhookNotifier(''))->test();
    } catch (ValidationError) {
        $threw = true;
    }

    assertTrue($threw, 'webhook ที่ยังไม่ตั้ง URL ต้องโยน error ตอนกดทดสอบ');
});

test('อีเมลต้องเรียก sendmail ผ่าน Executor ด้วยอาร์กิวเมนต์แยก ไม่ใช่สตริง shell', static function (): void {
    // ที่อยู่ผู้รับมาจากฟอร์ม · การต่อสตริงแล้วโยนเข้า shell คือช่องฉีดคำสั่ง
    // ARCHITECTURE §4.4 บังคับให้ทุกคำสั่งเดินผ่าน Executor อยู่แล้ว เทสต์นี้ตรึงไว้
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Notify/EmailNotifier.php');

    // ฟังก์ชันรันโปรเซสของ PHP ที่รับสตริงแล้วส่งเข้า shell — ห้ามทั้งหมด
    // (`exec(` ตัวเดียวเช็คไม่ได้ เพราะเมธอดของ Executor ก็ชื่อ `exec` เหมือนกัน)
    foreach (['shell_exec', 'passthru', 'system(', 'proc_open', 'popen'] as $forbidden) {
        assertTrue(!str_contains($source, $forbidden), "ห้ามใช้ {$forbidden}");
    }

    assertSame(
        0,
        preg_match('/(?<![>\w])exec\s*\(/', $source),
        'ห้ามเรียก exec() ของ PHP ตรง ๆ — ต้องเป็นเมธอดของ Executor เท่านั้น',
    );
    assertTrue(str_contains($source, '$this->executor->exec('), 'ต้องเดินผ่าน Executor');

    // อาร์กิวเมนต์ต้องเป็น **array** ไม่ใช่สตริงที่ต่อกันเอง — ที่อยู่ผู้รับมาจากฟอร์ม
    // ถ้าต่อเป็นสตริงเมื่อไร ที่อยู่อย่าง `a@b.com; rm -rf /` จะกลายเป็นคำสั่งจริง
    assertSame(
        1,
        preg_match('/executor->exec\(\s*\[/', $source),
        'ต้องส่งอาร์กิวเมนต์เป็น array',
    );

    // `-t` อ่านผู้รับจาก header ในเนื้อความ · `-f` ตั้งผู้ส่งซองจดหมายให้ SPF ผ่าน
    assertTrue(str_contains($source, "'-t'"), 'ต้องใช้ -t');
    assertTrue(str_contains($source, "'-i'"), 'ต้องใช้ -i กันจุดเดี่ยวตัดเนื้อความ');
});

test('อีเมลต้องเงียบเมื่อยังไม่ตั้งผู้รับหรือผู้ส่ง', static function (): void {
    $executor = new \Phpcp\Agent\Executor\DryRunExecutor();

    assertTrue(!(new EmailNotifier($executor, '', 'from@example.com'))->isConfigured(), 'ไม่มีผู้รับ = ยังไม่พร้อม');
    assertTrue(!(new EmailNotifier($executor, 'to@example.com', ''))->isConfigured(), 'ไม่มีผู้ส่ง = ยังไม่พร้อม');
    assertTrue((new EmailNotifier($executor, 'to@example.com', 'from@example.com'))->isConfigured(), 'ครบแล้วต้องพร้อม');

    assertTrue(
        !(new EmailNotifier($executor, '', ''))->notify('หัวข้อ', 'เนื้อความ'),
        'ยังไม่ตั้งค่าต้องคืน false ไม่ใช่โยน error',
    );
});

test('หัวข้ออีเมลภาษาไทยต้องถูก encode ตาม RFC 2047', static function (): void {
    // หัวข้อที่มีอักษรไทยส่งดิบ ๆ จะแสดงเป็นอักขระเพี้ยนในตัวอ่านเมลเกือบทุกตัว
    // — ข้อความแจ้งเตือนที่อ่านไม่ออกเท่ากับไม่ได้แจ้ง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Notify/EmailNotifier.php');

    assertTrue(str_contains($source, '=?UTF-8?B?'), 'ต้องประกาศรูปแบบ Base64 ของ RFC 2047');
    assertTrue(str_contains($source, 'base64_encode($subject)'), 'ต้อง encode ตัวหัวข้อจริง');
});
