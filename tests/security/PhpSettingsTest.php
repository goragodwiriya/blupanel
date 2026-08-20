<?php

declare(strict_types=1);

/**
 * ค่า PHP ที่ผู้ดูแลตั้งเองได้ — ต้องมีผลจริงกับไฟล์ ไม่ใช่แค่ตัวเลขในฐานข้อมูล
 *
 * **สิ่งที่ชุดนี้กันไว้จริง ๆ** — ของเดิมพังแบบ "ดูเหมือนตั้งได้ แต่ไม่ได้ตั้ง": `Site`
 * มีพร็อพเพอร์ตี้ `memoryLimitMb`/`uploadLimitMb`/`maxChildren` มาตลอด แต่ไม่มีคอลัมน์
 * และ `fromRow()` ไม่เคยอ่านค่าเหล่านั้นเลย ทุกเว็บบนเครื่องจึงได้ค่าเริ่มต้นเหมือนกันหมด
 * โดยไม่มีอะไรผิดพลาดให้เห็นสักอย่าง · ความล้มเหลวแบบนี้ไม่ทำให้เทสต์ไหนแดง
 * นอกจากเทสต์ที่ไล่จาก **ค่าในฐานข้อมูล → ตัวหนังสือในไฟล์ค่าตั้ง** ให้ครบเส้น
 *
 * รันได้โดยไม่ต้องมี root และไม่ต้องมี php-fpm — ทุกข้อเทียบข้อความที่จะถูกเขียนลงไฟล์
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\PhpSettings;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\Php\PanelPhpTuning;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\NginxDriver;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('PhpSettings — ค่าที่ตั้งต้องไปโผล่ในไฟล์จริง');

function phpSettingsExecutor(): SandboxExecutor
{
    return new SandboxExecutor(sys_get_temp_dir() . '/phpcp-phpset-' . getmypid());
}

/** บริบทสำหรับรัน capability ตรง ๆ โดยไม่ต้องมี agent ทำงานอยู่ */
function phpSettingsContext(\Phpcp\Kernel\Db $db): Context
{
    return new Context(
        new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );
}

/** เว็บหนึ่งแห่งที่เจ้าของถือค่า PHP ชุดที่ระบุ */
function phpSettingsSite(PhpSettings $php): Site
{
    return new Site(1, 'example.test', new UserAccount(7, 'sitefiles', php: $php), '8.4');
}

function phpSettingsPool(PhpSettings $php): string
{
    return (new FpmManager(new Template(PHPCP_ROOT . '/templates')))
        ->renderPool(phpSettingsSite($php), 'www-data', phpSettingsExecutor());
}

test('ค่าที่ตั้งต้องกลายเป็นบรรทัด php_admin_value ในไฟล์ pool จริง', static function (): void {
    $conf = phpSettingsPool(new PhpSettings(
        memoryLimitMb: 512,
        uploadMaxMb: 256,
        postMaxMb: 512,
        maxExecutionTime: 600,
        maxInputVars: 5000,
        maxChildren: 12,
    ));

    foreach ([
        'php_admin_value[memory_limit] = 512M',
        'php_admin_value[upload_max_filesize] = 256M',
        'php_admin_value[post_max_size] = 512M',
        'php_admin_value[max_execution_time] = 600',
        'php_admin_value[max_input_vars] = 5000',
        'pm.max_children      = 12',
    ] as $line) {
        assertTrue(str_contains($conf, $line), "ไฟล์ pool ต้องมีบรรทัด {$line}");
    }
});

test('ต้องเป็น php_admin_value ไม่ใช่ php_value — ไม่งั้นโค้ดของลูกค้าดันเพดานเองได้', static function (): void {
    /*
     * php_value กับ .user.ini ถูกทับได้ด้วย ini_set() ของลูกค้าเอง · ถ้าเผลอเขียนเป็น
     * php_value เมื่อไร "จำกัดหน่วยความจำต่อบัญชี" จะไม่จำกัดอะไรเลยสำหรับคนที่อ่าน
     * คู่มือ PHP มา ซึ่งอ่านจากหน้าจอไม่ออกเลยว่าต่างกัน
     */
    $conf = phpSettingsPool(new PhpSettings());

    assertTrue(
        preg_match('/^\h*php_value\[/m', $conf) !== 1,
        'ไฟล์ pool ต้องไม่มี php_value[...] เลยแม้แต่บรรทัดเดียว',
    );
    assertTrue(
        preg_match('/^\h*php_flag\[/m', $conf) !== 1,
        'ไฟล์ pool ต้องไม่มี php_flag[...] เลยแม้แต่บรรทัดเดียว',
    );
});

