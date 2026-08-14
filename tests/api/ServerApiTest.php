<?php

declare(strict_types=1);

/**
 * สัญญาของทรัพยากรฝั่ง SERVER — PLAN-V2 เฟส B3.4
 *
 * เทสต์ที่สำคัญที่สุดของชุดนี้คือ **"webadmin ต้องได้ 403 ทุก endpoint"**
 * ซึ่งเป็นเกณฑ์รับงานข้อ 3 ของเฟส B โดยตรง — ลูกค้าที่เช่าโฮสต์อยู่ต้องแตะ firewall
 * ค่าตั้ง SSH หรือ log ของระบบไม่ได้เลยแม้จะรู้ URL และมี session ที่ใช้งานได้จริง
 *
 * เทสต์ยังตรึงกลไกคืนค่าอัตโนมัติด้วย: คำตอบของ firewall/ssh ต้องแนบ `pending_rollback`
 * เสมอ ไม่งั้น SPA จะไม่รู้ว่าต้องขึ้นตัวนับถอยหลัง แล้วผู้ใช้จะเสียการตั้งค่าที่เพิ่งทำ
 * ไปเงียบ ๆ เมื่อครบเวลาโดยไม่เข้าใจว่าเพราะอะไร
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของทรัพยากรฝั่ง SERVER');

function serverHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('srvadmin', 'Server-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createUser('srvsys', 'Server-Sys-Pass-22', Permissions::SYSADMIN);
    $harness->createUser('srvweb', 'Server-Web-Pass-33', Permissions::WEBADMIN);

    return $harness;
}

function serverLogin(string $username, string $password): ApiHarness
{
    $harness = serverHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

/** ทุกเส้นทางของ B3.4 พร้อม body ที่ใช้ทดสอบ */
function serverEndpoints(): array
{
    return [
        ['GET', '/api/v2/services', []],
        ['POST', '/api/v2/services/apache2/actions', ['action' => 'restart']],
        ['GET', '/api/v2/firewall', []],
        ['POST', '/api/v2/firewall/rules', ['action' => 'allow', 'port' => '8080']],
        ['DELETE', '/api/v2/firewall/rules/1', ['expect' => 'x']],
        ['PUT', '/api/v2/firewall/enabled', ['enabled' => false]],
        ['GET', '/api/v2/ssh-config', []],
        ['PATCH', '/api/v2/ssh-config', ['Port' => '2222']],
        ['GET', '/api/v2/rollbacks', []],
        ['POST', '/api/v2/rollbacks/1/confirmation', []],
        ['POST', '/api/v2/rollbacks/1/execution', []],
        ['GET', '/api/v2/logs/sources', []],
        ['GET', '/api/v2/logs', []],
        ['GET', '/api/v2/security/scan', []],
        ['GET', '/api/v2/backup-destinations', []],
        ['POST', '/api/v2/backup-destinations', ['name' => 'x', 'driver' => 'local', 'path' => '/tmp/x']],
        ['GET', '/api/v2/backup-schedule', []],
        ['PATCH', '/api/v2/backup-schedule', ['schedule' => '0 1 * * *']],
        ['GET', '/api/v2/backup-targets', []],
        ['GET', '/api/v2/system/info', []],
    ];
}

test('ผู้ดูแลเว็บไซต์ต้องได้ 403 ทุก endpoint ของหมวด SERVER', static function (): void {
    // เกณฑ์รับงาน B4 ข้อ 3 — ตรวจทีละเส้นทาง ไม่ใช่เชื่อว่า Permissions::forRole() ถูก
    $harness = serverLogin('srvweb', 'Server-Web-Pass-33');

    foreach (serverEndpoints() as [$method, $path, $body]) {
        $response = $harness->request($method, $path, $body);

        assertSame(403, $response->status, "{$method} {$path} ต้องได้ 403 สำหรับ webadmin");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), "{$method} {$path} ต้องเป็น FORBIDDEN");
        assertTrue(!$response->looksLikeHtml(), "{$method} {$path} ต้องไม่มี HTML ปนออกมา");
    }
});

