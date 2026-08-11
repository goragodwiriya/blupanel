<?php

declare(strict_types=1);

/**
 * ศูนย์ความปลอดภัย — เกณฑ์รับงานเฟส 4
 *
 * คะแนนความปลอดภัยมีค่าก็ต่อเมื่อ "เชื่อถือได้" เท่านั้น ถ้าโกงได้ด้วยการซ่อนปัญหา
 * หรือรายงานเรื่องที่ไม่ใช่ปัญหาว่าเป็นปัญหา คนจะเลิกดูคะแนนภายในสัปดาห์เดียว
 * เทสต์ชุดนี้จึงคุมสองอย่างนั้นเป็นหลัก
 */

use Phpcp\Domain\SecurityScore;
use Phpcp\Domain\ServiceCatalog;

group('ศูนย์ความปลอดภัย — คะแนนต้องเชื่อถือได้');

/** @return array{status:string,weight:int} */
function chk(string $status, int $weight = 10): array
{
    return ['status' => $status, 'weight' => $weight];
}

test('ผ่านทุกข้อได้ 100 ไม่ผ่านทุกข้อได้ 0', static function (): void {
    assertSame(100, SecurityScore::calculate([chk(SecurityScore::PASS), chk(SecurityScore::PASS)]), 'ผ่านหมดต้องได้เต็ม');
    assertSame(0, SecurityScore::calculate([chk(SecurityScore::FAIL), chk(SecurityScore::FAIL)]), 'ไม่ผ่านหมดต้องได้ศูนย์');
    assertSame(0, SecurityScore::calculate([]), 'ไม่มีข้อตรวจเลยต้องได้ศูนย์ ไม่ใช่เต็ม');
});

test('ข้อที่ตรวจไม่ได้ต้องไม่ได้คะแนน ไม่ใช่ถูกข้าม', static function (): void {
    // นี่คือกฎที่สำคัญที่สุดของไฟล์นี้ ถ้าข้ามข้อที่ตรวจไม่ได้
    // เครื่องที่เสียจนตรวจอะไรไม่ได้เลยจะได้ 100 คะแนน ซึ่งตรงข้ามกับความจริง
    assertSame(
        0,
        SecurityScore::calculate([chk(SecurityScore::UNKNOWN), chk(SecurityScore::UNKNOWN)]),
        'ตรวจไม่ได้ทุกข้อต้องได้ศูนย์',
    );

    assertSame(
        50,
        SecurityScore::calculate([chk(SecurityScore::PASS), chk(SecurityScore::UNKNOWN)]),
        'ผ่านครึ่งเดียวต้องได้ครึ่งเดียว ไม่ใช่เต็ม',
    );
});

test('น้ำหนักมีผลจริง — ข้อสำคัญกระทบคะแนนมากกว่า', static function (): void {
    $heavy = SecurityScore::calculate([chk(SecurityScore::FAIL, 20), chk(SecurityScore::PASS, 5)]);
    $light = SecurityScore::calculate([chk(SecurityScore::FAIL, 5), chk(SecurityScore::PASS, 20)]);

    assertTrue($heavy < $light, 'ตกข้อที่หนักกว่าต้องได้คะแนนน้อยกว่า');
    assertSame(20, $heavy, 'ตก 20 ผ่าน 5 ต้องได้ 20 คะแนน');
    assertSame(80, $light, 'ตก 5 ผ่าน 20 ต้องได้ 80 คะแนน');
});

test('ข้อที่ควรปรับปรุงได้ครึ่งคะแนน', static function (): void {
    // ให้ 0 กับ WARN ทำให้ผู้ดูแลที่จัดการเรื่องสำคัญครบแล้วยังเห็นคะแนนต่ำ
    // จนเลิกสนใจคะแนนไปเลย — ซึ่งทำให้ทั้งหน้าไร้ประโยชน์
    assertSame(50, SecurityScore::calculate([chk(SecurityScore::WARN)]), 'WARN ต้องได้ครึ่งหนึ่ง');
});

test('คะแนนต้องปัดลงเสมอ — 100 ต้องแปลว่าไม่เหลืออะไรให้ทำจริง', static function (): void {
    // 24 จาก 25 ส่วน = 96% ต้องเป็น 96 ไม่ใช่ปัดขึ้นเป็นอย่างอื่น
    $score = SecurityScore::calculate([chk(SecurityScore::PASS, 24), chk(SecurityScore::FAIL, 1)]);
    assertSame(96, $score, 'ต้องปัดลง');

    // เกือบเต็มต้องไม่กลายเป็นเต็ม
    $almost = SecurityScore::calculate([chk(SecurityScore::PASS, 999), chk(SecurityScore::FAIL, 1)]);
    assertTrue($almost < 100, 'ยังมีข้อที่ไม่ผ่านอยู่ ต้องไม่ได้ 100');
});

test('ระดับและสีต้องสอดคล้องกับคะแนน', static function (): void {
    assertSame('ดี', SecurityScore::grade(90), '90 ต้องเป็นดี');
    assertSame('ดี', SecurityScore::grade(100), '100 ต้องเป็นดี');
    assertSame('พอใช้', SecurityScore::grade(70), '70 ต้องเป็นพอใช้');
    assertSame('ต้องปรับปรุง', SecurityScore::grade(50), '50 ต้องเป็นต้องปรับปรุง');
    assertSame('เสี่ยง', SecurityScore::grade(0), '0 ต้องเป็นเสี่ยง');

    assertSame('ok', SecurityScore::tone(95), 'คะแนนสูงต้องเป็นสีเขียว');
    assertSame('danger', SecurityScore::tone(30), 'คะแนนต่ำต้องเป็นสีแดง');
});

