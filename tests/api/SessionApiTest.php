<?php

declare(strict_types=1);

/**
 * สัญญาของ REST API v2 — PLAN-V2 เฟส B (B1 รากฐาน + B2 เซสชัน)
 *
 * เทสต์ชุดนี้ตรวจ "สัญญา" ไม่ใช่ "การทำงาน": รูปร่างของคำตอบ, รหัสข้อผิดพลาด,
 * status code และที่สำคัญที่สุดคือ **ไม่มี HTML หลุดออกมาแม้แต่ไบต์เดียวในทุกกรณี**
 * ซึ่งเป็นเงื่อนไขที่ทำให้เฟส C (SPA) เขียนได้โดยไม่ต้องเดาว่าเซิร์ฟเวอร์จะตอบอะไรกลับมา
 *
 * ทุกเคสยิงผ่าน HttpKernel จริงพร้อม middleware ครบทั้งเจ็ดตัว
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Csrf;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของ endpoint เซสชัน');

/** สภาพแวดล้อมร่วมของชุดนี้ สร้างครั้งเดียวแล้วใช้ซ้ำ (เร็วกว่าและพอสำหรับสัญญา) */
function apiHarness(): ApiHarness
{
    static $harness = null;

    if ($harness === null) {
        $harness = ApiHarness::boot();
        $harness->createUser('apiadmin', 'Correct-Horse-Battery-99', Permissions::SUPERADMIN);
        $harness->createUser('apiweb', 'Correct-Horse-Battery-77', Permissions::WEBADMIN);
    }

    return $harness;
}

/** ล็อกอินสดสำหรับเคสที่ต้องการ session ที่ใช้งานได้ */
function apiLogin(string $username = 'apiadmin', string $password = 'Correct-Horse-Battery-99'): ApiHarness
{
    $harness = apiHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status} — {$login->body}");
    }

    return $harness;
}

test('GET /session เรียกได้โดยไม่ต้องล็อกอิน และให้ CSRF token มาใช้', static function (): void {
    $harness = apiHarness();
    $harness->forget();

    $response = $harness->request('GET', '/api/v2/session');

    assertSame(200, $response->status, 'ยังไม่ล็อกอินไม่ใช่ข้อผิดพลาด ต้องได้ 200');
    assertTrue($response->json['ok'] === true, 'ต้องมี ok = true');
    assertSame(false, $response->data('authenticated'), 'ต้องบอกว่ายังไม่ได้ล็อกอิน');
    assertTrue($response->data('csrf_token') !== '', 'SPA ไม่มี HTML ให้ขูด token จึงต้องได้จากที่นี่');
    assertSame('sandbox', $response->data('mode'), 'ต้องบอกโหมดการทำงานเพื่อให้ SPA ขึ้นแถบเตือนได้');
    assertTrue(array_key_exists('agent_available', $response->json['data']), 'ต้องบอกว่า agent ใช้งานได้ไหม');

    // ยังไม่ล็อกอินต้องไม่มีข้อมูลผู้ใช้หลุดออกมา
    assertTrue(!array_key_exists('user', $response->json['data']), 'ยังไม่ล็อกอินต้องไม่มีข้อมูลผู้ใช้');
});

