<?php

declare(strict_types=1);

/**
 * ค่าตั้ง การแจ้งเตือน และเมลขาออก
 *
 * สองเรื่องที่ห้ามพลาดในไฟล์นี้:
 *   1. ความลับต้องไม่หลุดออกไปทางหน้าจอ และต้องไม่ถูกลบทิ้งตอนบันทึกค่าอื่น
 *   2. เมลที่ตั้งค่าผิดจนกลายเป็น open relay ทำให้ไอพีติดบัญชีดำถาวร
 *      ซึ่งกระทบทุกเว็บไซต์บนเครื่องเดียวกัน ไม่ใช่แค่เว็บที่ตั้งค่าผิด
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Template;
use Phpcp\Driver\Notify\TelegramNotifier;

group('ค่าตั้ง การแจ้งเตือน และเมล');

/**
 * ฐานข้อมูลเปล่า + Context สำหรับเรียก capability ของค่าตั้งตรง ๆ
 *
 * @return array{db:Phpcp\Kernel\Db,context:Phpcp\Agent\Context}
 */
function settingsFixture(): array
{
    $root = sys_get_temp_dir() . '/phpcp-settings-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return [
        'db' => $db,
        'context' => new Phpcp\Agent\Context(
            new Phpcp\Agent\Actor(0, 'tester', Phpcp\Security\Permissions::SUPERADMIN, '127.0.0.1', 'test'),
            Phpcp\Kernel\Config::load($root),
            $db,
        ),
    ];
}

test('ค่าที่เป็นความลับต้องถูกปิดบังก่อนส่งไปหน้าจอ', static function (): void {
    // token ที่หลุดไปทาง HTML แปลว่าใครก็ส่งข้อความในนามระบบได้
    // และจะติดอยู่ในแคชของเบราว์เซอร์กับประวัติของ proxy ไปอีกนาน
    $masked = SettingsRepository::mask([
        'notify.telegram.token' => '123456789:AAHrealtokenvaluethatmustnotleak',
        'mail.relay_password' => 'ความลับ',
        'notify.telegram.chat_id' => '-1001234567890',
    ]);

    assertSame('********', $masked['notify.telegram.token'], 'token ต้องถูกปิดบัง');
    assertSame('********', $masked['mail.relay_password'], 'รหัสผ่าน relay ต้องถูกปิดบัง');
    assertSame('-1001234567890', $masked['notify.telegram.chat_id'], 'chat id ไม่ใช่ความลับ ต้องแสดงได้');
});

test('ค่าว่างต้องไม่ถูกปิดบังเป็นดอกจัน', static function (): void {
    // ถ้าปิดบังค่าว่างด้วย หน้าจอจะดูเหมือนตั้งค่าไว้แล้วทั้งที่ยังไม่ได้ตั้ง
    $masked = SettingsRepository::mask(['notify.telegram.token' => '']);

    assertSame('', $masked['notify.telegram.token'], 'ค่าว่างต้องยังเป็นค่าว่าง');
});

test('บันทึกโดยไม่แตะช่องความลับต้องไม่ลบค่าเดิม', static function (): void {
    // เคยเป็นกับดักจริงในระบบอื่น: กดบันทึกเพื่อแก้ค่าอื่นเพียงค่าเดียว
    // แล้ว token ถูกเขียนทับด้วยดอกจัน กลายเป็นการแจ้งเตือนตายเงียบ ๆ
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SettingsSet.php');

    assertTrue(
        str_contains($source, "\$value === '********'") && str_contains($source, 'unset($values[$key])'),
        'ต้องตัดค่าที่ยังเป็นดอกจันออกก่อนบันทึก ไม่ใช่เขียนทับ',
    );
});