test('FPM ต้องรอนานกว่าที่ PHP ยอมแพ้เสมอ', static function (): void {
    /*
     * ถ้า request_terminate_timeout สั้นกว่า max_execution_time ผู้ใช้จะได้ 502 เปล่า ๆ
     * โดยไม่มีอะไรใน log ของ PHP ให้อ่าน — ค่านี้เคยตรึงไว้ 120s ตายตัว การเพิ่ม
     * max_execution_time จึงไม่ได้เวลาเพิ่มขึ้นจริงแม้แต่วินาทีเดียว
     */
    $conf = phpSettingsPool(new PhpSettings(maxExecutionTime: 600));

    assertTrue(str_contains($conf, 'request_terminate_timeout  = 630s'), 'ต้องยาวกว่า max_execution_time');

    // 0 = ไม่จำกัด ทั้งสองฝั่ง — ไม่ใช่ 0 + 30 ซึ่งจะกลายเป็นการฆ่าที่ 30 วินาที
    $unlimited = phpSettingsPool(new PhpSettings(maxExecutionTime: 0));

    assertTrue(str_contains($unlimited, 'request_terminate_timeout  = 0s'), '0 ต้องยังเป็น 0');
});

test('ไทม์โซนที่ไม่ได้ตั้ง ต้องไม่มีบรรทัดในไฟล์ ไม่ใช่บรรทัดค่าว่าง', static function (): void {
    // date.timezone ที่ว่างเปล่าทำให้ทุกการเรียกวันที่ของทุกเว็บขึ้น warning
    $without = phpSettingsPool(new PhpSettings());

    assertTrue(!str_contains($without, 'date.timezone'), 'ไม่ได้ตั้งต้องไม่มีบรรทัดนี้เลย');

    $with = phpSettingsPool(new PhpSettings(timezone: 'Asia/Bangkok'));

    assertTrue(
        str_contains($with, 'php_admin_value[date.timezone] = Asia/Bangkok'),
        'ตั้งแล้วต้องมีบรรทัดนี้',
    );
});

test('ค่าที่ตั้งไม่ได้ต้องยังเป็นของเดิมในเทมเพลต — open_basedir กับ disable_functions', static function (): void {
    /*
     * สองบรรทัดนี้คือเส้นแบ่งระหว่างลูกค้ารายหนึ่งกับอีกราย (ARCHITECTURE §11)
     * หน้าจอที่ขยายมันได้คือหน้าจอที่ยื่นไฟล์ของลูกค้าคนอื่นให้ได้ · ต้องไม่มีวัน
     * หลุดเข้ามาอยู่ในรายการที่ตั้งได้ ไม่ว่าจะเผลอเพิ่มฟิลด์ตอนไหน
     */
    foreach (['open_basedir', 'disable_functions', 'allow_url_include', 'expose_php'] as $ini) {
        assertFalse(
            PhpSettings::isManagedDirective($ini),
            "{$ini} ต้องไม่อยู่ในรายการที่ตั้งได้จากหน้าเว็บ",
        );
    }

    $conf = phpSettingsPool(new PhpSettings(allowUrlFopen: true));

    assertTrue(str_contains($conf, 'php_admin_value[open_basedir] = '), 'open_basedir ต้องยังอยู่');
    assertTrue(str_contains($conf, 'php_admin_value[disable_functions] = '), 'disable_functions ต้องยังอยู่');
    assertTrue(str_contains($conf, 'php_admin_flag[allow_url_include] = off'), 'allow_url_include ต้องยังปิดตายอยู่');
});

