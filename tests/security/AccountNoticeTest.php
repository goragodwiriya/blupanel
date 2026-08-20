<?php

declare(strict_types=1);

/**
 * อีเมลถึงเจ้าของบัญชี · รหัสผ่านที่กรอกไว้ล่วงหน้า · ข้อมูลการเชื่อมต่อของลูกค้า
 *
 * สามเรื่องนี้อยู่ไฟล์เดียวกันเพราะมันคือด้านเดียวกันของระบบ: **สิ่งที่ panel
 * ส่งมอบให้ลูกค้า** และความผิดพลาดของมันมีหน้าตาเหมือนกันหมด — ข้อมูลที่ไม่ควร
 * ออกไปกลับออกไป หรือข้อมูลที่ควรออกไปกลับไม่มีใครได้รับ
 *
 *   - ตัวส่งเมลที่รับที่อยู่ผู้รับจากคำขอ = ช่องส่งสแปมด้วยเครื่องของลูกค้า
 *   - ความพร้อมของเมลที่ลูกค้าเห็น = พาธใบรับรอง ชื่อโฮสต์เครื่อง และ PTR
 *   - ฟอร์มที่ไม่กรอกรหัสสุ่มไว้ = ผู้ดูแลคิดรหัสเอง ซึ่งได้รหัสที่อ่อนที่สุดในระบบ
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Routes;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;

group('การส่งมอบให้ลูกค้า — อีเมล รหัสผ่าน และข้อมูลการเชื่อมต่อ');

test('ความพร้อมของเมลต้องเป็นของผู้ดูแลเครื่องเท่านั้น', static function (): void {
    /*
     * ตารางนี้ตอบคำถามเกี่ยวกับ "เครื่อง" ล้วน ๆ — พาธใบรับรองบนดิสก์ ชื่อโฮสต์
     * ของเมล เรกคอร์ด PTR และผู้ให้บริการบล็อกพอร์ต 25 ขาออกหรือไม่ · ทุกแถวที่
     * ไม่ผ่านบอกให้ "ไปแก้ที่ผู้ให้บริการ VPS" หรือ "panel แก้ให้ได้" ซึ่งลูกค้า
     * ทำไม่ได้สักอย่าง · เดิมมันอยู่ที่ mail.view ซึ่งลูกค้าถือไว้เพื่อจัดการกล่อง
     * จดหมายของโดเมนตัวเอง
     */
    $found = false;

    foreach (Routes::build()->routes() as $route) {
        if ($route->path !== '/api/v2/mail/readiness') {
            continue;
        }

        $found = true;
        assertSame('settings.manage', $route->permission, 'ความพร้อมของเมลต้องใช้ settings.manage');
    }

    assertTrue($found, 'ต้องมีเส้นทางความพร้อมของเมลอยู่จริง');
    assertTrue(!Permissions::roleHas(Permissions::WEBADMIN, 'settings.manage'), 'ลูกค้าต้องไม่มี settings.manage');
});

test('การ์ดความพร้อมของเมลต้องถูกตัดออกจาก DOM ไม่ใช่แค่ซ่อน', static function (): void {
    /*
     * `data-component="api"` ที่ถูกซ่อนไว้ยังยิงคำขอจริง · ถ้ากั้นด้วย CSS อย่างเดียว
     * เบราว์เซอร์ของลูกค้าทุกคนจะยังถามหาพาธใบรับรองของเครื่องแล้วได้ 403 กลับมา
     * ทุกครั้งที่เปิดหน้านี้ — กั้นสองชั้นถึงจะครบ
     */
    $template = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/mailboxes.html');

    $position = strpos($template, '/api/v2/mail/readiness');
    assertTrue($position !== false, 'หน้านี้ต้องยังมีการ์ดความพร้อมของเมล');

    // ต้องมี data-if ครอบอยู่ก่อนหน้า endpoint นั้นในไฟล์
    $before = substr($template, 0, (int) $position);
    $gate = strrpos($before, "data-if=\"permissions['settings.manage']\"");

    assertTrue($gate !== false, 'ต้องมี data-if ของ settings.manage ครอบการ์ดความพร้อมของเมล');
});

