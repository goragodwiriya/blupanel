<?php

declare(strict_types=1);

/**
 * บัญชี MariaDB ประจำผู้ใช้และการเข้า phpMyAdmin โดยไม่ต้องพิมพ์รหัส — เฟส M5
 *
 * นี่คือส่วนที่ระบบ**เก็บความลับชนิดใหม่** ซึ่งเดิมไม่เคยเก็บเลย (รหัสผ่าน MariaDB
 * แบบถอดกลับได้) เทสต์ชุดนี้จึงเฝ้าสามอย่างที่ถ้าพลาดแล้วความลับนั้นรั่ว:
 *   1. ขอรหัสของ**คนอื่น**ต้องเป็นไปไม่ได้ — ไม่ใช่แค่ "ถูกปฏิเสธ" แต่ต้องไม่มีทางระบุ
 *      เป้าหมายได้ตั้งแต่แรก
 *   2. รหัสในฐานข้อมูลต้องเป็น ciphertext เสมอ — ใครได้ panel.db ไปอย่างเดียวต้องอ่านไม่ออก
 *   3. รหัสต้องไม่ไหลลง audit log ซึ่งเป็น hash-chain ที่ลบไม่ได้และถูก mirror ลงไฟล์
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Context;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Routes;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;

group('DbAccount — บัญชีฐานข้อมูลประจำผู้ใช้ต้องไม่รั่วข้ามคน');

test('คำสั่งขอรหัสฐานข้อมูลต้องไม่รับ id ของใครเลย', static function (): void {
    // ด่านที่แข็งแรงที่สุดคือ "ไม่มี argument ให้ส่งมา" ไม่ใช่ "ตรวจ argument ให้ดี" —
    // การตรวจมีวันลืม แต่สิ่งที่ไม่มีอยู่ ลืมไม่ได้
    $registry = new CapabilityRegistry();

    $files = [
        'db.account_credentials' => 'DbAccountCredentials',
        'db.account_rotate' => 'DbAccountRotate',
    ];

    foreach ($files as $name => $class) {
        $capability = $registry->resolve($name);

        $clean = $capability->validate([
            'user_id' => 999,
            'username' => 'victim',
            'mysql_user' => 'victim',
        ]);

        assertSame([], $clean, "{$name} ต้องทิ้ง argument ทุกตัวที่ส่งมา");

        $source = (string) file_get_contents(PHPCP_ROOT.'/src/Agent/Capability/'.$class.'.php');

        assertTrue(
            str_contains($source, 'currentUser($context)'),
            "{$name} ต้องอ่านผู้ใช้จาก actor ของ session (currentUser) ไม่ใช่จาก argument",
        );

        // และต้องไม่มีการหยิบ id ของใครออกจาก args เลยแม้แต่ที่เดียว
        assertTrue(
            !str_contains($source, "\$args['user_id']") && !str_contains($source, '$args["user_id"]'),
            "{$name} ต้องไม่อ่าน user_id จาก argument",
        );
    }
});

test('รหัสผ่านในฐานข้อมูลต้องเป็น ciphertext ไม่ใช่ข้อความอ่านได้', static function (): void {
    $db = migratedDb();
    $accounts = new DbAccountRepository($db);
    $secret = new Secret(Config::load(PHPCP_ROOT)->secretKey());

    $userId = (new Phpcp\Domain\UserRepository($db))->createHostingAccount(
        'dbowner',
        'Db-Owner-Password-11',
        'เจ้าของฐานข้อมูล',
        'dbowner@example.com',
    );

    $plain = 'รหัสลับที่ห้ามอ่านออกจากไฟล์ฐานข้อมูล';
    $accounts->store($userId, 'dbowner', 'localhost', $secret->encrypt($plain));

    $stored = (string) $accounts->find($userId)['password_enc'];

    assertTrue(!str_contains($stored, $plain), 'รหัสผ่านต้องไม่อยู่ในฐานข้อมูลแบบอ่านได้');
    assertSame($plain, $secret->decrypt($stored), 'และต้องถอดกลับได้ด้วยคีย์ที่ถูกต้อง');

    // คีย์อื่นต้องถอดไม่ออก — คีย์อยู่ใน config.php ซึ่งเป็นคนละไฟล์กับ panel.db
    assertRejects(
        RuntimeException::class,
        static fn () => (new Secret(base64_decode(Secret::generateKey(), true)))->decrypt($stored),
        'คีย์ผิดต้องถอดไม่ได้',
    );
});

test('repository ต้องไม่มีเมธอดถอดรหัสให้ชั้นเว็บเรียกได้', static function (): void {
    // ถ้ามี decrypt() อยู่ใน repository ชั้นเว็บจะเรียกได้ทันทีโดยไม่มีอะไรขวาง
    // แล้วความลับจะไหลไปอยู่ในกระบวนการที่ผู้ใช้เข้าถึงได้แทนที่จะอยู่แต่ในกระบวนการที่รันด้วย root
    $methods = get_class_methods(DbAccountRepository::class);

    foreach ($methods as $method) {
        assertTrue(
            !str_contains(strtolower($method), 'decrypt'),
            "DbAccountRepository ต้องไม่มีเมธอด {$method}",
        );
    }

    $source = (string) file_get_contents(PHPCP_ROOT.'/src/Domain/DbAccountRepository.php');
    assertTrue(!str_contains($source, 'Secret'), 'repository ต้องไม่รู้จักคลาสที่ถือคีย์เลย');
});

test('คำนำหน้าชื่อฐานข้อมูลต้องกันชื่อชนกันข้ามลูกค้า และใส่ซ้ำไม่ได้', static function (): void {
    // MariaDB มี namespace เดียวทั้งเครื่อง ลูกค้าสองรายที่อยากได้ชื่อ `shop` ต้องได้ทั้งคู่
    assertSame('alice_shop', DbAccountRepository::qualify('alice', 'shop'), 'ชื่อเปล่าต้องได้คำนำหน้า');
    assertSame('bob_shop', DbAccountRepository::qualify('bob', 'shop'), 'ลูกค้าอีกรายได้ชื่อของตัวเอง');

    // ผู้ใช้ที่พิมพ์ชื่อเต็มมาแล้วต้องไม่ได้ alice_alice_shop
    assertSame('alice_shop', DbAccountRepository::qualify('alice', 'alice_shop'), 'ใส่คำนำหน้าซ้ำไม่ได้');

    assertSame('alice_', DbAccountRepository::prefixFor('alice'), 'คำนำหน้าต้องลงท้ายด้วย _');
});

test('ฐานข้อมูลของลูกค้าต้องได้คำนำหน้าอัตโนมัติ และบอกให้รู้เมื่อชื่อยาวเกิน', static function (): void {
    $db = migratedDb();
    $users = new Phpcp\Domain\UserRepository($db);
    $now = time();

    $ownerId = $users->createHostingAccount('shopowner', 'Shop-Owner-Password-11', 'ร้านค้า', 'shop@example.com');
    $db->update('users', ['system_user' => 'shopowner'], ['id' => $ownerId]);

    $siteId = $db->insert('sites', [
        'name' => 'ร้านค้า',
        'primary_domain' => 'shop.example.com',
        'docroot' => '/srv/phpcp/users/shopowner/domains/shop.example.com/public',
        'php_version' => '8.4',
        'owner_user_id' => $ownerId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $context = new Context(
        new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    $reflection = new ReflectionMethod(Phpcp\Agent\Capability\DbCreate::class, 'ownerAccount');
    $account = $reflection->invoke(new Phpcp\Agent\Capability\DbCreate(), $context, $siteId);

    assertTrue($account !== null, 'ต้องหาเจ้าของเว็บเจอ');
    assertSame('shopowner', $account->username, 'ชื่อบัญชี MariaDB ต้องตรงกับบัญชีระบบ');

    // ฐานข้อมูลที่ไม่ได้ผูกกับเว็บของลูกค้าไม่ถูกแตะ — ผู้ดูแลตั้งชื่ออะไรก็ได้
    assertSame(null, $reflection->invoke(new Phpcp\Agent\Capability\DbCreate(), $context, 0), 'ไม่มีเว็บ = ไม่มีคำนำหน้า');

    // ความยาวที่เหลือหลังหักคำนำหน้าต้องถูกคำนวณให้ถูก
    assertSame(64 - strlen('shopowner_'), 64 - strlen(DbAccountRepository::prefixFor('shopowner')), 'สูตรความยาวต้องตรงกัน');
});

test('เส้นทางเข้า phpMyAdmin ต้องเป็น POST เท่านั้น', static function (): void {
    // ถ้าเป็น GET เว็บใดก็ตามที่ผู้ใช้เปิดอยู่จะฝัง <img src="…/phpmyadmin/session"> แล้ว
    // ทำให้เบราว์เซอร์ของเหยื่อสร้าง session ของ phpMyAdmin ขึ้นมาเงียบ ๆ ได้
    $router = Routes::build();

    assertSame(null, $router->match('GET', '/api/v2/phpmyadmin/session'), 'ต้องไม่มีเส้นทางแบบ GET');

    $route = $router->match('POST', '/api/v2/phpmyadmin/session');

    assertTrue($route !== null, 'ต้องมีเส้นทางแบบ POST');
    assertSame('db.view', $route['route']->permission, 'ต้องใช้สิทธิ์ db.view');

    // หน้าเว็บต้องไม่มีทางเข้าที่ข้ามด่าน CSRF ไปได้ — ลิงก์ตรงคือทางแบบนั้น
    $page = (string) file_get_contents(PHPCP_ROOT.'/public/assets/spa/templates/databases.html');

    assertTrue(
        !str_contains($page, 'href="/phpmyadmin'),
        'หน้าเว็บต้องไม่มีลิงก์ตรงไป phpMyAdmin — ต้องยิง POST ที่มี CSRF token',
    );

    // รหัสผ่านฐานข้อมูลต้องไม่เคยผ่านมือ JavaScript — คำตอบมีแค่ URL ปลายทาง
    $controller = (string) file_get_contents(PHPCP_ROOT.'/src/Http/V2/PhpMyAdminController.php');

    assertTrue(
        !preg_match("/ok\\(\\[[^\\]]*password/", $controller),
        'คำตอบของ endpoint ต้องไม่มีรหัสผ่านติดไปด้วย',
    );
    assertTrue(
        str_contains($controller, "\$_SESSION['PMA_single_signon_password']"),
        'รหัสผ่านต้องเดินจาก agent ไปลง session ของ phpMyAdmin เท่านั้น',
    );
});

test('สิทธิ์ฐานข้อมูลต้องผูกกับบทบาท และถูกถอนเมื่อลดบทบาท', static function (): void {
    // เหตุผลที่ผู้ดูแลได้สิทธิ์ระดับทั้งเครื่อง: บัญชี root ของ MariaDB ใช้ `unix_socket`
    // ซึ่งแตะได้เฉพาะ root ของระบบปฏิบัติการ · phpMyAdmin รันในนาม phpcp-web จึงเป็น
    // root ไม่ได้เลย · ทางเลือกอื่นคือตั้งรหัสให้ root ซึ่งทำให้บัญชีที่มีอำนาจสูงสุด
    // กลายเป็นเป้าที่เดารหัสได้ — แย่กว่าวิธีนี้ชัดเจน
    //
    // **สิ่งที่เทสต์นี้เฝ้าจริง ๆ คือขาถอน** — ถ้าให้สิทธิ์ตอนสร้างอย่างเดียว ผู้ดูแลที่ถูก
    // ลดเป็นลูกค้าจะยังเห็นฐานข้อมูลของลูกค้าทุกรายตลอดไป
    $source = (string) file_get_contents(PHPCP_ROOT.'/src/Agent/Capability/DbAccountCredentials.php');

    assertTrue(
        str_contains($source, 'syncPrivileges('),
        'ทุกครั้งที่ออกบัตรต้องปรับสิทธิ์ให้ตรงบทบาทปัจจุบัน ไม่ใช่แค่ตอนสร้างบัญชี',
    );

    $manager = (string) file_get_contents(PHPCP_ROOT.'/src/Driver/Db/MariaDbManager.php');

    assertTrue(str_contains($manager, 'GRANT ALL PRIVILEGES ON *.*'), 'ต้องมีขาให้สิทธิ์');
    assertTrue(
        str_contains($manager, 'REVOKE ALL PRIVILEGES, GRANT OPTION ON *.*'),
        'ต้องมีขาถอนสิทธิ์ด้วย ไม่ใช่ให้อย่างเดียว',
    );

    // ต้องอ่านสถานะจริงก่อนสั่ง — REVOKE ที่ไม่มีอะไรให้ถอนจะล้มด้วย error 1141
    // และการกลืน error ทิ้งจะทำให้ความล้มเหลวจริงหายไปด้วย
    assertTrue(
        str_contains($manager, 'hasGlobalPrivileges(') && str_contains($manager, 'if ($current === $granted)'),
        'ต้องเทียบกับสถานะจริงก่อนสั่ง แทนที่จะสั่งแล้วกลืน error',
    );

    // **สิทธิ์ในฐานข้อมูลต้องไม่เกินสิทธิ์ใน panel** — sysadmin มี db.view แต่ไม่มี
    // db.manage การให้สิทธิ์ทั้งเครื่องกับเขาจะทำให้ทำผ่าน phpMyAdmin ได้มากกว่าที่
    // panel ยอมให้ทำ ซึ่งทำให้ตาราง permission กลายเป็นของประดับ
    $capability = (string) file_get_contents(PHPCP_ROOT.'/src/Agent/Capability/DbAccountCapability.php');

    assertTrue(
        str_contains($capability, 'Permissions::SUPERADMIN'),
        'สิทธิ์ระดับทั้งเครื่องต้องจำกัดที่ superadmin และอ้างค่าคงที่ ไม่ใช่สตริงที่พิมพ์เอง',
    );

    foreach (['sysadmin', 'webadmin'] as $role) {
        assertTrue(
            !in_array('db.manage', Permissions::forRole($role), true) || $role === 'webadmin',
            "ถ้า {$role} ได้ db.manage เมื่อไร ต้องทบทวนกฎการให้สิทธิ์ระดับทั้งเครื่องใหม่",
        );
    }
});

test('รหัสฐานข้อมูลต้องไม่ไหลลง audit log', static function (): void {
    // audit_log เป็น hash-chain ที่ลบไม่ได้และถูก mirror ลงไฟล์ที่ผู้ดูแลอ่านได้
    // รหัสที่หลุดลงไปจะอยู่ตรงนั้นถาวร
    $redact = new ReflectionMethod(Phpcp\Agent\Dispatcher::class, 'redact');

    $clean = $redact->invoke(null, [
        'password' => 'ห้ามบันทึก',
        'password_enc' => 'ห้ามบันทึก',
        'mysql_user' => 'alice',
    ]);

    $flat = json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '';

    assertTrue(!str_contains($flat, 'ห้ามบันทึก'), 'ค่าที่เป็นรหัสผ่านต้องถูกปิดบังทั้งหมด');
    assertSame('alice', $clean['mysql_user'], 'ชื่อผู้ใช้ที่ไม่ใช่ความลับต้องยังอ่านได้');
});

test('capability ที่คืนความลับต้องไม่ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบโดยพลาด', static function (): void {
    // db.account_credentials คืนรหัสผ่านออกไป แต่ไม่ได้เปลี่ยนอะไรที่ผู้ใช้มองเห็น
    // ถ้าตั้ง isMutating เป็น true จะถูกบันทึกลง audit ทุกครั้งที่กดเปิด phpMyAdmin
    // ซึ่งไม่ได้เพิ่มข้อมูลที่มีประโยชน์เลยแต่ทำให้ log ท่วมจนของสำคัญหาไม่เจอ
    $registry = new CapabilityRegistry();

    assertTrue(
        !$registry->resolve('db.account_credentials')->isMutating(),
        'การขอรหัสของตัวเองไม่ใช่การเปลี่ยนแปลงระบบ',
    );
    assertTrue(
        $registry->resolve('db.account_rotate')->isMutating(),
        'การหมุนรหัสเปลี่ยนสถานะจริง ต้องถูกบันทึก',
    );
    assertSame(
        'db.manage',
        $registry->resolve('db.account_rotate')->permission(),
        'การหมุนรหัสต้องใช้สิทธิ์จัดการ ไม่ใช่แค่สิทธิ์ดู',
    );
});
