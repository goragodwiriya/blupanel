<?php

declare(strict_types=1);

/**
 * กล่องจดหมายจริงบนเครื่อง — PLAN-MAIL เฟส M1
 *
 * สิ่งที่ชุดนี้เฝ้าคือความผิดพลาดที่ **ไม่มีอะไรฟ้องจนกว่าจะสายเกินไป**:
 *
 *   - ที่อยู่ที่ผ่านการตรวจกลายเป็นชื่อไดเรกทอรีและบรรทัดในตารางของ Postfix
 *     ถ้ายอมให้มีเว้นวรรคหรือขึ้นบรรทัดใหม่ ไฟล์ map จะถูกอ่านผิดบรรทัดทั้งไฟล์
 *   - ลืมทับท้ายเส้นทาง Maildir แล้วเมลทุกฉบับจะถูกเขียนต่อกันเป็น mbox
 *     ซึ่งอ่านผ่าน IMAP ไม่ได้ และรู้ตัวตอนที่มีเมลอยู่ในนั้นแล้ว
 *   - กล่องที่ลบไปแล้วยังค้างในไฟล์ตาราง = ยังรับเมลต่อได้
 */

use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Template;

group('เมลโฮสติ้ง — กล่องจดหมายและตารางค้นหาของ Postfix');