test('เพดาน body ของ nginx ต้องตามตัวที่ใหญ่กว่าระหว่าง upload กับ post', static function (): void {
    /*
     * POST หนึ่งครั้งแบกทั้งไฟล์และช่องอื่นในฟอร์ม · ตั้ง client_max_body_size ตาม
     * ขนาดไฟล์อย่างเดียวจะได้ 413 จาก nginx สำหรับการอัปโหลดที่ PHP รับได้ และ 413 นั้น
     * เกิดก่อนคำขอถึง PHP จึงไม่มีอะไรใน log ของเว็บให้อ่านเลย
     */
    $driver = new NginxDriver(new Template(PHPCP_ROOT . '/templates'));
    $conf = $driver->renderVhost(
        phpSettingsSite(new PhpSettings(uploadMaxMb: 100, postMaxMb: 300)),
        phpSettingsExecutor(),
    );

    assertTrue(str_contains($conf, 'client_max_body_size 300M;'), 'ต้องใช้ค่าที่ใหญ่กว่า');
});

test('post_max_size ที่เล็กกว่า upload_max_filesize ต้องถูกปฏิเสธตั้งแต่ต้นทาง', static function (): void {
    // PHP รับค่าที่ขัดกันแบบนี้เงียบ ๆ แล้วกดเพดานลงมาที่ตัวเล็กกว่า — ผู้ดูแลตั้ง 512M
    // เห็นอัปโหลดยังพังที่ 64M และไม่มีอะไรให้อ่านว่าทำไม
    $bad = new PhpSettings(uploadMaxMb: 512, postMaxMb: 64);
    $threw = false;

    try {
        $bad->assertConsistent();
    } catch (ValidationError) {
        $threw = true;
    }

    assertTrue($threw, 'ต้องปฏิเสธ ไม่ใช่บันทึกแล้วปล่อยให้งงทีหลัง');

    // เท่ากันคือถูกต้อง ไม่ใช่ขอบที่พลาด
    (new PhpSettings(uploadMaxMb: 64, postMaxMb: 64))->assertConsistent();
});

test('ค่านอกช่วงและไทม์โซนที่ไม่มีจริงต้องถูกปฏิเสธ', static function (): void {
    foreach ([
        ['memory_limit_mb', 0],
        ['memory_limit_mb', 999999],
        ['memory_limit_mb', 'abc'],
        ['upload_max_mb', 999999],
        ['max_children', 0],
        ['timezone', 'Mars/Olympus'],
        ['timezone', "Asia/Bangkok\nphp_admin_value[open_basedir] = /"],
    ] as [$field, $value]) {
        $threw = false;

        try {
            PhpSettings::assertValue($field, $value);
        } catch (ValidationError) {
            $threw = true;
        }

        assertTrue($threw, "{$field} = " . var_export($value, true) . ' ต้องถูกปฏิเสธ');
    }
});

test('ค่าที่ไม่ได้ส่งคือ "ไม่เปลี่ยน" ไม่ใช่ "คืนค่าเริ่มต้น"', static function (): void {
    $current = new PhpSettings(memoryLimitMb: 512, uploadMaxMb: 256, postMaxMb: 256, timezone: 'Asia/Bangkok');
    $after = PhpSettings::fromArray(['memory_limit_mb' => 1024], $current);

    assertSame(1024, $after->memoryLimitMb, 'ค่าที่ส่งต้องเปลี่ยน');
    assertSame(256, $after->uploadMaxMb, 'ค่าที่ไม่ได้ส่งต้องคงเดิม');
    assertSame('Asia/Bangkok', $after->timezone, 'ไทม์โซนที่ไม่ได้ส่งต้องคงเดิม');

    // ส่งมาเป็นค่าว่างคือ "ล้างไทม์โซน" ซึ่งต่างจากไม่ส่ง
    $cleared = PhpSettings::fromArray(['timezone' => ''], $current);

    assertSame('', $cleared->timezone, 'ส่งค่าว่างมาคือสั่งล้าง');
});

