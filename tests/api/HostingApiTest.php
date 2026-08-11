<?php

declare(strict_types=1);

/**
 * สัญญาของ certificates / databases / cron-jobs / backups — PLAN-V2 เฟส B3.2
 *
 * สองทรัพยากรในชุดนี้มีคุณสมบัติที่พังแล้วเจ็บเป็นพิเศษ จึงตรวจละเอียดกว่าตัวอื่น:
 *
 *   - **cron-jobs** ข้อมูลอยู่ในฐานข้อมูลแต่ของจริงคือไฟล์ `/etc/cron.d` ที่ generate ตาม
 *     ถ้าเขียนไฟล์ไม่สำเร็จแล้วไม่ย้อนฐานข้อมูลกลับ หน้าจอจะบอกว่างานทำงานอยู่
 *     ทั้งที่ cron ไม่รู้จักมันเลย — เทสต์นี้บังคับให้ย้อนกลับจริง
 *   - **backups** ไฟล์สำรองระดับระบบมีค่า config ของทั้งเครื่องอยู่ข้างใน
 *     ลูกค้าต้องมองไม่เห็นและกู้ไม่ได้เด็ดขาด
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของ certificates, databases, cron และ backups');

/** สภาพแวดล้อมของชุดนี้ พร้อมเว็บไซต์ งาน cron และไฟล์สำรองตัวอย่าง */
function hostingHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('hostadmin', 'Hosting-Admin-Pass-11', Permissions::SUPERADMIN);
    $ownerId = $harness->createHostingUser('hostowner', 'Hosting-Owner-Pass-22', Permissions::WEBADMIN);
    $strangerId = $harness->createHostingUser('hoststranger', 'Hosting-Stranger-Pass-33', Permissions::WEBADMIN);

    $db = $harness->app->db();
    $now = time();

    $siteId = $db->insert('sites', [
        'name' => 'เว็บของลูกค้า',
        'primary_domain' => 'host.example.com',
        'docroot' => '/srv/phpcp/sites/host.example.com/public',
        'php_version' => '8.4',
        'status' => 'active',
        'owner_user_id' => $ownerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $otherId = $db->insert('sites', [
        'name' => 'เว็บของคนอื่น',
        'primary_domain' => 'stranger.example.com',
        'docroot' => '/srv/phpcp/sites/stranger.example.com/public',
        'php_version' => '8.4',
        'status' => 'active',
        'owner_user_id' => $strangerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->insert('cron_jobs', [
        'site_id' => $siteId,
        'name' => 'ล้างแคชรายวัน',
        'schedule' => '0 3 * * *',
        'command' => 'php /srv/phpcp/sites/host.example.com/public/clear-cache.php',
        'enabled' => 1,
        'created_at' => $now,
    ]);

    // ไฟล์สำรองของเว็บลูกค้า และไฟล์ระดับระบบที่ลูกค้าต้องมองไม่เห็น
    $db->insert('backups', [
        'name' => 'host.example.com-2026-08-05',
        'type' => 'site',
        'site_id' => $siteId,
        'path' => '/var/lib/phpcp/backups/site-host.example.com.tar.gz',
        'size_bytes' => 5_242_880,
        'checksum' => str_repeat('a', 64),
        'status' => 'ok',
        'created_at' => $now - 3600,
    ]);

    $db->insert('backups', [
        'name' => 'config-2026-08-05',
        'type' => 'config',
        'site_id' => null,
        'path' => '/var/lib/phpcp/backups/config-2026-08-05.tar.gz',
        'size_bytes' => 65_536,
        'checksum' => str_repeat('b', 64),
        'status' => 'ok',
        'created_at' => $now - 1800,
    ]);

    $db->insert('backups', [
        'name' => 'stranger-2026-08-05',
        'type' => 'site',
        'site_id' => $otherId,
        'path' => '/var/lib/phpcp/backups/site-stranger.example.com.tar.gz',
        'size_bytes' => 1024,
        'checksum' => str_repeat('c', 64),
        'status' => 'ok',
        'created_at' => $now - 900,
    ]);

    return $harness;
}

function hostingLogin(string $username, string $password): ApiHarness
{
    $harness = hostingHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

function hostingSiteId(string $domain): int
{
    return (int) hostingHarness()->app->db()->value(
        'SELECT id FROM sites WHERE primary_domain = :d',
        ['d' => $domain],
        0,
    );
}

test('รายการงานอัตโนมัติคืนทั้งค่าดิบและคำอธิบายที่อ่านได้', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/cron-jobs');

    assertSame(200, $response->status, 'ต้องดูรายการงานได้');
    assertSame(1, $response->json['meta']['total'] ?? 0, 'ต้องเห็นงานตัวอย่าง');

    $job = $response->json['data'][0];

    // ฟอร์มแก้ไขต้องใช้ค่าดิบ ส่วนหน้าจอรายการอยากได้คำอธิบาย — ต้องมีทั้งคู่
    assertSame('0 3 * * *', $job['schedule'], 'ต้องคืน cron expression ดิบสำหรับฟอร์มแก้ไข');
    assertTrue($job['schedule_label'] !== '', 'ต้องมีคำอธิบายที่อ่านได้สำหรับหน้าจอ');
    assertSame(true, $job['enabled'], 'enabled ต้องเป็น boolean จริง ไม่ใช่ 1');
    assertSame(null, $job['last_exit_code'], 'งานที่ยังไม่เคยรันต้องเป็น null ไม่ใช่ 0');
    assertSame('host.example.com', $job['site_domain'], 'ต้องบอกว่าเป็นงานของเว็บไหน');
});

test('cron ที่รูปแบบผิดถูกปฏิเสธก่อนแตะฐานข้อมูล', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $siteId = hostingSiteId('host.example.com');
    $before = (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0);

    $response = $harness->request('POST', '/api/v2/cron-jobs', [
        'site_id' => $siteId,
        'name' => 'งานที่ตารางเวลาพัง',
        'schedule' => 'ทุกวันตอนเช้า',
        'command' => 'echo hi',
    ]);

    assertSame(422, $response->status, 'ตารางเวลาที่ผิดรูปแบบต้องถูกปฏิเสธ');
    assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertSame(
        $before,
        (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0),
        'ต้องไม่มีแถวใหม่เกิดขึ้นเลยเมื่อค่าไม่ผ่าน',
    );
});

test('สร้าง cron แล้วเขียนไฟล์ไม่สำเร็จ ต้องไม่เหลือแถวค้างในฐานข้อมูล', static function (): void {
    // agent ไม่ทำงานในเทสต์ → cron.sync ล้มเสมอ ซึ่งเป็นสถานการณ์ที่ต้องพิสูจน์พอดี:
    // ฐานข้อมูลกับไฟล์ต้องไม่หลุดจากกัน แม้ในกรณีที่แย่ที่สุด
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $siteId = hostingSiteId('host.example.com');
    $before = (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0);

    $response = $harness->request('POST', '/api/v2/cron-jobs', [
        'site_id' => $siteId,
        'name' => 'งานที่ sync ไม่สำเร็จ',
        'schedule' => '*/5 * * * *',
        'command' => 'echo hi',
    ]);

    assertSame(503, $response->status, 'เขียนไฟล์ไม่ได้เพราะ agent ล่ม ต้องเป็น 503');
    assertSame(
        $before,
        (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0),
        'แถวที่เพิ่งสร้างต้องถูกลบทิ้ง ไม่ปล่อยให้เหลืองานที่ cron ไม่รู้จัก',
    );
});

test('แก้ cron แล้ว sync ล้ม ต้องย้อนค่าเดิมกลับครบทุกฟิลด์', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    $jobId = (int) $harness->app->db()->value("SELECT id FROM cron_jobs WHERE name = 'ล้างแคชรายวัน'", [], 0);
    $before = $harness->app->db()->first('SELECT * FROM cron_jobs WHERE id = :id', ['id' => $jobId]);

    $response = $harness->request('PATCH', '/api/v2/cron-jobs/' . $jobId, [
        'schedule' => '*/15 * * * *',
        'enabled' => false,
    ]);

    assertSame(503, $response->status, 'sync ล้มต้องเป็น 503');

    $after = $harness->app->db()->first('SELECT * FROM cron_jobs WHERE id = :id', ['id' => $jobId]);

    assertSame($before['schedule'], $after['schedule'], 'ตารางเวลาต้องถูกย้อนกลับ');
    assertSame($before['enabled'], $after['enabled'], 'สถานะเปิด/ปิดต้องถูกย้อนกลับ');
    assertSame($before['command'], $after['command'], 'คำสั่งต้องไม่ถูกแตะ');
});

test('ลบ cron แล้ว sync ล้ม ต้องได้งานคืนมาครบ', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    $jobId = (int) $harness->app->db()->value("SELECT id FROM cron_jobs WHERE name = 'ล้างแคชรายวัน'", [], 0);

    $response = $harness->request('DELETE', '/api/v2/cron-jobs/' . $jobId);

    assertSame(503, $response->status, 'sync ล้มต้องเป็น 503');
    assertSame(
        1,
        (int) $harness->app->db()->value("SELECT count(*) FROM cron_jobs WHERE name = 'ล้างแคชรายวัน'", [], 0),
        'งานที่ลบไปต้องถูกใส่กลับเมื่อเขียนไฟล์ไม่สำเร็จ',
    );
});

