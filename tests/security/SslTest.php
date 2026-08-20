<?php

declare(strict_types=1);

/**
 * SSL — เกณฑ์รับงานเฟส 4
 *
 * ความเสี่ยงของหน้านี้ไม่เหมือนหน้าอื่น: คำสั่งที่ผิดไม่ได้ทำให้เว็บเดียวล่ม
 * แต่ทำให้ Apache ไม่ยอมโหลดค่าตั้งทั้งเครื่อง แล้วเว็บ "ทุกเว็บ" ดับพร้อมกัน
 * เทสต์ชุดนี้จึงเน้นลำดับการทำงานที่ห้ามสลับ และการที่ไฟล์กับฐานข้อมูลต้องตรงกันเสมอ
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Security\Permissions;

group('SSL — ใบรับรองและการสลับโหมด HTTPS');

/** เว็บไซต์ตัวอย่างสำหรับเรนเดอร์ vhost */
function sslSite(string $mode = 'off', array $aliases = []): Site
{
    return new Site(
        id: 1,
        domain: 'example.test',
        owner: new UserAccount(7, 'sitefiles'),
        phpVersion: '8.4',
        sslMode: $mode,
        aliases: $aliases,
    );
}

test('อีเมลที่ไม่ถูกต้องถูกปฏิเสธก่อนถึง certbot', static function (): void {
    foreach (['', 'ไม่ใช่อีเมล', 'a@', '@b.com', "a@b.com\nX-Injected: 1", 'a b@c.com'] as $email) {
        assertRejects(
            ValidationError::class,
            static fn () => CertbotManager::assertEmail($email),
            'อีเมลไม่ถูกต้องต้องถูกปฏิเสธ: ' . var_export($email, true),
        );
    }

    assertSame('admin@example.com', CertbotManager::assertEmail('admin@example.com'), 'อีเมลที่ถูกต้องต้องผ่าน');
});

test('โหมด SSL รับเฉพาะสามค่าที่ฐานข้อมูลยอม', static function (): void {
    foreach (['off', 'on', 'forced'] as $mode) {
        assertSame($mode, Site::assertSslMode($mode), "โหมด {$mode} ต้องผ่าน");
    }

    // ค่านอกรายการต้องตายที่นี่ ไม่ใช่ไปตายที่ CHECK constraint ของ SQLite
    // ซึ่งจะกลายเป็น error ของฐานข้อมูลที่ผู้ใช้อ่านไม่รู้เรื่อง
    foreach (['ON', 'yes', '1', 'force', '', 'off;drop'] as $mode) {
        assertRejects(
            ValidationError::class,
            static fn () => Site::assertSslMode($mode),
            "โหมด '{$mode}' ต้องถูกปฏิเสธ",
        );
    }
});

test('vhost แบบไม่มี SSL ต้องไม่มีบล็อก 443 หลุดมา', static function (): void {
    $driver = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    $conf = $driver->renderVhost(sslSite('off'), $executor);

    assertTrue(!str_contains($conf, 'SSLEngine'), 'เว็บที่ปิด SSL ต้องไม่มี SSLEngine');
    assertTrue(!str_contains($conf, ':443'), 'เว็บที่ปิด SSL ต้องไม่มีบล็อก 443');
    assertTrue(!str_contains($conf, 'Strict-Transport-Security'), 'เว็บที่ปิด SSL ต้องไม่มี HSTS');
});

test('กฎกันไฟล์ลับต้องอยู่ในบล็อก 443 ด้วย ไม่ใช่แค่ 80', static function (): void {
    // เคยเกือบพลาด: ถ้าเทมเพลต SSL คัดลอกกฎเองแล้วแก้ไม่ครบทั้งสองที่
    // จะได้ช่องโหว่ที่เปิดเฉพาะบน HTTPS ซึ่งเป็นทางที่คนใช้จริงมากกว่า
    $driver = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    $conf = $driver->renderVhost(sslSite('on'), $executor);
    $blocks = preg_split('/<VirtualHost /', $conf);
    $ssl = '';

    foreach ($blocks as $block) {
        if (str_starts_with($block, '*:443>')) {
            $ssl = $block;
        }
    }

    assertTrue($ssl !== '', 'ต้องมีบล็อก 443');

    foreach (['\.env', '\.git', 'DirectoryMatch', 'X-Powered-By', 'SetHandler'] as $needle) {
        assertTrue(
            str_contains($ssl, $needle),
            "บล็อก 443 ต้องมีกฎ {$needle} เหมือนบล็อก 80",
        );
    }
});

