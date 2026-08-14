<?php

declare(strict_types=1);

/**
 * เส้นทางจัดการลูกค้า — PLAN-V2 เฟส A2
 *
 * capability สามตัวนี้ (customer.create / quota_update / site_attach) เคยลงทะเบียนไว้
 * แต่ไม่มีใครเรียกเลย ชั้นเว็บกับ CLI เขียนตรรกะเดียวกันซ้ำแล้วแตะฐานข้อมูลตรง ๆ
 * ผลคือเส้นทางนี้ไม่ได้ผ่าน Dispatcher เหมือนคำสั่งอื่นทั้งระบบ และไม่มีใครรู้ว่ามันพัง
 *
 * เทสต์ชุดนี้กันไม่ให้กลับไปเป็นแบบนั้นอีก และตรึงกฎโควตาที่เพี้ยนกันได้ง่ายที่สุด
 * (-1 = ไม่จำกัด ซึ่งเคยถูกปฏิเสธในเส้นทางหนึ่งแต่ผ่านในอีกเส้นทางหนึ่ง)
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Context;
use Phpcp\Agent\Dispatcher;
use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserRepository;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('Customer — บัญชีโฮสติ้งต้องเดินผ่าน agent ทั้งหมด');

/** บริบทสำหรับรัน capability ตรง ๆ โดยไม่ต้องมี agent ทำงานอยู่ */
function customerContext(\Phpcp\Kernel\Db $db): Context
{
    return new Context(
        new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );
}

function customerExecutor(): SandboxExecutor
{
    return new SandboxExecutor(sys_get_temp_dir() . '/phpcp-cust-' . getmypid());
}

test('โควตา -1 (ไม่จำกัด) ต้องตั้งได้ทุกเส้นทาง', static function (): void {
    $registry = new CapabilityRegistry();

    // เคยเป็นบั๊กจริง: customer.create ปฏิเสธค่าติดลบทั้งหมดด้วยข้อความ
    // "โควตาต้องเป็นจำนวนเต็มบวก" ทั้งที่ทั้งระบบใช้ -1 แทน "ไม่จำกัด"
    $clean = $registry->resolve('customer.create')->validate([
        'username' => 'unlimited',
        'password' => 'password1234',
        'email' => 'a@example.com',
        'quota_domains' => -1,
        'quota_databases' => -1,
    ]);

    assertSame(-1, $clean['quota_domains'], 'customer.create ต้องรับ -1 = ไม่จำกัด');

    $updated = $registry->resolve('customer.quota_update')->validate([
        'user_id' => 1,
        'quota_domains' => -1,
    ]);

    assertSame(-1, $updated['quota_domains'], 'customer.quota_update ต้องรับ -1 เหมือนกัน');
});

test('โควตาที่ผิดกฎถูกปฏิเสธด้วย ValidationError ไม่ใช่ระเบิดเป็น error ภายใน', static function (): void {
    $registry = new CapabilityRegistry();

    // ผู้ใช้ที่กรอกค่าผิดต้องได้ข้อความที่บอกว่าต้องแก้อะไร ไม่ใช่ "เกิดข้อผิดพลาดภายในระบบ"
    assertRejects(
        ValidationError::class,
        static fn () => $registry->resolve('customer.quota_update')->validate([
            'user_id' => 1,
            'quota_domains' => 0,
        ]),
        'โควตาโดเมน 0 ต้องถูกปฏิเสธ (ปิดโดเมนทั้งหมด = ลูกค้าใช้อะไรไม่ได้เลย)',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $registry->resolve('customer.create')->validate([
            'username' => 'x',
            'password' => 'password1234',
            'email' => 'a@example.com',
            'quota_domains' => -5,
        ]),
        'โควตาที่น้อยกว่า -1 ต้องถูกปฏิเสธ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $registry->resolve('customer.quota_update')->validate(['user_id' => 1]),
        'ไม่ระบุโควตาสักตัวต้องถูกปฏิเสธ ไม่ใช่เขียนทับด้วยค่าว่าง',
    );
});

test('ไม่ส่งโควตามา = ไม่เปลี่ยนค่านั้น (ต่างจากส่ง 0)', static function (): void {
    // เคยเป็นบั๊กจริงที่ไม่มีใครเจอเพราะไม่มีใครเรียก capability ตัวนี้เลย:
    // ค่าเริ่มต้นถูกส่งเป็น null เข้า Validator::optionalInt() ที่รับเฉพาะ int
    // ซึ่งตายด้วย TypeError ทันทีที่มีคนเรียกจริง
    $clean = (new CapabilityRegistry())->resolve('customer.quota_update')->validate([
        'user_id' => 1,
        'quota_databases' => 3,
    ]);

    assertSame(null, $clean['quota_domains'], 'โควตาที่ไม่ได้ส่งมาต้องเป็น null = ไม่แตะ');
    assertSame(3, $clean['quota_databases'], 'โควตาที่ส่งมาต้องถูกใช้');
});