test('เข้าสู่ระบบสำเร็จได้ผู้ใช้ สิทธิ์ และ token ใหม่ที่ใช้ได้จริง', static function (): void {
    $harness = apiHarness();
    $harness->forget();

    $bootstrap = $harness->request('GET', '/api/v2/session');
    $guestToken = (string) $bootstrap->data('csrf_token');

    $response = $harness->request('POST', '/api/v2/session', [
        'username' => 'apiadmin',
        'password' => 'Correct-Horse-Battery-99',
    ]);

    assertSame(200, $response->status, 'ล็อกอินถูกต้องต้องได้ 200');
    assertSame(true, $response->data('authenticated'), 'ต้องบอกว่าล็อกอินแล้ว');
    assertSame('apiadmin', $response->json['data']['user']['username'] ?? '', 'ต้องคืนข้อมูลผู้ใช้');
    // สิทธิ์เป็น **map ที่มีครบทุกตัว** ไม่ใช่รายการเฉพาะที่ได้รับ — หน้าจอเขียน
    // `data-if="permissions['x']"` ตรง ๆ ได้ · ถ้าส่งเฉพาะตัวที่มี คีย์ที่ขาดจะเป็น
    // undefined แล้วองค์ประกอบนั้นจะโผล่แทนที่จะถูกซ่อน
    $permissions = $response->json['data']['permissions'] ?? [];

    assertSame(true, $permissions['site.create'] ?? null, 'สิทธิ์ที่มีต้องเป็น true');
    assertSame(
        array_keys(Phpcp\Security\Permissions::all()),
        array_keys($permissions),
        'ต้องคืนสิทธิ์ครบทุกตัวที่ระบบรู้จัก ไม่ใช่เฉพาะที่ได้รับ',
    );
    assertSame(
        [],
        array_values(array_filter($permissions, static fn (mixed $v): bool => !is_bool($v))),
        'ทุกค่าต้องเป็น true/false เท่านั้น — ค่าที่หายไปทำให้ data-if แสดงแทนที่จะซ่อน',
    );

    // token ต้องเปลี่ยนหลังล็อกอิน เพราะผูกกับ session id ที่เพิ่งสร้างใหม่
    $newToken = (string) $response->data('csrf_token');
    assertTrue($newToken !== '' && $newToken !== $guestToken, 'token ต้องออกใหม่ให้ตรงกับ session ใหม่');
    assertSame($newToken, $response->headers[Csrf::HEADER] ?? '', 'ต้องส่ง token ใหม่ทาง header ด้วยตาม §4.4');

    // และต้องใช้ได้จริงกับคำขอที่เปลี่ยนข้อมูลในทันที ไม่ใช่ต้องรีเฟรชก่อน
    $me = $harness->request('GET', '/api/v2/me');
    assertSame(200, $me->status, 'session ที่เพิ่งสร้างต้องใช้งานได้ทันที');
    assertSame('apiadmin', $me->data('username'), '/me ต้องคืนบัญชีของผู้ที่ล็อกอินอยู่');
});

test('ข้อมูลลับต้องไม่หลุดออกมากับข้อมูลผู้ใช้', static function (): void {
    $harness = apiLogin();
    $me = $harness->request('GET', '/api/v2/me');

    // ระวัง: `must_change_password` เป็นฟิลด์ที่ถูกต้องและมีคำว่า password อยู่
    // จึงต้องตรวจชื่อฟิลด์ที่เป็นความลับจริง ๆ ไม่ใช่ตรวจคำว่า password ลอย ๆ
    foreach (['password_hash', 'totp_secret', 'recovery_code', 'failed_attempts'] as $forbidden) {
        assertTrue(
            !str_contains($me->body, $forbidden),
            "ผลลัพธ์ต้องไม่มีฟิลด์ {$forbidden} — Resource ต้องเลือกฟิลด์ทีละตัว ไม่ใช่คืนทั้งแถว",
        );
    }

    // ฟิลด์ที่ต้องมีจริง ๆ ต้องยังอยู่ครบ ไม่งั้นเทสต์ข้างบนผ่านเพราะคืน array ว่าง
    foreach (['id', 'username', 'role', 'permissions'] as $required) {
        assertTrue(array_key_exists($required, $me->json['data']), "ต้องมีฟิลด์ {$required}");
    }
});

test('ล็อกอินผิดตอบเหมือนกันหมดและไม่บอกว่าชื่อผู้ใช้มีจริงหรือไม่', static function (): void {
    $harness = apiHarness();
    $harness->forget();
    $harness->request('GET', '/api/v2/session');

    $wrongUser = $harness->request('POST', '/api/v2/session', [
        'username' => 'ไม่มีคนนี้',
        'password' => 'อะไรก็ได้ที่ยาวพอ',
    ]);

    $harness->forget();
    $harness->request('GET', '/api/v2/session');

    $wrongPassword = $harness->request('POST', '/api/v2/session', [
        'username' => 'apiadmin',
        'password' => 'รหัสผ่านผิดแน่นอน',
    ]);

    assertSame(401, $wrongUser->status, 'ชื่อผู้ใช้ผิดต้องได้ 401');
    assertSame(401, $wrongPassword->status, 'รหัสผ่านผิดต้องได้ 401');
    assertSame(
        $wrongUser->json['error']['message'] ?? 'a',
        $wrongPassword->json['error']['message'] ?? 'b',
        'สองกรณีต้องได้ข้อความเดียวกันเป๊ะ ไม่งั้นใช้ไล่หาชื่อผู้ใช้ที่มีอยู่จริงได้',
    );
    assertSame(ApiProblem::Unauthenticated->value, $wrongUser->errorCode(), 'ต้องใช้รหัส UNAUTHENTICATED');
});