test('HSTS ต้องมีเฉพาะตอนบังคับ HTTPS เท่านั้น', static function (): void {
    // ใส่ HSTS ตอนที่ยังเปิดทั้ง HTTP และ HTTPS แล้วผู้ดูแลเปลี่ยนใจปิด SSL ทีหลัง
    // = ผู้ที่เคยเข้าเว็บแล้วจะเข้าไม่ได้เลยจนกว่า header จะหมดอายุ แก้ที่เซิร์ฟเวอร์ไม่ได้
    $driver = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    assertTrue(
        !str_contains($driver->renderVhost(sslSite('on'), $executor), 'Strict-Transport-Security'),
        'โหมด on ต้องไม่มี HSTS',
    );

    assertTrue(
        str_contains($driver->renderVhost(sslSite('forced'), $executor), 'Strict-Transport-Security'),
        'โหมด forced ต้องมี HSTS',
    );
});

test('บังคับ HTTPS ต้องยกเว้นเส้นทางตรวจสอบของ Let\'s Encrypt', static function (): void {
    // ถ้า redirect ทุกอย่างไป HTTPS การต่ออายุอัตโนมัติจะล้มเงียบ ๆ
    // แล้วใบรับรองหมดอายุใน 90 วัน โดยไม่มีอะไรเตือนจนกว่าเว็บจะเข้าไม่ได้
    $driver = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    $conf = $driver->renderVhost(sslSite('forced'), $executor);

    assertTrue(str_contains($conf, 'acme-challenge'), 'ต้องมี Alias ของ acme-challenge');
    assertTrue(
        str_contains($conf, 'RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/'),
        'กฎ redirect ต้องยกเว้น acme-challenge',
    );
});

test('โดเมนสำรองต้องถูกใส่ใน vhost ทั้งสองบล็อก', static function (): void {
    $driver = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    $conf = $driver->renderVhost(sslSite('on', ['www.example.test']), $executor);

    assertSame(
        2,
        substr_count($conf, 'ServerAlias www.example.test'),
        'โดเมนสำรองต้องอยู่ทั้งบล็อก 80 และ 443 ไม่งั้นจะเข้าได้ทางเดียว',
    );
});

test('capability ของ SSL ทุกตัวใช้ permission ของหมวด hosting', static function (): void {
    $registry = new CapabilityRegistry();

    foreach ($registry->describe() as $name => $meta) {
        if (!str_starts_with($name, 'ssl.')) {
            continue;
        }

        assertTrue(
            in_array($meta['permission'], ['ssl.view', 'ssl.manage'], true),
            "{$name} ต้องใช้ permission ของ ssl ไม่ใช่ {$meta['permission']}",
        );

        // ใบรับรองเป็นของเว็บไซต์ ไม่ใช่ของเครื่อง ผู้ดูแลเว็บไซต์จึงต้องทำเองได้
        assertTrue(
            Permissions::roleHas(Permissions::WEBADMIN, $meta['permission']),
            "ผู้ดูแลเว็บไซต์ต้องมีสิทธิ์ {$meta['permission']} เพื่อจัดการใบรับรองของเว็บตัวเอง",
        );
    }
});

test('capability ที่เปลี่ยนแปลงระบบต้องตรวจสิทธิ์เจ้าของเว็บไซต์', static function (): void {
    // ใบรับรองผูกกับโดเมน ถ้าข้ามการตรวจนี้ได้ ผู้ดูแลเว็บไซต์หนึ่ง
    // ก็สั่งขอหรือลบใบรับรองของโดเมนคนอื่นบนเครื่องเดียวกันได้ทันที
    foreach (['SslIssue', 'SslRenew', 'SslSetMode', 'SslDelete'] as $class) {
        $source = (string) file_get_contents(PHPCP_ROOT . "/src/Agent/Capability/{$class}.php");

        assertTrue(
            str_contains($source, '$this->assertSiteAccess($context, $args[\'site_id\'])'),
            "{$class} ต้องเรียก assertSiteAccess()",
        );
    }
});