test('สร้างลูกค้าผ่าน capability ได้บัญชี panel พร้อมบังคับเปลี่ยนรหัสผ่าน', static function (): void {
    $db = migratedDb();
    $context = customerContext($db);

    $capability = (new CapabilityRegistry())->resolve('customer.create');
    $clean = $capability->validate([
        'username' => 'somchai',
        'password' => 'temporary-password',
        'email' => 'somchai@example.com',
        'must_change_password' => true,
    ]);

    $result = $capability->run($clean, customerExecutor(), $context);

    $user = (new UserRepository($db))->find((int) $result['id']);

    assertSame('somchai', $user['username'], 'ต้องสร้างบัญชีจริง');
    assertSame(Permissions::WEBADMIN, $user['role'], 'บัญชีโฮสติ้งต้องเป็น webadmin');
    assertSame('somchai@example.com', $user['email'], 'อีเมลต้องอยู่บนแถวเดียวกับที่ใช้ล็อกอิน');
    assertSame(1, (int) $user['must_change_password'], 'รหัสผ่านที่ระบบสุ่มให้ต้องถูกบังคับเปลี่ยนตอนล็อกอินแรก');

    // ไม่มีรหัสผ่านชุดที่สองให้หลุด sync อีกแล้ว — ตารางลูกค้าถูกยุบไปตั้งแต่ migration 0005
    assertSame(
        0,
        (int) $db->value("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'customers'", [], 0),
        'ตาราง customers ต้องไม่มีอยู่แล้ว',
    );

    // ชื่อซ้ำต้องถูกปฏิเสธอย่างสุภาพ ไม่ใช่ให้ UNIQUE constraint ระเบิดออกมา
    assertRejects(
        ValidationError::class,
        static fn () => $capability->run($clean, customerExecutor(), $context),
        'ชื่อผู้ใช้ซ้ำต้องถูกปฏิเสธด้วย ValidationError',
    );
});

