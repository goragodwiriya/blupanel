<?php

declare(strict_types=1);

/**
 * กันเดารหัสผ่านหน้าเข้าสู่ระบบของ panel — jail ที่อ่าน audit log
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **regex ต้องยึดกับค่าที่ผู้ยิงปลอมไม่ได้** — `actor` ในบรรทัดเดียวกันคือชื่อผู้ใช้
 *      ที่คนยิงพิมพ์เข้ามาเอง · ยึดกับ `"ip":"` เฉย ๆ แล้วเขาตั้งชื่อผู้ใช้เป็น
 *      `evil","ip":"9.9.9.9` เพื่อให้ระบบไปแบน IP ของคนอื่นแทน ซึ่งเปลี่ยนฟีเจอร์
 *      ป้องกันให้กลายเป็นอาวุธเล็งใครก็ได้
 *   2. **ต้องไม่นับการล็อกอินที่สำเร็จ** — นับผิดแล้วผู้ดูแลที่ทำงานปกติจะถูกแบน
 *   3. **ต้องระบุ backend เอง** — Debian ตั้ง `backend = systemd` ไว้ใน [DEFAULT]
 *      ซึ่งทำให้ fail2ban เมิน logpath แล้ว jail จะไม่นับอะไรเลยโดยไม่มีอะไรฟ้อง
 *   4. **ค่าที่ตั้งต้องเดินทางคู่กับไฟล์เสมอ** — เขียนค่าโดยไฟล์ไม่เปลี่ยนตาม
 *      แปลว่าหน้าจอรายงานการป้องกันที่ไม่มีอยู่จริง
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Middleware\RateLimit;
use Phpcp\Security\AuditLog;

group('PanelJail — กันเดารหัสผ่านหน้าเข้าสู่ระบบ');

/** เนื้อไฟล์ filter ที่ตัวจริงจะเขียนลงเครื่อง */
function panelJailFilter(): string
{
    $manager = new Fail2banManager(new Phpcp\Agent\Executor\DryRunExecutor());

    $method = new ReflectionMethod($manager, 'panelLoginFilter');
    $method->setAccessible(true);

    return (string) $method->invoke($manager);
}

/** เนื้อไฟล์ jail ที่ตัวจริงจะเขียนลงเครื่อง */
function panelJailContent(array $settings = []): string
{
    $manager = new Fail2banManager(new Phpcp\Agent\Executor\DryRunExecutor());

    $method = new ReflectionMethod($manager, 'panelLoginJail');
    $method->setAccessible(true);

    return (string) $method->invoke($manager, '/var/log/phpcp/audit.log', $settings + [
        'max_retry' => 10,
        'find_seconds' => 600,
        'ban_seconds' => 1800,
        'ignore_ips' => '',
    ]);
}

/** ดึง failregex ออกมาเป็น regex ของ PHP โดยแทน <HOST> ด้วยกลุ่มจับ IP */
function panelJailRegex(): string
{
    preg_match('/^failregex = (.+)$/m', panelJailFilter(), $m);

    // `<HOST>` เป็น token ของ fail2ban — แทนด้วยกลุ่มจับที่กว้างกว่า IP จริงโดยตั้งใจ
    // เพื่อให้เทสต์จับได้ถ้า regex ดันไปคว้าข้อความที่ไม่ใช่ IP
    return '/' . str_replace('<HOST>', '([^"]+)', trim($m[1])) . '/';
}

