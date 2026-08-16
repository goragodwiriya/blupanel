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

    $ownerId = $users->createHostingAccount('shopowner', 'Shop-Owner-Password-11',  'shop@example.com');
    $db->update('users', ['system_user' => 'shopowner'], ['id' => $ownerId]);

    $siteId = $db->insert('sites', [
        'primary_domain' => 'shop.example.com',
        'docroot' => '/home/shopowner/public_html',
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
    $capability = new Phpcp\Agent\Capability\DbCreate();

    // มาจากเว็บ — ฟอร์มของหน้ารายละเอียดเว็บส่งแค่ site_id มา
    $account = $reflection->invoke($capability, $context, 0, $siteId);

    assertTrue($account !== null, 'ต้องหาเจ้าของเว็บเจอ');
    assertSame('shopowner', $account->username, 'ชื่อบัญชี MariaDB ต้องตรงกับบัญชีระบบ');

    /*
     * มาจากบัญชีโดยตรง — ฟอร์มหน้าฐานข้อมูลให้เลือก "บัญชีเจ้าของ" จากรายการ
     *
     * ต้องได้คำตอบเดียวกับตอนมาจากเว็บ ไม่งั้นฐานข้อมูลที่ลูกค้าสร้างโดยไม่ผูกกับเว็บ
     * จะไม่มีคำนำหน้าและไม่มีใครเปิดดูได้ใน phpMyAdmin
     */
    $direct = $reflection->invoke($capability, $context, $ownerId, 0);

    assertTrue($direct !== null, 'เลือกบัญชีตรง ๆ ต้องหาเจอเหมือนกัน');
    assertSame('shopowner', $direct->username, 'ต้องเป็นบัญชีเดียวกับที่เลือก');

    // ไม่ระบุทั้งสองอย่าง = ฐานข้อมูลของเครื่อง ไม่ใช่ของลูกค้า — ผู้ดูแลตั้งชื่ออะไรก็ได้
    assertSame(null, $reflection->invoke($capability, $context, 0, 0), 'ไม่มีเจ้าของ = ไม่มีคำนำหน้า');

    // ความยาวที่เหลือหลังหักคำนำหน้าต้องถูกคำนวณให้ถูก
    assertSame(64 - strlen('shopowner_'), 64 - strlen(DbAccountRepository::prefixFor('shopowner')), 'สูตรความยาวต้องตรงกัน');
});

test('ชื่อผู้ใช้ประจำฐานข้อมูลต้องคิดจากชื่อที่เติมคำนำหน้าแล้ว', static function (): void {
    /*
     * **ลูกค้าสองรายที่ตั้งชื่อฐานว่า `shop` เหมือนกันต้องไม่ไปชนผู้ใช้คนเดียวกัน**
     *
     * ของเดิมคิดชื่อผู้ใช้ปริยายจากชื่อฐาน**ก่อน**เติมคำนำหน้า ทั้งคู่จึงได้ `shop_user`
     * · รายที่สองสร้างไม่ได้พร้อมข้อความที่พูดถึงชื่อที่เขาไม่เคยพิมพ์ และถ้าวันหนึ่ง
     * `CREATE USER` ถูกเปลี่ยนเป็น `IF NOT EXISTS` มันจะกลายเป็นการยกฐานข้อมูลของ
     * ลูกค้ารายที่สองให้ผู้ใช้ของรายแรกทันที
     *
     * ชื่อที่ถูกจองไปแล้วบนเครื่องต้องได้ส่วนต่อท้ายสุ่ม ไม่ใช่ถูกใช้ซ้ำ
     */
    $capability = new Phpcp\Agent\Capability\DbCreate();
    $method = new ReflectionMethod($capability, 'dedicatedUser');
    $executor = new Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-dbuser-' . getmypid());

    $alice = $method->invoke($capability, $executor, 'alice_shop', 'localhost');
    $bob = $method->invoke($capability, $executor, 'bob_shop', 'localhost');

    assertSame('alice_shop_user', $alice, 'ชื่อผู้ใช้ต้องมาจากชื่อฐานที่เติมคำนำหน้าแล้ว');
    assertSame('bob_shop_user', $bob, 'ลูกค้าอีกรายต้องได้ชื่อของตัวเอง');
    assertTrue($alice !== $bob, 'สองรายที่ตั้งชื่อฐานเหมือนกันต้องไม่ได้ผู้ใช้คนเดียวกัน');

    // ยาวเกิน 32 ตัวอักษรต้องถูกตัด ไม่ใช่ปล่อยให้ MariaDB ปฏิเสธทีหลัง
    $long = $method->invoke($capability, $executor, str_repeat('x', 60), 'localhost');

    assertTrue(strlen($long) <= 32, 'ชื่อผู้ใช้ต้องไม่เกิน 32 ตัวอักษรตามที่ MariaDB รับได้');
});