test('ผู้ดูแลเว็บไซต์ยังเรียก system/health ได้ — ต้องรู้ว่าทำไมปุ่มไม่ทำงาน', static function (): void {
    $harness = serverLogin('srvweb', 'Server-Web-Pass-33');

    $response = $harness->request('GET', '/api/v2/system/health');

    assertSame(200, $response->status, 'ทุกบทบาทต้องเรียก health ได้');
    assertSame(false, $response->data('agent_available'), 'ต้องบอกได้ว่า agent ไม่ทำงาน');
    assertSame('sandbox', $response->data('mode'), 'ต้องบอกโหมดการทำงาน');
});

test('health ตอบได้แม้ agent ล่ม — ไม่ล้มพร้อมกับสิ่งที่มันตรวจ', static function (): void {
    // ถ้า health เรียก agent มันจะได้ 503 พร้อมกับทุก endpoint อื่น แล้วตอบไม่ได้เลย
    // ในจังหวะที่ต้องการคำตอบที่สุด — เทสต์นี้ตรึงพฤติกรรมนั้นไว้
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    $health = $harness->request('GET', '/api/v2/system/health');
    $info = $harness->request('GET', '/api/v2/system/info');

    assertSame(200, $health->status, 'health ต้องตอบ 200 แม้ agent ล่ม');
    assertSame(503, $info->status, 'ส่วน system/info ที่ต้องใช้ agent จริงต้องเป็น 503');
    assertSame(ApiProblem::AgentUnavailable->value, $info->errorCode(), 'ต้องเป็นรหัส AGENT_UNAVAILABLE');
});

test('sysadmin ใช้งานหมวด SERVER ได้ แต่แตะผู้ใช้ panel ไม่ได้', static function (): void {
    $harness = serverLogin('srvsys', 'Server-Sys-Pass-22');

    // sysadmin มีสิทธิ์หมวด SERVER ครบ — ต้องผ่าน middleware ไปถึง agent (แล้วได้ 503)
    // ไม่ใช่ถูกตัดที่ 403 · ความต่างนี้พิสูจน์ว่าสิทธิ์ถูกผูกกับเส้นทางถูกต้อง
    foreach ([
        ['GET', '/api/v2/services'],
        ['GET', '/api/v2/firewall'],
        ['GET', '/api/v2/ssh-config'],
        ['GET', '/api/v2/security/scan'],
        ['GET', '/api/v2/system/info'],
    ] as [$method, $path]) {
        $response = $harness->request($method, $path);

        assertSame(503, $response->status, "{$method} {$path} ต้องผ่านสิทธิ์แล้วไปติดที่ agent ไม่ใช่ 403");
    }

    // แต่ rollbacks กับ logs ไม่ต้องใช้ agent ในการอ่านรายการ
    assertSame(200, $harness->request('GET', '/api/v2/rollbacks')->status, 'sysadmin ต้องดูรายการรอยืนยันได้');
    assertSame(200, $harness->request('GET', '/api/v2/logs/sources')->status, 'sysadmin ต้องดูแหล่ง log ได้');
});

test('คำสั่งบริการที่ไม่รู้จักถูกปฏิเสธก่อนส่งออกไปหา agent', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    // ถ้าปล่อยไป ชื่อ capability จะถูกประกอบจากค่าที่ผู้ใช้ส่งมา ('service.' . $action)
    // ซึ่งเป็นรูปแบบที่ไม่ควรมีอยู่ในระบบเลย
    foreach (['destroy', 'reload; rm -rf /', '', 'RESTART'] as $bad) {
        $response = $harness->request('POST', '/api/v2/services/apache2/actions', ['action' => $bad]);

        assertSame(422, $response->status, "คำสั่ง '{$bad}' ต้องถูกปฏิเสธที่ชั้นเว็บ ไม่ใช่ส่งต่อ");
        assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
        assertTrue(
            str_contains((string) ($response->json['error']['fields']['action'] ?? ''), 'restart'),
            'ต้องบอกรายการคำสั่งที่ใช้ได้ให้ด้วย',
        );
    }
});