test('ลูกค้าแตะงาน cron ของเว็บคนอื่นไม่ได้', static function (): void {
    $harness = hostingHarness();
    $db = $harness->app->db();

    $strangerJob = $db->insert('cron_jobs', [
        'site_id' => hostingSiteId('stranger.example.com'),
        'name' => 'งานของคนอื่น',
        'schedule' => '0 4 * * *',
        'command' => 'echo other',
        'enabled' => 1,
        'created_at' => time(),
    ]);

    $harness = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');

    $list = $harness->request('GET', '/api/v2/cron-jobs');
    assertSame(1, $list->json['meta']['total'] ?? 0, 'ต้องเห็นเฉพาะงานของเว็บตัวเอง');

    foreach ([['PATCH', ['name' => 'แก้ของคนอื่น']], ['DELETE', []]] as [$method, $body]) {
        $response = $harness->request($method, '/api/v2/cron-jobs/' . $strangerJob, $body);

        assertSame(404, $response->status, "{$method} งานของคนอื่นต้องได้ 404");
    }

    assertSame(
        1,
        (int) $db->value('SELECT count(*) FROM cron_jobs WHERE id = :id', ['id' => $strangerJob], 0),
        'งานของคนอื่นต้องยังอยู่ครบ',
    );
});

test('ลูกค้าไม่เห็นและกู้ไฟล์สำรองระดับระบบไม่ได้', static function (): void {
    $harness = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');

    $list = $harness->request('GET', '/api/v2/backups');

    assertSame(200, $list->status, 'ลูกค้าต้องดูไฟล์สำรองของตัวเองได้');
    assertSame(1, $list->json['meta']['total'] ?? 0, 'ต้องเห็นเฉพาะไฟล์ของเว็บตัวเอง');
    assertSame('site', $list->json['data'][0]['type'], 'ต้องเป็นไฟล์ของเว็บไซต์ ไม่ใช่ของระบบ');

    $configBackupId = (int) $harness->app->db()->value(
        "SELECT id FROM backups WHERE type = 'config'",
        [],
        0,
    );

    // ไฟล์ระดับระบบมีค่า config ของทั้งเครื่องอยู่ข้างใน — ลูกค้าต้องแตะไม่ได้ทุกทาง
    //
    // สองเส้นทางถูกปฏิเสธคนละชั้นและได้รหัสต่างกันอย่างถูกต้อง:
    //   DELETE → 404 ที่ controller เพราะ webadmin *มี* สิทธิ์ backup.manage
    //            แต่ไฟล์นี้ไม่ใช่ของเขา จึงต้องเหมือนไม่มีอยู่จริง
    //   POST restoration → 403 ที่ middleware เพราะ webadmin ไม่มีสิทธิ์ backup.restore
    //            เลยแม้แต่กับไฟล์ของตัวเอง (การกู้คืนเป็นสิทธิ์แยกต่างหาก)
    $delete = $harness->request('DELETE', '/api/v2/backups/' . $configBackupId);
    assertSame(404, $delete->status, 'ลบไฟล์สำรองระดับระบบต้องเหมือนไม่มีไฟล์นั้นอยู่');

    $restore = $harness->request('POST', '/api/v2/backups/' . $configBackupId . '/restoration', ['confirm' => 'x']);
    assertSame(403, $restore->status, 'ลูกค้าไม่มีสิทธิ์กู้คืนเลย จึงถูกตัดตั้งแต่ชั้น middleware');
    assertSame(ApiProblem::Forbidden->value, $restore->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');

    // และไฟล์ต้องยังอยู่ครบหลังจากลูกค้าพยายามแตะทั้งสองทาง
    assertSame(
        1,
        (int) $harness->app->db()->value('SELECT count(*) FROM backups WHERE id = :id', ['id' => $configBackupId], 0),
        'ไฟล์สำรองระดับระบบต้องไม่ถูกแตะเลย',
    );
});

test('ผู้ดูแลระบบเห็นไฟล์สำรองครบทุกชนิดพร้อมขนาดรวม', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/backups');

    assertSame(3, $response->json['meta']['total'] ?? 0, 'ผู้ดูแลระบบต้องเห็นทั้งหมด');
    assertTrue(($response->headers['X-Total-Size'] ?? '') !== '', 'ต้องบอกขนาดรวมทาง header');

    $first = $response->json['data'][0];
    assertTrue(is_int($first['size_bytes']), 'ขนาดต้องเป็นไบต์ดิบ ไม่ใช่ "5 MB"');
    assertTrue(strlen((string) $first['checksum']) === 64, 'ต้องส่ง checksum มาให้เห็นว่าไฟล์ตรวจสอบได้');

    $filtered = $harness->request('GET', '/api/v2/backups', query: ['type' => 'config']);
    assertSame(1, $filtered->json['meta']['total'] ?? 0, 'กรองตามชนิดต้องทำงาน');
});

