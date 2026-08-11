<?php

declare(strict_types=1);

/**
 * สัญญาของ users / customers / settings — PLAN-V2 เฟส B3.5 (ชุดสุดท้ายของเฟส B)
 *
 * สองเรื่องที่เทสต์ชุดนี้ต้องตรึงไว้ให้แน่นที่สุด:
 *
 *   1. **กฎกันล็อกตัวเองออกจากระบบ** — เปลี่ยนบทบาท/ระงับ/ลบบัญชีตัวเองไม่ได้
 *      และต้องเหลือผู้ดูแลระบบที่ใช้งานได้อย่างน้อยหนึ่งบัญชีเสมอ · ถ้ากฎนี้พัง
 *      ผู้ดูแลจะกดพลาดครั้งเดียวแล้วเข้าระบบไม่ได้อีกเลยจนกว่าจะไปที่หน้าเครื่อง
 *   2. **ลูกค้าต้องเดินผ่าน capability** ที่เฟส A2 เดินสายไว้ — ไม่ใช่กลับไปเรียก
 *      repository ตรง ๆ ซึ่งจะทำให้กฎโควตากับ audit แยกเป็นสองชุดอีกครั้ง
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของ users (รวมลูกค้าโฮสติ้ง) และ settings');

function accountsHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('acctadmin', 'Accounts-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createUser('acctbackup', 'Accounts-Backup-Pass-22', Permissions::SUPERADMIN);
    $harness->createUser('acctsys', 'Accounts-Sys-Pass-33', Permissions::SYSADMIN);
    $harness->createUser('acctweb', 'Accounts-Web-Pass-44', Permissions::WEBADMIN);

    return $harness;
}

function accountsLogin(string $username, string $password): ApiHarness
{
    $harness = accountsHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

function accountsUserId(string $username): int
{
    return (int) accountsHarness()->app->db()->value(
        'SELECT id FROM users WHERE username = :u',
        ['u' => $username],
        0,
    );
}

test('สร้างผู้ใช้ได้รหัสผ่านสุ่มที่ต้องเปลี่ยนตอนล็อกอินแรก', static function (): void {
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $response = $harness->request('POST', '/api/v2/users', [
        'username' => 'acctnew',
        'role' => Permissions::SYSADMIN,
        'display_name' => 'ผู้ดูแลคนใหม่',
    ]);

    assertSame(201, $response->status, 'สร้างสำเร็จต้องเป็น 201');
    assertTrue(($response->headers['Location'] ?? '') !== '', '201 ต้องแนบ Location');
    assertTrue(strlen((string) $response->data('password')) >= 20, 'ต้องคืนรหัสผ่านที่สุ่มให้ครั้งเดียว');
    assertSame(true, $response->data('must_change_password'), 'ต้องบังคับเปลี่ยนตอนล็อกอินครั้งแรก');

    // คำตอบของคำสั่งไม่แนบทรัพยากรทั้งก้อนกลับมาอีกแล้ว — บทบาทที่ตั้งไว้ตรวจจาก
    // ตัวทรัพยากรเองซึ่งเป็น endpoint แบบอ่าน (Location ชี้ไปที่นั่นอยู่แล้ว)
    $created = $harness->request('GET', (string) ($response->headers['Location'] ?? ''));

    assertSame(Permissions::SYSADMIN, $created->data('role'), 'บทบาทต้องตรงกับที่ขอ');

    // และต้องสั่งหน้าจอให้แสดงรหัสที่สุ่มให้ — ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย
    assertSame(['modal'], $response->actionTypes(), 'ต้องเปิดกล่องที่ผู้ใช้กดปิดเอง ไม่ใช่ toast');

    // ข้อมูลลับต้องไม่หลุดมากับผลลัพธ์
    assertTrue(!str_contains($response->body, 'password_hash'), 'ต้องไม่มี password_hash');

    // ชื่อซ้ำต้องได้ 409 ไม่ใช่ 422 — เป็นการขัดกับสถานะปัจจุบัน ไม่ใช่ค่าผิดรูปแบบ
    $duplicate = $harness->request('POST', '/api/v2/users', ['username' => 'acctnew', 'role' => Permissions::SYSADMIN]);
    assertSame(409, $duplicate->status, 'ชื่อซ้ำต้องเป็น 409');
    assertSame(ApiProblem::Conflict->value, $duplicate->errorCode(), 'ต้องเป็นรหัส CONFLICT');
});

test('แก้บทบาทหรือสถานะของตัวเองไม่ได้ — กันล็อกตัวเองออก', static function (): void {
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');
    $selfId = accountsUserId('acctadmin');

    foreach ([['role' => Permissions::WEBADMIN], ['status' => 'disabled']] as $body) {
        $response = $harness->request('PATCH', '/api/v2/users/' . $selfId, $body);

        assertSame(403, $response->status, 'แก้บัญชีตัวเองต้องถูกปฏิเสธ');
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
    }

    $delete = $harness->request('DELETE', '/api/v2/users/' . $selfId);
    assertSame(403, $delete->status, 'ลบบัญชีตัวเองต้องถูกปฏิเสธ');

    // และบัญชีต้องยังใช้งานได้ตามปกติหลังจากพยายามทั้งหมด
    assertSame(200, $harness->request('GET', '/api/v2/me')->status, 'บัญชีต้องไม่ได้รับผลกระทบ');
});

test('ต้องเหลือผู้ดูแลระบบที่ใช้งานได้อย่างน้อยหนึ่งบัญชีเสมอ', static function (): void {
    // **ข้อสังเกตที่ได้จากการเขียนเทสต์นี้:** ผ่าน API เส้นทางนี้แทบไม่มีทางไปถึงเลย
    // เพราะคนที่จะลดบทบาทผู้ดูแลระบบได้ต้องมีสิทธิ์ user.manage ซึ่งมีแต่ superadmin
    // และถ้าเป้าหมายเป็น "คนสุดท้าย" ก็แปลว่าเป้าหมายคือตัวผู้สั่งเอง ซึ่งถูกกันไว้
    // ตั้งแต่ด่านแรกแล้ว (403) · การตรวจ wouldRemoveLastSuperadmin จึงเป็นตาข่ายชั้นสอง
    //
    // เทสต์จึงตรวจสองระดับ: ตัวกฎที่ repository (เข้าถึงได้จริงและมีความหมาย)
    // และการที่ controller เรียกใช้กฎนั้นจริงในทุกเส้นทางที่ลดจำนวนผู้ดูแลได้
    $db = migratedDb();
    $users = new \Phpcp\Domain\UserRepository($db);

    $onlyAdmin = $users->create('solo', 'Only-Admin-Password-99', Permissions::SUPERADMIN, 'คนเดียว');
    $helper = $users->create('helper', 'Helper-Password-88', Permissions::SYSADMIN, 'ผู้ช่วย');

    assertTrue($users->wouldRemoveLastSuperadmin($onlyAdmin), 'ลดบทบาทผู้ดูแลคนสุดท้ายต้องถูกจับได้');
    assertTrue(!$users->wouldRemoveLastSuperadmin($helper), 'บัญชีที่ไม่ใช่ผู้ดูแลระบบต้องไม่ติดกฎนี้');

    $users->setRole($helper, Permissions::SUPERADMIN);
    assertTrue(
        !$users->wouldRemoveLastSuperadmin($onlyAdmin),
        'พอมีผู้ดูแลระบบสองคนแล้ว การลดบทบาทคนหนึ่งต้องทำได้',
    );

    // และ controller ต้องเรียกกฎนี้ในทุกเส้นทางที่ทำให้จำนวนผู้ดูแลลดลง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');

    assertSame(
        3,
        substr_count($source, 'wouldRemoveLastSuperadmin'),
        'ต้องตรวจครบทั้งสามทาง: ลดบทบาท · ระงับบัญชี · ลบบัญชี',
    );
});

test('เปลี่ยนบทบาทแล้ว session เดิมของคนนั้นถูกตัดทิ้ง', static function (): void {
    // สิทธิ์เปลี่ยนแล้วแต่ session เดิมยังถือของเก่าอยู่ = ยกระดับสิทธิ์ค้างไว้จนกว่าจะหมดอายุ
    $harness = accountsHarness();
    $targetId = accountsUserId('acctweb');

    // ให้เป้าหมายล็อกอินค้างไว้ก่อน
    accountsLogin('acctweb', 'Accounts-Web-Pass-44');
    $before = (int) $harness->app->db()->value(
        'SELECT count(*) FROM sessions WHERE user_id = :u',
        ['u' => $targetId],
        0,
    );
    assertTrue($before > 0, 'ต้องมี session ค้างอยู่ก่อนเปลี่ยนบทบาท');

    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');
    $response = $harness->request('PATCH', '/api/v2/users/' . $targetId, ['role' => Permissions::SYSADMIN]);

    assertSame(200, $response->status, 'เปลี่ยนบทบาทต้องสำเร็จ');
    assertTrue($response->data('sessions_revoked') > 0, 'ต้องรายงานจำนวน session ที่ถูกตัด');
    assertSame(
        0,
        (int) $harness->app->db()->value('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $targetId], 0),
        'session เดิมต้องถูกตัดทิ้งทั้งหมด',
    );

    $harness->request('PATCH', '/api/v2/users/' . $targetId, ['role' => Permissions::WEBADMIN]);
});

test('ปิด 2FA ให้คนอื่นได้ แต่ไม่มีทางเปิดแทน', static function (): void {
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');
    $targetId = accountsUserId('acctweb');

    // คำสั่งตอบ 200 พร้อม `actions` — 204 ไม่มีเนื้อคำตอบ หน้าจอจึงไม่รู้ว่าต้องทำอะไรต่อ
    $disabled = $harness->request('DELETE', '/api/v2/users/' . $targetId . '/two-factor');

    assertSame(200, $disabled->status, 'ปิด 2FA ต้องได้ 200 พร้อมคำสั่งหน้าจอ');
    assertSame(['notification', 'redirect'], $disabled->actionTypes(), 'ต้องแจ้งผลแล้วโหลดตารางใหม่');

    // ไม่มีเส้นทางเปิด 2FA แทนคนอื่น — ถ้ามี ผู้ดูแลจะถือ secret ของคนอื่นได้
    // ซึ่งทำลายความหมายของ 2FA ทั้งหมด
    foreach (['POST', 'PUT', 'PATCH'] as $method) {
        $response = $harness->request($method, '/api/v2/users/' . $targetId . '/two-factor');

        assertTrue(
            in_array($response->status, [404, 405], true),
            "{$method} /users/{id}/two-factor ต้องไม่มีอยู่จริง แต่ได้ {$response->status}",
        );
    }
});

test('ลูกค้าเดินผ่าน capability — ชั้นเว็บไม่เรียก repository เอง', static function (): void {
    // เฟส A2 เดินสายไว้แล้ว · ถ้าใครกลับไปเรียก repository ตรง ๆ กฎโควตากับ audit
    // จะแยกเป็นสองชุดอีกครั้งเหมือนก่อนเฟส A2
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');

    foreach (['->createHostingAccount(', '->updateQuota('] as $forbidden) {
        assertTrue(
            !str_contains($source, $forbidden),
            "UsersController ต้องไม่เรียก {$forbidden} เอง — ต้องสั่งผ่าน agent",
        );
    }

    foreach (['customer.create', 'customer.quota_update', 'customer.site_attach'] as $capability) {
        assertTrue(str_contains($source, "'{$capability}'"), "ต้องเรียก capability {$capability}");
    }

    // และเมื่อ agent ไม่ทำงาน เส้นทางเหล่านั้นต้องได้ 503 ไม่ใช่ทำงานสำเร็จ
    // (ซึ่งจะแปลว่ามันไม่ได้ผ่าน agent จริง)
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $created = $harness->request('POST', '/api/v2/users', [
        'username' => 'shouldnotexist',
        'role' => 'webadmin',
        'email' => 'x@example.com',
    ]);

    assertSame(503, $created->status, 'สร้างบัญชีโฮสติ้งต้องไปถึง agent (503 เมื่อ agent ไม่ทำงาน)');
    assertSame(
        0,
        (int) $harness->app->db()->value("SELECT count(*) FROM users WHERE username = 'shouldnotexist'", [], 0),
        'ต้องไม่มีบัญชีถูกสร้างขึ้นเมื่อ agent ไม่ทำงาน',
    );

    // ส่วนบัญชีผู้ดูแลไม่มีโควตาให้ตรวจ จึงสร้างที่ชั้นเว็บได้โดยไม่ต้องผ่าน agent
    $admin = $harness->request('POST', '/api/v2/users', ['username' => 'plainadmin', 'role' => 'sysadmin']);
    assertSame(201, $admin->status, 'บัญชีผู้ดูแลสร้างได้โดยไม่ต้องมี agent');
});

test('ลบผู้ใช้ที่ยังเป็นเจ้าของเว็บไซต์อยู่ไม่ได้', static function (): void {
    // เว็บไร้เจ้าของคือเว็บที่ยังรันอยู่บนเครื่องแต่ไม่มีใครรับผิดชอบและไม่ถูกนับเข้าโควตาใคร
    // ฐานข้อมูลจึงกันไว้ด้วย trigger และชั้นเว็บตอบ 409 พร้อมบอกว่าต้องทำอะไรต่อ
    $harness = accountsHarness();
    $db = $harness->app->db();
    $now = time();

    $userId = $harness->createHostingUser('acctcust', 'Accounts-Cust-Pass-55', Permissions::WEBADMIN);

    $siteId = $db->insert('sites', [
        'name' => 'เว็บของลูกค้าทดสอบ',
        'primary_domain' => 'acct.example.com',
        'docroot' => '/srv/phpcp/sites/acct.example.com/public',
        'php_version' => '8.4',
        'owner_user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $blocked = $harness->request('DELETE', '/api/v2/users/' . $userId);
    assertSame(409, $blocked->status, 'ลบผู้ใช้ที่มีเว็บไซต์ต้องถูกปฏิเสธ');
    assertSame(ApiProblem::Conflict->value, $blocked->errorCode(), 'ต้องเป็นรหัส CONFLICT');

    // ถอนเว็บออกจากลูกค้าแล้วต้องลบได้ · เว็บต้องยังมีเจ้าของอยู่เสมอ ไม่กลายเป็นเว็บกำพร้า
    $detach = $harness->request('DELETE', "/api/v2/users/{$userId}/sites/{$siteId}");
    assertSame(200, $detach->status, 'ถอนเว็บออกจากบัญชีต้องได้ 200 พร้อมบอกเจ้าของใหม่');

    $newOwner = $db->value('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);
    assertTrue($newOwner !== null, 'เว็บต้องไม่เหลือสภาพไร้เจ้าของ');
    assertSame(
        accountsUserId('acctadmin'),
        (int) $newOwner,
        'เจ้าของใหม่ต้องเป็นผู้ดูแลที่สั่งถอน — จะได้รู้ว่าใครรับผิดชอบเว็บนี้ต่อ',
    );

    // ตอบ 200 พร้อม `actions` ไม่ใช่ 204 — 204 ไม่มีเนื้อคำตอบ หน้าจอจึงไม่รู้ว่าต้อง
    // โหลดตารางใหม่ แล้วแถวที่ลบไปแล้วจะค้างอยู่จนกว่าผู้ใช้จะรีเฟรชเอง
    $deleted = $harness->request('DELETE', '/api/v2/users/' . $userId);

    assertSame(200, $deleted->status, 'ลบผู้ใช้ได้แล้ว');
    assertSame(
        ['notification', 'redirect'],
        array_column($deleted->json['actions'] ?? [], 'type'),
        'ต้องสั่งหน้าจอแจ้งผลแล้วโหลดตารางใหม่',
    );
});

test('sysadmin จัดการลูกค้าได้ แต่แตะบัญชีผู้ดูแลไม่ได้', static function (): void {
    // การยุบ customers เข้า users ทำให้เส้นทางเดียวกันใช้จัดการได้ทั้งลูกค้าและผู้ดูแล
    // ถ้าไม่กัน sysadmin (ซึ่งมี customer.manage แต่ไม่มี user.manage) จะรีเซ็ตรหัสผ่าน
    // ของ superadmin ผ่านเส้นทางนี้ได้ทันที = ยกระดับสิทธิ์เต็มรูปแบบ
    $harness = accountsHarness();
    $victimId = $harness->createUser('acctvictim', 'Accounts-Victim-Pass-66', Permissions::WEBADMIN);

    $harness = accountsLogin('acctsys', 'Accounts-Sys-Pass-33');
    $adminId = accountsUserId('acctadmin');

    // สิ่งที่ sysadmin ต้องทำได้: จัดการบัญชีลูกค้า
    assertSame(
        200,
        $harness->request('PATCH', '/api/v2/users/' . $victimId, ['service_status' => 'suspended'])->status,
        'sysadmin ต้องระงับบริการของลูกค้าได้',
    );

    // สิ่งที่ sysadmin ต้องทำไม่ได้: ทุกอย่างที่แตะบัญชีผู้ดูแล
    $forbidden = [
        ['PATCH', '/api/v2/users/' . $adminId, ['status' => 'disabled']],
        ['POST', '/api/v2/users/' . $adminId . '/password-reset', []],
        ['DELETE', '/api/v2/users/' . $adminId . '/two-factor', []],
        ['DELETE', '/api/v2/users/' . $adminId, []],
        ['POST', '/api/v2/users', ['username' => 'sneakyadmin', 'role' => 'superadmin']],
        ['PATCH', '/api/v2/users/' . $victimId, ['role' => 'sysadmin']],
    ];

    foreach ($forbidden as [$method, $path, $body]) {
        $response = $harness->request($method, $path, $body);

        assertSame(403, $response->status, "{$method} {$path} ต้องถูกปฏิเสธสำหรับ sysadmin");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), "{$method} {$path} ต้องเป็น FORBIDDEN");
    }

    // และต้องไม่มีอะไรเปลี่ยนจริงในฐานข้อมูล
    $db = accountsHarness()->app->db();
    assertSame('active', (string) $db->value('SELECT status FROM users WHERE id = :id', ['id' => $adminId]), 'บัญชีผู้ดูแลต้องไม่ถูกแตะ');
    assertSame('webadmin', (string) $db->value('SELECT role FROM users WHERE id = :id', ['id' => $victimId]), 'บทบาทของลูกค้าต้องไม่ถูกเลื่อน');
});

test('ค่าตั้งที่เป็นความลับไม่หลุดออกมาทางคำตอบ', static function (): void {
    $harness = accountsHarness();

    // ตั้ง token ปลอมไว้ในฐานข้อมูลแล้วดูว่า API ส่งอะไรกลับมา
    $harness->app->db()->run(
        "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('notify.telegram.token', :v, :t)",
        ['v' => '123456:ความลับที่ห้ามหลุด', 't' => time()],
    );

    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');
    $response = $harness->request('GET', '/api/v2/settings');

    // agent ไม่ทำงานในเทสต์ จึงได้ 503 — ตรวจที่ตัวปิดบังโดยตรงแทน เพราะสิ่งที่ต้อง
    // รับประกันคือ "ค่าถูกปิดบังก่อนออกจาก capability" ไม่ใช่พฤติกรรมของ HTTP
    assertSame(503, $response->status, 'settings.get ต้องผ่าน agent');

    $masked = \Phpcp\Domain\SettingsRepository::mask(['notify.telegram.token' => '123456:ความลับที่ห้ามหลุด']);
    assertSame('********', $masked['notify.telegram.token'], 'token ต้องถูกปิดบังก่อนส่งออก');

    $empty = \Phpcp\Domain\SettingsRepository::mask(['notify.telegram.token' => '']);
    assertSame('', $empty['notify.telegram.token'], 'ค่าที่ว่างต้องยังว่าง — หน้าจอต้องแยกออกว่ายังไม่ได้ตั้ง');
});

test('ผู้ดูแลเว็บไซต์แตะ users และ settings ไม่ได้เลย', static function (): void {
    $harness = accountsLogin('acctweb', 'Accounts-Web-Pass-44');

    $cases = [
        ['GET', '/api/v2/users', []],
        ['POST', '/api/v2/users', ['username' => 'evil', 'role' => 'superadmin']],
        ['PATCH', '/api/v2/users/1', ['role' => 'superadmin']],
        ['DELETE', '/api/v2/users/1', []],
        ['POST', '/api/v2/users/1/password-reset', []],
        ['DELETE', '/api/v2/users/1/two-factor', []],
        ['PUT', '/api/v2/users/1/quota', ['quota_domains' => -1]],
        ['POST', '/api/v2/users/1/sites', ['site_ids' => [1]]],
        ['DELETE', '/api/v2/users/1/sites/1', []],
        ['GET', '/api/v2/settings', []],
        ['PATCH', '/api/v2/settings', ['notify.telegram.enabled' => '1']],
        ['POST', '/api/v2/settings/mail-test', ['to' => 'x@example.com']],
    ];

    foreach ($cases as [$method, $path, $body]) {
        $response = $harness->request($method, $path, $body);

        assertSame(403, $response->status, "{$method} {$path} ต้องได้ 403 สำหรับ webadmin");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), "{$method} {$path} ต้องเป็น FORBIDDEN");
    }
});

test('sysadmin ดูผู้ใช้ได้แต่จัดการไม่ได้', static function (): void {
    // ตาม Permissions::forRole: sysadmin มี user.view แต่ไม่มี user.manage
    // — ผู้ดูแลเซิร์ฟเวอร์ต้องไม่ตั้งบทบาทให้ตัวเองเป็น superadmin ได้
    $harness = accountsLogin('acctsys', 'Accounts-Sys-Pass-33');

    assertSame(200, $harness->request('GET', '/api/v2/users')->status, 'sysadmin ต้องดูรายการผู้ใช้ได้');

    $create = $harness->request('POST', '/api/v2/users', ['username' => 'sneaky', 'role' => 'superadmin']);
    assertSame(403, $create->status, 'sysadmin ต้องสร้างบัญชีผู้ดูแลไม่ได้');

    $promote = $harness->request('PATCH', '/api/v2/users/' . accountsUserId('acctsys'), ['role' => 'superadmin']);
    assertSame(403, $promote->status, 'sysadmin ต้องเลื่อนบทบาทตัวเองไม่ได้');
});

test('ทุกเส้นทางของ B3.5 ตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $cases = [
        ['GET', '/api/v2/users', []],
        ['PATCH', '/api/v2/users/999999', ['role' => 'sysadmin']],
        ['DELETE', '/api/v2/users/999999', []],
        ['POST', '/api/v2/users/999999/password-reset', []],
        ['DELETE', '/api/v2/users/999999/two-factor', []],
        ['GET', '/api/v2/users/999999', []],
        ['PUT', '/api/v2/users/999999/quota', ['quota_domains' => 5]],
        ['POST', '/api/v2/users/999999/sites', ['site_ids' => [1]]],
        ['DELETE', '/api/v2/users/999999/sites/1', []],
        ['GET', '/api/v2/settings', []],
        ['PATCH', '/api/v2/settings', ['notify.telegram.enabled' => true]],
        ['POST', '/api/v2/settings/notification-test', []],
        ['POST', '/api/v2/settings/mail-config', ['hostname' => 'mail.example.com']],
        ['POST', '/api/v2/settings/mail-test', ['to' => 'x@example.com']],
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

test('บัญชีที่ไม่มีอีเมลต้องแก้ชื่อที่แสดงได้', static function (): void {
    // เดิม `UserRepository::updateProfile()` ตรวจรูปแบบอีเมลกับค่าว่างด้วย และ
    // `UsersController::update()` ส่งอีเมลเดิมกลับเข้ามาเป็นค่าตั้งต้นเมื่อผู้เรียกไม่ได้ส่งมา
    // ผลคือบัญชีที่ไม่มีอีเมล (เช่นที่สร้างจาก `phpcp user:create`) แก้ชื่อไม่ได้เลย
    // ตอบ 422 "อีเมลไม่ถูกต้อง" ทั้งที่ผู้ใช้ไม่ได้แตะช่องอีเมลเลย
    $harness = accountsHarness();
    $users = new Phpcp\Domain\UserRepository($harness->app->db());

    $id = $harness->createUser('noemail', 'No-Email-Pass-99', Phpcp\Security\Permissions::WEBADMIN);

    assertSame('', (string) ($users->find($id)['email'] ?? 'x'), 'บัญชีทดสอบต้องไม่มีอีเมล');

    $users->updateProfile($id, 'ชื่อใหม่', '');

    assertSame('ชื่อใหม่', (string) $users->find($id)['display_name'], 'ต้องแก้ชื่อได้แม้ไม่มีอีเมล');

    assertRejects(
        InvalidArgumentException::class,
        static fn () => $users->updateProfile($id, 'x', 'ไม่ใช่อีเมล'),
        'อีเมลที่ผิดรูปแบบยังต้องถูกปฏิเสธเหมือนเดิม',
    );
});