test('คำแนะนำต้องเรียงตามผลกระทบ ไม่ใช่ตามลำดับที่ตรวจ', static function (): void {
    // คนที่มีเวลาแก้แค่เรื่องเดียวต้องได้แก้เรื่องที่สำคัญที่สุด
    $checks = [
        ['id' => 'a', 'status' => SecurityScore::WARN, 'weight' => 30],
        ['id' => 'b', 'status' => SecurityScore::PASS, 'weight' => 99],
        ['id' => 'c', 'status' => SecurityScore::FAIL, 'weight' => 5],
        ['id' => 'd', 'status' => SecurityScore::UNKNOWN, 'weight' => 20],
        ['id' => 'e', 'status' => SecurityScore::FAIL, 'weight' => 40],
    ];

    $order = array_column(SecurityScore::recommendations($checks), 'id');

    assertSame(['e', 'c', 'd', 'a'], $order, 'ต้องเรียง FAIL (หนักก่อน) → UNKNOWN → WARN และตัดข้อที่ผ่านออก');
});

test('ข้อที่ผ่านแล้วต้องไม่โผล่ในรายการสิ่งที่ต้องทำ', static function (): void {
    $checks = [
        ['id' => 'a', 'status' => SecurityScore::PASS, 'weight' => 10],
        ['id' => 'b', 'status' => SecurityScore::PASS, 'weight' => 10],
    ];

    assertSame([], SecurityScore::recommendations($checks), 'ผ่านหมดต้องไม่มีคำแนะนำเลย');
});

test('รายการ PHP ที่หมดอายุต้องเป็นสับเซ็ตของเวอร์ชันที่ระบบรู้จัก', static function (): void {
    // ถ้าพิมพ์ผิดในรายการ EOL การตรวจจะไม่เจออะไรเลยแบบเงียบ ๆ
    foreach (ServiceCatalog::PHP_EOL_VERSIONS as $version) {
        assertTrue(
            in_array($version, ServiceCatalog::PHP_VERSIONS, true),
            "PHP {$version} อยู่ในรายการหมดอายุแต่ไม่อยู่ในรายการที่ระบบรู้จัก",
        );
    }

    // เวอร์ชันใหม่สุดต้องไม่ถูกจัดว่าหมดอายุ ไม่งั้นทุกเครื่องจะตกข้อนี้ตลอดกาล
    assertTrue(
        !in_array(ServiceCatalog::PHP_VERSIONS[0], ServiceCatalog::PHP_EOL_VERSIONS, true),
        'เวอร์ชันใหม่สุดต้องไม่อยู่ในรายการหมดอายุ',
    );
});

test('การตรวจสิทธิ์ไฟล์ต้องยอมรับค่าที่ตัวติดตั้งตั้งไว้จริง', static function (): void {
    // เคยเป็นบั๊กจริง: ตรวจโดยเทียบกับ 0600 ตรง ๆ ทำให้ config.php ที่ตัวติดตั้ง
    // ตั้งเป็น root:phpcp 0640 โดยเจตนา (web tier อ่านได้แต่เขียนไม่ได้ ซึ่งแข็งแรงกว่า)
    // ถูกรายงานว่าผิด — คะแนนที่รายงานการตั้งค่าที่ถูกต้องว่าผิด จะไม่มีใครเชื่ออีกเลย
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SecurityScan.php');

    assertTrue(
        str_contains($source, '($mode & 0007)') && str_contains($source, '($mode & 0020)'),
        'ต้องตรวจว่าผู้ใช้อื่นเข้าถึงได้และกลุ่มเขียนได้ ไม่ใช่เทียบเลขโหมดตายตัว',
    );

    // ตรวจตรรกะเดียวกันด้วยตัวเลขจริง
    $unsafe = static fn (int $mode): bool => ($mode & 0007) !== 0 || ($mode & 0020) !== 0;

    assertTrue(!$unsafe(0600), '0600 ต้องผ่าน');
    assertTrue(!$unsafe(0640), '0640 (ตัวติดตั้งตั้งไว้) ต้องผ่าน');
    assertTrue($unsafe(0644), '0644 ต้องไม่ผ่าน เพราะผู้ใช้อื่นอ่านได้');
    assertTrue($unsafe(0660), '0660 ต้องไม่ผ่าน เพราะกลุ่มเขียนได้');
    assertTrue($unsafe(0777), '0777 ต้องไม่ผ่าน');
});

test('security.scan ต้องอ่านอย่างเดียว', static function (): void {
    // หน้าที่ผู้ดูแลเปิดดูบ่อยต้องไม่เปลี่ยนสถานะระบบโดยไม่ตั้งใจ
    $registry = new \Phpcp\Agent\CapabilityRegistry();
    $meta = $registry->describe()['security.scan'];

    assertTrue(!$meta['mutating'], 'security.scan ต้องไม่ใช่คำสั่งที่เปลี่ยนแปลงระบบ');
    assertSame('security.view', $meta['permission'], 'ต้องใช้สิทธิ์ระดับดูอย่างเดียว');

    assertTrue(
        !\Phpcp\Security\Permissions::roleHas(\Phpcp\Security\Permissions::WEBADMIN, 'security.view'),
        'ผู้ดูแลเว็บไซต์ต้องไม่เห็นศูนย์ความปลอดภัยของทั้งเครื่อง',
    );
});