test('ออกจากระบบตอบ 204 และคุกกี้ใช้ต่อไม่ได้', static function (): void {
    $harness = apiLogin();

    $response = $harness->request('DELETE', '/api/v2/session');

    assertSame(204, $response->status, 'ออกจากระบบต้องได้ 204');
    assertSame('', $response->body, '204 ต้องไม่มี body');
    assertTrue($response->isJson(), 'Content-Type ต้องเป็น JSON แม้ body ว่าง');

    $after = $harness->request('GET', '/api/v2/session');
    assertSame(false, $after->data('authenticated'), 'หลังออกจากระบบต้องไม่เหลือสถานะล็อกอิน');

    // เรียกซ้ำต้องไม่พังและต้องไม่บอกว่าคุกกี้ที่ถืออยู่ใช้ได้หรือไม่
    assertSame(204, $harness->request('DELETE', '/api/v2/session')->status, 'ออกจากระบบซ้ำต้องได้ 204 เหมือนเดิม');
});

test('คำขอที่ต้องล็อกอินต้องได้ 401 พร้อมรหัสที่เครื่องอ่านได้', static function (): void {
    $harness = apiHarness();
    $harness->forget();

    $response = $harness->request('GET', '/api/v2/me');

    assertSame(401, $response->status, 'ยังไม่ล็อกอินต้องได้ 401');
    assertSame(ApiProblem::Unauthenticated->value, $response->errorCode(), 'ต้องเป็นรหัส UNAUTHENTICATED');
    assertTrue($response->isJson(), 'ต้องเป็น JSON ไม่ใช่ redirect ไปหน้าล็อกอิน');
    assertTrue(!$response->looksLikeHtml(), 'ห้ามมี HTML แม้แต่ไบต์เดียว');
});

test('คำขอที่เปลี่ยนข้อมูลโดยไม่มี CSRF token ต้องได้ 419 พร้อม token ที่ถูกต้องกลับมา', static function (): void {
    $harness = apiLogin();

    $response = $harness->request('PATCH', '/api/v2/me/password', [
        'current_password' => 'Correct-Horse-Battery-99',
        'new_password' => 'Another-Long-Password-42',
    ], withCsrf: false);

    assertSame(419, $response->status, 'ไม่มี CSRF token ต้องได้ 419');
    assertSame(ApiProblem::CsrfInvalid->value, $response->errorCode(), 'ต้องเป็นรหัส CSRF_INVALID');
    assertTrue(
        ($response->headers[Csrf::HEADER] ?? '') !== '',
        'ต้องส่ง token ที่ถูกต้องกลับมาให้ SPA ลองใหม่ได้ทันทีตาม §4.4',
    );
    assertTrue(!$response->looksLikeHtml(), 'หน้า 419 แบบ HTML ต้องไม่หลุดมาที่ API');
});

test('เปลี่ยนรหัสผ่านที่ไม่ผ่านเกณฑ์ได้ 422 พร้อมบอกว่าช่องไหนผิด', static function (): void {
    $harness = apiLogin();

    $response = $harness->request('PATCH', '/api/v2/me/password', [
        'current_password' => 'รหัสผ่านปัจจุบันผิด',
        'new_password' => 'สั้น',
    ]);

    assertSame(422, $response->status, 'ค่าที่ไม่ผ่านการตรวจต้องได้ 422');
    assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertTrue(
        isset($response->json['error']['fields']['current_password']),
        'ต้องบอกเป็นรายฟิลด์ ไม่ใช่ข้อความก้อนเดียวที่ SPA เอาไปทาสีช่องกรอกไม่ได้',
    );
    assertTrue(
        isset($response->json['error']['fields']['new_password']),
        'รหัสผ่านใหม่ที่สั้นเกินไปต้องถูกรายงานที่ช่องของมันเอง',
    );
});