test('ลูกค้าต้องได้ข้อมูลที่จำเป็นต่อการใช้งานแทน', static function (): void {
    // ตัดของที่ไม่ควรเห็นออกอย่างเดียวไม่พอ — ลูกค้าสร้างกล่องจดหมายได้แต่ไม่มี
    // ที่ไหนบอกว่าจะตั้งใน Outlook อย่างไร คือปัญหาที่มีอยู่ก่อนหน้านี้
    $permissions = [];

    foreach (Routes::build()->routes() as $route) {
        $permissions[$route->method . ' ' . $route->path] = $route->permission;
    }

    assertSame('mail.view', $permissions['GET /api/v2/mail/connection'] ?? null, 'ค่าการเชื่อมต่อเมลต้องถึงลูกค้า');
    assertSame('db.view', $permissions['GET /api/v2/databases/connection'] ?? null, 'ค่าการเชื่อมต่อฐานข้อมูลต้องถึงลูกค้า');

    foreach (['mail.view', 'db.view', 'file.view'] as $permission) {
        assertTrue(Permissions::roleHas(Permissions::WEBADMIN, $permission), "ลูกค้าต้องมี {$permission}");
    }

    // SFTP: หน้าเดิมบอกแค่ "เปิดอยู่" กับชื่อผู้ใช้ ไม่บอกโฮสต์และพอร์ตเลย
    $sftp = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/sftp.html');
    assertTrue(str_contains($sftp, 'connection.host'), 'หน้า SFTP ต้องบอกโฮสต์');
    assertTrue(str_contains($sftp, 'connection.port'), 'หน้า SFTP ต้องบอกพอร์ต');
    assertTrue(str_contains($sftp, 'own.sftp_username'), 'ต้องแสดงชื่อบัญชีระบบ ไม่ใช่ชื่อผู้ใช้ panel');
});

test('การอ่านพอร์ต SSH ต้องไม่ต้องแลกด้วยสิทธิ์ดู sshd ทั้งไฟล์', static function (): void {
    /*
     * `ssh.config_get` ตอบทั้งไฟล์ตั้งค่า รวมถึง root ล็อกอินได้ไหมและรับรหัสผ่าน
     * ว่างไหม · การให้ลูกค้าถือ `ssh.view` เพื่อรู้เลขพอร์ตตัวเดียวคือการแลกที่ผิด
     */
    $capability = (new CapabilityRegistry())->resolve('sftp.connection');

    assertSame('file.view', $capability->permission(), 'ต้องใช้สิทธิ์เดียวกับหน้า SFTP');
    assertTrue(!$capability->isMutating(), 'เป็นการอ่านอย่างเดียว');
    assertTrue(!Permissions::roleHas(Permissions::WEBADMIN, 'ssh.view'), 'ลูกค้าต้องไม่ได้ ssh.view มาด้วย');

    // ต้องคืนแค่โฮสต์กับพอร์ต ไม่มีอะไรอื่นของ sshd หลุดออกไป
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SftpConnection.php');
    foreach (['PermitRootLogin', 'PasswordAuthentication', 'PermitEmptyPasswords'] as $leak) {
        assertTrue(!str_contains($source, $leak), "ต้องไม่ส่ง {$leak} ออกไป");
    }
});