test('ค่าตั้ง SSH ที่ไม่อยู่ใน allowlist ถูกปฏิเสธพร้อมบอกคีย์ที่ใช้ได้', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    // การเขียนบรรทัดอิสระลง sshd_config คือทางที่ทำให้ sshd ไม่ขึ้นอีกเลย
    $response = $harness->request('PATCH', '/api/v2/ssh-config', [
        'AllowUsers' => 'root',
        'Match' => 'User evil',
    ]);

    assertSame(422, $response->status, 'คีย์นอก allowlist ต้องถูกปฏิเสธ');
    assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertTrue(
        str_contains((string) ($response->json['error']['message'] ?? ''), 'AllowUsers'),
        'ต้องบอกว่าคีย์ไหนที่ใช้ไม่ได้',
    );

    // ไม่ส่งอะไรมาเลยก็ต้องถูกปฏิเสธ ไม่ใช่ "บันทึกสำเร็จ" ทั้งที่ไม่มีอะไรเปลี่ยน
    $empty = $harness->request('PATCH', '/api/v2/ssh-config', ['window' => 120]);

    assertSame(422, $empty->status, 'ไม่ระบุค่าที่จะเปลี่ยนต้องถูกปฏิเสธ');
});

test('รายการรอยืนยันบอกเวลาที่เหลือให้ SPA ขึ้นตัวนับถอยหลังได้', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    $harness->app->db()->insert('pending_rollbacks', [
        'action' => 'ssh.config_set',
        'description' => 'เปลี่ยนพอร์ต SSH เป็น 2222',
        'payload_json' => '{"files":{},"units":[],"undo":[]}',
        'created_at' => time(),
        'expires_at' => time() + 90,
    ]);

    $response = $harness->request('GET', '/api/v2/rollbacks');

    assertSame(200, $response->status, 'ต้องดูรายการได้');
    assertSame(1, count($response->json['data']), 'ต้องเห็นรายการที่รอยืนยัน');

    $pending = $response->json['data'][0];

    assertTrue($pending['remaining_seconds'] > 0, 'ต้องบอกเวลาที่เหลือเป็นวินาที');
    assertTrue(is_int($pending['expires_at']), 'ต้องบอกเวลาหมดอายุเป็น unix timestamp');
    assertSame('เปลี่ยนพอร์ต SSH เป็น 2222', $pending['description'], 'ต้องบอกว่ากำลังรอยืนยันอะไรอยู่');

    // สั่งคืนค่ารายการที่ไม่มีอยู่ต้องได้ 404 ไม่ใช่เงียบ ๆ ผ่านไป
    $missing = $harness->request('POST', '/api/v2/rollbacks/999999/execution');
    assertSame(404, $missing->status, 'รายการที่ไม่มีอยู่ต้องได้ 404');

    $harness->app->db()->run('DELETE FROM pending_rollbacks');
});

test('แหล่ง log ถูกอ้างด้วยคีย์ ไม่ใช่เส้นทางไฟล์', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    $sources = $harness->request('GET', '/api/v2/logs/sources');

    assertSame(200, $sources->status, 'ต้องดูรายการแหล่ง log ได้');
    assertTrue(count($sources->json['data']) > 0, 'ต้องมีแหล่ง log อย่างน้อยหนึ่งแหล่ง');

    foreach ($sources->json['data'] as $source) {
        assertTrue(array_key_exists('key', $source), 'ทุกแหล่งต้องมีคีย์');
        // เส้นทางจริงบนเครื่องไม่ใช่ข้อมูลที่หน้าจอต้องรู้
        assertTrue(!array_key_exists('path', $source), 'ต้องไม่ส่งเส้นทางไฟล์จริงออกไป');
    }

    // ส่งเส้นทางไฟล์มาแทนคีย์ต้องถูกปฏิเสธ — นี่คือสิ่งที่กันการอ่านไฟล์ใดก็ได้บนเครื่อง
    foreach (['/etc/shadow', '../../etc/passwd', 'ไม่มีแหล่งนี้'] as $bad) {
        $response = $harness->request('GET', '/api/v2/logs', query: ['source' => $bad]);

        assertSame(403, $response->status, "แหล่ง '{$bad}' ต้องถูกปฏิเสธ");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
    }
});

