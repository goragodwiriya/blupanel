<?php

declare(strict_types=1);

/**
 * ไฟล์ตั้งค่าเพิ่มเติมที่ผู้ดูแลเขียนเอง — ขอบเขตอำนาจที่ห้ามหลุด
 *
 * คุณสมบัตินี้เปิดให้เขียนไฟล์ที่**เว็บเซิร์ฟเวอร์อ่านจริง** จึงเป็นจุดที่ความผิดพลาด
 * ราคาแพงที่สุดจุดหนึ่งของทั้งระบบ · สิ่งที่ชุดนี้เฝ้าคือสามอย่าง:
 *
 *   1. เส้นทางไฟล์ต้องไม่มีทางมาจากผู้ใช้ — ประกอบจากโดเมนที่ผ่าน Validator เท่านั้น
 *   2. เขียนแล้วต้องผ่านตัวตรวจของเว็บเซิร์ฟเวอร์เอง ไม่ใช่ตัวตรวจที่เราเขียนเอง
 *   3. ผ่านตัวตรวจแล้วยังต้องถอนคืนได้ — configtest ไม่ได้ตอบว่าเว็บยังใช้งานได้
 */

use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Template;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\WebServer\CustomConfig;

group('CustomConfig — ไฟล์ตั้งค่าที่ผู้ดูแลเขียนเอง');

test('เส้นทางไฟล์ต้องประกอบจากโดเมนที่ผ่านการตรวจเท่านั้น', static function (): void {
    /*
     * ผู้เรียกส่งได้แค่ "เนื้อไฟล์" ไม่ใช่ "จะเขียนที่ไหน" · ถ้าชื่อโดเมนหลุดออกนอก
     * กติกาได้ จะเขียนทับไฟล์ไหนบนเครื่องก็ได้ผ่านคุณสมบัตินี้
     */
    foreach (['../../etc/passwd', 'a/../../..', 'x y.com', "a\nb.com", '', '.', '/etc/shadow'] as $bad) {
        $rejected = false;

        try {
            CustomConfig::sitePath('apache', $bad);
        } catch (\Throwable) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธชื่อโดเมน: {$bad}");
    }

    $path = CustomConfig::sitePath('apache', 'example.com');

    assertSame('/etc/phpcp/custom/apache/example.com/custom.conf', $path, 'เส้นทางต้องอยู่ใต้รากที่กำหนด');
    assertTrue(str_starts_with($path, CustomConfig::ROOT . '/'), 'ต้องอยู่ใต้ราก /etc/phpcp/custom เสมอ');
});