/** บรรทัดในสำเนา audit log — สร้างด้วยตัวเข้ารหัสตัวเดียวกับของจริง */
function auditLine(string $actor, string $ip, string $action, string $result): string
{
    return (string) json_encode([
        'ts' => date('c'),
        'actor' => $actor,
        'user_id' => 0,
        'ip' => $ip,
        'request_id' => 'test',
        'action' => $action,
        'target' => $actor,
        'result' => $result,
        'detail' => [],
        'hash' => str_repeat('a', 64),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// --- 1. regex ต้องเล็งถูกคน --------------------------------------------------

test('ชื่อผู้ใช้ที่พยายามปลอมช่อง ip ต้องไม่ทำให้แบนผิดคน', static function (): void {
    /*
     * **ข้อที่สำคัญที่สุดของชุดนี้** · ถ้าพลาด ฟีเจอร์ป้องกันจะกลายเป็นเครื่องมือ
     * ให้คนนอกสั่งแบน IP ไหนก็ได้บนเครื่องนี้ แค่พิมพ์มันเป็นชื่อผู้ใช้ตอนล็อกอิน
     *
     * ตัวป้องกันมีสองชั้น: `json_encode` หนี `"` เป็น `\"` และ regex ยึดกับ
     * `"user_id":<เลข>,"ip":"` ซึ่งเลขจำนวนเต็มดิบปลอมผ่านข้อความไม่ได้
     */
    $line = auditLine('evil","ip":"9.9.9.9', '198.51.100.7', 'auth.login', 'denied');

    assertTrue(preg_match(panelJailRegex(), $line, $m) === 1, 'บรรทัดนี้ต้องยังถูกจับได้');
    assertSame('198.51.100.7', $m[1], 'ต้องได้ IP จริงของผู้ยิง ไม่ใช่ IP ที่ปลอมมาในชื่อผู้ใช้');
});

test('จับเฉพาะการยืนยันตัวตนที่ล้มเหลว', static function (): void {
    $regex = panelJailRegex();

    $shouldMatch = [
        auditLine('admin', '203.0.113.9', 'auth.login', 'denied'),
        auditLine('admin', '203.0.113.9', 'auth.2fa', 'denied'),
    ];

    foreach ($shouldMatch as $line) {
        assertTrue(preg_match($regex, $line) === 1, 'การยืนยันตัวตนที่ล้มเหลวต้องถูกนับ: ' . $line);
    }

    $shouldNotMatch = [
        // ล็อกอินสำเร็จ — นับเมื่อไรผู้ดูแลที่ทำงานปกติจะถูกแบน
        auditLine('admin', '203.0.113.9', 'auth.login', 'ok'),
        auditLine('admin', '203.0.113.9', 'auth.2fa', 'ok'),
        // คำสั่งอื่นที่ถูกปฏิเสธเพราะสิทธิ์ไม่พอ ไม่ใช่การเดารหัสผ่าน
        auditLine('admin', '203.0.113.9', 'site.create', 'denied'),
        auditLine('admin', '203.0.113.9', 'auth.logout', 'ok'),
    ];

    foreach ($shouldNotMatch as $line) {
        assertTrue(preg_match($regex, $line) === 0, 'บรรทัดนี้ต้องไม่ถูกนับ: ' . $line);
    }
});

test('ลำดับคีย์ที่ regex พึ่งพาต้องเป็นลำดับที่ AuditLog เขียนจริง', static function (): void {
    /*
     * regex อ่าน `user_id` → `ip` → `action` → `result` ตามลำดับที่ปรากฏในบรรทัด
     * ถ้ามีใครสลับลำดับคีย์ใน `AuditLog::mirror()` regex จะเงียบ ๆ เลิกจับ แล้ว
     * การป้องกันจะหายไปโดยไม่มีอะไรฟ้อง — เทสต์นี้ผูกสองไฟล์นั้นเข้าด้วยกัน
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Security/AuditLog.php');

    /*
     * ดูเฉพาะเนื้อ `mirror()` — `write()` ข้างบนก็มีคีย์ชื่อคล้ายกันสำหรับ INSERT
     * แต่ลำดับคอลัมน์ใน SQL ไม่เกี่ยวกับ regex เลย · เทสต์รุ่นแรกอ่านทั้งไฟล์แล้ว
     * เทียบ `'action'` ของ INSERT กับ `'ip'` ของ mirror ซึ่งเป็นคนละเรื่องกัน
     */
    $start = strpos($source, 'private function mirror(');
    assertTrue($start !== false, 'ต้องมีเมธอด mirror()');

    $end = strpos($source, "\n    /**", $start);
    $mirror = substr($source, $start, $end === false ? null : $end - $start);

    $order = [];
    foreach (['user_id', 'ip', 'action', 'result'] as $key) {
        $at = strpos($mirror, "'{$key}' => ");
        assertTrue($at !== false, "AuditLog::mirror() ต้องมีคีย์ {$key}");
        $order[$key] = $at;
    }

    assertTrue($order['user_id'] < $order['ip'], 'user_id ต้องมาก่อน ip');
    assertTrue($order['ip'] < $order['action'], 'ip ต้องมาก่อน action');
    assertTrue($order['action'] < $order['result'], 'action ต้องมาก่อน result');
});

// --- 2. เนื้อไฟล์ jail -------------------------------------------------------

test('jail ต้องระบุ backend เอง และชี้ไปที่สำเนา audit log', static function (): void {
    $content = panelJailContent();

    assertTrue(str_contains($content, 'backend  = auto'), 'ต้องระบุ backend เอง ไม่งั้น Debian จะให้อ่าน journal แทน');
    assertTrue(str_contains($content, 'logpath  = /var/log/phpcp/audit.log'), 'ต้องชี้ไปที่สำเนา audit log');
    assertTrue(str_contains($content, '[' . Fail2banManager::PANEL_LOGIN_JAIL . ']'), 'ชื่อ jail ต้องตรงกับค่าคงที่');
});

test('localhost ต้องไม่ถูกแบนเด็ดขาด', static function (): void {
    // แบน 127.0.0.1 = ตัด health check ของตัวเอง และตัดหน้า panel ที่ผู้ดูแล
    // กำลังใช้เข้ามาแก้ปัญหาพอดี
    $content = panelJailContent(['ignore_ips' => '203.0.113.0/24']);

    assertTrue(str_contains($content, '127.0.0.1/8'), 'ต้องยกเว้น IPv4 loopback เสมอ');
    assertTrue(str_contains($content, '::1'), 'ต้องยกเว้น IPv6 loopback เสมอ');
    assertTrue(str_contains($content, '203.0.113.0/24'), 'ต้องยกเว้น IP ที่ผู้ดูแลระบุด้วย');
});

test('ไฟล์ jail ต้องบอกทางออกเมื่อแบนตัวเอง', static function (): void {
    // การแบนตัดทุกพอร์ต คนที่โดนเองจะเข้าหน้าจัดการไม่ได้ — คำสั่งกู้ต้องอยู่ในไฟล์
    // ที่เขาจะไปเจอตอนไล่หาสาเหตุ ไม่ใช่อยู่แต่ในคู่มือ
    $content = panelJailContent();

    assertTrue(
        str_contains($content, 'fail2ban-client set ' . Fail2banManager::PANEL_LOGIN_JAIL . ' unbanip'),
        'ต้องมีคำสั่งปลดแบนอยู่ในคอมเมนต์ของไฟล์',
    );
});

// --- 3. ขอบเขตของค่าที่รับได้ ------------------------------------------------

test('ค่าที่ทำให้ผู้ดูแลล็อกตัวเองออกง่ายเกินไปต้องถูกปฏิเสธ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('security.panel_jail_set');

    // ผิดได้แค่ 1-2 ครั้ง = คนที่เปิด Caps Lock ค้างไว้โดนตัดขาดจากเครื่องตัวเอง
    foreach ([0, 1, 2, 101] as $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => $capability->validate([
                'enabled' => true,
                'max_retry' => $bad,
                'find_seconds' => 600,
                'ban_seconds' => 1800,
            ]),
            "max_retry {$bad} ต้องถูกปฏิเสธ",
        );
    }

    $clean = $capability->validate([
        'enabled' => true,
        'max_retry' => 10,
        'find_seconds' => 600,
        'ban_seconds' => 1800,
        'ignore_ips' => '203.0.113.5',
    ]);

    assertSame(10, $clean['max_retry'], 'ค่าที่ถูกต้องต้องผ่าน');
    assertSame('203.0.113.5', $clean['ignore_ips'], 'รายการยกเว้นต้องถูกทำให้เป็นรูปแบบมาตรฐาน');
});

test('เกณฑ์ที่ตัวจำกัดอัตราทำให้ไม่มีวันถึงต้องถูกปฏิเสธ', static function (): void {
    /*
     * **เจอจากการวัดบนเครื่องจริง** — ยิงรหัสผิดรัว 10 ครั้งได้บรรทัดใน audit log
     * แค่บรรทัดเดียว ที่เหลือถูก `RateLimit` ตอบ 429 ตัดทิ้งก่อนถึง controller
     * จึงไม่มีอะไรให้ fail2ban นับ
     *
     * แปลว่า maxretry มีเพดานที่คำนวณได้ · ตั้งเกินเพดาน = jail ที่เปิดอยู่แต่
     * ไม่มีวันแบนใครเลย ซึ่งแย่กว่าปิดไว้เพราะหน้าจอจะบอกว่ากันอยู่
     */
    $capability = (new CapabilityRegistry())->resolve('security.panel_jail_set');

    // ใน 600 วินาที: กดรัวได้ 5 + เติมกลับนาทีละครั้งอีก 10 = 15 ครั้ง
    assertSame(15, RateLimit::maxLoginFailuresWithin(600), 'เพดานต้องคำนวณจากโควตาจริงของหน้าล็อกอิน');

    assertRejects(
        ValidationError::class,
        static fn () => $capability->validate([
            'enabled' => true,
            'max_retry' => 16,
            'find_seconds' => 600,
            'ban_seconds' => 1800,
        ]),
        'เกณฑ์ที่เกินเพดานต้องถูกปฏิเสธ ไม่ใช่รับไว้แล้วเงียบ',
    );

    // ที่เพดานพอดีต้องผ่าน — ไม่ใช่ปฏิเสธเผื่อไว้จนใช้ค่าที่ถูกต้องไม่ได้
    $clean = $capability->validate([
        'enabled' => true,
        'max_retry' => 15,
        'find_seconds' => 600,
        'ban_seconds' => 1800,
    ]);
    assertSame(15, $clean['max_retry'], 'ค่าที่เพดานพอดีต้องผ่าน');

    // ขยายช่วงเวลาแล้วเพดานต้องขยายตาม
    $wider = $capability->validate([
        'enabled' => true,
        'max_retry' => 16,
        'find_seconds' => 1200,
        'ban_seconds' => 1800,
    ]);
    assertSame(16, $wider['max_retry'], 'ช่วงเวลายาวขึ้นต้องรับเกณฑ์ที่สูงขึ้นได้');
});

test('ค่าเริ่มต้นที่ระบบตั้งมาต้องอยู่ใต้เพดานของตัวเอง', static function (): void {
    // ค่าเริ่มต้นที่ตั้งเกินเพดาน = ทุกเครื่องที่กดเปิดโดยไม่แก้อะไรจะได้ jail ที่ไม่ทำงาน
    $defaults = SettingsRepository::defaults();

    $maxRetry = (int) $defaults['security.panel_jail.max_retry'];
    $findSeconds = (int) $defaults['security.panel_jail.find_seconds'];

    assertTrue(
        $maxRetry <= RateLimit::maxLoginFailuresWithin($findSeconds),
        sprintf(
            'ค่าเริ่มต้น maxretry=%d ใน %d วินาที เกินเพดาน %d',
            $maxRetry,
            $findSeconds,
            RateLimit::maxLoginFailuresWithin($findSeconds),
        ),
    );
});

test('ปิดแล้วไม่ต้องกรอกค่าที่กำลังจะไม่ถูกใช้', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('security.panel_jail_set');
    $clean = $capability->validate(['enabled' => false]);

    assertSame(false, $clean['enabled'], 'ปิดต้องผ่านโดยไม่ต้องมีค่าอื่น');
});

