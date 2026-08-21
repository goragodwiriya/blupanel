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

    // เปิด Domain Pointer ไว้ในชุดนี้ — ค่าเริ่มต้นคือรายการว่าง (ฟีเจอร์ปิด) ซึ่งจะทำให้
    // เทสต์ของช่อง Document Root ในหน้าสร้างผู้ใช้ผ่านโดยไม่ได้ตรวจอะไรเลย
    $harness = ApiHarness::boot(['sites' => ['pointer_roots' => ['/srv/pointer-test']]]);
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

test('หน้าสร้างผู้ใช้ต้องเสนอโฟลเดอร์แม่ให้เลือกเหมือนหน้าสร้างเว็บ', static function (): void {
    /*
     * **สองช่องนี้เคยมีอยู่แต่ใน site-create.html เท่านั้น**
     *
     * ฟอร์มสร้างผู้ใช้สร้างเว็บแรกให้ในคำขอเดียวกันอยู่แล้ว แต่บอกไม่ได้ว่าไฟล์ของเว็บนั้น
     * อยู่ที่ไหน · เครื่องที่เก็บโปรเจกต์ไว้นอกบ้านของผู้ใช้ (เครื่องพัฒนาทุกเครื่อง)
     * จึงต้องสร้างบัญชีก่อน แล้วไปสร้างเว็บซ้ำอีกหน้าหนึ่ง
     */
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/users/0');

    assertSame(200, $response->status, 'id=0 คือค่าเริ่มต้นของฟอร์ม ไม่ใช่ผู้ใช้ที่ไม่มีอยู่');
    assertSame(true, $response->data('data')['has_pointer_roots'] ?? null, 'ชุดนี้ตั้ง pointer_roots ไว้ ต้องได้ช่องโฟลเดอร์แม่');
    assertSame(
        [['value' => '/srv/pointer-test', 'text' => '/srv/pointer-test']],
        $response->data('options')['pointer_root'] ?? null,
        'ตัวเลือกโฟลเดอร์แม่ต้องมาจาก sites.pointer_roots ตรง ๆ',
    );
});