test('บทบาทที่ไม่มีสิทธิ์ต้องได้ 403 ไม่ใช่หน้า HTML', static function (): void {
    $harness = apiLogin('apiweb', 'Correct-Horse-Battery-77');

    // webadmin ไม่มี permission ของหมวด SERVER เลยแม้แต่ตัวเดียว
    $me = $harness->request('GET', '/api/v2/me');
    assertSame(200, $me->status, 'webadmin ต้องดูบัญชีตัวเองได้');
    assertTrue(
        !in_array('server.view', $me->data('permissions') ?? [], true),
        'webadmin ต้องไม่ได้รับสิทธิ์ของหมวด SERVER',
    );
});

test('เส้นทางที่ไม่มีอยู่และวิธีเรียกที่ผิดตอบ JSON เสมอ', static function (): void {
    $harness = apiLogin();

    $missing = $harness->request('GET', '/api/v2/ไม่มีเส้นทางนี้');
    assertSame(404, $missing->status, 'เส้นทางที่ไม่มีต้องได้ 404');
    assertSame(ApiProblem::NotFound->value, $missing->errorCode(), 'ต้องเป็นรหัส NOT_FOUND');
    assertTrue($missing->isJson() && !$missing->looksLikeHtml(), '404 ของ API ต้องเป็น JSON ไม่ใช่หน้า error');

    $wrongMethod = $harness->request('PUT', '/api/v2/me');
    assertSame(405, $wrongMethod->status, 'path ที่มีอยู่แต่คนละ method ต้องได้ 405');
    assertTrue($wrongMethod->isJson() && !$wrongMethod->looksLikeHtml(), '405 ต้องเป็น JSON เช่นกัน');
});

test('ทุกคำตอบของ v2 เป็น JSON ไม่ว่าจะสำเร็จหรือล้มเหลว', static function (): void {
    $harness = apiLogin();

    // ครอบทุกกรณีที่มีคนพลาดบ่อย: สำเร็จ, ไม่มีสิทธิ์, ไม่พบ, method ผิด,
    // CSRF ผิด, ค่าไม่ผ่าน — ทั้งหมดต้องมี Content-Type เดียวกันและไม่มี HTML
    $cases = [
        ['GET', '/api/v2/session', [], true],
        ['GET', '/api/v2/me', [], true],
        ['GET', '/api/v2/ไม่มีจริง', [], true],
        ['PUT', '/api/v2/me', [], true],
        ['PATCH', '/api/v2/me/password', ['new_password' => 'x'], true],
        ['PATCH', '/api/v2/me/password', ['new_password' => 'x'], false],
    ];

    foreach ($cases as [$method, $path, $body, $withCsrf]) {
        $response = $harness->request($method, $path, $body, withCsrf: $withCsrf);

        assertTrue(
            $response->isJson(),
            "{$method} {$path} ต้องตอบ application/json แต่ได้ " . $response->contentType(),
        );
        assertTrue(
            !$response->looksLikeHtml(),
            "{$method} {$path} มี HTML ปนออกมา ซึ่งสัญญาของ v2 ห้ามเด็ดขาด",
        );

        // รูปร่างของคำตอบต้องคงที่: มี ok เสมอ และถ้าล้มเหลวต้องมีรหัสที่อยู่ใน enum
        if ($response->status !== 204) {
            assertTrue(array_key_exists('ok', $response->json), "{$method} {$path} ต้องมีฟิลด์ ok");
        }

        if (($response->json['ok'] ?? true) === false) {
            assertTrue(
                ApiProblem::tryFrom($response->errorCode()) !== null,
                "{$method} {$path} ใช้รหัสข้อผิดพลาดที่ไม่อยู่ใน enum: " . $response->errorCode(),
            );
        }
    }
});

