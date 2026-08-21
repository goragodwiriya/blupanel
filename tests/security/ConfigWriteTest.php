<?php

declare(strict_types=1);

/**
 * การเขียนไฟล์ config — จุดที่พลาดแล้วเว็บทั้งเครื่องดับ (ARCHITECTURE §10)
 *
 * ครอบคลุมสองความเสี่ยง:
 *   1. ค่าที่ผู้ใช้ควบคุมได้แทรก directive เข้าไปในไฟล์ config (เทียบเท่า command injection)
 *   2. config ที่ผิดถูกนำไปใช้จริงโดยไม่ผ่านการตรวจ
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;

group('ConfigWrite — เขียนไฟล์ config อย่างปลอดภัยและย้อนกลับได้');

function testTemplates(): Template
{
    return new Template(PHPCP_ROOT . '/templates');
}

function testSite(array $overrides = []): Site
{
    return new Site(
        id: $overrides['id'] ?? 42,
        domain: $overrides['domain'] ?? 'example.test',
        owner: new UserAccount(7, $overrides['systemUser'] ?? 'sitefiles'),
        phpVersion: $overrides['phpVersion'] ?? '8.4',
        status: $overrides['status'] ?? 'active',
        aliases: $overrides['aliases'] ?? ['www.example.test'],
    );
}

test('Template ปฏิเสธค่าที่มีขึ้นบรรทัดใหม่ (แทรก directive)', static function (): void {
    $payloads = [
        "example.test\n    Require all granted",
        "example.test\r\nServerAlias evil.test",
        "example.test\n</VirtualHost>\n<VirtualHost *:80>",
        "value\x00null",
        "value\x1bescape",
    ];

    foreach ($payloads as $payload) {
        assertRejects(
            ValidationError::class,
            static fn () => testTemplates()->render('apache/vhost.conf.tpl', [
                'DOMAIN' => $payload,
                'SERVER_ALIASES' => Template::lines('ServerAlias', []),
                'DOCROOT' => '/srv/x/public',
                'FPM_SOCKET' => '/run/php/x.sock',
                'ERROR_LOG' => '/srv/x/logs/error.log',
                'ACCESS_LOG' => '/srv/x/logs/access.log',
                'SITE_USER' => 'web_1',
                'PHP_VERSION' => '8.4',
                'HTTP_PORT' => 80,
                'GENERATED_AT' => 'now',
            ]),
            'ต้องปฏิเสธค่าที่แทรกบรรทัดใหม่เข้ามาได้',
        );
    }
});

test('Template ปฏิเสธเมื่อมี placeholder ที่ไม่ได้กำหนดค่า', static function (): void {
    assertRejects(
        RuntimeException::class,
        static fn () => testTemplates()->render('apache/vhost.conf.tpl', ['DOMAIN' => 'x.test']),
        'เทมเพลตที่ยังมี {{...}} ค้างต้องไม่ถูกเขียนออกไป',
    );
});

test('Template::lines ตรวจทุกบรรทัดที่สร้าง', static function (): void {
    assertRejects(
        ValidationError::class,
        static fn () => Template::lines('ServerAlias', ["ok.test", "bad.test\n  Require all denied"]),
        'รายการหลายบรรทัดต้องถูกตรวจทีละบรรทัด',
    );

    $safe = Template::lines('ServerAlias', ['a.test', 'b.test']);
    assertSame(
        "    ServerAlias a.test\n    ServerAlias b.test",
        $safe->text,
        'ค่าที่ถูกต้องต้องสร้างออกมาได้ตามรูปแบบ',
    );
});

test('vhost ที่สร้างมี directive ที่จำเป็นครบและไม่มี placeholder ตกค้าง', static function (): void {
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-test-' . getmypid());
    $driver = new ApacheDriver(testTemplates());

    $vhost = $driver->renderVhost(testSite(), $executor);

    foreach (['ServerName example.test', 'ServerAlias www.example.test', 'DocumentRoot',
        'SetHandler "proxy:unix:', 'ErrorLog', 'CustomLog'] as $needle) {
        assertTrue(str_contains($vhost, $needle), "vhost ต้องมี {$needle}");
    }

    assertTrue(!str_contains($vhost, '{{'), 'vhost ต้องไม่มี placeholder ตกค้าง');

    // socket ต้องผูกกับเวอร์ชัน PHP — จุดที่ทำให้สลับเวอร์ชันต่อเว็บไซต์ได้
    assertTrue(str_contains($vhost, '-8.4.sock'), 'socket ต้องระบุเวอร์ชัน PHP');

    $vhost74 = $driver->renderVhost(testSite(['phpVersion' => '7.4']), $executor);
    assertTrue(str_contains($vhost74, '-7.4.sock'), 'เปลี่ยนเวอร์ชันแล้ว socket ต้องเปลี่ยนตาม');
});

test('vhost ของเว็บที่ถูกระงับต้องไม่ส่งงานให้ PHP เลย', static function (): void {
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-test-' . getmypid());
    $driver = new ApacheDriver(testTemplates());

    $vhost = $driver->renderVhost(testSite(['status' => 'suspended']), $executor);

    assertTrue(!str_contains($vhost, 'proxy:unix:'), 'เว็บที่ถูกระงับต้องไม่มี handler ของ PHP');
    assertTrue(str_contains($vhost, '503'), 'เว็บที่ถูกระงับต้องตอบ 503');
});

test('pool ของ FPM ต้องมีมาตรการแยกเว็บไซต์ครบ', static function (): void {
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-test-' . getmypid());
    $pool = (new FpmManager(testTemplates()))->renderPool(testSite(), 'www-data', $executor);

    // สองบรรทัดนี้คือกลไกที่กันไม่ให้เว็บที่ถูกแฮ็กอ่านไฟล์เว็บอื่นหรือยกระดับเป็น root
    assertTrue(str_contains($pool, 'open_basedir'), 'pool ต้องกำหนด open_basedir');
    assertTrue(str_contains($pool, 'disable_functions'), 'pool ต้องปิด shell function');

    foreach (['exec', 'shell_exec', 'proc_open', 'passthru', 'system', 'pcntl_exec'] as $dangerous) {
        assertTrue(
            preg_match('/disable_functions.*\b' . preg_quote($dangerous, '/') . '\b/', $pool) === 1,
            "disable_functions ต้องมี {$dangerous}",
        );
    }

    assertTrue(str_contains($pool, 'user  = sitefiles'), 'pool ต้องรันด้วย uid ของเจ้าของเว็บ');
    assertTrue(str_contains($pool, 'listen.mode  = 0660'), 'socket ต้องไม่เปิดให้ผู้ใช้อื่นในเครื่องต่อได้');
});

test('ConfigTransaction คืนไฟล์เดิมเมื่อการตรวจไม่ผ่าน', static function (): void {
    $prefix = sys_get_temp_dir() . '/phpcp-tx-' . getmypid();
    $executor = new SandboxExecutor($prefix);

    $path = '/etc/phpcp-test/demo.conf';
    $resolved = $executor->path($path);

    $executor->writeFile($resolved, "เนื้อหาเดิม\n");

    $transaction = new ConfigTransaction($executor);
    $transaction->write($path, "เนื้อหาใหม่ที่ผิด\n");

    assertSame("เนื้อหาใหม่ที่ผิด\n", $executor->readFile($resolved), 'ระหว่างทางต้องเห็นเนื้อหาใหม่');

    assertRejects(
        \Phpcp\Agent\ExecutionFailed::class,
        static fn () => $transaction->commit(static fn (): array => [false, 'จำลองว่าตรวจไม่ผ่าน']),
        'commit ที่ตรวจไม่ผ่านต้องโยน error',
    );

    assertSame("เนื้อหาเดิม\n", $executor->readFile($resolved), 'ต้องคืนเนื้อหาเดิมกลับมาครบ');

    @unlink($resolved);
    @rmdir(dirname($resolved));
});

test('ConfigTransaction ลบไฟล์ที่เพิ่งสร้างเมื่อย้อนกลับ', static function (): void {
    $prefix = sys_get_temp_dir() . '/phpcp-tx2-' . getmypid();
    $executor = new SandboxExecutor($prefix);

    $path = '/etc/phpcp-test/new.conf';
    $resolved = $executor->path($path);

    $transaction = new ConfigTransaction($executor);
    $transaction->write($path, "ไฟล์ใหม่\n");
    assertTrue($executor->exists($resolved), 'ไฟล์ต้องถูกสร้างระหว่างทาง');

    $transaction->rollback();

    assertTrue(!$executor->exists($resolved), 'ไฟล์ที่เดิมไม่มีต้องถูกลบทิ้งตอนย้อนกลับ');
});

test('site.create ปฏิเสธชื่อโดเมนที่ผิดรูปแบบ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('site.create');

    $bad = [
        'ไม่ใช่โดเมน', '-bad.test', 'bad-.test', 'no-tld', '..test',
        'a.test/../../etc', "a.test\nServerAlias evil", 'a.test;rm -rf /',
        str_repeat('a', 300) . '.test',
    ];

    foreach ($bad as $domain) {
        assertRejects(
            ValidationError::class,
            static fn () => $capability->validate(['domain' => $domain, 'php_version' => '8.4']),
            "ต้องปฏิเสธโดเมน: {$domain}",
        );
    }

    $clean = $capability->validate(['domain' => 'Good.Example.TEST', 'php_version' => '8.4']);
    assertSame('good.example.test', $clean['domain'], 'โดเมนต้องถูกแปลงเป็นตัวพิมพ์เล็ก');
});

test('site.create ปฏิเสธเวอร์ชัน PHP ที่ผิดรูปแบบ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('site.create');

    foreach (['8', 'latest', '8.4; rm -rf /', '../8.4', '8.4.1'] as $version) {
        assertRejects(
            ValidationError::class,
            static fn () => $capability->validate(['domain' => 'ok.test', 'php_version' => $version]),
            "ต้องปฏิเสธเวอร์ชัน: {$version}",
        );
    }

    /*
     * **เวอร์ชันที่รูปแบบถูกแต่ยังไม่ได้ติดตั้ง ต้องผ่านชั้นนี้ไปตกที่ run()**
     *
     * เดิม validate() เทียบกับรายการชื่อที่คอมไพล์มากับ panel ผลคือวันที่ PHP
     * ปล่อยเวอร์ชันใหม่ apt ติดตั้งได้ php-fpm รันอยู่จริง แต่ panel ปฏิเสธ
     * เพราะยังไม่มีใครไปแก้ค่าคงที่ · คำถามว่า "เวอร์ชันนี้มีจริงไหม" ต้องถาม
     * เครื่อง ไม่ใช่ถามรายการ — SiteCreate/SiteSetPhp เรียก isVersionInstalled()
     * ก่อนเขียนไฟล์อยู่แล้ว
     */
    $clean = $capability->validate(['domain' => 'ok.test', 'php_version' => '9.9']);
    assertSame('9.9', $clean['php_version'], 'รูปแบบถูกต้องผ่าน validate ได้');

    foreach (['SiteCreate', 'SiteSetPhp'] as $class) {
        $source = (string) file_get_contents(PHPCP_ROOT . "/src/Agent/Capability/{$class}.php");

        assertTrue(
            str_contains($source, 'isVersionInstalled'),
            "{$class} ต้องตรวจว่าเวอร์ชันติดตั้งจริงก่อนเขียนไฟล์ ไม่งั้นเว็บจะถูกสร้างบน pool ที่ไม่มีอยู่",
        );
    }
});