test('คีย์ที่ไม่อยู่ในรายการต้องถูกปฏิเสธ', static function (): void {
    $keys = SettingsRepository::keys();

    foreach (['evil.key', 'panel.port', 'security.secret_key', ''] as $key) {
        assertTrue(!isset($keys[$key]), "คีย์ {$key} ต้องไม่อยู่ในรายการที่ยอมให้แก้");
    }

    // ค่าที่ต้องมีก่อนระบบบูตต้องแก้จากหน้าเว็บไม่ได้ — config.php เป็นไฟล์ PHP ที่ถูก include
    // ถ้าหน้าเว็บเขียนได้ ช่องโหว่เดียวในหน้าตั้งค่าจะกลายเป็นการรันโค้ดทันที
    $allowedPrefixes = ['notify.', 'mail.', 'dns.', 'webserver.'];

    /*
     * ข้อยกเว้นระบุเป็น**คีย์เต็ม ไม่ใช่คำนำหน้า** — เปิด `panel.` ทั้งหมดแปลว่าคีย์
     * อันตรายที่จะเพิ่มในอนาคต (`panel.port`, `panel.ip_allowlist`) ผ่านเข้ามาได้เงียบ ๆ
     * · `panel.cert_domain` ปลอดภัยเพราะไม่ได้ถูกอ่านตอนบูต เป็นแค่บันทึกว่าไฟล์ใบรับรอง
     * ที่วางอยู่มาจากโดเมนไหน และ `webEditableKeys()` กันไม่ให้เขียนผ่านฟอร์มตั้งค่าทั่วไป
     * ต้องผ่าน `panel.cert_set` ที่ตรวจคู่กุญแจและตั้งเวลาถอนคืนให้เท่านั้น
     */
    $allowedKeys = ['panel.cert_domain'];

    /*
     * `security.panel_jail.*` เข้าข่ายเดียวกับ `panel.cert_domain` — อยู่นอกคำนำหน้า
     * ที่อนุญาต และ `webEditableKeys()` กันไม่ให้เขียนผ่านฟอร์มตั้งค่าทั่วไปด้วย
     *
     * เหตุผลคนละแบบกับ `panel.cert_domain` แต่ผลเหมือนกัน: ค่าพวกนี้ต้องเดินทางคู่กับ
     * ไฟล์ jail ที่ผ่านตัวตรวจของ fail2ban แล้ว · เขียนค่าโดยไม่เขียนไฟล์เมื่อไร
     * หน้าจอจะบอกว่ากันเดารหัสผ่านอยู่ทั้งที่ไม่มีอะไรกันเลย
     */
    foreach (array_keys($keys) as $key) {
        if (str_starts_with($key, 'security.')) {
            $allowedKeys[] = $key;

            assertTrue(
                !isset(SettingsRepository::webEditableKeys()[$key]),
                "คีย์ {$key} ต้องแก้ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้",
            );
        }
    }

    /*
     * ข้อยกเว้นชั้นที่สอง: อยู่นอกคำนำหน้าที่อนุญาต **แต่แก้ผ่านฟอร์มได้**
     *
     * ต่างจาก `$allowedKeys` ตรงที่อันนั้นคือ "ยกเว้นกฎคำนำหน้า แต่ยังห้ามแก้ผ่านฟอร์ม"
     * — สองความหมายนี้เคยปนกันอยู่ในรายการเดียว ซึ่งใช้ไม่ได้ทันทีที่มีคีย์แรกที่
     * ต้องยกเว้นกฎคำนำหน้าและควรแก้ได้จริง
     *
     * `sites.layout` เข้าข่ายเพราะ**ค่าที่ถูกนำไปใช้ไม่ได้มาจากผู้ใช้โดยตรง** —
     * `SiteLayout::tryFrom()` รับแค่ 'phpcp' กับ 'cpanel' อะไรที่ไม่ตรงตกไปที่ค่าเริ่มต้น
     * · ต่างจาก `sites.users_dir` ที่เป็นข้อความอิสระซึ่งถูกเอาไปประกอบเป็น open_basedir
     * และเส้นทางใน vhost ที่รันด้วยสิทธิ์ root — อันนั้นต้องอยู่ใน config.php ต่อไป
     */
    $allowedEditableKeys = ['sites.layout'];

    foreach (array_keys($keys) as $key) {
        if (in_array($key, $allowedKeys, true) || in_array($key, $allowedEditableKeys, true)) {
            continue;
        }

        assertTrue(
            array_filter($allowedPrefixes, static fn (string $p): bool => str_starts_with($key, $p)) !== [],
            "คีย์ {$key} อยู่นอกขอบเขตที่หน้าเว็บควรแก้ได้",
        );
    }

    // ข้อยกเว้นต้องแก้ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้จริง ไม่ใช่แค่ได้รับการยกเว้นในเทสต์นี้
    foreach ($allowedKeys as $key) {
        assertTrue(
            !isset(SettingsRepository::webEditableKeys()[$key]),
            "คีย์ {$key} ต้องเขียนผ่านฟอร์มตั้งค่าทั่วไปไม่ได้",
        );
    }

    // ค่าที่ไม่รู้จักต้องไม่กลายเป็นเส้นทางแปลก ๆ — นี่คือเหตุผลเดียวที่คีย์นี้แก้จากเว็บได้
    foreach (['../../etc', 'cpanel; rm -rf /', '', 'ไม่มีอยู่จริง'] as $bad) {
        $resolved = Phpcp\Domain\SiteLayout::parse($bad);

        assertTrue(
            in_array($resolved, [Phpcp\Domain\SiteLayout::Phpcp, Phpcp\Domain\SiteLayout::Cpanel], true),
            "ค่า {$bad} ต้องตกไปที่เลย์เอาต์ที่รู้จัก ไม่ใช่ถูกใช้ตรง ๆ",
        );
    }
});