test('ตัวส่งอีเมลถึงลูกค้าต้องอ่านที่อยู่ผู้รับเอง ไม่รับจากคำขอ', static function (): void {
    /*
     * **นี่คือข้อที่สำคัญที่สุดของไฟล์นี้**
     *
     * ผู้เรียกส่งมาแค่ `user_id` · ที่อยู่อีเมลถูกอ่านจากตาราง users ฝั่ง agent เอง
     * ผลคือต่อให้ชั้นเว็บถูกยึดทั้งชั้น ก็สั่งให้เครื่องส่งเมลไปหาที่อยู่นอกระบบ
     * ไม่ได้ — ทำได้อย่างมากคือส่งไปยังที่อยู่ที่อยู่บนบัญชีอยู่แล้ว ซึ่งมีแต่
     * ผู้ดูแลที่เปลี่ยนได้ และการเปลี่ยนถูกบันทึกใน audit log
     */
    $capability = (new CapabilityRegistry())->resolve('mail.user_notice');
    $clean = $capability->validate(['user_id' => 5, 'subject' => 'x', 'body' => 'y', 'to' => 'attacker@evil.test']);

    assertTrue(!array_key_exists('to', $clean), 'ที่อยู่ผู้รับที่ส่งมาต้องถูกทิ้ง ไม่ใช่ถูกใช้');
    assertSame(5, $clean['user_id'], 'รับเฉพาะ user_id');

    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/MailUserNotice.php');
    assertTrue(
        str_contains($source, 'SELECT username, email FROM users'),
        'ต้องอ่านอีเมลจากตาราง users เอง',
    );

    assertSame('customer.manage', $capability->permission(), 'ต้องใช้สิทธิ์เดียวกับการตั้งรหัสผ่านใหม่ให้ลูกค้า');
    assertTrue($capability->isMutating(), 'ต้องถูกบันทึก audit — "ใครส่งรหัสผ่านให้ลูกค้าคนไหนเมื่อไร" ต้องตอบได้ย้อนหลัง');
});

test('อีเมลที่ไม่มีเนื้อความหรือยาวเกินจริงต้องถูกปฏิเสธ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('mail.user_notice');

    foreach ([['body' => ''], ['body' => '   '], ['body' => str_repeat('a', 20001)]] as $bad) {
        $rejected = false;

        try {
            $capability->validate(['user_id' => 1, 'subject' => 'x'] + $bad);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, 'เนื้อความว่างหรือยาวเกินต้องถูกปฏิเสธ');
    }
});