test('site.delete ต้องได้ชื่อโดเมนยืนยันที่ถูกต้อง', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('site.delete');

    assertRejects(
        ValidationError::class,
        static fn () => $capability->validate(['site_id' => 1]),
        'ต้องบังคับให้ยืนยันด้วยชื่อโดเมน',
    );

    $clean = $capability->validate(['site_id' => 1, 'confirm_domain' => 'x.test']);
    assertSame('x.test', $clean['confirm_domain'], 'ชื่อยืนยันที่ถูกต้องต้องผ่าน');
});

test('เส้นทางของเว็บไซต์อนุมานจากเจ้าของและโดเมนอย่างสม่ำเสมอ', static function (): void {
    $site = testSite();

    assertSame('/home/sitefiles/.phpcp/example.test', $site->root(), 'ที่เก็บของประจำเว็บ');
    assertSame('/home/sitefiles/public_html', $site->docroot(), 'docroot');

    // socket และไฟล์ pool ผูกกับ (เจ้าของ × เวอร์ชัน) ไม่ใช่กับโดเมน — เว็บหลายแห่ง
    // ของเจ้าของคนเดียวกันจึงใช้ pool ร่วมกันได้
    assertSame('/run/php/phpcp-sitefiles-8.4.sock', $site->fpmSocket(), 'socket ผูกกับเจ้าของและเวอร์ชัน');
    assertSame('/etc/php/8.4/fpm/pool.d/phpcp-sitefiles.conf', $site->fpmPoolFile(), 'ไฟล์ pool');

    // เปลี่ยนเวอร์ชันแล้ว socket กับ pool ต้องย้ายตามกันทั้งคู่ ไม่ใช่ย้ายอย่างเดียว
    $switched = $site->withPhpVersion('7.4');
    assertSame('/run/php/phpcp-sitefiles-7.4.sock', $switched->fpmSocket(), 'socket ย้ายตามเวอร์ชัน');
    assertSame('/etc/php/7.4/fpm/pool.d/phpcp-sitefiles.conf', $switched->fpmPoolFile(), 'pool ย้ายตามเวอร์ชัน');
});