test('Document Root ที่ผิดต้องถูกปฏิเสธก่อนบัญชีจะถูกสร้าง', static function (): void {
    /*
     * ลำดับสำคัญกว่าตัวข้อความ · ขั้นตอนหลังจากสร้างบัญชีแล้ว (เว็บ ใบรับรอง ฐานข้อมูล
     * อีเมล) ตั้งใจให้ล้มได้โดยไม่ล้มทั้งคำขอ เพราะบัญชีเป็นของจริงไปแล้ว — แต่พาธที่
     * พิมพ์ผิดตั้งแต่ต้นไม่ใช่ "สำเร็จครึ่งหนึ่ง" มันคือ typo และต้องเด้งกลับเป็น 422
     * ตอนที่ชื่อผู้ใช้ยังว่างอยู่ และรหัสผ่านที่สุ่มมายังไม่ถูกใช้ทิ้ง
     */
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $outside = $harness->request('POST', '/api/v2/users', [
        'username' => 'acctpointer',
        'email' => 'pointer@example.com',
        'password' => 'Accounts-Pointer-Pass-99',
        'domain' => 'pointer.example.com',
        'docroot' => '/etc',
    ]);

    assertSame(422, $outside->status, 'พาธนอกขอบเขตที่ตั้งไว้ต้องได้ 422');
    assertSame(ApiProblem::ValidationError->value, $outside->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertSame(0, accountsUserId('acctpointer'), 'ต้องยังไม่มีบัญชีไหนถูกสร้าง');

    // Document Root โดยไม่มีโดเมน = ไม่มีอะไรจะเสิร์ฟโฟลเดอร์นั้น — บอกไปตรง ๆ
    // ดีกว่าสร้างบัญชีแล้วทิ้งค่าที่พิมพ์มาอย่างเงียบ ๆ
    $noDomain = $harness->request('POST', '/api/v2/users', [
        'username' => 'acctpointer',
        'email' => 'pointer@example.com',
        'password' => 'Accounts-Pointer-Pass-99',
        'docroot' => 'my-project',
    ]);

    assertSame(422, $noDomain->status, 'Document Root ที่ไม่มีโดเมนคู่กันต้องได้ 422');
    assertSame(0, accountsUserId('acctpointer'), 'ต้องยังไม่มีบัญชีไหนถูกสร้าง');
});

test('สร้างผู้ใช้ได้รหัสผ่านสุ่มที่ต้องเปลี่ยนตอนล็อกอินแรก', static function (): void {
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $response = $harness->request('POST', '/api/v2/users', [
        'username' => 'acctnew',
        'role' => Permissions::SYSADMIN,
    ]);

    assertSame(201, $response->status, 'สร้างสำเร็จต้องเป็น 201');
    assertTrue(($response->headers['Location'] ?? '') !== '', '201 ต้องแนบ Location');
    assertTrue(strlen((string) $response->data('password')) >= 20, 'ต้องคืนรหัสผ่านที่สุ่มให้ครั้งเดียว');
    assertSame(true, $response->data('must_change_password'), 'ต้องบังคับเปลี่ยนตอนล็อกอินครั้งแรก');

    // คำตอบของคำสั่งไม่แนบทรัพยากรทั้งก้อนกลับมาอีกแล้ว — บทบาทที่ตั้งไว้ตรวจจาก
    // ตัวทรัพยากรเองซึ่งเป็น endpoint แบบอ่าน (Location ชี้ไปที่นั่นอยู่แล้ว)
    $created = $harness->request('GET', (string) ($response->headers['Location'] ?? ''));

    assertSame(Permissions::SYSADMIN, $created->data('role'), 'บทบาทต้องตรงกับที่ขอ');

    /*
     * และต้องสั่งหน้าจอให้แสดงรหัสที่สุ่มให้ — ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย
     *
     * ลำดับเดียวกับทุกหน้าในระบบ (ApiController::revealed): แจ้งผล → กล่องที่ผู้ใช้
     * กดปิดเอง → สั่งรีเฟรชหน้าจอ · **ห้ามมี modal close นำหน้ากล่อง** เพราะ
     * Modal.hide() ล้างเนื้อในตัวเอง 150ms ให้หลัง ซึ่งมาถึงหลังเนื้อหาใหม่ถูกใส่แล้ว
     */
    assertSame(
        ['notification', 'modal', 'refresh'],
        $response->actionTypes(),
        'ต้องเปิดกล่องที่ผู้ใช้กดปิดเอง ไม่ใช่ toast — และห้ามปิดหน้าต่างก่อนเปิดกล่อง',
    );

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

    $onlyAdmin = $users->create('solo', 'Only-Admin-Password-99', Permissions::SUPERADMIN);
    $helper = $users->create('helper', 'Helper-Password-88', Permissions::SYSADMIN);

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
    assertSame(['notification', 'refresh'], $disabled->actionTypes(), 'ต้องแจ้งผลแล้วโหลดตารางใหม่');

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

    /*
     * **ลบบัญชีโฮสติ้งต้องไปถึง agent เหมือนตอนสร้าง** — 503 เมื่อ agent ไม่ทำงาน
     *
     * เดิมเป็น `DELETE FROM users` ที่ชั้นเว็บล้วน ๆ ไม่แตะ agent เลย · ทุกอย่างที่
     * บัญชีนั้นถือครองบนเครื่องจริงจึงอยู่ต่อทั้งหมด — บัญชี Linux บ้านพร้อมไฟล์
     * ฐานข้อมูล และบัญชี phpMyAdmin — โดยไม่มีอะไรบอก และไม่มีอะไรมาเก็บกวาดทีหลัง
     *
     * ที่อันตรายกว่าความรกคือ **สร้างชื่อเดิมซ้ำแล้วรับช่วงของพวกนั้นทั้งชุด**
     * เพราะบัญชี Linux ที่ตกค้างยังมีลายเซ็นของ panel อยู่ ลูกค้าคนใหม่จึงเข้า SFTP
     * ไปเจอไฟล์ของคนเก่าได้ทันทีโดยหน้าจอรายงานว่าสำเร็จ
     *
     * ถ้าเทสต์นี้กลับไปเป็น 200 อีกเมื่อไหร่ แปลว่ามีคนย้ายมันกลับมาไว้ที่ชั้นเว็บ
     */
    $deleted = $harness->request('DELETE', '/api/v2/users/' . $userId, ['confirm_username' => 'acctcust']);

    assertSame(503, $deleted->status, 'ลบบัญชีโฮสติ้งต้องไปถึง agent');
    assertSame(
        1,
        (int) $db->value('SELECT count(*) FROM users WHERE id = :id', ['id' => $userId], 0),
        'agent ไม่ทำงาน = ต้องไม่มีบัญชีไหนหายไป',
    );

    // บัญชีผู้ดูแลไม่ได้ถือครองอะไรบนเครื่องเลย (บัญชีระบบสร้างให้เฉพาะบัญชีโฮสติ้ง)
    // จึงยังลบที่ชั้นเว็บได้ตามเดิม · ตอบ 200 พร้อม `actions` ไม่ใช่ 204 — 204 ไม่มี
    // เนื้อคำตอบ หน้าจอจึงไม่รู้ว่าต้องโหลดตารางใหม่ แถวที่ลบไปแล้วจะค้างอยู่
    $adminId = $harness->createUser('acctgoner', 'Accounts-Goner-Pass-77', Permissions::SYSADMIN);
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    $deletedAdmin = $harness->request('DELETE', '/api/v2/users/' . $adminId);

    assertSame(200, $deletedAdmin->status, 'ลบบัญชีผู้ดูแลได้โดยไม่ต้องมี agent');

    // ปิด Modal มาก่อนเสมอ — คำสั่งลบเข้ามาได้ทางเดียวคือฟอร์มใน Modal ที่
    // `GET /users/{id}/delete/form` เปิดไว้ (ต้องพิมพ์ชื่อบัญชียืนยันถึงจะส่งได้)
    // ถ้าไม่สั่งปิด ฟอร์มจะค้างบนจอถามลบแถวที่หายไปแล้วซ้ำอีกรอบ
    // และตารางที่โหลดใหม่ก็อยู่ใต้ Modal ซึ่งมองไม่เห็นอยู่ดี
    assertSame(
        ['modal', 'notification', 'refresh'],
        array_column($deletedAdmin->json['actions'] ?? [], 'type'),
        'ต้องปิด Modal แจ้งผล แล้วโหลดตารางใหม่',
    );
    assertSame(
        'close',
        $deletedAdmin->json['actions'][0]['action'] ?? '',
        'คำสั่ง modal ตัวแรกต้องเป็นการปิด',
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

test('บัญชีที่ไม่มีอีเมลต้องตั้งอีเมลได้ — ค่าว่างไม่ใช่ค่าที่ผิด', static function (): void {
    // เดิม `updateProfile()` ตรวจรูปแบบอีเมลกับค่าว่างด้วย และ `update()` ส่งอีเมลเดิม
    // กลับเข้ามาเป็นค่าตั้งต้นเมื่อผู้เรียกไม่ได้ส่งมา · บัญชีที่ไม่มีอีเมล (เช่นที่สร้าง
    // จาก `phpcp user:create`) จึงแก้อะไรไม่ได้เลย ตอบ 422 "อีเมลไม่ถูกต้อง"
    // ทั้งที่ผู้ใช้ไม่ได้แตะช่องอีเมล
    $harness = accountsHarness();
    $users = new Phpcp\Domain\UserRepository($harness->app->db());

    $id = $harness->createUser('noemail', 'No-Email-Pass-99', Phpcp\Security\Permissions::WEBADMIN);

    assertSame('', (string) ($users->find($id)['email'] ?? 'x'), 'บัญชีทดสอบต้องไม่มีอีเมล');

    $users->updateProfile($id, 'set@example.com');

    assertSame('set@example.com', (string) $users->find($id)['email'], 'ต้องตั้งอีเมลได้');
});

test('บันทึกบัญชีแล้วต้องได้ทรัพยากรครบเหมือนตอน GET — ไม่ใช่แค่ที่เปลี่ยน', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** ส่วน SFTP ในหน้าผู้ใช้ "บางทีแสดง บางทีไม่แสดง"
     *
     * หน้าจอผูกข้อมูลทั้งหน้าไว้กับ endpoint นี้ คำตอบของการบันทึกจึงไปทับข้อมูลที่ผูกอยู่ ·
     * เดิม PATCH ตอบแค่ `{user_id, sessions_revoked, ...ที่เปลี่ยน}` ซึ่งไม่มี `sftp_enabled`
     * และไม่มี `sites` — สองคีย์ที่ส่วนนั้นใช้เป็นเงื่อนไขแสดง · กดบันทึกชื่อหรืออีเมล
     * แล้วส่วน SFTP หายทั้งส่วนทันที กลับมาเมื่อโหลดหน้าใหม่ ดูเหมือนระบบไม่เสถียร
     *
     * แก้ที่คำตอบไม่ใช่ที่เทมเพลต เพราะสัญญาของ PATCH คือ "นี่คือทรัพยากรหลังแก้" —
     * ผู้เรียกอื่น (curl, สคริปต์) ก็ควรได้ของครบเหมือนกัน ไม่ใช่ต้องเรียกซ้ำอีกรอบ
     */
    $harness = accountsLogin('acctadmin', 'Accounts-Admin-Pass-11');

    // สร้างตรงในฐานข้อมูล — `customer.create` เดินผ่าน agent ซึ่งไม่มีในชุดทดสอบ API
    // และเรื่องที่กำลังตรวจคือ**รูปร่างของคำตอบ** ไม่ใช่เส้นทางการสร้าง
    $id = $harness->app->db()->insert('users', [
        'username' => 'shapecheck',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $get = $harness->request('GET', '/api/v2/users/' . $id);
    assertSame(200, $get->status, 'อ่านบัญชีต้องสำเร็จ');

    $patch = $harness->request('PATCH', '/api/v2/users/' . $id, ['email' => 'changed@example.com']);
    assertSame(200, $patch->status, 'บันทึกต้องสำเร็จ');

    // คีย์ที่หน้าจอใช้เป็นเงื่อนไขแสดงผล ต้องมีครบทั้งสองทาง
    foreach (['sftp_enabled', 'sftp_available', 'sites', 'quota'] as $key) {
        assertTrue($get->data($key) !== null, "GET ต้องส่ง {$key}");
        assertTrue(
            $patch->data($key) !== null,
            "PATCH ต้องส่ง {$key} ด้วย ไม่งั้นหน้าจอที่ผูกข้อมูลไว้จะเสียส่วนนั้นไปหลังกดบันทึก",
        );
    }

    assertSame('changed@example.com', $patch->data('email'), 'และต้องเป็นค่าหลังแก้จริง');
});