test('ปลดแบนต้องรับเฉพาะ IP จริง', static function (): void {
    // ค่านี้กลายเป็นอาร์กิวเมนต์ของ fail2ban-client
    $capability = (new CapabilityRegistry())->resolve('security.panel_jail_unban');

    foreach (['; rm -rf /', '203.0.113.5 --all', 'localhost', ''] as $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => $capability->validate(['ip' => $bad]),
            "ค่า '{$bad}' ต้องถูกปฏิเสธ",
        );
    }

    assertSame('203.0.113.5', $capability->validate(['ip' => '203.0.113.5'])['ip'], 'IP จริงต้องผ่าน');
});

// --- 4. ค่าที่ตั้งต้องเดินทางคู่กับไฟล์ ---------------------------------------

test('ค่าของ jail ต้องแก้ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้', static function (): void {
    // เขียนค่าโดยไม่เขียนไฟล์ = หน้าจอรายงานการป้องกันที่ไม่มีอยู่จริง
    $editable = SettingsRepository::webEditableKeys();

    foreach (array_keys(SettingsRepository::keys()) as $key) {
        if (!str_starts_with($key, 'security.panel_jail.')) {
            continue;
        }

        assertTrue(!isset($editable[$key]), "คีย์ {$key} ต้องไม่อยู่ในฟอร์มตั้งค่าทั่วไป");
    }
});