test('กู้คืนต้องใช้สิทธิ์ backup.restore ที่แยกจากการสร้าง/ลบ', static function (): void {
    // sysadmin มี backup.view แต่ไม่มี backup.manage/backup.restore ตาม Permissions::forRole
    $harness = hostingHarness();
    $harness->createUser('hostsys', 'Hosting-Sys-Pass-33', Permissions::SYSADMIN);
    $harness = hostingLogin('hostsys', 'Hosting-Sys-Pass-33');

    $backupId = (int) $harness->app->db()->value("SELECT id FROM backups WHERE type = 'site'", [], 0);

    assertSame(200, $harness->request('GET', '/api/v2/backups')->status, 'sysadmin ต้องดูรายการได้');

    $restore = $harness->request('POST', '/api/v2/backups/' . $backupId . '/restoration', ['confirm' => 'host.example.com']);
    assertSame(403, $restore->status, 'sysadmin ต้องกู้คืนไม่ได้');
    assertSame(ApiProblem::Forbidden->value, $restore->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
});

test('ทรัพยากรที่ต้องพึ่ง agent ตอบ 503 อย่างสม่ำเสมอ', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    // certificates และ databases อ่านสถานะจริงจาก agent ทั้งคู่ — agent ล่มต้องบอกให้ชัด
    // ว่าเป็นปัญหาชั่วคราว (503) ไม่ใช่ตอบรายการว่างซึ่งดูเหมือน "ไม่มีข้อมูล"
    foreach (['/api/v2/certificates', '/api/v2/databases'] as $path) {
        $response = $harness->request('GET', $path);

        assertSame(503, $response->status, "{$path} ต้องเป็น 503 เมื่อ agent ไม่ตอบ");
        assertSame(ApiProblem::AgentUnavailable->value, $response->errorCode(), 'ต้องเป็นรหัส AGENT_UNAVAILABLE');
        assertTrue($response->isJson() && !$response->looksLikeHtml(), 'ต้องยังเป็น JSON');
    }
});