test('ชนิดเว็บเซิร์ฟเวอร์ต้องอยู่ในรายการที่รู้จัก', static function (): void {
    // ไฟล์ของ Apache กับ nginx ไวยากรณ์คนละแบบ · ถ้าปนกันได้ การสลับเซิร์ฟเวอร์จะทำให้
    // configtest ล้มทั้งเครื่องโดยที่ผู้ดูแลไม่ได้แก้อะไรเลยในวันนั้น
    foreach (['../apache', 'httpd', '', 'nginx/../..'] as $bad) {
        $rejected = false;

        try {
            CustomConfig::siteDirectory($bad, 'example.com');
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธชนิดเซิร์ฟเวอร์: {$bad}");
    }

    assertSame(
        '/etc/phpcp/custom/nginx/example.com',
        CustomConfig::siteDirectory('nginx', 'example.com'),
        'ชนิดที่รู้จักต้องได้เส้นทางของตัวเอง',
    );
});

test('เนื้อไฟล์ที่ใหญ่เกินไปหรือมีไบต์ศูนย์ต้องถูกปฏิเสธ', static function (): void {
    // ไบต์ศูนย์ทำให้ไฟล์ถูกอ่านไม่ครบโดยไม่มีข้อผิดพลาด — เป็นวิธีซ่อนคำสั่งจากคนที่
    // เปิดไฟล์ดูด้วยตา แต่เว็บเซิร์ฟเวอร์ยังอ่านได้ (หรือกลับกัน แล้วแต่ตัวอ่าน)
    $rejected = false;

    try {
        CustomConfig::assertContent("Header set X-A \"1\"\0Header set X-B \"2\"");
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'ไบต์ศูนย์ต้องถูกปฏิเสธ');

    $tooBig = false;

    try {
        CustomConfig::assertContent(str_repeat('# ', 40000));
    } catch (ValidationError) {
        $tooBig = true;
    }

    assertTrue($tooBig, 'ไฟล์ที่ใหญ่เกินต้องถูกปฏิเสธ');

    // ค่าปกติต้องผ่านและลงท้ายด้วยขึ้นบรรทัดใหม่เสมอ
    assertSame(
        "Header set X-Test \"1\"\n",
        CustomConfig::assertContent("Header set X-Test \"1\"\r\n\r\n"),
        'ค่าปกติต้องผ่านและลงท้ายด้วยขึ้นบรรทัดใหม่เสมอ',
    );
    assertSame('', CustomConfig::assertContent("\n\n"), 'ค่าว่างต้องยังเป็นค่าว่าง ไม่ใช่บรรทัดเปล่า');
});

test('vhost ที่ระบบสร้างต้องอ่านไฟล์ของผู้ดูแลเป็นอันสุดท้าย', static function (): void {
    /*
     * **ลำดับคือทั้งหมดของคุณสมบัตินี้** — ถ้าไฟล์ของผู้ดูแลถูกอ่านก่อนค่าเริ่มต้น
     * ค่าที่เขียนจะถูกทับทันทีและไม่มีอะไรบอกว่าทำไมมันไม่มีผล
     *
     * ตรวจจากเนื้อไฟล์ที่เรนเดอร์จริง ไม่ใช่จากเทมเพลต — ตัวแปรที่ไม่ถูกส่งเข้ามาจะ
     * เหลือเป็น `{{CUSTOM_DIR}}` ค้างอยู่ ซึ่งเทสต์ที่อ่านเทมเพลตดิบ ๆ จับไม่ได้
     */
    $templates = new Template(PHPCP_ROOT . '/templates');

    $apache = $templates->render('apache/vhost-body.conf.tpl', [
        'DOCROOT' => '/srv/phpcp/sites/example.com/public',
        'FPM_SOCKET' => '/run/phpcp/example.com-8.4.sock',
        'ERROR_LOG' => '/var/log/phpcp/example.com-error.log',
        'ACCESS_LOG' => '/var/log/phpcp/example.com-access.log',
        'CUSTOM_DIR' => '/etc/phpcp/custom/apache/example.com',
    ]);

    assertTrue(
        str_contains($apache, 'IncludeOptional /etc/phpcp/custom/apache/example.com/*.conf'),
        'Apache ต้อง include ไดเรกทอรีของผู้ดูแล',
    );
    assertTrue(!str_contains($apache, '{{'), 'ต้องไม่มีตัวแปรค้างในไฟล์ที่เรนเดอร์แล้ว');

    // ต้องอยู่**ท้ายสุด** — เทียบตำแหน่งกับกฎกันไฟล์ที่ต้องมาก่อน
    $deny = strpos($apache, 'Require all denied');
    $include = strpos($apache, 'IncludeOptional');

    assertTrue($deny !== false && $include !== false && $include > $deny, 'ไฟล์ของผู้ดูแลต้องถูกอ่านหลังค่าเริ่มต้น');

    $nginx = $templates->render('nginx/vhost-body.conf.tpl', [
        'DOCROOT' => '/srv/phpcp/sites/example.com/public',
        'FPM_SOCKET' => '/run/phpcp/example.com-8.4.sock',
        'ERROR_LOG' => '/var/log/phpcp/example.com-error.log',
        'ACCESS_LOG' => '/var/log/phpcp/example.com-access.log',
        'UPLOAD_LIMIT' => 64,
        'CUSTOM_DIR' => '/etc/phpcp/custom/nginx/example.com',
    ]);

    /*
     * nginx ล้มทันทีถ้า `include` ชี้ไปไฟล์ที่ไม่มีอยู่ · ต้องเป็นรูปแบบ mask (`*.conf`)
     * ซึ่ง "ไม่ตรงกับไฟล์ใดเลย" ไม่ถือเป็นข้อผิดพลาด — ไม่งั้นเว็บที่ยังไม่เคยเขียน
     * ค่าเพิ่มเติมจะสตาร์ตไม่ขึ้นทั้งเครื่อง
     */
    assertTrue(
        str_contains($nginx, 'include /etc/phpcp/custom/nginx/example.com/*.conf;'),
        'nginx ต้อง include แบบ mask ที่ทนกับไฟล์ที่ยังไม่มี',
    );
    assertTrue(!str_contains($nginx, '{{'), 'ต้องไม่มีตัวแปรค้างในไฟล์ที่เรนเดอร์แล้ว');
});

test('การเขียนต้องผ่านตัวตรวจของเว็บเซิร์ฟเวอร์เองและถอนคืนได้', static function (): void {
    /*
     * ตรวจจากโค้ดว่าเดินครบสามด่าน — ไม่ใช่แค่เขียนไฟล์แล้วจบ
     *
     * `configtest` ตอบแค่ว่าไวยากรณ์ถูก **ไม่ได้ตอบว่าเว็บยังใช้งานได้** · กฎที่เขียน
     * ถูกทุกตัวอักษรแต่บล็อกทุกคำขอ หรือ redirect วนไม่รู้จบ ผ่าน configtest สบาย ๆ
     * ด่านที่กันกรณีนั้นคือ RollbackGuard ซึ่งเป็นด่านที่คนมักลืม
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteCustomConfig.php'),
    );

    assertTrue(str_contains($code, 'new ConfigTransaction'), 'ต้องเขียนผ่าน ConfigTransaction เพื่อให้คืนค่าเดิมได้');
    assertTrue(str_contains($code, '->testConfig($executor)'), 'ต้องให้ตัวตรวจของเว็บเซิร์ฟเวอร์เองตัดสิน');
    assertTrue(str_contains($code, 'RollbackGuard'), 'ต้องตั้งเวลาถอนคืน — configtest ผ่านไม่ได้แปลว่าเว็บใช้งานได้');
    assertTrue(str_contains($code, "'settings.manage'"), 'ต้องเป็นสิทธิ์ของผู้ดูแลเครื่อง ไม่ใช่เจ้าของเว็บ');

    // เส้นทางต้องมาจาก CustomConfig เท่านั้น ห้ามประกอบเส้นทางเองในตัว capability
    assertTrue(
        !preg_match("~'/etc/[^']*'~", $code),
        'ห้ามฮาร์ดโค้ดเส้นทางในตัว capability — ต้องผ่าน CustomConfig ที่ตรวจชื่อโดเมนให้',
    );
});

test('ทะเบียนไฟล์ต้องแยก "แก้ได้" กับ "ดูได้อย่างเดียว" ออกจากกันชัดเจน', static function (): void {
    /*
     * **`writable` ต้องเป็นคำตอบจากเซิร์ฟเวอร์ ไม่ใช่การเดาของหน้าจอจากชื่อไฟล์**
     *
     * ถ้าหน้าจอเป็นคนตัดสิน วันที่มีคนเพิ่มไฟล์ชนิดใหม่เข้ามาจะได้ปุ่มแก้ไขบนไฟล์ที่
     * แก้ไม่ได้ทันที โดยไม่มีอะไรเตือน
     */
    $files = ConfigFileCatalog::forSite(7, 'example.com', 'apache', [
        '/etc/phpcp/vhosts.d/example.com.conf',
        '/etc/phpcp/vhosts.d/example.com-ssl.conf',
    ]);

    $writable = array_values(array_filter(
        $files,
        static fn (array $f): bool => $f['kind'] === ConfigFileCatalog::KIND_WRITABLE,
    ));

    assertSame(1, count($writable), 'ต้องมีไฟล์ที่แก้ได้ไฟล์เดียวเท่านั้น');
    assertSame(
        CustomConfig::sitePath('apache', 'example.com'),
        $writable[0]['path'],
        'ไฟล์ที่แก้ได้ต้องเป็นไฟล์ส่วนเสริมเท่านั้น ไม่ใช่ไฟล์ที่ระบบสร้าง',
    );

    // ไฟล์ที่ระบบสร้างต้องอยู่ในทะเบียน (เพื่อให้เปิดดูได้) แต่ต้องไม่ใช่ชนิดที่แก้ได้
    foreach ($files as $file) {
        if (str_contains((string) $file['path'], 'vhosts.d')) {
            assertSame(
                ConfigFileCatalog::KIND_GENERATED,
                $file['kind'],
                'ไฟล์ที่ระบบสร้างต้องเป็นชนิดอ่านอย่างเดียวเสมอ',
            );
        }
    }
});

test('คีย์ที่ไม่อยู่ในทะเบียนต้องหาไฟล์ไม่เจอ ไม่ว่าจะพยายามอย่างไร', static function (): void {
    /*
     * นี่คือกติกาที่ทำให้ทั้งเรื่องปลอดภัย: **หน้าจออ้างไฟล์ด้วยคีย์ ไม่ใช่เส้นทาง**
     * ต่อให้ส่งอะไรมาก็ได้แค่ "ไม่พบในทะเบียน" ไม่มีทางไปโผล่เป็นการอ่านไฟล์อื่นบนเครื่อง
     */
    $files = ConfigFileCatalog::forSite(7, 'example.com', 'apache', ['/etc/phpcp/vhosts.d/example.com.conf']);

    foreach (['site.8.custom', 'site.7.vhost.9', 'unknown', 'site.7.custom.extra'] as $key) {
        assertTrue(
            ConfigFileCatalog::find($files, $key) === null,
            "คีย์นอกทะเบียนต้องหาไม่เจอ: {$key}",
        );
    }

    foreach (['../../etc/shadow', 'site/../7', "a\nb", str_repeat('a', 200)] as $bad) {
        $rejected = false;

        try {
            ConfigFileCatalog::assertKey($bad);
        } catch (\Throwable) {
            $rejected = true;
        }

        assertTrue($rejected, "รูปแบบคีย์ที่ผิดต้องถูกปฏิเสธ: " . substr($bad, 0, 20));
    }

    assertTrue(ConfigFileCatalog::find($files, 'site.7.custom') !== null, 'คีย์ที่ถูกต้องต้องหาเจอ');
});

test('ตัวเขียนต้องปฏิเสธคีย์ของไฟล์ที่ระบบสร้าง ไม่ใช่แค่ไม่แสดงปุ่ม', static function (): void {
    /*
     * **ปุ่มที่ไม่ขึ้นบนหน้าจอไม่ใช่ด่านความปลอดภัย** — คำขอที่ประกอบเองยังส่งคีย์ของ
     * ไฟล์ที่ระบบสร้างมาได้เสมอ · ด่านต้องอยู่ที่ชั้นล่างสุดที่ลงมือเขียนจริง
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteCustomConfig.php'),
    );

    assertTrue(str_contains($code, 'ConfigFileCatalog::find('), 'ตัวเขียนต้องค้นทะเบียนเอง');
    assertTrue(str_contains($code, 'KIND_WRITABLE'), 'ต้องเทียบชนิดของไฟล์ก่อนเขียน');
});

test('ไฟล์ตั้งต้นต้องอธิบายได้ครบและไม่มีคำสั่งที่ทำงานจริงสักบรรทัด', static function (): void {
    /*
     * **ไฟล์ตั้งต้นที่บันทึกทันทีต้องไม่เปลี่ยนพฤติกรรมของเว็บแม้แต่นิดเดียว**
     *
     * ถ้ามีใครเผลอเอาเครื่องหมายคอมเมนต์ออกจากตัวอย่างสักบรรทัด เว็บใหม่ทุกเว็บที่เปิด
     * หน้านี้แล้วกดบันทึกจะได้คำสั่งนั้นติดไปด้วยโดยไม่มีใครตั้งใจ — และเป็นการเปลี่ยน
     * พฤติกรรมที่ไม่มีอะไรบนหน้าจอบอกเลย
     *
     * คำอธิบายอยู่ในไฟล์เพราะเป็นที่ที่ผู้ดูแลระบบคาดว่าจะเจอ และติดไปกับไฟล์เสมอ
     * ไม่ว่าจะเปิดจากหน้าเว็บหรือ `cat` ผ่าน SSH
     */
    $templates = new Template(PHPCP_ROOT . '/templates');
    $seed = new CustomConfig();

    foreach (['apache' => '<VirtualHost>', 'nginx' => 'server { }'] as $server => $context) {
        $content = $seed->seed($templates, $server, 'example.com');

        assertTrue($content !== '', "ต้องมีไฟล์ตั้งต้นของ {$server}");
        assertTrue(!str_contains($content, '{{'), "ไฟล์ตั้งต้นของ {$server} ต้องไม่มีตัวแปรค้าง");
        assertTrue(str_contains($content, 'example.com'), "ต้องเติมชื่อโดเมนจริงลงไป");

        // ทุกบรรทัดต้องเป็นคอมเมนต์หรือบรรทัดว่าง — ไม่มีคำสั่งที่ทำงานจริงเลย
        $active = array_values(array_filter(
            preg_split('/\R/', $content) ?: [],
            static fn (string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), '#'),
        ));

        assertSame([], $active, "ไฟล์ตั้งต้นของ {$server} ต้องไม่มีคำสั่งที่ทำงานจริง");

        // สามข้อที่ผู้ดูแลไม่มีทางรู้เองและเดาผิดแล้วเสียเวลา
        assertTrue(str_contains($content, $context), "ต้องบอกว่าไฟล์อยู่ในบริบท {$context}");
        assertTrue(str_contains($content, 'ท้ายสุด'), 'ต้องบอกว่าถูกอ่านท้ายสุดจึงเขียนทับค่าเริ่มต้นได้');
        assertTrue(str_contains($content, 'ยืนยัน'), 'ต้องบอกว่าไม่กดยืนยันแล้วระบบคืนค่าเดิม');
    }
});

