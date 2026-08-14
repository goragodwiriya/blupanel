<?php

declare(strict_types=1);

/**
 * โควตาพื้นที่ดิสก์ระดับบัญชี — PLAN-V2 เฟส E2
 *
 * ครอบสามชั้นที่เฟสนี้เพิ่มเข้ามา เรียงตามความเสียหายถ้าพลาด:
 *   1. **`disk.usage` ต้องไม่ SQL error อีก** — บั๊กเดิมอ้างคอลัมน์ `sites.disk_quota_mb`
 *      ที่หายไปตั้งแต่ migration 0006 ทำให้ scheduler ล้มทุก 15 นาทีเงียบ ๆ
 *   2. **`QuotaChecker` ต้องปฏิเสธการสร้างทรัพยากรใหม่เมื่อดิสก์เต็ม** — นี่คือด่านบังคับ
 *      ใช้จริงหนึ่งเดียวของเฟสนี้ (ทางแอปพลิเคชัน ไม่ใช่ระดับ filesystem — ดูหมายเหตุ
 *      ใน QuotaChecker::diskQuotaExceeded())
 *   3. **แจ้งเตือนต้องไม่สแปมและต้องไม่พลาดตอนขึ้นระดับใหม่** — เก็บ "ระดับล่าสุด"
 *      ต่อบัญชีเดียว ต่างจาก expiry ที่แจ้งครั้งเดียวจบเพราะดิสก์ขึ้นลงได้
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\DiskQuotaCheck;
use Phpcp\Agent\Capability\DiskUsage;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Paths;
use Phpcp\Security\Permissions;

group('DiskQuota — โควตาพื้นที่ดิสก์ระดับบัญชี');

/** สภาพแวดล้อมทดสอบแยกจากข้อมูลจริง — ฐานข้อมูลชั่วคราวของตัวเอง */
function diskQuotaFixture(): array
{
    $root = sys_get_temp_dir() . '/phpcp-diskquota-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);

    $db = new Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $context = new Context(
        new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    register_shutdown_function(static function () use ($root): void {
        exec('rm -rf ' . escapeshellarg($root));
    });

    return [
        'root' => $root,
        'db' => $db,
        'context' => $context,
        'executor' => new RealExecutor(),
        'users' => new UserRepository($db),
    ];
}

/** สลับ Paths::usersDir() ชั่วคราวให้ชี้เข้าไดเรกทอรีทดสอบ แล้วคืนค่าเดิมเสมอแม้ล้ม */
function withDiskQuotaUsersDir(string $dir, callable $fn): void
{
    $previous = Paths::usersDir();

    try {
        Paths::useUsersDir($dir);
        $fn();
    } finally {
        Paths::useUsersDir($previous);
    }
}

/** @return array{id:int,username:string} */
function seedHostingAccount(
    array $fixture,
    string $username,
    ?int $diskQuotaMb,
    int $diskUsedMb = 0,
    ?string $systemUser = null,
): array {
    $id = $fixture['db']->insert('users', [
        'username' => $username,
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'role' => Permissions::WEBADMIN,
        'totp_enabled' => 0,
        'must_change_password' => 0,
        'status' => 'active',
        'failed_attempts' => 0,
        'email' => '',
        'service_status' => 'active',
        'system_user' => $systemUser,
        'uid' => 0,
        'gid' => 0,
        'quota_domains' => 10,
        'quota_subdomains' => -1,
        'quota_aliases' => -1,
        'quota_emails' => -1,
        'quota_databases' => -1,
        'quota_ftp_users' => -1,
        'disk_quota_mb' => $diskQuotaMb,
        'disk_used_mb' => $diskUsedMb,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    return ['id' => $id, 'username' => $username];
}

// --- 1. disk.usage ต้องไม่ SQL error อีก ------------------------------------

test('disk.usage รันได้โดยไม่ SQL error แม้ไม่มีเว็บไซต์เลย (บั๊กเดิม: อ้าง sites.disk_quota_mb ที่หายไป)', static function (): void {
    $fixture = diskQuotaFixture();

    $capability = new DiskUsage();
    $result = $capability->run($capability->validate([]), $fixture['executor'], $fixture['context']);

    assertSame(0, $result['measured'], 'ไม่มีเว็บไซต์ให้วัด');
    assertSame(0, $result['failed'], 'ต้องไม่มีรายการล้มเหลว');
});

// --- 2. QuotaChecker ต้องปฏิเสธเมื่อดิสก์เต็ม --------------------------------

test('สร้างทรัพยากรใหม่ไม่ได้เมื่อพื้นที่ดิสก์เต็มแล้ว', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'diskfull', diskQuotaMb: 1000, diskUsedMb: 1000);

    $quota = new QuotaChecker($fixture['users']);
    $result = $quota->checkOwnerCanCreate($account['id'], 'database');

    assertSame(false, $result['ok'], 'ต้องถูกปฏิเสธเมื่อ used >= limit');
    assertTrue(str_contains($result['message'], 'พื้นที่ดิสก์เต็ม'), 'ข้อความต้องบอกสาเหตุว่าเป็นเรื่องดิสก์ ไม่ใช่โควตาชนิดอื่น: ' . $result['message']);
});

test('ยังสร้างทรัพยากรใหม่ได้เมื่อพื้นที่ดิสก์ยังไม่เต็ม', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'diskok', diskQuotaMb: 1000, diskUsedMb: 500);

    $quota = new QuotaChecker($fixture['users']);
    $result = $quota->checkOwnerCanCreate($account['id'], 'database');

    assertSame(true, $result['ok'], 'ยังไม่ถึงโควตาต้องสร้างได้: ' . $result['message']);
});