test('ชื่อบัญชีระบบต้องอยู่ในรูปแบบที่ปลอดภัยพอจะเป็นชื่อโฟลเดอร์และชื่อ pool', static function (): void {
    // ค่านี้ไปโผล่ในเส้นทางไฟล์และไฟล์ config ที่รันด้วยสิทธิ์ root — ต้องกันตั้งแต่ต้นทาง
    // ตัวพิมพ์ใหญ่และจุดถูกห้ามด้วย เพราะทำให้ chown, quota และเครื่องมือจัดการเมลตีความผิด
    foreach (['root;id', '../evil', 'Web42', 'a', 'has.dot', 'has space', '9start', ''] as $bad) {
        assertRejects(
            InvalidArgumentException::class,
            static fn () => UserAccount::assertSystemUser($bad),
            "ต้องปฏิเสธชื่อบัญชี: {$bad}",
        );
    }

    assertSame('customer_a', UserAccount::assertSystemUser('customer_a'), 'รูปแบบที่ถูกต้องต้องผ่าน');
});

test('ตัวติดตั้งเขียน config.php ได้ครบทุกค่า และไม่ทำลายคีย์ที่มีอยู่', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** `/etc/phpcp/config.php` ของเครื่องที่ให้บริการ
     * อยู่มี `'panel' => [443, ...]` — ชื่อคีย์ `port` หายไปทั้งคี่ย์ กลายเป็นสมาชิกลำดับที่ 0
     *
     * เพราะ replacement ถูกประกอบเป็น `"$1" . "8443"` = `$18443` แล้ว PCRE อ่านตัวเลขให้มาก
     * ที่สุดเป็นหมายเลขกลุ่ม: ได้ `$18` ซึ่งไม่มี จึงแทนด้วยค่าว่างแล้วเหลือ `443` ลอย ๆ
     * · มองไม่เห็นมานานเพราะพอร์ตที่ Apache ฟังมาจากเทมเพลต vhost ไม่ได้มาจากไฟล์นี้ตอนรัน
     *
     * และคีย์ที่ยังไม่มีในไฟล์ต้องถูก**เพิ่ม** ไม่ใช่เงียบ — เครื่องที่ติดตั้งก่อนคีย์นั้น
     * จะไม่ได้รับค่าใหม่เลยไม่ว่ารันตัวติดตั้งซ้ำกี่รอบ (ข้อ 5 ไม่เขียนทับ config.php ที่มีอยู่)
     */
    $installer = (string) file_get_contents(PHPCP_ROOT . '/install.sh');

    assertTrue(
        !str_contains($installer, '"$1".$argv[3]'),
        'replacement ที่ตามด้วยตัวเลขต้องใช้ ${1} ไม่ใช่ $1 — PCRE จะกลืนตัวเลขเข้าไปเป็นหมายเลขกลุ่ม',
    );

    // พิสูจน์พฤติกรรมจริงของ PCRE ไม่ใช่แค่ดูว่าซอร์สเขียนยังไง
    $broken = preg_replace("/('port'\s*=>\s*)\d+/", '$1' . '8443', "'port' => 443");
    $fixed = preg_replace("/('port'\s*=>\s*)\d+/", '${1}' . '8443', "'port' => 443");

    assertSame('443', $broken, 'รูปแบบเดิมกลืนชื่อคีย์ทิ้ง — นี่คือบั๊กที่เจอบนเครื่องจริง');
    assertSame("'port' => 8443", $fixed, 'รูปแบบใหม่ต้องเก็บชื่อคีย์ไว้ครบ');

    // ค่าใหม่ทุกตัวต้องมีที่ทางในไฟล์ตัวอย่าง ไม่งั้นตัวติดตั้งไม่มีที่ให้เขียน
    $example = (string) file_get_contents(PHPCP_ROOT . '/etc/config.example.php');

    foreach (["'users_dir'", "'layout'", "'pointer_roots'", "'dir'"] as $key) {
        assertTrue(str_contains($example, $key), "config.example.php ต้องมีคีย์ {$key}");
    }
});