test('ตัวเขียนต้องไม่เติมหัวไฟล์เอง — ไม่งั้นบันทึกซ้ำได้หัวไฟล์ซ้อนกันไปเรื่อย ๆ', static function (): void {
    // คำอธิบายมากับไฟล์ตั้งต้นแล้ว · ถ้าตัวเขียนเติมซ้ำทุกครั้งที่บันทึก ไฟล์จะยาวขึ้น
    // เรื่อย ๆ ด้วยหัวไฟล์ที่ซ้ำกัน ซึ่งผู้ใช้ต้องมาไล่ลบเองทุกครั้ง
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteCustomConfig.php'),
    );

    assertTrue(
        str_contains($code, "write(\$path, \$args['content']"),
        'ต้องเขียนเนื้อไฟล์ตามที่ส่งมาตรง ๆ',
    );
    assertTrue(!str_contains($code, 'header()'), 'ต้องไม่เติมหัวไฟล์เองอีกแล้ว');
});

test('ระบบเมล — ทะเบียนแยกไฟล์ที่แก้ได้ออกจากไฟล์ที่ระบบสร้าง', static function (): void {
    /*
     * ขอบเขตของเมลเป็นของทั้งเครื่อง ไม่ผูกกับเว็บไซต์ · แต่กติกาเดียวกันทุกข้อ:
     * อ้างด้วยคีย์ ไม่ใช่เส้นทาง · แก้ได้เฉพาะไฟล์ของผู้ดูแล · ไฟล์ที่ระบบสร้างเปิดดูได้
     */
    $files = ConfigFileCatalog::forMail(['/etc/postfix/main.cf', '/etc/dovecot/conf.d/99-phpcp.conf']);

    $writable = array_values(array_filter(
        $files,
        static fn (array $f): bool => $f['kind'] === ConfigFileCatalog::KIND_WRITABLE,
    ));

    assertSame(2, count($writable), 'ต้องแก้ได้สองไฟล์: ของ Postfix กับของ Dovecot');
    assertSame(CustomConfig::servicePath('postfix'), $writable[0]['path'], 'ไฟล์แรกต้องเป็นของ Postfix');
    assertSame(CustomConfig::servicePath('dovecot'), $writable[1]['path'], 'ไฟล์ที่สองต้องเป็นของ Dovecot');

    foreach ($files as $file) {
        if (str_contains((string) $file['path'], '/etc/postfix/main.cf')
            || str_contains((string) $file['path'], '99-phpcp.conf')
        ) {
            assertSame(
                ConfigFileCatalog::KIND_GENERATED,
                $file['kind'],
                'ไฟล์ที่ระบบสร้างต้องเป็นชนิดอ่านอย่างเดียวเสมอ',
            );
        }
    }

    // คีย์ของเว็บไซต์ต้องใช้กับขอบเขตเมลไม่ได้ และกลับกัน — สองทะเบียนแยกกันสิ้นเชิง
    assertTrue(ConfigFileCatalog::find($files, 'site.1.custom') === null, 'คีย์ของเว็บไซต์ต้องไม่อยู่ในทะเบียนเมล');
});