test('token และ chat id ที่ผิดรูปแบบถูกปฏิเสธตั้งแต่ตอนบันทึก', static function (): void {
    foreach (['ไม่ใช่ token', 'abc:def', '123:short', '123456789', 'x' . str_repeat('y', 50)] as $token) {
        assertRejects(
            ValidationError::class,
            static fn () => TelegramNotifier::assertToken($token),
            "token '{$token}' ต้องถูกปฏิเสธ",
        );
    }

    assertSame('', TelegramNotifier::assertToken(''), 'ค่าว่างแปลว่ายังไม่ตั้ง ต้องผ่าน');
    TelegramNotifier::assertToken('123456789:AAHabcdefghijklmnopqrstuvwxyz012345');
    TestRunner::$assertions++;

    foreach (['abc', '@ab', 'user name', '12 34'] as $chat) {
        assertRejects(
            ValidationError::class,
            static fn () => TelegramNotifier::assertChatId($chat),
            "chat id '{$chat}' ต้องถูกปฏิเสธ",
        );
    }

    assertSame('-1001234567890', TelegramNotifier::assertChatId('-1001234567890'), 'กลุ่มใช้เลขติดลบ');
});

test('ชื่อโฮสต์และพอร์ตของเมลต้องผ่านการตรวจ', static function (): void {
    foreach (['evil host;rm -rf /', 'a b', "smtp\nx", '-bad.com', ''] as $host) {
        assertRejects(
            ValidationError::class,
            static fn () => MailManager::assertHost($host),
            "โฮสต์ '{$host}' ต้องถูกปฏิเสธ",
        );
    }

    assertSame('smtp.example.com', MailManager::assertHost('SMTP.Example.COM'), 'แปลงเป็นตัวพิมพ์เล็ก');
    assertSame('203.0.113.5', MailManager::assertHost('203.0.113.5'), 'ไอพีใช้ได้');

    foreach ([0, -1, 65536, 99999] as $port) {
        assertRejects(
            ValidationError::class,
            static fn () => MailManager::assertPort($port),
            "พอร์ต {$port} ต้องถูกปฏิเสธ",
        );
    }
});