test('ค่าเดินทางครบจากคอลัมน์ของ users ไปถึงไฟล์ pool', static function (): void {
    /*
     * นี่คือเส้นที่เคยขาด — พร็อพเพอร์ตี้มีอยู่ แต่ไม่มีใครอ่านจากฐานข้อมูล
     * เทสต์นี้เดินจาก **แถวจริง** ผ่าน Site::fromRow() ไปจนถึงตัวหนังสือในไฟล์
     */
    $site = Site::fromRow([
        'id' => 3,
        'primary_domain' => 'shop.example.com',
        'php_version' => '8.4',
        'owner_user_id' => 9,
        'owner_username' => 'shopuser',
        'owner_system_user' => 'shopuser',
        'php_memory_limit_mb' => 768,
        'php_upload_max_mb' => 300,
        'php_post_max_mb' => 400,
        'php_max_children' => 20,
        'php_timezone' => 'Asia/Bangkok',
        'php_display_errors' => 1,
    ]);

    assertSame(768, $site->php()->memoryLimitMb, 'ต้องอ่านจากแถวจริง ไม่ใช่ค่าเริ่มต้น');

    $conf = (new FpmManager(new Template(PHPCP_ROOT . '/templates')))
        ->renderPool($site, 'www-data', phpSettingsExecutor());

    assertTrue(str_contains($conf, 'php_admin_value[memory_limit] = 768M'), 'ค่าจากแถวต้องไปถึงไฟล์');
    assertTrue(str_contains($conf, 'php_admin_flag[display_errors] = on'), 'ค่า on/off ต้องไปถึงไฟล์ด้วย');
    assertTrue(str_contains($conf, 'pm.max_children      = 20'), 'จำนวนโปรเซสต้องไปถึงไฟล์ด้วย');
});

test('customer.php_set ต้องบันทึกลงแถวจริง และค่าที่ไม่ได้ส่งต้องไม่ถูกแตะ', static function (): void {
    /*
     * เดินเส้นเดียวกับที่หน้าเว็บเดิน: capability → คอลัมน์บน users → อ่านกลับผ่าน
     * UserAccount · ทดสอบกับบัญชีที่ยังไม่มีเว็บ เพราะขั้นเขียนไฟล์ต้องมี php-fpm จริง
     * ซึ่งไม่มีใน CI — ส่วนที่เขียนไฟล์ถูกตรึงไว้ด้วยเทสต์เรนเดอร์ข้างบนแล้ว
     */
    $db = migratedDb();
    $context = phpSettingsContext($db);
    $registry = new CapabilityRegistry();

    $created = $registry->resolve('customer.create');
    $user = $created->run(
        $created->validate(['username' => 'phpuser', 'password' => 'temporary-password', 'email' => 'phpuser@example.com']),
        phpSettingsExecutor(),
        $context,
    );

    $capability = $registry->resolve('customer.php_set');
    $capability->run(
        $capability->validate([
            'user_id' => (int) $user['id'],
            'memory_limit_mb' => 512,
            'upload_max_mb' => 256,
            'post_max_mb' => 256,
        ]),
        phpSettingsExecutor(),
        $context,
    );

    $account = UserAccount::fromRow((new UserRepository($db))->find((int) $user['id']));

    assertSame(512, $account->php->memoryLimitMb, 'ค่าที่ส่งต้องถูกบันทึกลงแถวจริง');
    assertSame(256, $account->php->uploadMaxMb, 'ค่าที่ส่งต้องถูกบันทึกลงแถวจริง');
    assertSame(120, $account->php->maxExecutionTime, 'ค่าที่ไม่ได้ส่งต้องคงค่าเดิมไว้');
    assertSame(5, $account->php->maxChildren, 'ค่าที่ไม่ได้ส่งต้องคงค่าเดิมไว้');

    // ส่งค่าที่ขัดกันต้องถูกปฏิเสธ **ก่อน** ที่อะไรจะถูกบันทึก
    assertRejects(
        ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['user_id' => (int) $user['id'], 'post_max_mb' => 8]),
            phpSettingsExecutor(),
            $context,
        ),
        'post_max_size ที่เล็กกว่า upload_max_filesize ต้องถูกปฏิเสธ',
    );

    $unchanged = UserAccount::fromRow((new UserRepository($db))->find((int) $user['id']));

    assertSame(256, $unchanged->php->postMaxMb, 'คำสั่งที่ถูกปฏิเสธต้องไม่ทิ้งค่าครึ่ง ๆ กลาง ๆ ไว้');
});