test('ที่อยู่ที่กลายเป็นชื่อไดเรกทอรีได้ต้องแคบกว่ากติกาของ RFC', static function (): void {
    $bad = [
        'เว้นวรรค' => 'first last@example.com',
        'ขึ้นบรรทัดใหม่' => "me\nroot@example.com",
        'ทับ' => 'me/../root@example.com',
        'จุดนำหน้า' => '.me@example.com',
        'จุดติดกัน' => 'me..you@example.com',
        'ไม่มีโดเมน' => 'me',
        'โดเมนไม่มีจุด' => 'me@localhost',
        'ชื่อสงวน' => 'vmail@example.com',
        'ว่าง' => '@example.com',
    ];

    foreach ($bad as $label => $value) {
        $rejected = false;

        try {
            MailAddress::parse($value);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธ: {$label}");
    }

    // ที่อยู่ที่คนใช้กันจริงต้องผ่าน
    foreach (['me@example.com', 'first.last@example.com', 'sales+web@example.co.th', 'a_b-c@sub.example.com'] as $good) {
        $address = MailAddress::parse($good);

        assertSame($good, $address->full(), "ต้องรับ: {$good}");
    }
});

test('เส้นทาง Maildir ต้องมีทับท้ายเสมอ', static function (): void {
    // ไม่มีทับท้าย Postfix จะเขียนเป็น mbox ไฟล์เดียว — เมลทุกฉบับต่อกันจนอ่านผ่าน
    // IMAP ไม่ได้ และกว่าจะรู้ตัวก็มีเมลอยู่ในนั้นแล้ว
    $path = (new MailAddress('me', 'example.com'))->maildir('/srv/phpcp/mail');

    assertSame('/srv/phpcp/mail/example.com/me/', $path, 'ต้องเป็น <ราก>/<โดเมน>/<ชื่อ>/ และลงท้ายด้วยทับ');
});

test('ตารางของ Postfix ต้องเขียนใหม่ทั้งไฟล์ ไม่ใช่ต่อท้าย', static function (): void {
    /*
     * เขียนต่อท้ายแปลว่ากล่องที่ถูกลบไปแล้วยังอยู่ในไฟล์และยังรับเมลได้ · ที่นี่จึง
     * ตรวจว่าเนื้อไฟล์ที่ได้มีเฉพาะสิ่งที่ส่งเข้าไปรอบนี้ ไม่มีอะไรจากรอบก่อนติดมา
     */
    $manager = new MailboxManager(new Template(PHPCP_ROOT . '/templates'));
    $render = new ReflectionMethod(MailboxManager::class, 'renderMailboxes');

    $first = (string) $render->invoke($manager, [
        ['address' => 'a@example.com', 'maildir' => '/srv/phpcp/mail/example.com/a/', 'password' => 'x', 'quota_mb' => 100],
        ['address' => 'b@example.com', 'maildir' => '/srv/phpcp/mail/example.com/b/', 'password' => 'x', 'quota_mb' => 100],
    ]);

    assertTrue(str_contains($first, 'a@example.com /srv/phpcp/mail/example.com/a/'), 'ต้องมีบรรทัดของกล่องแรก');
    assertTrue(str_contains($first, 'b@example.com'), 'ต้องมีบรรทัดของกล่องที่สอง');

    // ลบ b ออกแล้วเขียนใหม่ — ไฟล์ที่ได้ต้องไม่เหลือ b อีกเลย
    $second = (string) $render->invoke($manager, [
        ['address' => 'a@example.com', 'maildir' => '/srv/phpcp/mail/example.com/a/', 'password' => 'x', 'quota_mb' => 100],
    ]);

    assertTrue(!str_contains($second, 'b@example.com'), 'กล่องที่ถูกลบต้องหายจากไฟล์: ' . $second);
});

test('catch-all ต้องไม่กลืนกล่องจริงของโดเมนเดียวกัน', static function (): void {
    /*
     * **บั๊กที่เจอจากการส่งเมลจริงเท่านั้น (2026-08-12)**
     *
     * Postfix แปลง virtual alias ซ้ำจนกว่าจะไม่มีอะไรตรง · โดเมนที่มี catch-all
     * จะกลืนกล่องจริงทุกกล่อง: เมลถึง `sales@` แปลงเป็น `webbox@` ตาม alias แล้ว
     * `webbox@` โดน catch-all แปลงต่อไปที่กล่องของ catch-all — กล่องจริงไม่ได้รับเลย
     *
     * ไฟล์ตั้งค่าทุกไฟล์ถูกต้องหมดและ `postmap -q` ก็ตอบถูก · จับได้ตอนเปิดกล่อง
     * แล้วพบว่าเมลไปโผล่ผิดที่เท่านั้น
     */
    $manager = new MailboxManager(new Template(PHPCP_ROOT . '/templates'));
    $render = new ReflectionMethod(MailboxManager::class, 'renderAliases');

    $aliases = (string) $render->invoke(
        $manager,
        [
            ['source' => '@example.com', 'destination' => 'catchall@example.com'],
            ['source' => 'sales@example.com', 'destination' => 'somchai@example.com'],
        ],
        [
            ['address' => 'somchai@example.com', 'maildir' => '/x/', 'password' => 'x', 'quota_mb' => 100],
            ['address' => 'catchall@example.com', 'maildir' => '/y/', 'password' => 'x', 'quota_mb' => 100],
        ],
    );

    assertTrue(
        str_contains($aliases, 'somchai@example.com somchai@example.com'),
        'กล่องจริงต้องมีบรรทัดชี้กลับหาตัวเอง เพื่อหยุดการแปลงรอบสอง: ' . $aliases,
    );

    // บรรทัดของกล่องต้องมาก่อน catch-all เสมอ — อ่านไฟล์แล้วต้องเห็นเจตนาได้ทันที
    assertTrue(
        strpos($aliases, 'somchai@example.com somchai') < strpos($aliases, '@example.com catchall'),
        'บรรทัดของกล่องต้องอยู่ก่อน catch-all',
    );
});

test('ไฟล์ผู้ใช้ของ Dovecot ต้องมีโควตาต่อกล่องเสมอ', static function (): void {
    // กล่องที่ไม่มีโควตาคือกล่องที่โตได้จนดิสก์เต็ม ซึ่งทำให้**ทุกบริการ**บนเครื่องล้ม
    $manager = new MailboxManager(new Template(PHPCP_ROOT . '/templates'));
    $render = new ReflectionMethod(MailboxManager::class, 'renderDovecotUsers');

    $users = (string) $render->invoke($manager, [
        ['address' => 'me@example.com', 'maildir' => '/x/', 'password' => '{ARGON2ID}$argon2id$v=19$abc', 'quota_mb' => 250],
    ]);

    assertTrue(str_contains($users, 'me@example.com:{ARGON2ID}'), 'ต้องมีที่อยู่และแฮชคั่นด้วยทวิภาค');
    assertTrue(str_contains($users, 'userdb_quota_rule=*:bytes=250M'), 'ต้องมีโควตาติดมากับทุกบรรทัด: ' . $users);
});

test('ลบกล่องต้องแตะได้เฉพาะใต้ที่เก็บเมลเท่านั้น', static function (): void {
    // เส้นทางมาจากค่าที่ประกอบในโค้ด แต่กันไว้อีกชั้นเพราะพลาดที่นี่คือ `rm -rf` ผิดที่
    $manager = new MailboxManager(new Template(PHPCP_ROOT . '/templates'));
    $executor = new Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-mail-' . getmypid());

    foreach (['/etc', '/srv/phpcp/mail/../../etc', '/srv/phpcp', '/'] as $path) {
        $blocked = false;

        try {
            $manager->removeMaildir($executor, $path);
        } catch (\Throwable) {
            $blocked = true;
        }

        assertTrue($blocked, "ต้องปฏิเสธเส้นทาง: {$path}");
    }
});

test('สร้างกุญแจ DKIM ต้องไม่พึ่งเครื่องมือที่ใช้ JIT', static function (): void {
    /*
     * **เจอจริงบนเครื่องจริง (2026-08-12):** `rspamadm dkim_keygen` เขียนด้วย LuaJIT
     * ซึ่งต้องขอหน่วยความจำที่ทั้งเขียนและรันได้ · agent รันภายใต้
     * `MemoryDenyWriteExecute=yes` จึงระเบิดทันทีเป็น "PANIC: ... restricted kernel?"
     *
     * ผ่อนกฎนั้นเพื่อเครื่องมือตัวเดียวไม่คุ้ม — เหตุผลเดียวกับที่ agent ปิด pcre.jit
     * แทนที่จะผ่อน MemoryDenyWriteExecute · กุญแจ DKIM เป็นกุญแจ RSA ธรรมดา
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Mail/DkimManager.php');
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

    assertTrue(!str_contains($code, 'rspamadm'), 'ห้ามเรียก rspamadm ซึ่งใช้ LuaJIT');
    assertTrue(str_contains($code, 'genrsa'), 'ต้องสร้างกุญแจด้วย openssl');
});

test('ลบโดเมนต้องแตะได้เฉพาะโฟลเดอร์ของโดเมนนั้น', static function (): void {
    // ชื่อโดเมนผ่าน Validator มาแล้วทุกทาง แต่กันอีกชั้นเพราะพลาดที่นี่คือ rm -rf ผิดที่
    $manager = new MailboxManager(new Template(PHPCP_ROOT . '/templates'));
    $executor = new Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-mail-' . getmypid());

    foreach (['', '..', 'a/../..', 'x/y'] as $bad) {
        $blocked = false;

        try {
            $manager->removeDomainDir($executor, $bad);
        } catch (\Throwable) {
            $blocked = true;
        }

        assertTrue($blocked, "ต้องปฏิเสธชื่อโดเมน: '{$bad}'");
    }
});

test('Dovecot ต้องไม่เปิดพอร์ตที่ไม่เข้ารหัส', static function (): void {
    // รหัสผ่านเมลวิ่งผ่านเน็ตทุกครั้งที่โปรแกรมเมลเช็คกล่อง (ทุกไม่กี่นาที)
    // การเปิด 110/143 ไว้คือการประกาศรหัสผ่านของลูกค้าให้ทั้งวง
    $conf = (new Template(PHPCP_ROOT . '/templates'))->render('dovecot/99-phpcp.conf.tpl', [
        'MAIL_ROOT' => '/srv/phpcp/mail',
        'VMAIL_USER' => 'vmail',
        'USERS_FILE' => '/etc/dovecot/phpcp-users',
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);

    assertTrue(str_contains($conf, 'ssl = required'), 'ต้องบังคับ TLS');
    assertTrue(str_contains($conf, "inet_listener imap {\n    port = 0"), 'ต้องปิดพอร์ต imap ธรรมดา');
    assertTrue(str_contains($conf, "inet_listener pop3 {\n    port = 0"), 'ต้องปิดพอร์ต pop3 ธรรมดา');
    assertTrue(str_contains($conf, 'port = 993'), 'ต้องเปิด imaps');
    assertTrue(str_contains($conf, 'auth_username_format = %u'), 'LMTP ต้องใช้ที่อยู่เต็ม ไม่งั้นกล่องชื่อเดียวกันคนละโดเมนชนกัน');
});