test('เชื่อมเว็บไซต์ต้องนับโควตาที่ติดมากับเว็บนั้นด้วย', static function (): void {
    $db = migratedDb();
    $context = customerContext($db);
    $registry = new CapabilityRegistry();

    $created = $registry->resolve('customer.create')->run(
        $registry->resolve('customer.create')->validate([
            'username' => 'limited',
            'password' => 'temporary-password',
            'email' => 'limited@example.com',
            'quota_domains' => 2,
        ]),
        customerExecutor(),
        $context,
    );

    $userId = (int) $created['id'];
    $adminId = (new UserRepository($db))->create('siteholder', 'Site-Holder-Pass-11', Permissions::SUPERADMIN);
    $now = time();

    // เว็บที่ยังไม่ได้ขาย = ผู้ดูแลถือไว้ · เว็บไร้เจ้าของเกิดขึ้นไม่ได้อีกแล้ว
    // (owner_user_id มี FK ไป users และมี trigger ห้ามเป็น NULL)
    $siteId = $db->insert('sites', [
        'primary_domain' => 'many.example.com',
        'docroot' => '/srv/phpcp/sites/many.example.com/public',
        'php_version' => '8.4',
        'owner_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // เว็บนี้มีโดเมนติดมา 3 รายการ แต่ลูกค้ามีโควตาแค่ 2
    foreach (['many.example.com' => 'primary', 'a.many.example.com' => 'subdomain', 'b.many.example.com' => 'subdomain'] as $domain => $type) {
        $db->insert('domains', [
            'site_id' => $siteId,
            'domain' => $domain,
            'type' => $type,
            'created_at' => $now,
        ]);
    }

    $attach = $registry->resolve('customer.site_attach');
    $result = $attach->run(
        $attach->validate(['user_id' => $userId, 'site_ids' => [$siteId]]),
        customerExecutor(),
        $context,
    );

    assertSame(0, $result['attached_count'], 'เว็บที่ทำให้ลูกค้าทะลุโควตาต้องไม่ถูกเชื่อม');
    assertSame(1, $result['quota_exceeded_count'], 'ต้องรายงานว่าข้ามเพราะโควตา');
    assertSame('quota_exceeded', $result['results'][0]['status'], 'เหตุผลต้องอยู่ในผลลัพธ์รายเว็บด้วย');

    assertSame(
        $adminId,
        (int) $db->value('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId], -1),
        'เว็บต้องยังอยู่กับผู้ดูแลเมื่อโควตาของลูกค้าไม่พอ',
    );
});

test('เชื่อมเว็บไซต์ที่อยู่ในโควตาได้ และตั้งเจ้าของบน sites.owner_user_id', static function (): void {
    $db = migratedDb();
    $context = customerContext($db);
    $registry = new CapabilityRegistry();

    $created = $registry->resolve('customer.create')->run(
        $registry->resolve('customer.create')->validate([
            'username' => 'owner',
            'password' => 'temporary-password',
            'email' => 'owner@example.com',
        ]),
        customerExecutor(),
        $context,
    );

    $userId = (int) $created['id'];
    $adminId = (new UserRepository($db))->create('siteholder', 'Site-Holder-Pass-11', Permissions::SUPERADMIN);
    $now = time();

    $siteId = $db->insert('sites', [
        'primary_domain' => 'single.example.com',
        'docroot' => '/srv/phpcp/sites/single.example.com/public',
        'php_version' => '8.4',
        'owner_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $attach = $registry->resolve('customer.site_attach');
    $result = $attach->run(
        $attach->validate(['user_id' => $userId, 'site_ids' => [$siteId]]),
        customerExecutor(),
        $context,
    );

    assertSame(1, $result['attached_count'], 'เว็บที่อยู่ในโควตาต้องเชื่อมได้');

    // เจ้าของอยู่ที่เดียวแล้ว ไม่มี id สองระบบให้สลับกันอีก
    $site = $db->first('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);

    assertSame($userId, (int) $site['owner_user_id'], 'sites.owner_user_id ต้องชี้ไปที่ users.id');

    // เว็บที่มีเจ้าของแล้วต้องโอนซ้ำไม่ได้ — การย้ายเจ้าของต้องเป็นคำสั่งของตัวเอง
    $again = $attach->run(
        $attach->validate(['user_id' => $userId, 'site_ids' => [$siteId]]),
        customerExecutor(),
        $context,
    );

    assertSame(0, $again['attached_count'], 'เว็บที่เป็นของบัญชีนี้อยู่แล้วต้องไม่ถูกนับซ้ำ');
});

test('audit log ต้องไม่เก็บรหัสผ่านหรือเนื้อหาไฟล์ดิบ', static function (): void {
    // audit_log เป็น hash-chain ที่ลบไม่ได้และถูก mirror ลงไฟล์ที่ผู้ดูแลอ่านได้
    // รหัสผ่านลูกค้าหรือเนื้อหาไฟล์ .env ที่หลุดลงไปจึงอยู่ตรงนั้นถาวร
    $redact = new ReflectionMethod(Dispatcher::class, 'redact');

    $clean = $redact->invoke(null, [
        'username' => 'somchai',
        'password' => 'ความลับ-ห้ามบันทึก',
        'notify.telegram.token' => '123456:AAH-ห้ามบันทึก',
        'content' => str_repeat('x', 5000),
        'nested' => ['db_password' => 'ห้ามบันทึก', 'host' => 'localhost'],
        'site_id' => 7,
    ]);

    $flat = json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '';

    assertTrue(!str_contains($flat, 'ห้ามบันทึก'), 'ค่าที่เป็นความลับต้องไม่หลุดลง audit log');
    assertSame('***', $clean['password'], 'รหัสผ่านต้องถูกแทนที่');
    assertSame('***', $clean['notify.telegram.token'], 'token ต้องถูกแทนที่');
    assertSame('***', $clean['nested']['db_password'], 'ต้องล้างค่าที่ซ้อนอยู่ข้างในด้วย');
    assertTrue(strlen((string) $clean['content']) < 100, 'เนื้อหาไฟล์ต้องถูกตัด เหลือแค่ขนาด');

    // ค่าที่ไม่ใช่ความลับต้องยังอ่านได้ ไม่งั้น audit log จะหมดประโยชน์
    assertSame('somchai', $clean['username'], 'ค่าปกติต้องยังอยู่ครบ');
    assertSame(7, $clean['site_id'], 'ค่าตัวเลขปกติต้องไม่ถูกแตะ');
    assertSame('localhost', $clean['nested']['host'], 'ค่าปกติที่ซ้อนอยู่ต้องยังอยู่');
});

test('ชั้นเว็บและ CLI ต้องไม่เขียนตรรกะลูกค้าซ้ำอีก', static function (): void {
    // กันการถอยกลับไปเป็นแบบเดิม: ถ้ามีใครเรียก repository ตรง ๆ อีกครั้ง
    // เส้นทางนั้นจะไม่ผ่าน Dispatcher = ไม่มี audit และกฎโควตาจะเริ่มเพี้ยนจากกันอีก
    // ชั้นเว็บของลูกค้าย้ายไปอยู่ `Http/V2/UsersController` แล้ว ตอนลบ UI แบบ HTML —
    // ตอนนี้ลูกค้ากับผู้ดูแลเป็นทรัพยากรเดียวกัน (`/api/v2/users`) ตั้งแต่เฟส M
    $controller = (string) file_get_contents(PHPCP_ROOT . '/src/Http/V2/UsersController.php');
    $cli = (string) file_get_contents(PHPCP_ROOT . '/src/Cli/Application.php');

    foreach (['->createHostingAccount(', '->updateQuota('] as $forbidden) {
        assertTrue(
            !str_contains($controller, $forbidden),
            "UsersController ต้องไม่เรียก {$forbidden} เอง — ต้องสั่งผ่าน agent",
        );
        assertTrue(
            !str_contains($cli, $forbidden),
            "CLI ต้องไม่เรียก {$forbidden} เอง — ต้องสั่งผ่าน agent",
        );
    }

    foreach (['customer.create', 'customer.quota_update', 'customer.site_attach'] as $capability) {
        assertTrue(
            str_contains($controller, "'{$capability}'") || str_contains($cli, "'{$capability}'"),
            "ต้องมีคนเรียก capability {$capability} จริง ไม่ใช่ลงทะเบียนไว้เฉย ๆ",
        );
    }
});