test('บัญชีที่ยังไม่ตั้งอะไรเลยต้องได้ค่าเดิมของระบบเป๊ะ', static function (): void {
    /*
     * เครื่องที่อัปเดตขึ้นมาต้องได้พฤติกรรมเหมือนเดิมทุกอย่างจนกว่าจะมีคนเปลี่ยนเอง ·
     * ค่าเริ่มต้นของคอลัมน์ใหม่จึงต้องตรงกับตัวเลขที่ pool เคยฝังไว้ ไม่ใช่ค่าที่
     * "ดูดีกว่า" ซึ่งจะกลายเป็นการเปลี่ยนพฤติกรรมให้ทุกเว็บบนเครื่องโดยไม่มีใครสั่ง
     */
    $db = migratedDb();
    $registry = new CapabilityRegistry();
    $created = $registry->resolve('customer.create');

    $user = $created->run(
        $created->validate(['username' => 'freshuser', 'password' => 'temporary-password', 'email' => 'freshuser@example.com']),
        phpSettingsExecutor(),
        phpSettingsContext($db),
    );

    $php = UserAccount::fromRow((new UserRepository($db))->find((int) $user['id']))->php;

    assertSame(256, $php->memoryLimitMb, 'memory_limit เดิมของ pool คือ 256M');
    assertSame(64, $php->uploadMaxMb, 'upload_max_filesize เดิมของ pool คือ 64M');
    assertSame(64, $php->postMaxMb, 'post_max_size เดิมผูกกับ upload_max_filesize');
    assertSame(120, $php->maxExecutionTime, 'max_execution_time เดิมคือ 120');
    assertSame(5, $php->maxChildren, 'pm.max_children เดิมคือ 5');
});

group('PhpSettings — pool ของ panel เอง');

test('ค่าเริ่มต้นของ panel ต้องตรงกับตัวเลขในเทมเพลตเป๊ะ', static function (): void {
    /*
     * เทมเพลตคือสิ่งที่เครื่องรันจริงจนกว่าผู้ดูแลจะเปลี่ยน · ถ้าค่าเริ่มต้นในโค้ด
     * ไม่ตรงกับไฟล์ หน้าจอจะแสดงตัวเลขที่ไม่มีโปรเซสไหนใช้อยู่ ซึ่งแย่กว่าไม่แสดงเลย
     */
    $template = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/panel-pool.conf.tpl');
    $defaults = PhpSettings::panelDefaults();

    assertTrue(
        str_contains($template, 'php_admin_value[memory_limit]   = ' . $defaults->memoryLimitMb . 'M'),
        'memory_limit ในเทมเพลตต้องตรงกับ panelDefaults()',
    );
    assertTrue(
        str_contains($template, 'php_admin_value[upload_max_filesize] = ' . $defaults->uploadMaxMb . 'M'),
        'upload_max_filesize ในเทมเพลตต้องตรงกับ panelDefaults()',
    );
    assertTrue(
        str_contains($template, 'php_admin_value[post_max_size]       = ' . $defaults->postMaxMb . 'M'),
        'post_max_size ในเทมเพลตต้องตรงกับ panelDefaults()',
    );
    assertTrue(
        str_contains($template, 'pm.max_children        = ' . $defaults->maxChildren),
        'pm.max_children ในเทมเพลตต้องตรงกับ panelDefaults()',
    );

    $httpd = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/httpd.conf.tpl');

    assertTrue(
        str_contains($httpd, 'LimitRequestBody ' . ($defaults->bodyLimitMb() * 1048576)),
        'LimitRequestBody ในเทมเพลตต้องตรงกับ post_max_size เริ่มต้นของ panel',
    );
});