test('เมลขาออกอย่างเดียวต้องไม่เปิดรับจากภายนอก', static function (): void {
    // ค่าสองบรรทัดนี้คือเส้นแบ่งระหว่าง "เมลขาออกที่ปลอดภัย" กับ "เครื่องส่งสแปม"
    // ถ้าเผลอเปลี่ยน ไอพีจะติดบัญชีดำภายในไม่กี่ชั่วโมง และกระทบทุกเว็บบนเครื่อง
    //
    // ตั้งแต่มีเมลโฮสติ้ง (PLAN-MAIL) `inet_interfaces` เป็นค่าที่เปลี่ยนตามโหมด
    // จึงต้องตรวจ**ผลที่เรนเดอร์ออกมาจริง** ไม่ใช่ตัวเทมเพลตที่ยังเป็นช่องว่างอยู่
    $conf = postfixMainCf(hosting: false);

    assertTrue(str_contains($conf, 'inet_interfaces = loopback-only'), 'เครื่องที่ไม่ได้เปิดเมลต้องฟังเฉพาะ loopback');
    assertTrue(!str_contains($conf, 'inet_interfaces = all'), 'ต้องไม่ฟังทุกอินเทอร์เฟซ');
    assertTrue(!str_contains($conf, '0.0.0.0/0'), 'mynetworks ต้องไม่กว้างถึงทั้งอินเทอร์เน็ต');
    assertTrue(!str_contains($conf, 'virtual_mailbox_domains'), 'ยังไม่เปิดเมลก็ต้องไม่มีส่วนของการรับเมล');
});

test('เปิดเมลโฮสติ้งแล้วยังต้องไม่เป็น open relay', static function (): void {
    /*
     * เปิดรับเมลเข้าแปลว่าต้องฟังทุกหน้าตัดเน็ต — ซึ่งเป็นจุดที่พลาดแล้วราคาแพงที่สุด
     * ของทั้งระบบ · สิ่งที่กันไว้ไม่ใช่ "ไม่ฟัง" แต่เป็นกฎสามชั้นนี้:
     *
     *   1. mynetworks ยังแคบเท่าเดิม (เชื่อเฉพาะเครื่องนี้)
     *   2. smtpd_relay_restrictions ปิดท้ายด้วย reject_unauth_destination
     *   3. ทางที่ผู้ใช้ส่งออก (587/465) ต้องล็อกอินอย่างเดียว ไม่มีข้อยกเว้นให้ mynetworks
     */
    $conf = postfixMainCf(hosting: true);

    assertTrue(str_contains($conf, 'inet_interfaces = all'), 'เปิดเมลต้องฟังทุกหน้าตัดเน็ต');
    assertTrue(!str_contains($conf, '0.0.0.0/0'), 'mynetworks ต้องไม่กว้างถึงทั้งอินเทอร์เน็ต');
    assertTrue(str_contains($conf, 'reject_unauth_destination'), 'ต้องปฏิเสธปลายทางที่ไม่ใช่ของเรา');
    assertTrue(str_contains($conf, 'permit_sasl_authenticated'), 'คนที่ล็อกอินแล้วต้องส่งได้');
    assertTrue(str_contains($conf, 'smtpd_tls_auth_only = yes'), 'ห้ามล็อกอินแบบไม่เข้ารหัส');

    $submission = (new Template(PHPCP_ROOT . '/templates'))->render('postfix/submission.cf.tpl', []);

    assertTrue(
        str_contains($submission, 'smtpd_relay_restrictions=permit_sasl_authenticated,reject'),
        'ทางส่งออกของผู้ใช้ต้องล็อกอินอย่างเดียว ไม่มีข้อยกเว้นให้ mynetworks: ' . $submission,
    );
});

