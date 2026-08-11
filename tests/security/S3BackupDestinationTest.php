<?php

declare(strict_types=1);

/**
 * ปลายทางสำรอง S3 — การเซ็น AWS Signature Version 4 (PLAN-V2 เฟส E1)
 *
 * **ทำไมเทสต์ชุดนี้ไม่ยิงเครือข่ายจริงเลย:** เครื่องพัฒนาไม่มีบัญชี S3 ให้ทดสอบ
 * (ดูหมายเหตุใน `S3Destination` — ยังไม่เคยพิสูจน์กับ endpoint จริง) เทสต์จึงเรียก
 * `sign()`/`requestHost()`/`canonicalUri()` ผ่าน reflection ตรง ๆ ซึ่งเป็น pure function
 * ล้วน (ไม่แตะ curl หรือดิสก์) ตรวจได้ว่าอัลกอริทึมประกอบค่าถูกลำดับ ถูกรูปแบบตามสเปก
 * ของ AWS และไวต่อการเปลี่ยนอินพุตทุกตัวที่ควรมีผลต่อลายเซ็น — ยังไม่ใช่การยืนยันว่า
 * AWS จะรับลายเซ็นนี้จริง ต้องทดสอบกับ endpoint จริงอย่างน้อยหนึ่งครั้งก่อน production
 */

use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\Backup\S3Destination;

group('S3BackupDestination — เซ็น SigV4 ถูกสเปกและปฏิเสธค่าตั้งที่ไม่ปลอดภัย');

/** เรียกเมธอด private ผ่าน reflection — ใช้เฉพาะฟังก์ชันล้วนที่ไม่แตะเครือข่าย/ดิสก์ */
function callPrivate(object $object, string $method, array $args = []): mixed
{
    // PHP 8.1+ ไม่ต้อง setAccessible(true) ก่อนเรียกเมธอด private ผ่าน reflection แล้ว
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
}

function s3(array $overrides = []): S3Destination
{
    $config = array_merge([
        'bucket' => 'phpcp-test-bucket',
        'region' => 'us-east-1',
        'accessKey' => 'AKIAEXAMPLE',
        'secretKey' => 'secretexample',
        'path' => 'backups',
        'endpoint' => '',
        'pathStyle' => false,
    ], $overrides);

    return new S3Destination(...$config);
}

test('ปฏิเสธค่าตั้งที่ขาดหรือผิดรูปแบบก่อนเรียกเครือข่ายเลย', static function (): void {
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['bucket' => '']), 'ต้องปฏิเสธ bucket ว่าง');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['bucket' => 'AB']), 'ต้องปฏิเสธชื่อ bucket ตัวใหญ่');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['region' => '']), 'ต้องปฏิเสธ region ว่าง');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['accessKey' => '']), 'ต้องปฏิเสธ access key ว่าง');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['secretKey' => '']), 'ต้องปฏิเสธ secret key ว่าง');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['path' => '../etc']), 'ต้องปฏิเสธเส้นทางที่มี ..');
    assertRejects(Phpcp\Agent\ValidationError::class, static fn () => s3(['endpoint' => 'http://insecure.example.com']), 'ต้องปฏิเสธ endpoint ที่ไม่ใช่ https');
});

test('โฮสต์ของคำขอสลับไปมาระหว่าง virtual-hosted กับ path-style ตามค่าตั้ง', static function (): void {
    $virtualHosted = callPrivate(s3(), 'requestHost');
    assertSame('phpcp-test-bucket.s3.us-east-1.amazonaws.com', $virtualHosted, 'ค่าเริ่มต้นต้องเป็น virtual-hosted บน AWS');

    $pathStyle = callPrivate(s3(['pathStyle' => true]), 'requestHost');
    assertSame('s3.us-east-1.amazonaws.com', $pathStyle, 'path-style ต้องไม่มีชื่อ bucket ปนอยู่ในโฮสต์');

    $customEndpoint = callPrivate(s3(['endpoint' => 'https://s3.us-west-000.backblazeb2.com', 'pathStyle' => true]), 'requestHost');
    assertSame('s3.us-west-000.backblazeb2.com', $customEndpoint, 'endpoint ที่ตั้งเองต้องถูกใช้แทนของ AWS');
});

test('canonical URI ใส่ชื่อ bucket เฉพาะตอน path-style และเข้ารหัส key ตามกฎของ AWS', static function (): void {
    // "/" ระหว่างโฟลเดอร์ยังเป็นตัวคั่นพาธตามปกติ — เข้ารหัสเฉพาะอักขระภายใน
    // แต่ละส่วนของ path (เช่นช่องว่าง) ไม่ใช่เข้ารหัส "/" ทั้งเส้นทางเป็นก้อนเดียว
    $virtualHosted = callPrivate(s3(), 'canonicalUri', ['backups/site 1.zip']);
    assertSame('/backups/site%201.zip', $virtualHosted, 'virtual-hosted ต้องไม่มี bucket ใน URI และเข้ารหัสช่องว่างในชื่อไฟล์');

    $pathStyle = callPrivate(s3(['pathStyle' => true]), 'canonicalUri', ['backups/site.zip']);
    assertSame('/phpcp-test-bucket/backups/site.zip', $pathStyle, 'path-style ต้องมีชื่อ bucket นำหน้า key ที่เข้ารหัสแล้ว');
});

