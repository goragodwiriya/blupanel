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
        'primary_domain' => 'host.example.com',
        'docroot' => '/srv/phpcp/sites/host.example.com/public',
        'php_version' => '8.4',
        'status' => 'active',
        'owner_user_id' => $ownerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $otherId = $db->insert('sites', [
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

test('ขอฟอร์มของใหม่ด้วย id = 0 ต้องได้โครงเปล่าพร้อมคำสั่งเปิด Modal', static function (): void {
    // Add กับ Edit ใช้ฟอร์มเดียวกัน — ปุ่ม Add เรียก /cron-jobs/0 แล้วเซิร์ฟเวอร์
    // เป็นคนสั่งเปิด Modal เอง · ถ้า id = 0 กลายเป็น 404 เมื่อไหร่ ปุ่ม Add ตายทันที
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/cron-jobs/0');

    assertSame(200, $response->status, 'id = 0 คือ "ของใหม่" ไม่ใช่ของที่หาไม่เจอ');
    assertSame(0, $response->json['data']['id'] ?? -1, 'ต้องคืน id เป็น 0 ให้ฟอร์มรู้ว่าเป็นของใหม่');
    assertSame('', $response->json['data']['name'] ?? null, 'ช่องต่าง ๆ ต้องว่าง');

    $action = $response->json['actions'][0] ?? [];

    assertSame('modal', $action['type'] ?? '', 'เซิร์ฟเวอร์ต้องเป็นคนสั่งเปิด Modal');
    assertSame('cron-job-form.html', $action['template'] ?? '', 'ต้องเป็นฟอร์มตัวเดียวกับที่ Edit ใช้');
});

test('ฟอร์มแก้ไขใช้ template เดียวกับฟอร์มเพิ่มใหม่', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $jobId = (int) $harness->app->db()->value("SELECT id FROM cron_jobs WHERE name = 'ล้างแคชรายวัน'", [], 0);

    $response = $harness->request('GET', '/api/v2/cron-jobs/' . $jobId);

    assertSame(200, $response->status, 'ต้องเปิดฟอร์มแก้ไขได้');
    assertSame($jobId, $response->json['data']['id'] ?? 0, 'ต้องคืนค่าของแถวนั้นให้ฟอร์มเติม');
    assertSame(
        'cron-job-form.html',
        $response->json['actions'][0]['template'] ?? '',
        'ถ้าแยกเป็นคนละไฟล์เมื่อไหร่ ช่องที่เพิ่มทีหลังจะหลุดไปฝั่งเดียว',
    );
});

test('บันทึกฟอร์มที่มี id ติดมาด้วย ต้องเป็นการแก้ไข ไม่ใช่สร้างใหม่', static function (): void {
    // ฟอร์มเดียวยิงไป POST /cron-jobs เสมอ — ตัวที่บอกว่าแก้หรือสร้างคือ id ที่ซ่อนอยู่
    // เคยพังตรงนี้มาแล้ว: store() ส่งต่อให้ update() ด้วย id ที่เป็น int ทำให้ทั้งคำขอ
    // ตายเป็น 500 (preg_match ไม่รับ int) — เห็นเฉพาะตอนกดบันทึกจริงเท่านั้น
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $jobId = (int) $harness->app->db()->value("SELECT id FROM cron_jobs WHERE name = 'ล้างแคชรายวัน'", [], 0);
    $siteId = hostingSiteId('host.example.com');
    $before = (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0);

    $response = $harness->request('POST', '/api/v2/cron-jobs', [
        'id' => $jobId,
        'site_id' => $siteId,
        'name' => 'ล้างแคชรายวัน (แก้ชื่อ)',
        'schedule' => '0 4 * * *',
        'command' => 'echo hi',
    ]);

    assertTrue($response->status !== 500, 'ต้องไม่ระเบิดเป็น 500 — คำขอนี้คือปุ่มบันทึกของฟอร์มแก้ไข');
    assertSame(
        $before,
        (int) $harness->app->db()->value('SELECT count(*) FROM cron_jobs', [], 0),
        'มี id ติดมา = แก้ของเดิม ต้องไม่เกิดแถวใหม่',
    );
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

test('รายการไฟล์สำรองต้องอ่านจากโฟลเดอร์จริง ไม่ใช่จากตาราง', static function (): void {
    /*
     * **นี่คือใจกลางของ PLAN-BACKUP-V2 ข้อ B4** — โฟลเดอร์ `<บ้าน>/backup` เปิดให้
     * ลูกค้าเข้าถึงผ่าน SFTP โดยตั้งใจ เขาลบไฟล์ของตัวเองได้ทุกเมื่อ · แถวในตาราง
     * `backups` ที่บันทึกไว้ตอนสร้างจึงเป็นคำโกหกที่รอเวลา: หน้าจอยังโชว์รายการ
     * ปุ่มกู้คืนยังกดได้ แล้วล้มตอนที่ผู้ใช้ต้องการมันที่สุด
     *
     * เทสต์นี้จึง**ตั้งใจเติมแถวลงตารางเก่าไว้** แล้วยืนยันว่าไม่มีแถวไหนหลุดออกมา
     * ทางหน้าจอเลย · ตอนที่ agent ไม่ตอบ คำตอบต้องเป็น 503 ตรง ๆ ไม่ใช่รายการจากตาราง
     * ซึ่งเป็นคำตอบที่ผิดแบบที่ดูเหมือนคำตอบที่ถูก
     */
    $harness = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');

    assertTrue(
        (int) $harness->app->db()->value('SELECT count(*) FROM backups', [], 0) > 0,
        'ต้องมีแถวค้างอยู่ในตารางเก่าจริง ๆ ถึงจะพิสูจน์ได้ว่ารายการไม่ได้อ่านจากมัน',
    );

    $list = $harness->request('GET', '/api/v2/backups');

    assertSame(503, $list->status, 'รายการต้องมาจาก agent ที่อ่านโฟลเดอร์จริง');
    assertSame(ApiProblem::AgentUnavailable->value, $list->errorCode(), 'ต้องเป็นรหัส AGENT_UNAVAILABLE');
    assertTrue(!isset($list->json['data'][0]), 'ต้องไม่มีแถวจากตารางเก่าหลุดออกมา');
});

test('ลูกค้ากู้คืนไม่ได้เลย — เป็นสิทธิ์แยกที่ webadmin ไม่มี', static function (): void {
    /*
     * webadmin มี `backup.manage` (สร้าง/ลบสำเนาของตัวเอง) แต่ไม่มี `backup.restore`
     * · การกู้คืนเขียนทับเว็บที่ให้บริการอยู่ทั้งก้อน จึงถูกตัดตั้งแต่ชั้น middleware
     * ก่อนที่ controller จะได้เห็นคำขอด้วยซ้ำ
     */
    $harness = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');
    $file = 'host.example.com-files-20260814-010101-aabbcc.tar.gz';

    $restore = $harness->request('POST', '/api/v2/backups/1/' . $file . '/restoration', ['confirm' => 'x']);

    assertSame(403, $restore->status, 'ลูกค้าไม่มีสิทธิ์กู้คืนเลย จึงถูกตัดตั้งแต่ชั้น middleware');
    assertSame(ApiProblem::Forbidden->value, $restore->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
});

test('กู้คืนต้องใช้สิทธิ์ backup.restore ที่แยกจากการสร้าง/ลบ', static function (): void {
    // sysadmin มี backup.view แต่ไม่มี backup.manage/backup.restore ตาม Permissions::forRole
    $harness = hostingHarness();
    $harness->createUser('hostsys', 'Hosting-Sys-Pass-33', Permissions::SYSADMIN);
    $harness = hostingLogin('hostsys', 'Hosting-Sys-Pass-33');
    $file = 'host.example.com-files-20260814-010101-aabbcc.tar.gz';

    $restore = $harness->request('POST', '/api/v2/backups/1/' . $file . '/restoration', ['confirm' => 'host.example.com']);

    assertSame(403, $restore->status, 'sysadmin ต้องกู้คืนไม่ได้');
    assertSame(ApiProblem::Forbidden->value, $restore->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
});

test('ทรัพยากรที่ต้องพึ่ง agent ตอบ 503 อย่างสม่ำเสมอ', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    // certificates และ databases อ่านสถานะจริงจาก agent ทั้งคู่ — agent ล่มต้องบอกให้ชัด
    // ว่าเป็นปัญหาชั่วคราว (503) ไม่ใช่ตอบรายการว่างซึ่งดูเหมือน "ไม่มีข้อมูล"
    // `/backups` เข้ากลุ่มนี้ตั้งแต่รายการอ่านจากโฟลเดอร์จริงของลูกค้า — โฟลเดอร์นั้น
    // เป็นของเขา โหมด 0750 ซึ่งโปรเซสเว็บอ่านไม่ได้ตามที่ตั้งใจไว้ใน SECURITY
    foreach (['/api/v2/certificates', '/api/v2/databases', '/api/v2/backups'] as $path) {
        $response = $harness->request('GET', $path);

        assertSame(503, $response->status, "{$path} ต้องเป็น 503 เมื่อ agent ไม่ตอบ");
        assertSame(ApiProblem::AgentUnavailable->value, $response->errorCode(), 'ต้องเป็นรหัส AGENT_UNAVAILABLE');
        assertTrue($response->isJson() && !$response->looksLikeHtml(), 'ต้องยังเป็น JSON');
    }
});

test('เส้นทางทั้งหมดของ B3.2 ตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $siteId = hostingSiteId('host.example.com');
    $backupFile = 'host.example.com-files-20260814-010101-aabbcc.tar.gz';

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
        ['POST', '/api/v2/backups/1/' . $backupFile . '/restoration', ['confirm' => 'x']],
        ['DELETE', '/api/v2/backups/1/' . $backupFile, []],
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

test('ไฟล์ตั้งค่าของเว็บไซต์ — คำขอต้องเดินผ่านชั้น controller ได้จริง', static function (): void {
    /*
     * **เทสต์นี้ยิงคำขอจริงผ่านชั้น HTTP** ไม่ใช่เรียก capability ตรง ๆ
     *
     * ตอนทำคุณสมบัตินี้ ผมทดสอบชั้น capability อย่างเดียวแล้วผ่านหมด แต่ของจริงล้มด้วย
     * 500 ตั้งแต่คำขอแรก เพราะ `Request::get()` รับค่าเริ่มต้นเป็น string เท่านั้น แต่
     * controller ส่ง `0` ไป · เป็นข้อผิดพลาดที่อยู่ในชั้นบาง ๆ ที่ไม่มีใครคิดว่าจะพัง
     * และไม่มีเทสต์ไหนแตะเลย
     *
     * **agent ไม่ได้รันในแท่นทดสอบ** จึงตรวจได้ถึงแค่ "คำขอเดินผ่าน controller ไปถึง
     * agent ได้" (503) หรือ "สำเร็จทั้งเส้น" (200) · สิ่งที่ต้องไม่เกิดคือ 500 ซึ่งแปลว่า
     * โค้ดพังก่อนถึง agent — ซึ่งคือบั๊กที่เกิดขึ้นจริง
     */
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');
    $siteId = hostingSiteId('host.example.com');

    // query ต้องส่งเป็นอาร์กิวเมนต์แยก — ต่อท้าย path เองจะกลายเป็นเส้นทางที่ไม่มีอยู่จริง
    $list = $harness->request('GET', '/api/v2/config-files', query: ['site_id' => (string) $siteId]);

    assertTrue(
        $list->status !== 500,
        'คำขอต้องไม่ล้มก่อนถึง agent — ได้ ' . $list->status . ' ' . ($list->json['error']['message'] ?? ''),
    );
    assertTrue(
        in_array($list->status, [200, 503], true),
        'ต้องได้ 200 (สำเร็จ) หรือ 503 (ไม่มี agent ในแท่นทดสอบ) · ได้ ' . $list->status,
    );

    // เส้นทางของไฟล์เดียวต้องเดินผ่านได้เหมือนกัน — คีย์ที่มีจุดต้องไม่ทำให้ router หลุด
    $open = $harness->request(
        'GET',
        '/api/v2/config-files/site.' . $siteId . '.custom',
        query: ['site_id' => (string) $siteId],
    );

    assertTrue($open->status !== 500, 'เปิดไฟล์เดียวต้องไม่ล้มก่อนถึง agent · ได้ ' . $open->status);
    assertTrue($open->status !== 404, 'คีย์ที่มีจุดต้องยังจับคู่กับเส้นทางได้ · ได้ ' . $open->status);

    // เว็บไซต์ที่ไม่มีอยู่ต้องได้ 404 ไม่ใช่ 500 — ด่านนี้อยู่ก่อนเรียก agent
    $missing = $harness->request('GET', '/api/v2/config-files', query: ['site_id' => '999999']);

    assertSame(404, $missing->status, 'เว็บไซต์ที่ไม่มีอยู่ต้องได้ 404');
});

test('เจ้าของเว็บไซต์แตะไฟล์ตั้งค่าไม่ได้เลย', static function (): void {
    // ไฟล์เหล่านี้ถูกอ่านโดยเว็บเซิร์ฟเวอร์ที่ใช้ร่วมกันทั้งเครื่อง เขียนผิดแล้วกระทบ
    // ทุกเว็บ ไม่ใช่แค่เว็บของคนเขียน — `site.edit` ที่เจ้าของเว็บมีจึงไม่พอ
    $harness = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');
    $siteId = hostingSiteId('host.example.com');

    foreach ([
        ['GET', '/api/v2/config-files'],
        ['GET', '/api/v2/config-files/site.' . $siteId . '.custom'],
        ['PUT', '/api/v2/config-files'],
    ] as [$method, $path]) {
        $response = $harness->request(
            $method,
            $path,
            ['site_id' => $siteId, 'content' => ''],
            query: ['site_id' => (string) $siteId],
        );

        assertSame(
            ApiProblem::Forbidden->value,
            $response->json['error']['code'] ?? '',
            "{$method} {$path} ต้องถูกปฏิเสธด้วย FORBIDDEN",
        );
    }
});

test('ขอบเขต DNS ต้องเดินผ่านชั้น HTTP ได้และเจ้าของเว็บไซต์แตะไม่ได้', static function (): void {
    /*
     * ขอบเขตใหม่ทุกตัวต้องมีเทสต์ที่ยิงผ่านชั้น HTTP จริง ไม่ใช่เรียก capability ตรง ๆ
     *
     * ขอบเขตของเว็บไซต์เคยล้มด้วย 500 ตั้งแต่คำขอแรกเพราะชนิดของค่าเริ่มต้นใน
     * `Request::get()` — ความผิดพลาดที่อยู่ในชั้นบาง ๆ ระหว่าง controller กับ agent
     * ซึ่งเทสต์ระดับ capability มองไม่เห็นเลยสักตัว
     *
     * **agent ไม่ได้รันในแท่นทดสอบ** จึงตรวจได้ถึงแค่ว่าคำขอเดินไปถึง agent (503)
     * หรือสำเร็จทั้งเส้น (200) · สิ่งที่ต้องไม่เกิดคือ 500
     */
    $harness = hostingLogin('hostadmin', 'Hosting-Admin-Pass-11');

    foreach ([
        ['GET', '/api/v2/config-files'],
        ['GET', '/api/v2/config-files/dns.bind.custom'],
    ] as [$method, $path]) {
        $response = $harness->request($method, $path, query: ['scope' => 'dns']);

        assertTrue(
            in_array($response->status, [200, 503], true),
            "{$method} {$path}?scope=dns ต้องได้ 200 หรือ 503 · ได้ {$response->status} "
                . ($response->json['error']['message'] ?? ''),
        );
    }

    /*
     * **`scope=dns` ต้องไม่ต้องการ `site_id`** — ถ้าโค้ดเผลอตกไปใช้เส้นทางของเว็บไซต์
     * มันจะหาเว็บไซต์ id 0 ไม่เจอแล้วตอบ 404 ซึ่งอ่านแล้วเข้าใจผิดว่าไฟล์ไม่มีอยู่
     */
    $list = $harness->request('GET', '/api/v2/config-files', query: ['scope' => 'dns']);
    assertTrue($list->status !== 404, 'ขอบเขต DNS ต้องไม่ถูกตีความเป็นขอบเขตของเว็บไซต์');

    // ค่าตั้งของ BIND กระทบทุกโดเมนบนเครื่อง — สิทธิ์ของเจ้าของเว็บไซต์ต้องไม่พอ
    $owner = hostingLogin('hostowner', 'Hosting-Owner-Pass-22');

    foreach ([
        ['GET', '/api/v2/config-files/dns.bind.custom'],
        ['PUT', '/api/v2/config-files'],
    ] as [$method, $path]) {
        $response = $owner->request($method, $path, ['scope' => 'dns', 'key' => 'dns.bind.custom', 'content' => ''], query: ['scope' => 'dns']);

        assertSame(
            ApiProblem::Forbidden->value,
            $response->json['error']['code'] ?? '',
            "{$method} {$path} (scope=dns) ต้องถูกปฏิเสธด้วย FORBIDDEN · ได้ {$response->status}",
        );
    }
});