test('เขียนไฟล์ก่อนบันทึกค่าเสมอ', static function (): void {
    // สลับลำดับเมื่อไร ค่าจะบอกว่าเปิดอยู่ทั้งที่ fail2ban ปฏิเสธไฟล์ไป
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/PanelJailSet.php');

    $applyAt = strpos($source, '$manager->applyPanelLogin(');
    $saveAt = strpos($source, "'security.panel_jail.enabled' => '1'");

    assertTrue($applyAt !== false && $saveAt !== false, 'ต้องมีทั้งสองอย่าง');
    assertTrue($applyAt < $saveAt, 'ต้องเขียนไฟล์ jail ก่อนบันทึกว่าเปิดอยู่');
});

test('สถานะต้องบอกได้เมื่อค่ากับความจริงไม่ตรงกัน', static function (): void {
    /*
     * "ตั้งว่าเปิดแต่ jail ไม่ทำงาน" แย่กว่า "ปิดอยู่" เพราะผู้ดูแลเชื่อว่ามีการป้องกันแล้ว
     * จึงไม่ไปหาทางอื่น · สถานะต้องมีคำตอบของกรณีนี้แยกออกมา ไม่ใช่แสดงว่าเปิดอยู่เฉย ๆ
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/PanelJailStatus.php');

    assertTrue(str_contains($source, "'drifted'"), 'ต้องมีฟิลด์บอกว่าค่ากับความจริงไม่ตรงกัน');
    assertTrue(
        str_contains($source, "\$enabled && !\$status['active']"),
        'drifted ต้องหมายถึง "ตั้งว่าเปิด แต่ fail2ban ไม่ได้โหลด jail"',
    );
});

test('AuditLog ต้องเขียนสำเนาเป็นไฟล์จริง ไม่งั้น fail2ban ไม่มีอะไรให้อ่าน', static function (): void {
    // fail2ban อ่าน SQLite ไม่เป็น · ทั้ง jail นี้ตั้งอยู่บนสมมติฐานว่ามีสำเนาไฟล์
    $file = sys_get_temp_dir() . '/phpcp-audit-' . bin2hex(random_bytes(4)) . '.log';
    register_shutdown_function(static fn () => @unlink($file));

    $db = migratedDb();
    $audit = new AuditLog($db, $file);

    $audit->write(
        new Phpcp\Agent\Actor(0, 'attacker', Phpcp\Security\Permissions::SUPERADMIN, '203.0.113.77', 'r1'),
        'auth.login',
        'admin',
        'denied',
        ['reason' => 'wrong password'],
    );

    assertTrue(is_file($file), 'ต้องมีไฟล์สำเนาเกิดขึ้นจริง');

    $line = trim((string) file_get_contents($file));

    assertTrue(
        preg_match(panelJailRegex(), $line, $m) === 1,
        'บรรทัดที่ AuditLog เขียนจริงต้องตรงกับ regex ที่ jail ใช้',
    );
    assertSame('203.0.113.77', $m[1], 'ต้องดึง IP ของผู้ยิงออกมาได้');
});

// --- 5. รายการห้ามแบนระดับเครื่อง (เคสโรงเรียนออกเน็ตผ่าน IP เดียว) ------------

test('รายการห้ามแบนระดับเครื่องต้องถูกฉีดเข้าไปในไฟล์ jail จริง', static function (): void {
    /*
     * โจทย์: ลูกค้าที่เป็นโรงเรียนออกเน็ตผ่าน IP เดียวกันทั้งโรงเรียน · นักเรียนคนเดียว
     * ที่เครื่องติดมัลแวร์แล้วสแกนอัตโนมัติจะทำให้ทั้งโรงเรียนถูกตัดขาดจากทุกเว็บ
     * บนเครื่อง เพราะ fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost
     *
     * รายการนี้ต้องไปโผล่ในไฟล์จริง ไม่ใช่แค่ถูกเก็บลงตาราง
     */
    $manager = (new Fail2banManager(new Phpcp\Agent\Executor\DryRunExecutor()))
        ->withNeverBan('203.0.113.0/24 198.51.100.7');

    $method = new ReflectionMethod($manager, 'panelLoginJail');
    $method->setAccessible(true);

    $content = (string) $method->invoke($manager, '/var/log/phpcp/audit.log', [
        'max_retry' => 10,
        'find_seconds' => 600,
        'ban_seconds' => 1800,
        'ignore_ips' => '192.0.2.9',
    ]);

    assertTrue(str_contains($content, '203.0.113.0/24'), 'ที่อยู่ของโรงเรียนต้องอยู่ในไฟล์ jail');
    assertTrue(str_contains($content, '198.51.100.7'), 'ที่อยู่ที่สองต้องอยู่ด้วย');
    assertTrue(str_contains($content, '192.0.2.9'), 'รายการเฉพาะของ jail นี้ต้องยังอยู่');
    assertTrue(str_contains($content, '127.0.0.1/8'), 'localhost ต้องยังถูกยกเว้นเสมอ');
});