test('โควตาดิสก์ -1 (ไม่จำกัด) ไม่ถูกบล็อกไม่ว่าใช้ไปเท่าไหร่', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'diskunlimited', diskQuotaMb: -1, diskUsedMb: 999999);

    $quota = new QuotaChecker($fixture['users']);
    $result = $quota->checkOwnerCanCreate($account['id'], 'database');

    assertSame(true, $result['ok'], '-1 ต้องแปลว่าไม่จำกัดเสมอ: ' . $result['message']);
});

test('บัญชีที่ไม่เคยตั้งโควตาดิสก์เลย (NULL) ต้องตีความเป็นไม่จำกัด ไม่ใช่ปิดการใช้งาน', static function (): void {
    // disk_quota_mb เป็น NULL ได้จริงเพราะคอลัมน์ไม่มี DEFAULT (ALTER TABLE ADD COLUMN)
    // ถ้า (int) null เผลอกลายเป็น 0 บัญชีเก่าทุกบัญชีที่ยังไม่เคยตั้งค่าจะโดนบล็อกทันที
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'disknull', diskQuotaMb: null, diskUsedMb: 999999);

    $quota = new QuotaChecker($fixture['users']);
    $result = $quota->checkOwnerCanCreate($account['id'], 'database');

    assertSame(true, $result['ok'], 'NULL ต้องไม่ทำให้บัญชีเก่าถูกบล็อกย้อนหลัง: ' . $result['message']);
});