test('รันตัวติดตั้งซ้ำโดยไม่ใส่ --pointer-root ต้องไม่ลบค่าที่ตั้งไว้', static function (): void {
    /*
     * **กับดักที่เจอบนเครื่องนี้ 2026-08-21:** ค่าอื่นทุกตัวที่ตัวติดตั้งเขียนมีค่าเริ่มต้น
     * จริง เขียนทับด้วยค่าเริ่มต้นจึงไม่เสียหาย · แต่ `pointer_roots` ไม่มี — "ไม่ได้ส่ง
     * มา" คือรายการว่าง ซึ่งเป็นค่าเดียวกับ "ปิดฟีเจอร์" · `sudo ./install.sh` เปล่า ๆ
     * เพื่ออัปเดตโค้ดจึงลบค่าที่ผู้ดูแลตั้งไว้ทิ้งเงียบ ๆ และอาการเดียวที่เห็นคือช่อง
     * Document Root หายไปจากฟอร์มโดยไม่มีอะไรบอกว่าทำไม
     */
    $installer = (string) file_get_contents(PHPCP_ROOT . '/install.sh');

    assertTrue(
        str_contains($installer, 'POINTER_ROOTS_GIVEN'),
        'ต้องแยก "ไม่ได้ส่ง --pointer-root มา" ออกจาก "ส่งมาเป็นค่าว่าง"',
    );

    assertTrue(
        str_contains($installer, 'if ($argv[8] === "yes") {'),
        'ต้องเขียน pointer_roots เฉพาะรอบที่ส่ง --pointer-root มาจริงเท่านั้น',
    );

    /*
     * และโฟลเดอร์ที่**ครอบ** panel เองต้องถูกปฏิเสธ — รายชื่อพาธระบบที่มีอยู่เดิม
     * (/etc /usr /var ...) ครอบไม่ถึง เพราะซอร์สของ panel มักอยู่ใต้โฟลเดอร์ที่เก็บ
     * ทุกโปรเจกต์รวมกัน · `--pointer-root=/mnt/Server/htdocs` บนเครื่องนี้ยื่น
     * `etc/config.php` ซึ่งมี secret key เป็นข้อความล้วน ให้เว็บไซต์อ่านได้ทันที
     */
    assertTrue(
        str_contains($installer, 'holds the control panel\'s own files'),
        'ต้องปฏิเสธโฟลเดอร์ที่ครอบไดเรกทอรีของ panel เอง',
    );

    assertTrue(
        str_contains($installer, 'is inside the control panel\'s own directory'),
        'ต้องปฏิเสธโฟลเดอร์ที่อยู่ใต้ไดเรกทอรีของ panel เอง',
    );
});