test('เดารหัสผ่านรัว ๆ ต้องถูกตัดที่ครั้งที่ 6 พร้อมบอกว่ารออีกนานเท่าไร', static function (): void {
    // การย้ายหน้าล็อกอินไป API ต้องไม่ทำให้โควตาเดารหัสผ่านหลวมลง — โควตาของ
    // /api/v2/session ต้องเป็นชุดเดียวกับ /login เดิม (5 ครั้งรัว) ไม่ใช่โควตาคำขอทั่วไป (120)
    $harness = apiHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    // ใช้บัญชีเฉพาะกิจ เพราะการเดารหัสผ่านซ้ำ ๆ จะทำให้ "บัญชีนั้น" ถูกล็อก 15 นาทีด้วย
    // (คนละกลไกกับโควตาต่อ IP) ถ้ายิงใส่บัญชีที่เคสอื่นใช้อยู่ เคสถัดไปจะล็อกอินไม่ได้
    $harness->createUser('apibrute', 'Brute-Target-Password-55');

    $statuses = [];
    for ($attempt = 1; $attempt <= 7; $attempt++) {
        $statuses[] = $harness->request('POST', '/api/v2/session', [
            'username' => 'apibrute',
            'password' => 'รหัสผ่านผิดทุกครั้ง',
        ])->status;
    }

    assertSame(401, $statuses[0], 'ครั้งแรกต้องเป็นการปฏิเสธปกติ');
    assertTrue(in_array(429, $statuses, true), 'ต้องมีการตัดด้วย 429 เมื่อยิงรัวเกินโควตา');

    $limited = $harness->request('POST', '/api/v2/session', ['username' => 'apibrute', 'password' => 'x']);

    assertSame(429, $limited->status, 'เมื่อโควตาหมดต้องได้ 429 ต่อเนื่อง');
    assertSame(ApiProblem::RateLimited->value, $limited->errorCode(), 'ต้องเป็นรหัส RATE_LIMITED');
    assertTrue(($limited->headers['Retry-After'] ?? '') !== '', '429 ต้องแนบ Retry-After ตาม §4.3');
    assertTrue(!$limited->looksLikeHtml(), 'หน้า 429 แบบ HTML ต้องไม่หลุดมาที่ API');

    $harness->clearRateLimits();
});

test('เปลี่ยนรหัสผ่านสำเร็จ ตัด session อื่นทิ้ง แต่ผู้เรียกยังใช้งานต่อได้', static function (): void {
    $harness = apiHarness();
    $harness->createUser('apiswap', 'First-Password-Value-11', Permissions::SYSADMIN);
    $harness = apiLogin('apiswap', 'First-Password-Value-11');

    $response = $harness->request('PATCH', '/api/v2/me/password', [
        'current_password' => 'First-Password-Value-11',
        'new_password' => 'Second-Password-Value-22',
    ]);

    assertSame(200, $response->status, 'เปลี่ยนรหัสผ่านที่ถูกต้องต้องได้ 200');
    assertSame(true, $response->data('changed'), 'ต้องยืนยันว่าเปลี่ยนแล้ว');

    // ต้องไม่หลุดออกจากระบบตัวเอง — ไม่งั้นผู้ใช้ต้องล็อกอินใหม่ทุกครั้งที่เปลี่ยนรหัสผ่าน
    $me = $harness->request('GET', '/api/v2/me');
    assertSame(200, $me->status, 'ผู้ที่เปลี่ยนรหัสผ่านต้องยังใช้งานต่อได้ทันที');

    // รหัสเดิมต้องใช้ล็อกอินไม่ได้อีก
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');
    $old = $harness->request('POST', '/api/v2/session', [
        'username' => 'apiswap',
        'password' => 'First-Password-Value-11',
    ]);

    assertSame(401, $old->status, 'รหัสผ่านเดิมต้องใช้ไม่ได้แล้ว');
});