test('ผู้ดูแลระบบไม่ถูกบล็อกด้วยโควตาดิสก์ แม้ตัวเลขที่เก็บไว้จะเกิน', static function (): void {
    // กฎเดียวกับโควตานับจำนวน — ตัดสินจากบทบาท ไม่ใช่ตัวเลขที่ค้างอยู่ (M-live bug เดิม)
    $fixture = diskQuotaFixture();
    $adminId = $fixture['db']->insert('users', [
        'username' => 'diskadmin',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Permissions::SYSADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1,
        'disk_quota_mb' => 100, 'disk_used_mb' => 100,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $quota = new QuotaChecker($fixture['users']);
    $result = $quota->checkOwnerCanCreate($adminId, 'database');

    assertSame(true, $result['ok'], 'sysadmin ต้องไม่ถูกจำกัดโควตาดิสก์: ' . $result['message']);
});

// --- 3. สถานะการแจ้งเตือน — ต้องขึ้นเมื่อระดับสูงขึ้น ไม่แจ้งซ้ำ ------------------

test('บันทึกระดับแจ้งเตือนแล้วอ่านกลับได้ถูกต้อง และรีเซ็ตได้เมื่อลดลง', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'threshold1', diskQuotaMb: 1000, diskUsedMb: 0);
    $users = $fixture['users'];

    assertSame(0, $users->diskQuotaThreshold($account['id']), 'ยังไม่เคยแจ้ง ต้องเป็น 0');

    $users->recordDiskQuotaThreshold($account['id'], 80);
    assertSame(80, $users->diskQuotaThreshold($account['id']), 'ต้องบันทึกระดับ 80 ได้');

    $users->recordDiskQuotaThreshold($account['id'], 100);
    assertSame(100, $users->diskQuotaThreshold($account['id']), 'ต้องขยับขึ้นได้');

    $users->recordDiskQuotaThreshold($account['id'], 0);
    assertSame(0, $users->diskQuotaThreshold($account['id']), 'ต้องลดกลับลงได้เมื่อใช้พื้นที่น้อยลง (ต่างจาก expiry_notifications ที่ห้ามย้อน)');
});

// --- 4. quota.disk_check — วัดจริงและปรับระดับแจ้งเตือนจริง ---------------------

test('quota.disk_check วัดพื้นที่บัญชีจริงแล้วอัปเดต disk_used_mb และระดับแจ้งเตือน', static function (): void {
    $fixture = diskQuotaFixture();
    $usersDir = $fixture['root'] . '/users';
    mkdir($usersDir, 0750, true);

    withDiskQuotaUsersDir($usersDir, function () use ($fixture): void {
        $account = seedHostingAccount($fixture, 'measured1', diskQuotaMb: 1, diskUsedMb: 0, systemUser: 'measured1');

        // สร้างไฟล์จริงใต้บ้านบัญชีให้เกินโควตา 1 MB แน่ ๆ (เขียน 2 MB)
        $home = $fixture['root'] . '/users/measured1';
        mkdir($home, 0750, true);
        file_put_contents($home . '/blob.bin', str_repeat('x', 2 * 1024 * 1024));

        $capability = new DiskQuotaCheck();
        $result = $capability->run([], $fixture['executor'], $fixture['context']);

        assertSame(1, $result['measured'], 'ต้องวัดได้ 1 บัญชี: ' . json_encode($result));
        assertSame(0, $result['failed'], 'ต้องไม่มีรายการล้มเหลว');

        $used = (int) $fixture['db']->value('SELECT disk_used_mb FROM users WHERE id = :id', ['id' => $account['id']]);
        assertTrue($used >= 2, "ต้องวัดได้อย่างน้อย 2 MB จริง ได้ {$used}");

        assertSame(100, $fixture['users']->diskQuotaThreshold($account['id']), 'เกินโควตาแล้วต้องขึ้นระดับ 100 ทันที');

        // รันซ้ำโดยใช้พื้นที่เท่าเดิม — ระดับต้องไม่เปลี่ยน (แปลว่าไม่แจ้งซ้ำ)
        $second = $capability->run([], $fixture['executor'], $fixture['context']);
        assertSame(100, $fixture['users']->diskQuotaThreshold($account['id']), 'ใช้พื้นที่เท่าเดิม ระดับต้องคงที่');
        assertSame(1, $second['measured'], 'รอบที่สองต้องวัดได้อีกครั้งตามปกติ');
    });
});

test('quota.disk_check ข้ามบัญชีที่ยังไม่เคยมีเว็บ (ไม่มี system_user) โดยไม่นับเป็นความล้มเหลว', static function (): void {
    $fixture = diskQuotaFixture();
    $usersDir = $fixture['root'] . '/users-empty';
    mkdir($usersDir, 0750, true);

    withDiskQuotaUsersDir($usersDir, function () use ($fixture): void {
        seedHostingAccount($fixture, 'nohome', diskQuotaMb: 1000, diskUsedMb: 0, systemUser: null);

        $capability = new DiskQuotaCheck();
        $result = $capability->run([], $fixture['executor'], $fixture['context']);

        assertSame(0, $result['checked'], 'บัญชีที่ยังไม่มีบ้านต้องถูกข้ามตั้งแต่ต้น ไม่ใช่นับแล้วล้ม');
        assertSame(0, $result['failed'], 'ต้องไม่มีรายการล้มเหลว');
    });
});