test('หัวเรื่องของเมลต้องเข้ารหัสไม่ใช่แค่กรอง', static function (): void {
    /*
     * หัวเรื่องถูกพับลงไปเป็นบรรทัดหัวเมล · การขึ้นบรรทัดใหม่ในนั้นแปลว่าผู้เรียก
     * แนบหัวเมลของตัวเองต่อท้ายได้ ซึ่ง `Bcc:` คือตัวที่ชัดที่สุด · การเข้ารหัส
     * base64 (ซึ่ง RFC 2047 บังคับอยู่แล้วสำหรับอักษรที่ไม่ใช่ ASCII) กำจัดอักขระ
     * นั้นทิ้งไปเลย แทนที่จะไว้ใจตัวกรอง
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Mail/MailManager.php');

    assertTrue(str_contains($source, 'base64_encode($subject)'), 'หัวเรื่องต้องถูกเข้ารหัส base64');
    assertTrue(str_contains($source, 'assertEmail($to)'), 'ที่อยู่ผู้รับต้องผ่านตัวตรวจรูปแบบ');
    assertTrue(str_contains($source, 'assertEmail($from)'), 'ที่อยู่ผู้ส่งต้องผ่านตัวตรวจรูปแบบ');
});

test('บัญชีใหม่ต้องใช้รหัสเดียวกันสำหรับ panel และ SFTP แต่ไม่ปนกับฐานข้อมูล', static function (): void {
    /*
     * สองอย่างนี้คือ "ตัวตนของลูกค้าบนโฮสต์นี้" อย่างเดียวกัน และถูกส่งมอบพร้อมกัน
     * ในอีเมลฉบับเดียวที่ต้องอ่านครั้งเดียวแล้วทำตาม · สุ่มสองค่าในอีเมลฉบับนั้นคือ
     * ทางที่ค่าหนึ่งจะถูกจดผิด
     *
     * แต่หยุดแค่นั้น — รหัสฐานข้อมูลกับกล่องจดหมายยังสุ่มแยกต่อทรัพยากร เพราะสอง
     * อันนั้นถูกคัดลอกไปฝังใน wp-config.php และในมือถือ แล้วอยู่อย่างนั้นเป็นปี ๆ
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');

    assertTrue(
        str_contains($source, '$sftpPassword = $password;'),
        'บัญชีใหม่: SFTP ต้องใช้รหัสเดียวกับ panel',
    );

    $dbCreate = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/DbCreate.php');
    assertTrue(
        str_contains($dbCreate, "\$args['password'] !== '' ? \$args['password'] : self::randomPassword()"),
        'ฐานข้อมูลต้องสุ่มรหัสของตัวเองเมื่อไม่ได้ระบุ ไม่ใช่ยืมของบัญชี',
    );
});

test('ทุกฟอร์มที่สร้างข้อมูลประจำตัวต้องเปิดมาพร้อมรหัสสุ่ม', static function (): void {
    // เดิมช่องรหัสผ่านมีสี่พฤติกรรมต่างกันสี่ที่ · ที่แย่ที่สุดคือ SFTP ซึ่งบังคับ
    // ให้ผู้ดูแลคิดรหัสเอง — รหัสที่คนคิดสด ๆ ตอนนั้นคือรหัสที่อ่อนที่สุดบนเครื่อง
    $forms = [
        'mailbox-form.html',
        'database-form.html',
        'sftp-password-form.html',
        'sftp-own-password-form.html',
        'user-create.html',
    ];

    foreach ($forms as $form) {
        $html = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/' . $form);

        assertTrue(
            str_contains($html, 'data-attr="value:suggested_password"'),
            "{$form} ต้องกรอกรหัสสุ่มไว้ให้แล้ว",
        );
        assertTrue(
            str_contains($html, 'click.prevent:suggestPassword'),
            "{$form} ต้องมีปุ่มขอรหัสใหม่",
        );
        assertTrue(
            !str_contains($html, 'type="password"'),
            "{$form} ต้องเป็นช่องข้อความอ่านออก — เป็นข้อมูลประจำตัวที่กำลังส่งมอบ ไม่ใช่รหัสที่กำลังพิมพ์ซ้ำ",
        );
    }
});

test('รหัสที่ระบบแนะนำต้องผ่านเกณฑ์ที่เซิร์ฟเวอร์จะใช้ตัดสินเอง', static function (): void {
    /*
     * ถ้าตัวสุ่มกับตัวตรวจไม่ตรงกัน อาการคือรหัสที่ระบบแนะนำเอง แต่เซิร์ฟเวอร์
     * ปฏิเสธตอนกดบันทึก — ในจังหวะที่ผู้ใช้คิดว่าทำเสร็จแล้ว · นี่คือเหตุผลที่
     * ตัวสุ่มอยู่ฝั่งเซิร์ฟเวอร์ที่เดียว ไม่มีอีกตัวในเบราว์เซอร์
     */
    for ($i = 0; $i < 50; $i++) {
        $password = Password::random(20);

        assertSame([], Password::problems($password), "รหัสที่สุ่มได้ต้องผ่านเกณฑ์เสมอ: {$password}");
    }

    $route = null;

    foreach (Routes::build()->routes() as $candidate) {
        if ($candidate->path === '/api/v2/password/suggest') {
            $route = $candidate;
        }
    }

    assertTrue($route !== null, 'ต้องมีเส้นทางขอรหัสสุ่ม');
    assertSame('dashboard.view', $route->permission, 'แค่ล็อกอินอยู่ก็พอ แต่ต้องไม่เปิดให้คนนอก');

    // มองหา "การเรียกใช้" ไม่ใช่ "การเอ่ยถึง" — คอมเมนต์ที่อธิบายว่าทำไมถึงไม่ใช้
    // มันคือสิ่งที่ควรมี ไม่ใช่สิ่งที่ควรจับได้
    $ui = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');
    assertTrue(
        !str_contains($ui, 'crypto.getRandomValues('),
        'ต้องไม่มีตัวสุ่มฝั่งเบราว์เซอร์อีกตัวให้ตามให้ตรงกัน',
    );
});