test('เส้นทางทั้งหมดของ B3.2 ตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $siteId = hostingSiteId('host.example.com');
    $backupId = (int) $harness->app->db()->value("SELECT id FROM backups WHERE type = 'site'", [], 0);

    $cases = [
        ['GET', '/api/v2/certificates', []],
        ['POST', '/api/v2/certificates', ['site_id' => $siteId, 'method' => 'self-signed']],
        ['POST', '/api/v2/certificates/' . $siteId . '/renewal', []],
        ['PUT', '/api/v2/certificates/' . $siteId . '/mode', ['mode' => 'on']],
        ['DELETE', '/api/v2/certificates/999999', []],
        ['GET', '/api/v2/databases', []],
        ['POST', '/api/v2/databases', ['name' => 'x', 'username' => 'y']],
        ['DELETE', '/api/v2/databases/nothing', ['confirm' => 'nothing']],
        ['POST', '/api/v2/database-users/nobody/password', []],
        ['GET', '/api/v2/cron-jobs', []],
        ['DELETE', '/api/v2/cron-jobs/999999', []],
        ['GET', '/api/v2/backups', []],
        ['POST', '/api/v2/backups/999999/restoration', ['confirm' => 'x']],
        ['DELETE', '/api/v2/backups/' . $backupId, []],
    ];

    foreach ($cases as [$method, $path, $body]) {
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