test('quota.disk_check นับเป็นล้มเหลวเมื่อมี system_user แต่หาโฟลเดอร์บ้านไม่เจอจริง ไม่ทำให้ทั้งรอบล้ม', static function (): void {
    $fixture = diskQuotaFixture();
    $usersDir = $fixture['root'] . '/users-missing';
    mkdir($usersDir, 0750, true);

    withDiskQuotaUsersDir($usersDir, function () use ($fixture): void {
        // ตั้งใจไม่สร้างโฟลเดอร์บ้านจริง — จำลองบัญชีที่ระบบไฟล์ไม่ตรงกับฐานข้อมูล
        seedHostingAccount($fixture, 'missinghome', diskQuotaMb: 1000, diskUsedMb: 0, systemUser: 'missinghome');

        $capability = new DiskQuotaCheck();
        $result = $capability->run([], $fixture['executor'], $fixture['context']);

        assertSame(1, $result['checked'], 'มี system_user จึงต้องถูกนับว่าเข้าเกณฑ์ที่ต้องตรวจ');
        assertSame(0, $result['measured'], 'วัดไม่ได้เพราะไม่มีโฟลเดอร์จริง');
        assertSame(1, $result['failed'], 'ต้องนับเป็นล้มเหลวหนึ่งบัญชี ไม่ใช่โยน exception ออกไปทั้งรอบ');
    });
});

// --- 5. ทะเบียน capability ต้องถูกต้อง ---------------------------------------

test('quota.disk_check ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบและใช้สิทธิ์ที่ถูกต้อง', static function (): void {
    $registry = new Phpcp\Agent\CapabilityRegistry();
    $capability = $registry->resolve('quota.disk_check');

    assertTrue($capability->isMutating(), 'quota.disk_check เขียน disk_used_mb และตาราง disk_quota_state จริง ต้องเข้า audit');
    assertSame('customer.view', $capability->permission(), 'ต้องใช้สิทธิ์เดียวกับ expiry.check เพราะเป็นงานระดับบัญชีโฮสติ้งเหมือนกัน');
});

// --- 6. UserRepository::updateDiskQuota ปฏิเสธค่าที่ผิดกฎ --------------------

test('updateDiskQuota ปฏิเสธค่าต่ำกว่า -1', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'diskinvalid', diskQuotaMb: 100);

    assertRejects(
        InvalidArgumentException::class,
        static fn () => $fixture['users']->updateDiskQuota($account['id'], -5),
        'ค่าต่ำกว่า -1 ต้องถูกปฏิเสธ',
    );
});

// --- 7. CustomerQuotaUpdate รับ disk_quota_mb ได้ -----------------------------

test('customer.quota_update แก้ disk_quota_mb ได้พร้อมกับโควตาชนิดอื่น', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'diskcapability', diskQuotaMb: 1000, diskUsedMb: 0);

    $capability = new Phpcp\Agent\Capability\CustomerQuotaUpdate();
    $args = $capability->validate(['user_id' => $account['id'], 'disk_quota_mb' => 5000]);
    $result = $capability->run($args, $fixture['executor'], $fixture['context']);

    assertSame(5000, $result['disk_quota_mb'], 'ค่าตอบกลับต้องเป็นค่าใหม่');
    assertSame(5000, (int) $fixture['db']->value('SELECT disk_quota_mb FROM users WHERE id = :id', ['id' => $account['id']]), 'ต้องบันทึกลงฐานข้อมูลจริง');
    assertSame(['from' => 1000, 'to' => 5000], $result['changes']['disk_quota_mb'], 'audit log ต้องเห็นทั้งค่าเก่าและค่าใหม่');
});

