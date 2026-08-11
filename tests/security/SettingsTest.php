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
use Phpcp\Driver\Notify\TelegramNotifier;

group('ค่าตั้ง การแจ้งเตือน และเมล');

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

    foreach (array_keys($keys) as $key) {
        assertTrue(
            array_filter($allowedPrefixes, static fn (string $p): bool => str_starts_with($key, $p)) !== [],
            "คีย์ {$key} อยู่นอกขอบเขตที่หน้าเว็บควรแก้ได้",
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

test('เทมเพลตของ Postfix ต้องไม่เปิดรับจากภายนอก', static function (): void {
    // ค่าสองบรรทัดนี้คือเส้นแบ่งระหว่าง "เมลขาออกที่ปลอดภัย" กับ "เครื่องส่งสแปม"
    // ถ้าเผลอเปลี่ยน ไอพีจะติดบัญชีดำภายในไม่กี่ชั่วโมง และกระทบทุกเว็บบนเครื่อง
    $tpl = (string) file_get_contents(PHPCP_ROOT . '/templates/postfix/main.cf.tpl');

    assertTrue(str_contains($tpl, 'inet_interfaces = loopback-only'), 'ต้องฟังเฉพาะ loopback');
    assertTrue(!str_contains($tpl, '0.0.0.0/0'), 'mynetworks ต้องไม่กว้างถึงทั้งอินเทอร์เน็ต');
    assertTrue(!str_contains($tpl, 'inet_interfaces = all'), 'ต้องไม่ฟังทุกอินเทอร์เฟซ');
});

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
    foreach (["'phase' => 'ล้มเหลว'", "'phase' => 'สำเร็จ'"] as $phase) {
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
        if (!preg_match('/^(settings|notify|mail)\./', $name)) {
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