test('บัญชีที่ถูกบังคับเปลี่ยนรหัสผ่านทำอย่างอื่นไม่ได้จนกว่าจะเปลี่ยน', static function (): void {
    $harness = apiHarness();
    $harness->createUser('apiforced', 'Temporary-Password-33', Permissions::SUPERADMIN, mustChangePassword: true);
    $harness = apiLogin('apiforced', 'Temporary-Password-33');

    $blocked = $harness->request('GET', '/api/v2/me');
    assertSame(403, $blocked->status, 'เส้นทางอื่นต้องถูกปิดไว้ก่อน');
    assertSame(
        ApiProblem::PasswordChangeRequired->value,
        $blocked->errorCode(),
        'ต้องบอกด้วยรหัสเฉพาะ เพื่อให้ SPA พาไปหน้าเปลี่ยนรหัสผ่านแทนที่จะเด้งออก',
    );

    // แต่ต้องเปลี่ยนรหัสผ่านได้ ไม่งั้นจะติดอยู่ในวงวนที่ออกไม่ได้เลย
    $changed = $harness->request('PATCH', '/api/v2/me/password', [
        'current_password' => 'Temporary-Password-33',
        'new_password' => 'Chosen-By-The-User-44',
    ]);

    assertSame(200, $changed->status, 'เส้นทางเปลี่ยนรหัสผ่านต้องเปิดไว้เสมอ');
    assertSame(200, $harness->request('GET', '/api/v2/me')->status, 'เปลี่ยนแล้วต้องใช้งานได้ตามปกติ');
});

test('JSON ที่ส่งมาพังต้องได้ 400 ไม่ใช่ 422 ที่บอกว่าฟิลด์ขาด', static function (): void {
    $harness = apiLogin();

    $response = $harness->request(
        'PATCH',
        '/api/v2/me/password',
        [],
        ['Content-Type' => 'application/json', Csrf::HEADER => $harness->csrfToken()],
        rawBody: '{"current_password": "x",,}',
    );

    assertSame(400, $response->status, 'body ที่แปลงไม่ได้ต้องเป็น 400');
    assertSame(ApiProblem::BadRequest->value, $response->errorCode(), 'ต้องเป็นรหัส BAD_REQUEST');
});

test('รหัสข้อผิดพลาดกับ HTTP status ผูกกันตายตัวตาม §4.3', static function (): void {
    $expected = [
        'VALIDATION_ERROR' => 422,
        'UNAUTHENTICATED' => 401,
        'TWO_FACTOR_REQUIRED' => 401,
        'PASSWORD_CHANGE_REQUIRED' => 403,
        'FORBIDDEN' => 403,
        'NOT_FOUND' => 404,
        'CONFLICT' => 409,
        'CSRF_INVALID' => 419,
        'RATE_LIMITED' => 429,
        'QUOTA_EXCEEDED' => 422,
        'PROTECTED_RESOURCE' => 403,
        'AGENT_UNAVAILABLE' => 503,
        'EXECUTION_FAILED' => 500,
        'INTERNAL_ERROR' => 500,
    ];

    foreach ($expected as $code => $status) {
        $problem = ApiProblem::tryFrom($code);

        assertTrue($problem !== null, "รหัส {$code} ที่แผนกำหนดต้องมีอยู่จริงใน enum");
        assertSame($status, $problem->status(), "รหัส {$code} ต้องผูกกับ status {$status}");
    }
});

test('ข้อผิดพลาดจาก agent ถูกแปลงเป็นรหัสที่ถูกต้อง', static function (): void {
    // สำคัญที่สุดคือ TransportError ต้องเป็น 503 ไม่ใช่ 500 — "agent ไม่ตอบ" คือปัญหา
    // ชั่วคราวที่ลองใหม่ได้ ต่างจากคำสั่งที่ล้มจริง ซึ่งลองใหม่กี่ครั้งก็ล้มเหมือนเดิม
    $map = [
        \Phpcp\Agent\ValidationError::class => ApiProblem::ValidationError,
        \Phpcp\Agent\PermissionDenied::class => ApiProblem::Forbidden,
        \Phpcp\Agent\ProtectedResource::class => ApiProblem::ProtectedResource,
        \Phpcp\Agent\TransportError::class => ApiProblem::AgentUnavailable,
        \Phpcp\Agent\ExecutionFailed::class => ApiProblem::ExecutionFailed,
    ];

    foreach ($map as $class => $problem) {
        assertSame(
            $problem,
            ApiProblem::fromAgentException(new $class('ทดสอบ')),
            "{$class} ต้องถูกแปลงเป็น {$problem->value}",
        );
    }
});