test('customer.quota_update ปฏิเสธ disk_quota_mb ที่ต่ำกว่า -1', static function (): void {
    $capability = new Phpcp\Agent\Capability\CustomerQuotaUpdate();

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $capability->validate(['user_id' => 1, 'disk_quota_mb' => -2]),
        'ค่าต่ำกว่า -1 ต้องถูกปฏิเสธตั้งแต่ validate()',
    );
});

// --- 8. DiskQuota::assertFits — ด่านของ "การเขียนหนึ่งครั้ง" -------------------
//
// เดิมด่านของไฟล์สำรองผ่านทันทีที่ "เหลือมากกว่า 0" โดยไม่เคยเทียบกับขนาดที่กำลังจะเขียน
// บัญชีที่เหลือโควตา 1 MB จึงสร้างไฟล์สำรองขนาดเท่าไรก็ได้ · และตัวจัดการไฟล์ไม่มีด่านนี้เลย

test('เขียนเกินที่เหลือต้องถูกปฏิเสธ — ไม่ใช่ผ่านเพราะ "ยังเหลืออยู่นิดหนึ่ง"', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'almostfull', diskQuotaMb: 1000, diskUsedMb: 999);

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $account['id'], 40 * 1024 * 1024 * 1024),
        'เหลือ 1 MB แต่จะเขียน 40 GB — นี่คือบั๊กเดิมที่ด่าน free > 0 ปล่อยผ่าน',
    );
});

test('เขียนเท่าที่เหลือพอดีต้องผ่าน — ด่านต้องไม่เข้มจนใช้งานไม่ได้', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'roomleft', diskQuotaMb: 1000, diskUsedMb: 500);

    Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $account['id'], 500 * 1024 * 1024);

    assertTrue(true, 'เหลือ 500 MB เขียน 500 MB ต้องผ่าน');
});

test('ขนาดที่รู้ล่วงหน้าไม่ได้ ต้องตกไปใช้ด่าน "เต็มหรือยัง" ไม่ใช่ผ่านฉลุย', static function (): void {
    $fixture = diskQuotaFixture();

    $full = seedHostingAccount($fixture, 'unknownfull', diskQuotaMb: 1000, diskUsedMb: 1000);
    $free = seedHostingAccount($fixture, 'unknownfree', diskQuotaMb: 1000, diskUsedMb: 10);

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $full['id']),
        'เต็มแล้วต้องปฏิเสธแม้ยังไม่รู้ขนาดที่จะเขียน',
    );

    Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $free['id']);

    assertTrue(true, 'ยังไม่เต็มและไม่รู้ขนาด ต้องปล่อยผ่าน');
});

test('โควตาไม่จำกัดต้องผ่านเสมอ ไม่ว่าขนาดเท่าไร', static function (): void {
    $fixture = diskQuotaFixture();
    $account = seedHostingAccount($fixture, 'unlimited', diskQuotaMb: -1, diskUsedMb: 999_999);

    Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $account['id'], 100 * 1024 * 1024 * 1024);

    assertTrue(true, '-1 = ไม่จำกัด');
});

test('บัญชีผู้ดูแลไม่ถูกจำกัดโควตา — กฎเดียวกับ QuotaChecker', static function (): void {
    $fixture = diskQuotaFixture();

    $adminId = $fixture['db']->insert('users', [
        'username' => 'ผู้ดูแล-diskquota',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'role' => Permissions::SYSADMIN,
        'totp_enabled' => 0,
        'must_change_password' => 0,
        'status' => 'active',
        'failed_attempts' => 0,
        'email' => '',
        'service_status' => 'active',
        'system_user' => null,
        'uid' => 0,
        'gid' => 0,
        'quota_domains' => 10,
        'quota_subdomains' => -1,
        'quota_aliases' => -1,
        'quota_emails' => -1,
        'quota_databases' => -1,
        'quota_ftp_users' => -1,
        'disk_quota_mb' => 10,
        'disk_used_mb' => 10_000,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    Phpcp\Domain\DiskQuota::assertFits($fixture['db'], $adminId, 50 * 1024 * 1024 * 1024);

    assertTrue(true, 'บัญชีผู้ดูแลข้ามด่านโควตา');
});