test('ลบใบรับรองต้องปิด HTTPS ก่อนลบไฟล์เสมอ', static function (): void {
    // สลับลำดับ = Apache ค้างอยู่กับ config ที่ชี้ไปยังไฟล์ที่ไม่มีแล้ว
    // แล้วจะไม่ยอมโหลดค่าตั้งทั้งเครื่อง เว็บทุกเว็บบนเซิร์ฟเวอร์ดับพร้อมกัน
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SslDelete.php');

    $rewriteAt = strpos($source, 'rewriteVhost');
    $deleteAt = strpos($source, '$this->certbot()->delete');

    assertTrue($rewriteAt !== false && $deleteAt !== false, 'ต้องมีทั้งการเขียน vhost ใหม่และการลบไฟล์');
    assertTrue($rewriteAt < $deleteAt, 'ต้องเขียน vhost ใหม่ก่อนลบไฟล์ใบรับรอง');
});

test('บันทึกฐานข้อมูลต้องอยู่ก่อน reload ไม่ใช่หลัง', static function (): void {
    // เคยเป็นบั๊กจริง: reload ที่ล้ม (เช่น systemd ใช้งานไม่ได้) โยน error ออกไป
    // ก่อนถึงบรรทัดบันทึก ผลคือไฟล์บนดิสก์เป็นค่าใหม่แต่ฐานข้อมูลยังเป็นค่าเก่า
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteSetPhp.php');

    $saveAt = strpos($source, '$repository->setPhpVersion');
    $reloadAt = strpos($source, '$provisioner->reload');

    assertTrue($saveAt !== false && $reloadAt !== false, 'ต้องมีทั้งการบันทึกและการ reload');
    assertTrue($saveAt < $reloadAt, 'ต้องบันทึกฐานข้อมูลก่อนสั่ง reload');
});

test('ลบใบรับรองนอกไดเรกทอรีที่กำหนดไม่ได้', static function (): void {
    $manager = new CertbotManager();
    $executor = new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ssl-' . getmypid());

    foreach (['../../etc/passwd', '..', 'a/../../b', '/etc/shadow'] as $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => $manager->deleteSelfSigned($executor, $bad),
            "เส้นทาง '{$bad}' ต้องถูกปฏิเสธ",
        );
    }
});

test('ใบรับรองที่เซ็นเองต้องไม่ใช่ใบ CA', static function (): void {
    // `openssl req -x509` ตั้ง CA:TRUE ให้เองถ้าไม่ระบุ ซึ่งทำให้ Apache เตือน AH01906
    // และเบราว์เซอร์รุ่นใหม่ปฏิเสธใบนั้นทันที — เจอตอนทดสอบใน container จริง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Ssl/CertbotManager.php');

    assertTrue(
        str_contains($source, 'basicConstraints=critical,CA:FALSE'),
        'ใบที่เซ็นเองต้องระบุ CA:FALSE',
    );
    assertTrue(
        str_contains($source, 'extendedKeyUsage=serverAuth'),
        'ใบที่เซ็นเองต้องระบุว่าใช้กับเซิร์ฟเวอร์',
    );
});

test('ไม่ใช้ปลั๊กอิน apache ของ certbot', static function (): void {
    // ปลั๊กอิน --apache จะเข้าไปแก้ vhost เอง แต่ vhost ทุกไฟล์ที่นี่ถูกเขียนทับ
    // ทุกครั้งที่เว็บไซต์เปลี่ยน การแก้ของ certbot จึงหายไปเงียบ ๆ ในครั้งถัดไป
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Ssl/CertbotManager.php');

    assertTrue(!str_contains($source, "'--apache'"), 'ต้องไม่ใช้ --apache');
    assertTrue(!str_contains($source, "'--nginx'"), 'ต้องไม่ใช้ --nginx');
    assertTrue(str_contains($source, "'--webroot'"), 'ต้องใช้ --webroot');
});