test('ลูกค้าสร้างฐานข้อมูลให้บัญชีคนอื่นไม่ได้', static function (): void {
    /*
     * รายการบัญชีในฟอร์มถูกกรองตามสิทธิ์แล้ว แต่รายการนั้นเป็นความสะดวกของคนกรอกฟอร์ม
     * **ไม่ใช่ด่าน** · ด่านอยู่ที่นี่ ที่ซึ่งคำขอที่ถูกแก้ด้วยมือถูกปฏิเสธ
     */
    $db = migratedDb();
    $users = new Phpcp\Domain\UserRepository($db);

    $mine = $users->createHostingAccount('mineown', 'Mine-Own-Password-11', 'mine@example.com');
    $theirs = $users->createHostingAccount('theirown', 'Their-Own-Password-11', 'their@example.com');

    $db->update('users', ['system_user' => 'mineown'], ['id' => $mine]);
    $db->update('users', ['system_user' => 'theirown'], ['id' => $theirs]);

    $capability = new Phpcp\Agent\Capability\DbCreate();
    $method = new ReflectionMethod($capability, 'assertOwnerAccess');
    $owner = Phpcp\Domain\UserAccount::fromRow($db->first('SELECT * FROM users WHERE id = :id', ['id' => $theirs]));

    $customer = new Context(
        new Actor($mine, 'mineown', Permissions::WEBADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    assertRejects(
        Phpcp\Agent\PermissionDenied::class,
        static fn () => $method->invoke($capability, $customer, $owner),
        'ลูกค้าต้องเลือกบัญชีของคนอื่นไม่ได้',
    );

    // ของตัวเองต้องผ่าน — ไม่ใช่ปฏิเสธทุกกรณีแล้วเทสต์ผ่าน
    $own = Phpcp\Domain\UserAccount::fromRow($db->first('SELECT * FROM users WHERE id = :id', ['id' => $mine]));
    $method->invoke($capability, $customer, $own);
    TestRunner::$assertions++;

    // ผู้ดูแลเลือกบัญชีไหนก็ได้
    $admin = new Context(
        new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    $method->invoke($capability, $admin, $owner);
    TestRunner::$assertions++;
});

test('เว็บไซต์ที่เลือกต้องเป็นของบัญชีเจ้าของฐานข้อมูล', static function (): void {
    /*
     * ทั้งบัญชีและเว็บมาจากฟอร์มเดียวกัน จึงขัดกันเองได้ · ฐานข้อมูลที่ถูกบันทึกไว้กับ
     * เว็บของลูกค้าอีกรายจะไปโผล่ในรายการของเขา เข้ารอบสำรองของเขา และกินโควตาของเขา
     */
    $db = migratedDb();
    $users = new Phpcp\Domain\UserRepository($db);
    $now = time();

    $a = $users->createHostingAccount('ownera', 'Owner-A-Password-11', 'a@example.com');
    $b = $users->createHostingAccount('ownerb', 'Owner-B-Password-11', 'b@example.com');
    $db->update('users', ['system_user' => 'ownera'], ['id' => $a]);
    $db->update('users', ['system_user' => 'ownerb'], ['id' => $b]);

    $siteOfB = $db->insert('sites', [
        'primary_domain' => 'b.example.com', 'docroot' => '/home/ownerb/public_html',
        'php_version' => '8.4', 'owner_user_id' => $b, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $context = new Context(
        new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    $capability = new Phpcp\Agent\Capability\DbCreate();
    $method = new ReflectionMethod($capability, 'assertSiteBelongsTo');
    $accountA = Phpcp\Domain\UserAccount::fromRow($db->first('SELECT * FROM users WHERE id = :id', ['id' => $a]));

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $method->invoke($capability, $context, $siteOfB, $accountA),
        'เว็บของอีกบัญชีต้องถูกปฏิเสธ',
    );

    // ไม่ผูกกับเว็บเลยต้องผ่าน — ฐานข้อมูลไม่จำเป็นต้องมีเว็บ
    $method->invoke($capability, $context, 0, $accountA);
    TestRunner::$assertions++;
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
    //
    // ตัดคอมเมนต์ทิ้งก่อนเทียบ · คำอธิบายที่บอกว่า "ห้ามทำแบบนี้" มีเส้นทางนั้นอยู่ในตัว
    // อยู่แล้ว การจับคำดิบ ๆ จึงทำให้เทสต์แดงเพราะคำอธิบาย ไม่ใช่เพราะโค้ด
    $page = (string) preg_replace(
        '/<!--.*?-->/s',
        '',
        (string) file_get_contents(PHPCP_ROOT.'/public/assets/spa/templates/databases.html'),
    );

    /*
     * เดิมตรวจแค่ `href="/phpmyadmin` ซึ่งจับปุ่มในตารางไม่ได้เลย — มันเป็น
     * `data-row-actions` ที่ใส่เส้นทางไว้ในคีย์ `url` · ผลคือปุ่มของทุกแถวพาไปที่
     * phpMyAdmin แบบ GET ที่ไม่มี session ผู้ใช้จึงเจอหน้าล็อกอินของ phpMyAdmin
     * แทนฐานข้อมูลของตัวเอง และไม่มีเทสต์ไหนฟ้อง
     */
    assertTrue(
        !str_contains($page, '/phpmyadmin/index.php'),
        'หน้าเว็บต้องไม่มีเส้นทางตรงไป phpMyAdmin ไม่ว่าจะในรูป href หรือ data-row-actions',
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

test('รายการฐานข้อมูลต้องเห็นฐานที่ไม่ได้ผูกกับเว็บไซต์ของบัญชีตัวเอง', static function (): void {
    /*
     * databases_ ไม่มีคอลัมน์ owner_user_id เลย มีแค่ site_id (nullable) —
     * ฐานข้อมูลที่ตอนสร้างเลือก "ไม่ผูกกับเว็บไซต์" (ตัวเลือกที่ฟอร์มรองรับจริง)
     * จึงไม่มีทางสืบไปถึงเจ้าของผ่าน sites ได้เลย
     *
     * เจอจากรายงานจริง (2026-08-16): ลูกค้าที่มีฐานข้อมูลแบบนี้ไม่เห็นมันในตาราง
     * ทั้งที่ปุ่ม "เปิด phpMyAdmin" ที่หัวหน้าเปิดได้ปกติ — เพราะปุ่มนั้นตรวจสิทธิ์
     * ผ่าน db_accounts/db_grants คนละทางกับ query ของรายการที่กรองผ่าน sites เท่านั้น
     */
    $db = migratedDb();
    $users = new Phpcp\Domain\UserRepository($db);
    $now = time();

    $ownerId = $users->createHostingAccount('bluprint', 'Bluprint-Password-11', 'owner@example.com');
    $db->update('users', ['system_user' => 'bluprint'], ['id' => $ownerId]);

    $otherId = $users->createHostingAccount('otherguy', 'Other-Guy-Password-11', 'other@example.com');
    $db->update('users', ['system_user' => 'otherguy'], ['id' => $otherId]);

    // ฐานของ bluprint เอง — ไม่ผูกกับเว็บไซต์ใดเลย (site_id = NULL)
    $dbId = $db->insert('databases_', [
        'db_name' => 'bluprint_standalone',
        'site_id' => null,
        'charset' => 'utf8mb4',
        'size_bytes' => 0,
        'created_at' => $now,
    ]);

    // ฐานของอีกคน — ก็ไม่ผูกกับเว็บไซต์เหมือนกัน ต้องไม่โผล่ในรายการของ bluprint
    $otherDbId = $db->insert('databases_', [
        'db_name' => 'otherguy_standalone',
        'site_id' => null,
        'charset' => 'utf8mb4',
        'size_bytes' => 0,
        'created_at' => $now,
    ]);

    $db->insert('db_accounts', [
        'user_id' => $ownerId,
        'mysql_user' => 'bluprint',
        'host' => 'localhost',
        'password_enc' => 'ciphertext-not-real',
        'created_at' => $now,
    ]);

    $dbUserId = $db->insert('db_users', ['username' => 'bluprint', 'host' => 'localhost']);
    $db->insert('db_grants', ['db_id' => $dbId, 'db_user_id' => $dbUserId, 'privileges' => 'full']);

    $context = new Context(
        new Actor($ownerId, 'bluprint', Permissions::WEBADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    // SandboxExecutor ที่ชี้ไปโฟลเดอร์ว่าง = ไม่พบไบนารี mysql/mariadb เลย
    // DbList จึงข้ามส่วนที่ต้องคุยกับ MariaDB จริงไปทั้งหมด เหลือแต่ query ที่ทดสอบ
    $executor = new Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-dblist-' . getmypid());
    $result = (new Phpcp\Agent\Capability\DbList())->run([], $executor, $context);

    $names = array_column($result['databases'], 'name');

    assertTrue(
        in_array('bluprint_standalone', $names, true),
        'ฐานที่ไม่ผูกเว็บไซต์ของตัวเองต้องเห็นในรายการ: ' . implode(', ', $names),
    );
    assertTrue(
        !in_array('otherguy_standalone', $names, true),
        'ฐานที่ไม่ผูกเว็บไซต์ของคนอื่นต้องไม่โผล่มาด้วย: ' . implode(', ', $names),
    );
});