test('แก้ไฟล์ pool ของ panel ต้องแทนที่บรรทัดเดิม ไม่ใช่เพิ่มบรรทัดซ้ำ', static function (): void {
    $template = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/panel-pool.conf.tpl');
    $result = PanelPhpTuning::applyToPool($template, new PhpSettings(
        memoryLimitMb: 512,
        uploadMaxMb: 512,
        postMaxMb: 512,
        maxChildren: 8,
    ));

    assertSame(
        1,
        preg_match_all('/^\h*php_admin_value\[memory_limit\]/m', $result),
        'ต้องเหลือ memory_limit บรรทัดเดียว',
    );
    assertTrue(str_contains($result, 'php_admin_value[memory_limit] = 512M'), 'ต้องเป็นค่าใหม่');
    assertTrue(!str_contains($result, '= 128M'), 'ค่าเดิมต้องไม่หลงเหลือ');
    assertTrue(str_contains($result, 'pm.max_children        = 8'), 'จำนวนโปรเซสต้องเปลี่ยนด้วย');
});

test('ทุกอย่างที่ตัวติดตั้งเขียนไว้ต้องรอดจากการแก้ค่า', static function (): void {
    /*
     * ไฟล์นี้ถูก "แก้ทีละบรรทัด" ไม่ใช่สร้างใหม่จากเทมเพลต เพราะค่าที่เหลือในไฟล์เป็นสิ่ง
     * ที่มีแต่ตัวติดตั้งเท่านั้นที่รู้ (ผู้ใช้ของ panel, เส้นทาง socket, open_basedir)
     * · เขียนใหม่แล้วเดาผิดข้อเดียว = หน้าจัดการดับ ซึ่งเป็นหน้าเดียวที่ใช้แก้กลับได้
     */
    $template = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/panel-pool.conf.tpl');
    $result = PanelPhpTuning::applyToPool($template, new PhpSettings(memoryLimitMb: 512, uploadMaxMb: 512, postMaxMb: 512));

    foreach ([
        '[panel]',
        'php_admin_value[open_basedir]',
        'php_admin_value[disable_functions]',
        'php_admin_value[session.save_path]',
        'listen       = {{RUN_DIR}}/panel-fpm.sock',
        'user  = {{PANEL_USER}}',
    ] as $needle) {
        assertTrue(str_contains($result, $needle), "ต้องไม่ทำ {$needle} หาย");
    }

    // SSE ของ panel ต้องเปิดค้างได้เป็นครึ่งชั่วโมง — ต่างจาก pool ของลูกค้าโดยตั้งใจ
    assertTrue(
        str_contains($result, 'request_terminate_timeout = 0'),
        'panel ต้องไม่ถูกตั้ง terminate timeout ตาม max_execution_time',
    );
});

test('เพดาน body ของ Apache ฝั่ง panel ต้องถูกเขียนตาม post_max_size', static function (): void {
    /*
     * Apache ปฏิเสธ body ที่ใหญ่เกิน **ก่อน** PHP จะเห็นคำขอ · ขยับแต่ฝั่ง PHP
     * จะได้ 413 ที่ไม่มีบรรทัดใดใน log ของ PHP อธิบายได้เลย — ซึ่งคือทางตันที่
     * ทั้งคำสั่งนี้มีไว้เพื่อกำจัด
     */
    $httpd = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/httpd.conf.tpl');
    $result = PanelPhpTuning::applyToHttpd($httpd, new PhpSettings(uploadMaxMb: 256, postMaxMb: 512));

    assertTrue(str_contains($result, 'LimitRequestBody ' . (512 * 1048576)), 'ต้องเป็นค่าใหม่เป็นไบต์');
    assertSame(
        1,
        preg_match_all('/^\h*LimitRequestBody/m', $result),
        'ต้องเหลือบรรทัดเดียว',
    );
});

test('ล้างไทม์โซนของ panel ต้องลบบรรทัดทิ้ง ไม่ใช่เขียนค่าว่าง', static function (): void {
    $template = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/panel-pool.conf.tpl');

    $withTz = PanelPhpTuning::applyToPool($template, new PhpSettings(timezone: 'Asia/Bangkok'));
    assertTrue(str_contains($withTz, 'php_admin_value[date.timezone] = Asia/Bangkok'), 'ตั้งแล้วต้องมี');

    $cleared = PanelPhpTuning::applyToPool($withTz, new PhpSettings(timezone: ''));
    assertTrue(!str_contains($cleared, 'date.timezone'), 'ล้างแล้วต้องไม่เหลือบรรทัดนี้เลย');
});