test('เมล — ไฟล์ของผู้ดูแลต้องถูกอ่านท้ายสุดทั้ง Postfix และ Dovecot', static function (): void {
    /*
     * **ลำดับคือทั้งหมดของคุณสมบัตินี้** และสองบริการนี้ทำคนละวิธี:
     *
     *   Postfix  ไม่มีคำสั่ง include สำหรับ main.cf เลย → เนื้อไฟล์ถูก "ผนวก" ท้ายไฟล์
     *            ตอน generate · Postfix ใช้ค่าที่ประกาศทีหลัง ค่าของผู้ดูแลจึงชนะ
     *   Dovecot  `!include_try` ซึ่ง **ไม่ล้มเมื่อไฟล์ยังไม่มี** ต่างจาก `!include`
     *            ที่จะทำให้ Dovecot สตาร์ตไม่ขึ้นทั้งตัวบนเครื่องที่ยังไม่เคยเขียนค่าเพิ่ม
     */
    $templates = new Template(PHPCP_ROOT . '/templates');

    $main = $templates->render('postfix/main.cf.tpl', [
        'HOSTNAME' => 'mail.example.com',
        'ORIGIN' => 'mail.example.com',
        'RELAY_HOST' => '',
        'SASL_ENABLED' => 'no',
        'TLS_SECURITY' => 'may',
        'INET_INTERFACES' => 'all',
        'MYDESTINATION' => 'localhost, $myhostname',
        'HOSTING_SECTION' => new Phpcp\Driver\SafeBlock('virtual_mailbox_domains = hash:/etc/postfix/vdomains'),
        'CUSTOM_SECTION' => new Phpcp\Driver\SafeBlock('message_size_limit = 99'),
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);

    assertTrue(!str_contains($main, '{{'), 'main.cf ต้องไม่มีตัวแปรค้าง');
    assertTrue(
        strpos($main, 'message_size_limit = 99') > strpos($main, 'virtual_mailbox_domains'),
        'ค่าของผู้ดูแลต้องอยู่ท้ายสุด — Postfix ใช้ค่าที่ประกาศทีหลัง',
    );
    assertTrue(
        strpos($main, 'message_size_limit = 99') > strpos($main, 'message_size_limit = 26214400'),
        'ค่าของผู้ดูแลต้องมาหลังค่าเริ่มต้นของ panel ไม่งั้นถูกทับ',
    );

    $dovecot = $templates->render('dovecot/99-phpcp.conf.tpl', [
        'MAIL_ROOT' => '/srv/phpcp/mail',
        'VMAIL_USER' => 'vmail',
        'USERS_FILE' => '/etc/dovecot/phpcp-users',
        'CUSTOM_DIR' => '/etc/phpcp/custom/dovecot',
        'TLS_CERT' => '/x.pem',
        'TLS_KEY' => '/x.key',
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);

    assertTrue(
        str_contains($dovecot, '!include_try /etc/phpcp/custom/dovecot/*.conf'),
        'Dovecot ต้องใช้ !include_try ที่ทนกับไฟล์ที่ยังไม่มี',
    );
    assertTrue(
        !str_contains($dovecot, "\n!include /etc/phpcp"),
        '`!include` ธรรมดาทำให้ Dovecot สตาร์ตไม่ขึ้นเมื่อยังไม่มีไฟล์',
    );
    assertTrue(
        strpos($dovecot, '!include_try') > strpos($dovecot, 'ssl = required'),
        'ไฟล์ของผู้ดูแลต้องถูกอ่านหลังค่าเริ่มต้น',
    );
});

test('เมล — ตัวเขียนต้องคืนไฟล์ของผู้ดูแลด้วยเมื่อตัวตรวจไม่ผ่าน', static function (): void {
    /*
     * **จุดที่พลาดง่ายที่สุดของขอบเขตนี้**
     *
     * `sync()` เขียน main.cf แล้วให้ `postfix check` ตัดสิน · ถ้าไม่ผ่าน ConfigTransaction
     * ข้างในคืน main.cf ให้เอง — แต่ไฟล์ของผู้ดูแลถูกเขียนไว้ก่อนหน้าในคนละธุรกรรม
     * ถ้าไม่คืนด้วย ไฟล์เสียจะค้างอยู่ แล้ว sync ครั้งถัดไป (ซึ่งเกิดจากคนอื่นแก้กล่อง
     * จดหมาย) จะล้มตามไปด้วยโดยไม่มีใครรู้ว่าเพราะอะไร
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/MailCustomConfig.php'),
    );

    assertTrue(str_contains($code, '$transaction->rollback();'), 'ต้องคืนไฟล์ของผู้ดูแลเมื่อ sync ล้ม');
    assertTrue(
        substr_count($code, '$this->sync($executor, $context);') >= 2,
        'ต้อง sync ซ้ำหลังคืนค่า ไม่งั้นไฟล์ที่ generate ยังเป็นของรอบที่ล้ม',
    );
    assertTrue(str_contains($code, 'RollbackGuard'), 'ต้องตั้งเวลาถอนคืนเหมือนขอบเขตเว็บไซต์');
    assertTrue(str_contains($code, 'KIND_WRITABLE'), 'ต้องตรวจจากทะเบียนว่าไฟล์นี้แก้ได้จริง');
});