/** main.cf ที่เรนเดอร์จริงตามโหมดที่ระบุ */
/**
 * `main.cf` ที่เรนเดอร์แล้ว **โดยตัดคอมเมนต์ออก**
 *
 * เทสต์เหล่านี้ถามว่า "ไฟล์นี้สั่ง Postfix ว่าอะไร" ซึ่งคำตอบอยู่ในบรรทัดคำสั่งเท่านั้น ·
 * คำอธิบายในไฟล์พูดถึงชื่อค่าตั้งตรง ๆ อยู่แล้วโดยธรรมชาติ (เช่นอธิบายว่าทำไมค่านี้ถึง
 * ห้ามชนกับ `virtual_mailbox_domains`) ถ้าไม่ตัดออก การเพิ่มคำอธิบายที่ดีจะทำให้เทสต์
 * ความปลอดภัยล้ม และคนแก้จะถูกกดดันให้เขียนคำอธิบายแย่ลงเพื่อให้เทสต์เขียว
 */
function postfixMainCf(bool $hosting): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', postfixMainCfRaw($hosting));
}

function postfixMainCfRaw(bool $hosting): string
{
    $templates = new Template(PHPCP_ROOT . '/templates');

    return $templates->render('postfix/main.cf.tpl', [
        'HOSTNAME' => 'mail.example.com',
        'ORIGIN' => 'mail.example.com',
        'RELAY_HOST' => '',
        'SASL_ENABLED' => 'no',
        'TLS_SECURITY' => 'may',
        'INET_INTERFACES' => $hosting ? 'all' : 'loopback-only',
        'MYDESTINATION' => 'localhost, $myhostname',
        'CUSTOM_SECTION' => '',
        'HOSTING_SECTION' => new SafeBlock($hosting ? $templates->render('postfix/hosting.cf.tpl', [
            'TLS_CERT' => '/etc/ssl/certs/x.pem',
            'TLS_KEY' => '/etc/ssl/private/x.key',
        ]) : ''),
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);
}

test('การแจ้งเตือนต้องไม่ทำให้งานหลักล้ม', static function (): void {
    // ถ้า Telegram ล่มแล้วการสร้างเว็บไซต์ล้มตาม ผู้ใช้จะเห็นว่างานที่สำเร็จแล้ว "ล้มเหลว"
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/Notifier.php');
    assertTrue(str_contains($source, 'catch (\\Throwable)'), 'Notifier ต้องกลืนข้อผิดพลาดไว้เอง');

    $dispatcher = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Dispatcher.php');
    assertTrue(
        str_contains($dispatcher, 'catch (\\Throwable)'),
        'จุดที่ Dispatcher เรียกแจ้งเตือนต้องมี try/catch ครอบ',
    );

    // ต้องแจ้งหลังบันทึก audit เสมอ — audit คือหลักฐานที่ลบไม่ได้ การแจ้งเตือนเป็นแค่ความสะดวก
    // ถ้าแจ้งก่อนบันทึก แล้วกระบวนการตายระหว่างนั้น จะมีข้อความแจ้งเตือนถึงเหตุการณ์
    // ที่ไม่มีบันทึกรองรับ ซึ่งทำให้ audit log ไม่ครบโดยไม่มีใครรู้
    //
    // ตรวจทีละเส้นทาง (ล้มเหลว/สำเร็จ) เพราะทั้งสองเส้นทางมี notify ของตัวเอง
    // การหาตำแหน่งแรกในไฟล์เฉย ๆ จะเทียบข้ามเส้นทางกันจนได้ผลลวง
    foreach (["'phase' => 'failed'", "'phase' => 'succeeded'"] as $phase) {
        $auditAt = strpos($dispatcher, $phase);
        assertTrue($auditAt !== false, "ต้องมีการบันทึก audit ช่วง {$phase}");

        $notifyAt = strpos($dispatcher, '$this->notify(', $auditAt);
        assertTrue($notifyAt !== false, "ต้องมีการแจ้งเตือนหลังช่วง {$phase}");
    }
});

test('แจ้งเตือนเฉพาะคำสั่งที่คัดไว้ ไม่ใช่ทุกคำสั่งที่เปลี่ยนระบบ', static function (): void {
    // การสร้างเว็บไซต์และแก้ไฟล์เกิดวันละหลายสิบครั้ง ถ้าแจ้งทั้งหมด
    // ผู้ดูแลจะปิดการแจ้งเตือน แล้ววันที่ firewall ถูกปิดจริงก็จะไม่มีใครเห็น
    $reflection = new ReflectionClass(\Phpcp\Agent\Dispatcher::class);
    $map = $reflection->getConstant('NOTIFY');

    assertTrue(is_array($map) && $map !== [], 'ต้องมีรายการคำสั่งที่แจ้งเตือน');
    assertTrue(!isset($map['file.write']), 'การแก้ไฟล์ต้องไม่แจ้งเตือน');
    assertTrue(!isset($map['site.create']), 'การสร้างเว็บไซต์ต้องไม่แจ้งเตือน');
    assertTrue(isset($map['firewall.disable']), 'การปิด firewall ต้องแจ้งเตือน');
    assertTrue(isset($map['ssh.config_set']), 'การแก้ค่า SSH ต้องแจ้งเตือน');

    foreach ($map as $capability => $event) {
        assertTrue(
            isset(Notifier::EVENTS[$event]),
            "{$capability} อ้างหมวด {$event} ที่ไม่มีอยู่จริง",
        );
    }
});

test('capability ของค่าตั้งต้องใช้สิทธิ์ระดับเซิร์ฟเวอร์', static function (): void {
    $registry = new CapabilityRegistry();

    foreach ($registry->describe() as $name => $meta) {
        // `mail.apply`/`mail.test` คือค่าตั้งเมล**ขาออกของทั้งเครื่อง** จึงเป็นสิทธิ์
        // ระดับเซิร์ฟเวอร์ · ส่วน `mail.box_*`/`mail.domain_set` เป็นการจัดการกล่อง
        // ของโดเมนที่ลูกค้าเป็นเจ้าของ ใช้ `mail.manage` ซึ่งเจ้าของเว็บมีได้ (PLAN-MAIL)
        if (!preg_match('/^(settings|notify)\.|^mail\.(apply|test)$/', $name)) {
            continue;
        }

        assertTrue(
            in_array($meta['permission'], ['settings.view', 'settings.manage'], true),
            "{$name} ต้องใช้ permission ของ settings",
        );

        assertTrue(
            !\Phpcp\Security\Permissions::roleHas(\Phpcp\Security\Permissions::WEBADMIN, $meta['permission']),
            "ผู้ดูแลเว็บไซต์ต้องไม่มีสิทธิ์ {$meta['permission']}",
        );
    }
});

test('ซ่อมเจ้าของไฟล์ต้องตรวจสิทธิ์และกันเส้นทางของ panel เอง', static function (): void {
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteResetOwner.php');

    assertTrue(str_contains($source, 'assertSiteAccess'), 'ต้องตรวจว่าเป็นเว็บของผู้เรียกเอง');
    assertTrue(str_contains($source, 'SelfProtection::assertPath'), 'ต้องกันเส้นทางของ panel เอง');
    assertTrue(str_contains($source, 'realPath'), 'ต้องตรวจซ้ำหลังแปลง symlink');

    // -h ทำให้ chown เปลี่ยนตัว symlink เอง ไม่ไล่ตามไปเปลี่ยนไฟล์ปลายทางนอกขอบเขต
    assertTrue(str_contains($source, "'-Rh'"), 'chown ต้องใช้ -h เพื่อไม่ไล่ตาม symlink');
});

test('เปลี่ยนเว็บเซิร์ฟเวอร์ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้ — ต้องผ่านการ apply เท่านั้น', static function (): void {
    // ถ้า PATCH /settings เขียนค่านี้ได้ จะได้เครื่องที่ค่าตั้งบอกว่า nginx
    // แต่ไฟล์ vhost บนดิสก์ยังเป็นของ Apache แล้วไม่มีอะไรฟ้องเลย
    $editable = SettingsRepository::webEditableKeys();

    foreach (['webserver.mode', 'webserver.static_by_nginx'] as $key) {
        assertTrue(isset(SettingsRepository::keys()[$key]), "{$key} ต้องเก็บในตารางตั้งค่าได้");
        assertTrue(!isset($editable[$key]), "{$key} ต้องแก้ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้");
    }

    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/SettingsController.php');
    assertTrue(
        str_contains($source, 'SettingsRepository::webEditableKeys()'),
        'ตัวควบคุมต้องใช้รายการที่กรอง webserver.* ออกแล้ว',
    );
});

test('nameserver ตั้งจากหน้าจอได้ ไม่ต้องแก้ไฟล์เอง', static function (): void {
    $editable = SettingsRepository::webEditableKeys();

    foreach (['dns.enabled', 'dns.nameservers', 'dns.soa_email'] as $key) {
        assertTrue(isset($editable[$key]), "{$key} ต้องแก้จากหน้าตั้งค่าได้");
    }

    $page = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/settings.html');
    assertTrue(str_contains($page, 'name="dns.nameservers"'), 'หน้าตั้งค่าต้องมีช่องกรอก nameserver');
    assertTrue(str_contains($page, 'name="mode"'), 'หน้าตั้งค่าต้องมีตัวเลือกเว็บเซิร์ฟเวอร์');
});

test('เปิดสวิตช์ DNS ต้องเปิดบริการ named ให้จริง ไม่ใช่แค่บันทึกค่า', static function (): void {
    // **เจอจากเซิร์ฟเวอร์จริง (2026-08-13):** ติดตั้งโดยไม่ส่ง --dns-ns (ค่าเริ่มต้น) แล้ว
    // แพ็กเกจ bind9 ถูกลงไว้แต่ service ไม่เคยถูก enable · ผู้ดูแลกดสวิตช์ในหน้าตั้งค่า
    // เห็น "บันทึกแล้ว" แต่เรกคอร์ดแรกที่เพิ่มล้มที่ `rndc reload` โดยข้อความไม่ได้บอกเลย
    // ว่าต้องไป start service ก่อน — สวิตช์ต้องทำงานให้จบ ไม่ใช่แค่จำความตั้งใจไว้
    $fixture = settingsFixture();

    (new SettingsRepository($fixture['db']))->save(['dns.nameservers' => 'ns1.example.com,ns2.example.com']);

    $executor = new Phpcp\Agent\Executor\DryRunExecutor();
    $capability = new Phpcp\Agent\Capability\SettingsSet();

    $result = $capability->run(
        $capability->validate(['dns.enabled' => '1']),
        $executor,
        $fixture['context'],
    );

    $commands = implode(' | ', $executor->simulatedCommands());

    assertTrue(
        str_contains($commands, 'systemctl enable --now named')
        || str_contains($commands, 'systemctl enable --now bind9'),
        'ต้องสั่งเปิดบริการ BIND9 ด้วย · ได้: ' . $commands,
    );
    assertTrue(
        str_contains($commands, '/etc/bind/zones'),
        'ต้องสร้างไดเรกทอรี zone ด้วย ไม่งั้นเขียนไฟล์แรกไม่ได้ · ได้: ' . $commands,
    );
    assertTrue(
        str_contains((string) $result['message'], 'named') || str_contains((string) $result['message'], 'bind9'),
        'ต้องรายงานกลับว่าทำอะไรไปกับบริการ · ได้: ' . $result['message'],
    );
});

test('เปิดสวิตช์ DNS โดยยังไม่มี nameserver ต้องบอกตรง ๆ ว่ายังสร้าง zone ไม่ได้', static function (): void {
    // BIND9 ปฏิเสธ zone ที่ไม่มี NS record — ปล่อยให้ผู้ดูแลไปเจอเองตอนเพิ่มเรกคอร์ดแรก
    // แปลว่าเขาจะไล่หาสาเหตุผิดที่ ทั้งที่ระบบรู้ตั้งแต่ตอนกดสวิตช์แล้ว
    $fixture = settingsFixture();

    $executor = new Phpcp\Agent\Executor\DryRunExecutor();
    $capability = new Phpcp\Agent\Capability\SettingsSet();

    $result = $capability->run(
        $capability->validate(['dns.enabled' => '1']),
        $executor,
        $fixture['context'],
    );

    assertTrue(
        str_contains((string) $result['message'], 'nameserver'),
        'ต้องบอกว่ายังขาดชื่อเนมเซิร์ฟเวอร์ · ได้: ' . $result['message'],
    );
    assertTrue(
        !str_contains(implode(' | ', $executor->simulatedCommands()), 'systemctl enable'),
        'ยังไม่ควรแตะบริการจนกว่าจะมีข้อมูลครบ',
    );
});

test('agent ต้องเห็นค่าที่ตั้งจากหน้าจอ ไม่ใช่แค่ค่าใน config.php', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14) — บั๊กที่ทำให้ "ตั้งค่าอะไรก็ไม่มีผล":**
     *
     * `Config::useStoredSettings()` ถูกเรียกจาก `App::db()` ที่เดียว ซึ่งเป็นเส้นทางของ
     * ชั้นเว็บ · ตัว agent ไม่ได้ผ่าน `App` — มันสร้าง `Db` เองในโปรเซสลูก จึงไม่เคย
     * เห็นตาราง `settings` เลย และทุก capability อ่านได้แต่ค่าใน config.php
     *
     * ผลจริง: เปิดสวิตช์ DNS จากหน้าจอ ค่าถูกบันทึกและหน้าจอตอบว่าสำเร็จ แต่
     * `BindZoneManager` ในตัว agent เห็น `dnsEnabled() === false` แล้วคืน no-op เงียบ ๆ
     * — ไม่มี zone file เกิดขึ้นเลยและไม่มีข้อความผิดพลาดใดบอกว่าเพราะอะไร
     *
     * ตรวจที่ตัว Server ว่าโหลดค่าก่อน dispatch จริง ไม่ใช่ตรวจว่า Config ทำงานถูก
     * (อันนั้นถูกอยู่แล้ว — ที่ผิดคือไม่มีใครเรียกมันในเส้นทางของ agent)
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Server.php');

    // ตัดคอมเมนต์ออกก่อน — ไฟล์นี้อธิบายเหตุผลไว้ยาว การ grep ทั้งไฟล์จะจับคำในคอมเมนต์
    $code = implode("\n", array_filter(
        explode("\n", $source),
        static fn (string $line): bool => !str_starts_with(ltrim($line), '*')
            && !str_starts_with(ltrim($line), '//')
            && !str_starts_with(ltrim($line), '/*'),
    ));

    assertTrue(
        str_contains($code, 'Config::useStoredSettings'),
        'agent ต้องโหลดค่าจากตาราง settings ก่อนส่งงานให้ capability',
    );

    // ต้องมาก่อน dispatch ไม่ใช่หลัง — หลังแปลว่า capability ตัวนั้นยังเห็นค่าเก่า
    $loadAt = strpos($code, 'Config::useStoredSettings');
    $dispatchAt = strpos($code, '->dispatch(');

    assertTrue(
        $loadAt !== false && $dispatchAt !== false && $loadAt < $dispatchAt,
        'ต้องโหลดค่าก่อนเรียก dispatch()',
    );
});