test('ลายเซ็นเหมือนเดิมทุกครั้งเมื่ออินพุตเหมือนเดิมทุกตัว', static function (): void {
    $a = callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']);
    $b = callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']);

    assertSame($a['authorization'], $b['authorization'], 'อินพุตเดิมต้องได้ Authorization header เดิมเป๊ะ');
});

test('Authorization header มีรูปแบบตรงตามสเปกของ AWS SigV4', static function (): void {
    $signed = callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']);

    $pattern = '#^AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/20260809/us-east-1/s3/aws4_request, '
        . 'SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[a-f0-9]{64}$#';

    assertTrue(preg_match($pattern, $signed['authorization']) === 1, 'รูปแบบต้องตรงสเปกเป๊ะ: ' . $signed['authorization']);
    assertTrue(str_starts_with($signed['canonicalRequest'], "PUT\n"), 'บรรทัดแรกของ canonical request ต้องเป็น HTTP method');
    assertTrue(str_contains($signed['canonicalRequest'], "host:phpcp-test-bucket.s3.us-east-1.amazonaws.com\n"), 'ต้องมี header host อยู่ใน canonical headers');
    assertTrue(str_starts_with($signed['stringToSign'], "AWS4-HMAC-SHA256\n20260809T120000Z\n20260809/us-east-1/s3/aws4_request\n"), 'string-to-sign ต้องขึ้นต้นด้วย algorithm/date/credential-scope ตามลำดับ');
});

test('เปลี่ยนตัวแปรที่ควรมีผลต่อลายเซ็นแล้วลายเซ็นต้องเปลี่ยนตาม', static function (): void {
    $base = callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']);

    $cases = [
        'secret key ต่างกัน' => callPrivate(s3(['secretKey' => 'a-different-secret']), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'access key ต่างกัน' => callPrivate(s3(['accessKey' => 'AKIADIFFERENT']), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'region ต่างกัน' => callPrivate(s3(['region' => 'ap-southeast-1']), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'bucket ต่างกัน' => callPrivate(s3(['bucket' => 'phpcp-other-bucket']), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'วันเวลาต่างกัน' => callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260810T120000Z']),
        'HTTP method ต่างกัน' => callPrivate(s3(), 'sign', ['DELETE', 'backups/x.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'key ต่างกัน' => callPrivate(s3(), 'sign', ['PUT', 'backups/y.zip', 'UNSIGNED-PAYLOAD', '20260809T120000Z']),
        'payload hash ต่างกัน' => callPrivate(s3(), 'sign', ['PUT', 'backups/x.zip', hash('sha256', 'x'), '20260809T120000Z']),
    ];

    foreach ($cases as $label => $variant) {
        assertTrue($variant['authorization'] !== $base['authorization'], "เปลี่ยน{$label}แล้วลายเซ็นต้องเปลี่ยนตาม แต่ไม่เปลี่ยน");
    }
});

test('DestinationFactory รู้จัก s3 ครบทั้งสามจุด', static function (): void {
    assertTrue(in_array('s3', Phpcp\Domain\BackupDestinationRepository::DRIVERS, true), 'ต้องอยู่ในรายชื่อ driver ที่ระบบรู้จัก');
    assertTrue(DestinationFactory::needsSecret('s3'), 's3 ต้องการความลับ (secret key) เสมอ');

    $required = DestinationFactory::requiredFields()['s3'] ?? [];
    assertSame(['bucket', 'region', 'access_key'], $required, 'ฟิลด์บังคับของ s3 ต้องตรงกับที่ฟอร์มและตัวตรวจค่าใช้');
});

test('DestinationFactory ประกอบ S3Destination จากแถวฐานข้อมูลได้ถูกต้อง', static function (): void {
    // BackupDestinationRepository เป็น final class — ทดสอบผ่านฐานข้อมูลจริงชั่วคราว
    // แทนการ mock ด้วย anonymous class เหมือนที่ BackupOffsiteTest.php ทำกับปลายทางอื่น
    $dbPath = sys_get_temp_dir() . '/phpcp-s3-factory-' . bin2hex(random_bytes(4)) . '.db';
    $db = new Phpcp\Kernel\Db($dbPath);
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    register_shutdown_function(static fn () => @unlink($dbPath));

    $secret = new Phpcp\Security\Secret((string) base64_decode(Phpcp\Security\Secret::generateKey(), true));
    $repository = new Phpcp\Domain\BackupDestinationRepository($db, $secret);

    $id = $repository->create(
        'S3 ทดสอบ',
        's3',
        ['bucket' => 'phpcp-test-bucket', 'region' => 'us-east-1', 'access_key' => 'AKIAEXAMPLE', 'path' => 'backups'],
        'secret-from-db',
        30,
        7,
    );

    $factory = new DestinationFactory($repository);
    $destination = $factory->make($repository->find($id));

    assertTrue($destination instanceof S3Destination, 'ต้องได้ instance ของ S3Destination');
    assertSame('s3', $destination::driver(), 'ชื่อ driver ต้องตรงกับที่เก็บในคอลัมน์ driver');
});