test('คีย์ที่ฟอร์มสร้างผู้ใช้อ่านจากคำตอบของ capability ต้องมีอยู่จริง', static function (): void {
    /*
     * **บั๊กจริงบนเครื่องใช้งาน (2026-08-20):** ฟอร์มอ่านรหัสเว็บที่เพิ่งสร้าง
     * ด้วย `$site['id']` แต่ `site.create` ตอบกลับมาเป็น `site_id` มาโดยตลอด
     * (SitesController อ่านชื่อนั้นถูกอยู่แล้ว) · ค่าจึงเป็น 0 เงียบ ๆ ตั้งแต่วัน
     * ที่เขียนบรรทัดนั้น และไม่มีใครเห็น เพราะมันแค่เดินทางออกไปในคำตอบเป็นลิงก์
     * ที่ไม่มีใครกด
     *
     * มันโผล่ตอนที่มีขั้นตอนอื่นในคำขอเดียวกันเริ่ม**ใช้**ค่านั้นจริง — การออก
     * ใบรับรอง — แล้วได้ข้อความ `site_id must be between 1 and …` ซึ่งบอกชื่อ
     * อาร์กิวเมนต์แต่ไม่บอกเลยว่าเลข 0 มาจากไหน
     *
     * ชื่อคีย์ที่ไม่ตรงกันข้าม layer แบบนี้ PHP ไม่เตือน และเทสต์ที่ mock ก็ไม่จับ
     * เพราะ mock ถูกเขียนตามสิ่งที่ผู้เรียกคาดหวัง ไม่ใช่ตามสิ่งที่ผู้ถูกเรียกตอบ
     */
    $users = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');
    $sites = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/SitesController.php');

    assertTrue(
        preg_match("~\\\$site\\['([a-z_]+)'\\] \\?\\? 0~", $users, $fromForm) === 1,
        'ฟอร์มสร้างผู้ใช้ต้องยังอ่านรหัสเว็บจากคำตอบของ site.create อยู่',
    );

    assertTrue(
        preg_match("~\\\$result\\['([a-z_]+)'\\];~", $sites, $fromSites) === 1,
        'หน้าเว็บไซต์ต้องยังอ่านรหัสเว็บจากคำตอบเดียวกันอยู่',
    );

    /*
     * ผู้เรียก capability เดียวกันสองที่ต้องอ่านคีย์ชื่อเดียวกัน · หน้าเว็บไซต์
     * ใช้งานได้จริงมาตลอด คีย์ของมันจึงเป็นตัวตัดสิน — อีกฝั่งที่ไม่ตรงคือฝั่งที่ผิด
     */
    assertSame(
        $fromSites[1],
        $fromForm[1],
        "SitesController อ่าน '{$fromSites[1]}' แต่ UsersController อ่าน '{$fromForm[1]}' — "
        . 'ผู้เรียก site.create สองที่อ่านคนละคีย์ ฝั่งที่ผิดจะได้ 0 เงียบ ๆ',
    );

    // และค่านั้นต้องถูกส่งต่อให้ขั้นตอนถัดไปจริง ไม่ใช่เก็บไว้เฉย ๆ
    assertTrue(
        str_contains($users, "'site_id' => (int) \$createdSite['id']"),
        'ขั้นตอน SSL ต้องใช้รหัสเว็บที่เพิ่งสร้าง',
    );
});