test('บริการของ panel ต้องเข้าถึงบ้านลูกค้าได้ แต่ยังกัน /root', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** ทุก unit ตั้ง `ProtectHome=yes` ซึ่งทำให้
     * /home, /root และ /run/user **ว่างเปล่า**ในมุมมองของบริการ · ตั้งแต่บ้านลูกค้าย้ายมา
     * อยู่ที่ /home ตัว agent จึงสร้างบ้านใครไม่ได้เลย — `site.create` ล้มด้วย
     * "สร้างไดเรกทอรีไม่สำเร็จ: /home/<ผู้ใช้>" ทุกครั้ง
     *
     * `ReadWritePaths=/home` ปลดล็อกไม่ได้ (ทดสอบบนเครื่องจริงแล้ว) เพราะ systemd ซ่อน
     * /home ตั้งแต่ตอนสร้าง mount namespace ก่อนที่ ReadWritePaths จะมีผล
     */
    foreach ([
        'phpcp-agentd.service.tpl',
        'phpcp-web.service.tpl',
        'phpcp-scheduler.service.tpl',
        'phpcp-fpm.service.tpl',
    ] as $unit) {
        $tpl = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/' . $unit);

        // ตัดคอมเมนต์ก่อน — ไฟล์อธิบายเหตุผลไว้ยาวและมีคำว่า ProtectHome อยู่ในนั้น
        $directives = implode("\n", array_filter(
            explode("\n", $tpl),
            static fn (string $l): bool => !str_starts_with(ltrim($l), '#'),
        ));

        assertTrue(
            !str_contains($directives, 'ProtectHome=yes'),
            "{$unit}: ProtectHome=yes ซ่อน /home ทำให้ panel ทำงานกับบ้านลูกค้าไม่ได้",
        );
        assertTrue(
            str_contains($directives, 'InaccessiblePaths=/root'),
            "{$unit}: ปิด ProtectHome แล้วต้องกัน /root ตรง ๆ แทน ไม่ใช่ปล่อยเปิดหมด",
        );
    }
});