test('ทุก jail ที่ panel เขียนต้องได้รายการห้ามแบนชุดเดียวกัน', static function (): void {
    /*
     * ก่อนหน้านี้ที่ยกเว้นมีสองที่แยกกัน (ตารางรายเว็บ กับค่าตั้งของ jail หน้าล็อกอิน)
     * ลงทะเบียนโรงเรียนหนึ่งแห่งจึงต้องไล่ใส่ทุกที่ และ jail ที่สร้างใหม่จะลืม
     *
     * เทสต์นี้ตรึงว่าทั้งสองตัวเขียนไฟล์ผ่านตัวรวมรายการตัวเดียวกัน
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    assertSame(
        2,
        preg_match_all('/\$ignore = \$this->ignoreList\(/', $source),
        'ทั้ง jail รายเว็บและ jail หน้าล็อกอินต้องรวมรายการผ่านเมธอดเดียวกัน',
    );
    assertTrue(
        !str_contains($source, "trim(self::LOCAL_IPS . ' '"),
        'ต้องไม่เหลือการต่อรายการยกเว้นเองนอก ignoreList()',
    );
});

test('เปลี่ยนรายการห้ามแบนต้องเขียนไฟล์ jail ที่เปิดอยู่ใหม่ ไม่ใช่แค่บันทึกค่า', static function (): void {
    /*
     * `ignoreip` ถูกอบเข้าไปในไฟล์ตอน jail ถูกเขียน · บันทึกแค่ค่าแล้วจบ = รายการใหม่
     * มีผลกับ jail ที่เขียนหลังจากนี้เท่านั้น ส่วนที่เปิดอยู่แล้วยังแบนโรงเรียนต่อไป
     * โดยที่หน้าจอบอกว่าลงทะเบียนยกเว้นแล้ว
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/NeverBanSet.php');

    assertTrue(str_contains($source, 'applyPanelLogin('), 'ต้องเขียน jail หน้าล็อกอินใหม่');
    assertTrue(str_contains($source, '$manager->apply($site'), 'ต้องเขียน jail รายเว็บใหม่ด้วย');
    assertTrue(
        str_contains($source, 'site_rate_limits WHERE enabled = 1'),
        'ต้องไล่เฉพาะเว็บที่เปิดการจำกัดอัตราอยู่',
    );
});