test('ออกใบรับรองแล้วต้องมีทางเปิด HTTPS จริงจากหน้าจอ', static function (): void {
    /*
     * **บั๊กที่อยู่มานาน และผู้ใช้เห็นเป็น "สร้าง SSL แล้วทำไมยังขึ้นไม่ปลอดภัย":**
     *
     * `ssl.issue` ตั้งใจไม่เปิด HTTPS ให้เอง (ดูคอมเมนต์ในคลาสนั้น) การเปิดเป็น
     * คำสั่งที่สอง `ssl.set_mode` ซึ่งมีเส้นทางรออยู่แล้วที่
     * `PUT /api/v2/certificates/{site_id}/mode` · แต่**ไม่มีหน้าจอไหนเรียกมันเลย
     * สักหน้า** ผลคือ `sites.ssl_mode` ค้างที่ `off` ตลอดกาล vhost ไม่มีบล็อก
     * `:443` และใบรับรองที่ออกมาถูกต้องทุกอย่างนอนอยู่บนดิสก์เฉย ๆ
     *
     * เส้นทางที่ไม่มีปุ่มเรียก = ความสามารถที่ไม่มีอยู่จริงสำหรับคนใช้งาน
     */
    $found = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) file_get_contents($file);

        if (str_contains($html, '/api/v2/certificates/{site_id}/mode')) {
            $found[] = basename($file);
        }
    }

    assertTrue(
        in_array('certificates.html', $found, true),
        'หน้าใบรับรองต้องมีปุ่มเปิด/บังคับ/ปิด HTTPS ไม่งั้นใบที่ออกมาไม่มีวันถูกใช้',
    );
    assertTrue(
        in_array('site.html', $found, true),
        'หน้าเว็บไซต์รายตัวต้องเปิด HTTPS ได้ด้วย — เป็นหน้าที่ผู้ดูแลอยู่ตอนเพิ่งสร้างเว็บเสร็จ',
    );

    // ทั้งสามโหมดต้องเรียกได้ ไม่ใช่เปิดได้อย่างเดียวแล้วปิดไม่ได้
    $certificates = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/certificates.html');

    foreach (['"mode": "on"', '"mode": "forced"', '"mode": "off"'] as $mode) {
        assertTrue(str_contains($certificates, $mode), "หน้าใบรับรองต้องสั่ง {$mode} ได้");
    }
});

test('สร้างผู้ใช้พร้อม SSL ต้องสั่งทั้งสองคำสั่ง ตามลำดับ', static function (): void {
    // ออกใบรับรองอย่างเดียวแล้วหยุด = ส่งมอบเว็บที่เบราว์เซอร์ยังขึ้น "ไม่ปลอดภัย"
    // ให้ลูกค้า ซึ่งเป็นสิ่งแรกที่ลูกค้าเห็นและเป็นสิ่งเดียวที่ผู้ดูแลไม่ได้เห็น
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');

    $issueAt = strpos($source, "'ssl.issue'");
    $modeAt = strpos($source, "'ssl.set_mode'");

    assertTrue($issueAt !== false, 'ฟอร์มสร้างผู้ใช้ต้องออกใบรับรองได้ในคำสั่งเดียวกัน');
    assertTrue($modeAt !== false, 'และต้องเปิด HTTPS ต่อให้ด้วย ไม่ใช่ปล่อยไว้เป็นงานที่ต้องจำไปทำต่อ');
    assertTrue($issueAt < $modeAt, 'ต้องออกใบรับรองก่อนแล้วค่อยเปิด — สลับลำดับจะถูกปฏิเสธเพราะยังไม่มีใบ');

    // ใบที่เซ็นเองต้องไม่ถูกบังคับ redirect — เบราว์เซอร์ไม่เชื่อถือใบนั้น
    // การบังคับทุกคนไปทาง HTTPS จะเหลือแต่หน้าเตือนเป็นหน้าเดียวของเว็บ
    assertTrue(
        str_contains($source, "\$sslMethod === 'letsencrypt' ? 'forced' : 'on'"),
        'letsencrypt เท่านั้นที่บังคับ HTTPS · ใบที่เซ็นเองเปิดไว้เฉย ๆ',
    );
});

