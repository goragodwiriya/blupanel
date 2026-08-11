<?php

declare(strict_types=1);

/**
 * สัญญาของตัวจัดการไฟล์ — PLAN-V2 เฟส B3.3
 *
 * ทรัพยากรนี้อันตรายที่สุดในเฟส B เพราะเป็นทางเดียวที่ผู้ใช้ส่ง "เส้นทาง" เข้ามาเอง
 * เทสต์ชุดนี้จึงเน้นสองเรื่องมากกว่ารูปร่างของคำตอบ:
 *
 *   1. **ขอบเขตที่เปิดไม่ได้ต้องถูกปฏิเสธที่ชั้นเว็บก่อนถึง agent** — ลูกค้าต้องเปิด
 *      ขอบเขตระดับเครื่อง (`etc`, `server`, `home`) ไม่ได้เลยแม้จะเดาคีย์ถูก
 *   2. **เส้นทางที่พยายามออกนอกขอบเขตต้องถูกปฏิเสธ** — `../`, เส้นทางสัมบูรณ์,
 *      null byte · ชั้นที่กันจริงคือ PathGuard ใน agent แต่สัญญาของ API ต้องบอกว่า
 *      ถูกปฏิเสธด้วยรหัสที่ถูกต้อง ไม่ใช่ 500 หรือ HTML
 *
 * เทสต์รันโดยไม่มี agent — คำสั่งที่ผ่านการตรวจของชั้นเว็บแล้วจะได้ 503 ซึ่งพิสูจน์ว่า
 * "มันไปถึง agent จริง" ส่วนคำสั่งที่ถูกตัดก่อนจะได้ 403 · ความต่างของสองรหัสนี้
 * คือสิ่งที่บอกว่าการตรวจสิทธิ์ทำงานตรงจุด ไม่ใช่บังเอิญล้มเพราะ agent ไม่อยู่
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของตัวจัดการไฟล์');

function filesHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('fileadmin', 'Files-Admin-Pass-11', Permissions::SUPERADMIN);
    $ownerId = $harness->createHostingUser('fileowner', 'Files-Owner-Pass-22', Permissions::WEBADMIN);
    $harness->createUser('filestranger', 'Files-Other-Pass-33', Permissions::WEBADMIN);

    $now = time();
    $harness->app->db()->insert('sites', [
        'name' => 'เว็บของลูกค้า',
        'primary_domain' => 'files.example.com',
        'docroot' => '/srv/phpcp/sites/files.example.com/public',
        'php_version' => '8.4',
        'status' => 'active',
        'owner_user_id' => $ownerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $harness;
}

function filesLogin(string $username, string $password): ApiHarness
{
    $harness = filesHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

test('ผู้ดูแลระบบเห็นขอบเขตระดับเครื่อง ลูกค้าเห็นเฉพาะของเว็บตัวเอง', static function (): void {
    $admin = filesLogin('fileadmin', 'Files-Admin-Pass-11');
    $adminRoots = array_column($admin->request('GET', '/api/v2/files/roots')->json['data'], 'key');

    foreach (['server', 'etc', 'varlog', 'home'] as $serverScope) {
        assertTrue(in_array($serverScope, $adminRoots, true), "ผู้ดูแลระบบต้องเห็นขอบเขต {$serverScope}");
    }

    $owner = filesLogin('fileowner', 'Files-Owner-Pass-22');
    $ownerResponse = $owner->request('GET', '/api/v2/files/roots');
    $ownerRoots = array_column($ownerResponse->json['data'], 'key');

    foreach (['server', 'etc', 'varlog', 'home', 'tmp', 'sites'] as $serverScope) {
        assertTrue(
            !in_array($serverScope, $ownerRoots, true),
            "ลูกค้าต้องไม่เห็นขอบเขตระดับเครื่อง {$serverScope}",
        );
    }

    assertTrue($ownerRoots !== [], 'ลูกค้าต้องเห็นขอบเขตของเว็บตัวเองอย่างน้อยหนึ่งขอบเขต');

    foreach ($ownerResponse->json['data'] as $scope) {
        assertSame('site', $scope['kind'], 'ทุกขอบเขตของลูกค้าต้องเป็นชนิด site');
        // เส้นทางจริงบนเครื่องต้องไม่หลุดออกไป — ฝั่งหน้าเว็บไม่มีอะไรต้องทำกับค่านั้น
        assertTrue(!array_key_exists('root', $scope), 'ต้องไม่ส่งเส้นทางจริงบนเครื่องออกไป');
    }

    assertTrue(
        ($ownerResponse->json['meta']['max_upload_bytes'] ?? 0) > 0,
        'ต้องบอกเพดานขนาดอัปโหลดให้ SPA รู้ล่วงหน้า',
    );
});

test('ลูกค้าเปิดขอบเขตระดับเครื่องไม่ได้แม้จะเดาคีย์ถูก', static function (): void {
    $harness = filesLogin('fileowner', 'Files-Owner-Pass-22');

    // คีย์เหล่านี้มีอยู่จริงในระบบ แค่ไม่ใช่ของบัญชีนี้ — ต้องถูกตัดที่ชั้นเว็บ
    // ก่อนถึง agent ด้วยซ้ำ (ถ้าไปถึง agent จะได้ 503 เพราะ agent ไม่ทำงานในเทสต์)
    foreach (['etc', 'server', 'home', 'varlog'] as $forbidden) {
        $response = $harness->request('GET', '/api/v2/files', query: ['root' => $forbidden, 'path' => '']);

        assertSame(403, $response->status, "ขอบเขต {$forbidden} ต้องถูกปฏิเสธด้วย 403");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
        assertTrue(!$response->looksLikeHtml(), 'ต้องไม่มี HTML ปนออกมา');
    }
});

test('ทุกคำสั่งที่เปลี่ยนแปลงไฟล์ตรวจขอบเขตก่อนเสมอ', static function (): void {
    $harness = filesLogin('fileowner', 'Files-Owner-Pass-22');

    // ครอบทุก endpoint ที่เขียนได้ — ถ้ามีตัวไหนลืมตรวจ จะได้ 503 (ไปถึง agent) แทน 403
    $cases = [
        ['PUT', '/api/v2/files/content', ['root' => 'etc', 'path' => 'passwd', 'content' => 'x']],
        ['POST', '/api/v2/files/upload', ['root' => 'etc', 'name' => 'x', 'content_base64' => 'eA==']],
        ['POST', '/api/v2/files/directories', ['root' => 'etc', 'name' => 'evil']],
        ['POST', '/api/v2/files/move', ['root' => 'etc', 'items' => ['passwd'], 'destination' => '']],
        ['POST', '/api/v2/files/copy', ['root' => 'etc', 'items' => ['passwd'], 'destination' => '']],
        ['DELETE', '/api/v2/files', ['root' => 'etc', 'items' => ['passwd']]],
        ['PUT', '/api/v2/files/permissions', ['root' => 'etc', 'path' => 'passwd', 'mode' => '777']],
        ['POST', '/api/v2/files/archives', ['root' => 'etc', 'items' => ['passwd'], 'archive' => 'x.zip']],
        ['POST', '/api/v2/files/extractions', ['root' => 'etc', 'path' => 'x.zip']],
    ];

    foreach ($cases as [$method, $path, $body]) {
        $response = $harness->request($method, $path, $body);

        assertSame(403, $response->status, "{$method} {$path} ต้องตรวจขอบเขตก่อนส่งต่อให้ agent");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), "{$method} {$path} ต้องเป็น FORBIDDEN");
    }
});

test('เส้นทางที่พยายามออกนอกขอบเขตถูกปฏิเสธด้วยรหัสที่ถูกต้อง', static function (): void {
    $harness = filesLogin('fileadmin', 'Files-Admin-Pass-11');

    // ชั้นที่กันจริงคือ PathGuard ในฝั่ง agent — ที่ตรวจตรงนี้คือ "สัญญา" ว่าคำตอบ
    // ต้องเป็น JSON ที่มีรหัสอ่านได้ ไม่ใช่ 500 หรือหน้า HTML ที่ SPA จัดการต่อไม่ได้
    $payloads = ['../../etc/passwd', '/etc/passwd', '....//....//etc/shadow', "ok\0.txt"];

    foreach ($payloads as $payload) {
        $response = $harness->request('GET', '/api/v2/files', query: ['root' => 'etc', 'path' => $payload]);

        assertTrue($response->isJson(), 'ต้องตอบ JSON เสมอ');
        assertTrue(!$response->looksLikeHtml(), 'ต้องไม่มี HTML ปนออกมา');
        assertTrue(
            in_array($response->status, [422, 503], true),
            "เส้นทาง {$payload} ต้องถูกปฏิเสธ (422) หรือไปถึง agent ที่ไม่ทำงาน (503) แต่ได้ {$response->status}",
        );

        if (($response->json['ok'] ?? true) === false) {
            assertTrue(
                ApiProblem::tryFrom($response->errorCode()) !== null,
                'รหัสข้อผิดพลาดต้องอยู่ใน enum: ' . $response->errorCode(),
            );
        }
    }
});

test('อัปโหลดที่ไม่ส่งไฟล์มาเลยได้ 422 พร้อมบอกวิธีส่งที่ถูกต้อง', static function (): void {
    $harness = filesLogin('fileadmin', 'Files-Admin-Pass-11');

    $response = $harness->request('POST', '/api/v2/files/upload', ['root' => 'tmp', 'path' => '']);

    assertSame(422, $response->status, 'ไม่ส่งไฟล์มาต้องเป็น 422');
    assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertTrue(
        str_contains((string) ($response->json['error']['fields']['files'] ?? ''), 'content_base64'),
        'ต้องบอกทางที่ถูกต้องให้ด้วย ไม่ใช่แค่บอกว่าผิด',
    );
});

test('แฟล็ก boolean จาก JSON ถูกแปลงให้ capability เข้าใจ', static function (): void {
    // capability ชุดไฟล์รับแฟล็กเป็นสตริง ('' = ไม่) เพราะเดิมมาจากฟอร์ม HTML
    // ส่วน SPA ส่ง true/false มาเป็น JSON — การแปลงต้องอยู่ที่ชั้น API ไม่ใช่ไปแก้ capability
    // ซึ่งเป็นชั้นที่แผนกำหนดว่าห้ามแตะ
    $method = new ReflectionMethod(\Phpcp\Http\V2\FilesController::class, 'flag');

    $controller = new \Phpcp\Http\V2\FilesController(
        filesHarness()->app,
        new \Phpcp\Kernel\Ctx(filesHarness()->app),
        \Phpcp\Kernel\Routes::build(),
    );

    foreach ([[true, '1'], ['1', '1'], ['true', '1'], ['on', '1'], [false, ''], ['0', ''], ['', ''], [null, '']] as [$input, $expected]) {
        $request = \Phpcp\Kernel\Request::make(
            'POST',
            '/api/v2/files/upload',
            rawBody: json_encode(['overwrite' => $input]),
            headers: ['Content-Type' => 'application/json'],
        );

        assertSame(
            $expected,
            $method->invoke($controller, $request, 'overwrite'),
            'ค่า ' . var_export($input, true) . ' ต้องถูกแปลงเป็น ' . var_export($expected, true),
        );
    }
});

test('ดาวน์โหลดเป็นข้อยกเว้นเดียวที่ตอบไม่ใช่ JSON — และต้องมี nosniff', static function (): void {
    // ตรวจจากโค้ดโดยตรง เพราะเส้นทางนี้ต้องมี agent จริงถึงจะได้เนื้อไฟล์กลับมา
    // สิ่งที่ต้องรับประกันคือ header ชุดกันไฟล์ .html ของผู้ใช้กลายเป็น stored XSS
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/FilesController.php');

    assertTrue(str_contains($source, "'X-Content-Type-Options', 'nosniff'"), 'ต้องส่ง nosniff เสมอ');
    assertTrue(str_contains($source, 'FileCatalog::downloadType()'), 'ต้องบังคับชนิดเป็น octet-stream');
    assertTrue(str_contains($source, "'Cache-Control', 'private, no-store'"), 'ไฟล์ของผู้ใช้ต้องไม่ถูกแคช');

    // และสเปกต้องระบุข้อยกเว้นนี้ไว้ให้ชัด ไม่ใช่ปล่อยให้คนอ่านสงสัยเอง
    $spec = (string) file_get_contents(PHPCP_ROOT . '/docs/openapi.yaml');
    assertTrue(str_contains($spec, 'application/octet-stream'), 'สเปกต้องระบุว่า download ตอบ octet-stream');
    assertTrue(str_contains($spec, 'ข้อยกเว้นเดียวของกฎ'), 'สเปกต้องอธิบายว่าทำไมจึงยกเว้น');
});

test('เส้นทางไฟล์ทั้งหมดตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = filesLogin('fileadmin', 'Files-Admin-Pass-11');

    $cases = [
        ['GET', '/api/v2/files/roots', [], []],
        ['GET', '/api/v2/files', [], ['root' => 'tmp']],
        ['GET', '/api/v2/files', [], ['root' => 'ไม่มีขอบเขตนี้']],
        ['GET', '/api/v2/files/content', [], ['root' => 'tmp', 'path' => 'x.txt']],
        ['PUT', '/api/v2/files/content', ['root' => 'tmp', 'path' => 'x.txt', 'content' => 'hi'], []],
        ['POST', '/api/v2/files/directories', ['root' => 'tmp', 'name' => 'ใหม่'], []],
        ['DELETE', '/api/v2/files', ['root' => 'tmp', 'items' => ['x.txt']], []],
        ['PUT', '/api/v2/files/permissions', ['root' => 'tmp', 'path' => 'x.txt', 'mode' => '644'], []],
        ['POST', '/api/v2/files/archives', ['root' => 'tmp', 'items' => ['x.txt'], 'archive' => 'a.zip'], []],
        ['POST', '/api/v2/files/extractions', ['root' => 'tmp', 'path' => 'a.zip'], []],
    ];

    foreach ($cases as [$method, $path, $body, $query]) {
        $response = $harness->request($method, $path, $body, query: $query);

        assertTrue($response->isJson(), "{$method} {$path} ต้องตอบ JSON แต่ได้ " . $response->contentType());
        assertTrue(!$response->looksLikeHtml(), "{$method} {$path} มี HTML ปนออกมา");

        if ($response->status !== 204) {
            assertTrue(array_key_exists('ok', $response->json), "{$method} {$path} ต้องมีฟิลด์ ok");
        }

        if (($response->json['ok'] ?? true) === false) {
            assertTrue(
                ApiProblem::tryFrom($response->errorCode()) !== null,
                "{$method} {$path} ใช้รหัสข้อผิดพลาดนอก enum: " . $response->errorCode(),
            );
        }
    }
});

test('endpoint อ่านอย่างเดียวสามตัวใหม่ตรวจขอบเขตก่อนส่งต่อให้ agent', static function (): void {
    // `tree`/`search`/`info` เพิ่มเข้ามาพร้อมตัวจัดการไฟล์เต็มจอ (2026-08-11) —
    // ถ้าตัวไหนลืมตรวจ `mayAccess` จะได้ 503 (ไปถึง agent ที่ไม่ทำงานในเทสต์) แทน 403
    // ซึ่งแปลว่าลูกค้ายิงคำขอเข้าไปอ่านโครงไฟล์ของทั้งเครื่องได้จริงเมื่อ agent ทำงาน
    $harness = filesLogin('fileowner', 'Files-Owner-Pass-22');

    $cases = [
        ['/api/v2/files/tree', ['root' => 'etc', 'path' => '']],
        ['/api/v2/files/search', ['root' => 'etc', 'path' => '', 'q' => 'passwd']],
        ['/api/v2/files/info', ['root' => 'etc', 'path' => 'passwd']],
    ];

    foreach ($cases as [$path, $query]) {
        $response = $harness->request('GET', $path, query: $query);

        assertSame(403, $response->status, "{$path} ต้องตรวจขอบเขตก่อนส่งต่อให้ agent");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), "{$path} ต้องเป็น FORBIDDEN");
        assertTrue(!$response->looksLikeHtml(), "{$path} มี HTML ปนออกมา");
    }
});

test('tree กับ search ใช้ขอบเขตแรกได้เมื่อไม่ระบุมา — เหมือน /files', static function (): void {
    // หน้าตัวจัดการไฟล์วาดแถบโฟลเดอร์และโหลดรายการพร้อมกันตั้งแต่เปิดหน้า ซึ่งเกิดก่อน
    // คำตอบของ `/files/roots` (คนละคำขอ) จะมาถึง — คำขอแรกจึงไม่มี `root` ติดไปด้วย
    // ถ้าเส้นทางเหล่านี้ตอบ 403 เมื่อไม่ระบุ หน้าจะขึ้นพร้อม error ทุกครั้งที่เปิด
    $harness = filesLogin('fileadmin', 'Files-Admin-Pass-11');

    foreach ([['/api/v2/files/tree', []], ['/api/v2/files/search', ['q' => 'etc']]] as [$path, $query]) {
        $response = $harness->request('GET', $path, query: $query);

        assertTrue($response->status !== 403, "{$path} ต้องไม่ถูกปฏิเสธด้วยสิทธิ์เมื่อไม่ระบุขอบเขต");
        assertTrue($response->isJson(), "{$path} ต้องตอบ JSON");
    }

    // ระบุขอบเขตที่ไม่มีสิทธิ์ยังต้องเป็น 403 เหมือนเดิม — "ยังไม่ได้เลือก" กับ
    // "เลือกสิ่งที่แตะไม่ได้" เป็นคนละเรื่องกัน
    $denied = $harness->request('GET', '/api/v2/files/tree', query: ['root' => 'ไม่มีจริง']);

    assertSame(403, $denied->status, 'ขอบเขตที่ไม่รู้จักยังต้องเป็น 403');
});