test('ตัวเลือกแรกของ select ต้องเป็นค่าเริ่มต้นที่ถูก — คนที่กดบันทึกเฉย ๆ ต้องไม่เปลี่ยนอะไร', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** หน้าตั้งค่าผูกค่าไว้เป็น
     * `values['sites.layout'] || 'phpcp'` และเรียง phpcp ไว้บนสุด · ผู้ดูแลเปิดหน้านั้น
     * แล้วกดบันทึก (เพื่อแก้ค่าอื่น) จึงเขียน `phpcp` ทับค่าเริ่มต้นของระบบโดยไม่ได้ตั้งใจ
     * แล้วเว็บที่สร้างหลังจากนั้นไปลงที่เลย์เอาต์เก่าทั้งหมด
     */
    $page = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/settings.html');

    assertTrue(
        !str_contains($page, "values['sites.layout'] || 'phpcp'"),
        'ห้ามใส่ fallback เป็นค่าที่ไม่ใช่ค่าเริ่มต้นของระบบ',
    );

    // ตัวเลือกแรกคือสิ่งที่เบราว์เซอร์เลือกให้เมื่อค่าที่ผูกไว้ยังว่าง
    $select = (string) (preg_match('/<select[^>]*name="sites\.layout".*?<\/select>/s', $page, $m) === 1 ? $m[0] : '');

    assertTrue($select !== '', 'ต้องมีช่องเลือกเลย์เอาต์ในหน้าตั้งค่า');
    assertTrue(
        preg_match('/<option value="([^"]*)"/', $select, $first) === 1
            && $first[1] === Phpcp\Domain\SiteLayout::DEFAULT->value,
        'ตัวเลือกแรกต้องเป็น ' . Phpcp\Domain\SiteLayout::DEFAULT->value . ' ซึ่งเป็นค่าเริ่มต้นของระบบ',
    );
});