test('ขั้นตอนเสริมตอนสร้างบัญชีต้องไม่ล้มทั้งคำสั่ง', static function (): void {
    /*
     * บัญชีถูกสร้างไปแล้วจริงตอนที่ขั้นตอนพวกนี้เริ่มทำงาน · ถ้าปล่อยให้ throw
     * ออกไป ผู้ดูแลจะอ่านคำตอบว่า "สร้างไม่สำเร็จ" แล้วกดซ้ำเข้าไปชนชื่อผู้ใช้ที่
     * มีอยู่แล้ว โดยที่รหัสผ่านสุ่มของรอบแรกหายไปแล้วไม่มีที่ให้ดูอีก
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');

    foreach (['ssl.issue', 'db.create', 'mail.user_notice'] as $capability) {
        $at = strpos($source, "'{$capability}'");

        assertTrue($at !== false, "ฟอร์มสร้างผู้ใช้ต้องเรียก {$capability}");

        // ต้องมี catch ครอบอยู่ในระยะที่เป็นบล็อกเดียวกันจริง ๆ
        $tail = substr($source, $at, 3000);

        assertTrue(
            str_contains($tail, 'catch (\Throwable $e)'),
            "{$capability} ต้องถูกครอบด้วย catch — ล้มแล้วต้องรายงานข้าง ๆ ความสำเร็จ ไม่ใช่ล้มทั้งคำสั่ง",
        );
    }
});

test('ตารางใบรับรองต้องแยก "มีใบแต่ไม่ได้ใช้" ออกจาก "ยังไม่มีใบ"', static function (): void {
    /*
     * สองสถานะนี้ต่างกันคนละเรื่อง แต่คอลัมน์โหมดเดิมพิมพ์ `off` เหมือนกันทั้งคู่
     * ข้าง ๆ ป้ายสถานะที่เขียนว่า `valid` — อ่านรวมกันแล้วเหมือนเว็บนี้เรียบร้อยดี
     * ทั้งที่ vhost ไม่มีบล็อก `:443` และผู้เข้าชมทุกคนยังได้ HTTP ล้วน
     */
    $flatten = (new ReflectionClass(\Phpcp\Http\V2\CertificatesController::class))->getMethod('flatten');
    $flatten->setAccessible(true);

    $withCertificate = $flatten->invoke(null, [
        'site_id' => 1,
        'domain' => 'example.test',
        'ssl_mode' => 'off',
        'certificate' => ['status' => 'valid', 'days_left' => 80],
    ]);

    assertSame('warn', $withCertificate['ssl_tone'], 'ใบที่ออกแล้วแต่ยังไม่ได้เปิดใช้ต้องเตือน ไม่ใช่สีเทาเฉย ๆ');
    assertSame('Certificate not in use', $withCertificate['ssl_mode_label'], 'ต้องบอกตรง ๆ ว่ามีใบแต่ไม่ได้ใช้');

    $noCertificate = $flatten->invoke(null, [
        'site_id' => 2,
        'domain' => 'plain.test',
        'ssl_mode' => 'off',
        'certificate' => ['status' => 'none'],
    ]);

    assertSame('muted', $noCertificate['ssl_tone'], 'เว็บที่ยังไม่ได้ขอใบเลยไม่ใช่ความผิดปกติ');
    assertSame('HTTPS off', $noCertificate['ssl_mode_label'], 'ยังไม่มีใบต้องอ่านว่าปิดอยู่เฉย ๆ');

    foreach (['on' => 'HTTPS on', 'forced' => 'HTTPS forced'] as $mode => $label) {
        $row = $flatten->invoke(null, [
            'site_id' => 3,
            'domain' => 'secure.test',
            'ssl_mode' => $mode,
            'certificate' => ['status' => 'valid', 'days_left' => 80],
        ]);

        assertSame('ok', $row['ssl_tone'], "โหมด {$mode} คือสถานะที่ถูกต้อง");
        assertSame($label, $row['ssl_mode_label'], "โหมด {$mode} ต้องอ่านออกว่าเปิดอยู่");
    }

    // ป้ายทุกอันต้องมีคำแปลไทย ไม่งั้นตารางจะขึ้นอังกฤษกลางหน้าจอไทย
    $lang = json_decode(
        (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/lang/th.json'),
        true,
    );

    foreach (['HTTPS off', 'HTTPS on', 'HTTPS forced', 'Certificate not in use'] as $label) {
        assertTrue(is_array($lang) && array_key_exists($label, $lang), "ป้าย '{$label}' ต้องมีคำแปลไทย");
    }
});