test('ทุกเส้นทางของ B3.4 ตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    foreach (serverEndpoints() as [$method, $path, $body]) {
        $response = $harness->request($method, $path, $body);

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

test('ตารางเวลาสำรองตรึง capability ไว้ที่ backup.run เสมอ ไม่ว่าจะส่งอะไรมา', static function (): void {
    /*
     * เลือก capability เองได้ = ตั้งเวลาให้ระบบรันคำสั่งอะไรก็ได้ในนามของ "ระบบ"
     * ซึ่งเป็นสิทธิ์สูงสุดที่มีในระบบนี้
     *
     * ตั้งแต่ PLAN-BACKUP-V2 ข้อ B10 ตารางเวลาเหลือ**ตัวเดียวทั้งเครื่อง** ชื่อคงที่
     * `backup.auto` · ผู้เรียกจึงสร้างแถวใหม่ไม่ได้เลย ได้แค่แก้เวลาของแถวเดียวนั้น
     * — ช่องทางแทรก capability ปิดไปพร้อมกับการตัด CRUD ทิ้ง ไม่ใช่ด้วยการกรองค่า
     */
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    $response = $harness->request('PATCH', '/api/v2/backup-schedule', [
        'schedule' => '0 2 * * *',
        'capability' => 'service.restart',
        'name' => 'rollback.run',
    ]);

    assertSame(200, $response->status, 'ต้องแก้เวลาได้ตามปกติ');

    $rows = $harness->app->db()->all("SELECT name, capability, schedule FROM scheduled_jobs WHERE name LIKE 'backup%'");

    assertSame(1, count($rows), 'ตารางเวลาสำรองต้องมีแถวเดียวเสมอ');
    assertSame('backup.auto', $rows[0]['name'], 'ชื่องานต้องคงที่ ผู้เรียกตั้งเองไม่ได้');
    assertSame('backup.run', $rows[0]['capability'], 'capability ต้องเป็น backup.run เสมอ');
    assertSame('0 2 * * *', $rows[0]['schedule'], 'เวลาที่ตั้งต้องถูกบันทึกจริง');
});

test('ตารางเวลาสำรองแตะงานของระบบไม่ได้เลย', static function (): void {
    // ในตาราง `scheduled_jobs` มี `rollback.run` ที่เป็นกลไกกันผู้ดูแลล็อกตัวเองออกจาก
    // เครื่อง · endpoint นี้อ้างงานด้วยชื่อคงที่ `backup.auto` เท่านั้น จึงไม่มีทาง
    // ให้ผู้เรียกชี้ไปที่งานอื่นได้เลย — ไม่ใช่แค่ "ตรวจแล้วปฏิเสธ"
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    (new Phpcp\Domain\ScheduledJobRepository($harness->app->db()))->installDefaults();

    $systemJob = $harness->app->db()->first(
        'SELECT id, enabled FROM scheduled_jobs WHERE name = :name',
        ['name' => 'rollback.run'],
    );

    assertTrue($systemJob !== null, 'ต้องมีงาน rollback.run อยู่จริงถึงจะทดสอบได้');

    // เส้นทางเดิมที่รับ id ต้องไม่มีอยู่แล้ว — ไม่ใช่มีอยู่แต่ตอบ 403
    $response = $harness->request('PATCH', '/api/v2/backup-schedules/'.(int) $systemJob['id'], ['enabled' => 0]);

    assertSame(404, $response->status, 'เส้นทางที่รับรหัสงานต้องไม่มีอยู่แล้ว');

    $after = $harness->app->db()->first('SELECT enabled FROM scheduled_jobs WHERE id = :id', ['id' => (int) $systemJob['id']]);

    assertSame(1, (int) $after['enabled'], 'งานของระบบต้องยังเปิดใช้งานอยู่');
});

test('ตารางเวลาสำรองปฏิเสธรูปแบบเวลาที่ผิด', static function (): void {
    $harness = serverLogin('srvadmin', 'Server-Admin-Pass-11');

    // ค่าว่างแปลว่า "ไม่เปลี่ยนเวลา" ไม่ใช่เวลาที่ผิด — จึงไม่อยู่ในรายการนี้
    foreach (['ทุกวัน', '* * *', '99 * * * *'] as $bad) {
        $response = $harness->request('PATCH', '/api/v2/backup-schedule', ['schedule' => $bad]);

        assertSame(422, $response->status, "ตารางเวลา '{$bad}' ต้องถูกปฏิเสธ");
    }
});
