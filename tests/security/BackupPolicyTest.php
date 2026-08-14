<?php

declare(strict_types=1);

/**
 * กฎที่ตัดสินว่า "สำรองอะไร ให้ใคร และเมื่อไรควรปฏิเสธ" — PLAN-BACKUP-V2 §4
 *
 * ทั้งชุดนี้เฝ้าการตัดสินใจที่ผิดแล้ว **เสียหายเงียบ ๆ**:
 *
 *   - โควตาเต็มแล้วยังเขียนต่อ = เว็บที่ยังให้บริการอยู่เขียนไฟล์ไม่ได้กลางคัน
 *     เพราะพื้นที่ถูกไฟล์สำรองของตัวเองกินไปหมด
 *   - กู้คืนไฟล์ที่ไม่ใช่ของเว็บนั้น = เขียนทับเว็บที่ให้บริการอยู่ด้วยไฟล์ของเว็บอื่น
 *   - รอบอัตโนมัติหยิบบัญชีผิด = ลูกค้าที่ไม่ได้ซื้อบริการสำรองถูกหักโควตา หรือ
 *     ลูกค้าที่ซื้อแล้วไม่ได้รับการสำรองเลย ซึ่งรู้ตัวตอนต้องใช้ไฟล์เท่านั้น
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\BackupCreate;
use Phpcp\Agent\Capability\BackupRestore;
use Phpcp\Agent\Capability\BackupRun;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SiteLayout;
use Phpcp\Driver\BackupManager;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Paths;
use Phpcp\Security\Permissions;

group('นโยบายการสำรอง — โควตา เจ้าของไฟล์ และรอบอัตโนมัติ');

/**
 * บัญชีโฮสติ้งจริงหนึ่งบัญชีพร้อมเว็บ บนฐานข้อมูลชั่วคราว
 *
 * ตั้ง `Paths::useUsersDir()` ทุกครั้งที่เรียก — ค่านั้นเป็น static ของทั้งโปรเซส
 * และเทสต์ไฟล์อื่นก็เปลี่ยนมันเหมือนกัน
 *
 * @return array{db:\Phpcp\Kernel\Db,context:Context,root:string,home:string,site_id:int,user_id:int}
 */
function policyFixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        Paths::useUsersDir($fixture['root'] . '/home');

        return $fixture;
    }

    $root = sys_get_temp_dir() . '/phpcp-policy-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root . '/home/cust/public_html', 0755, true);
    file_put_contents($root . '/home/cust/public_html/index.php', '<?php echo "ของจริง";');

    // เว็บที่สองของบัญชีเดียวกัน (โดเมนที่ไม่ใช่โดเมนหลัก จึงได้โฟลเดอร์ชื่อตัวเอง)
    mkdir($root . '/home/cust/other.example.com', 0755, true);
    file_put_contents($root . '/home/cust/other.example.com/index.php', '<?php echo "ของเว็บอื่น";');

    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $userId = $db->insert('users', [
        'username' => 'cust',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'role' => Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1,
        'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'system_user' => 'cust', 'site_layout' => SiteLayout::Cpanel->value, 'main_domain' => 'quota.example.com',
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $siteId = $db->insert('sites', [
        'primary_domain' => 'quota.example.com', 'docroot' => $root . '/home/cust/public_html',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);

    $context = new Context(
        // actor ระบบ (userId 0) — แบบเดียวกับที่ scheduler เรียก
        new Actor(0, 'system', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    /*
     * **ต้องตั้งหลัง `Config::load()` เสมอ** — `Config::load()` อ่าน `sites.users_dir`
     * ของการติดตั้งนี้แล้วเรียก `Paths::useUsersDir()` ทับ · ตั้งก่อนแปลว่าเทสต์จะไป
     * เขียนไฟล์ลง `/home` ของเครื่องจริง (แล้วล้มเพราะไม่มีสิทธิ์ ซึ่งยังโชคดี)
     */
    Paths::useUsersDir($root . '/home');

    return $fixture = [
        'db' => $db,
        'context' => $context,
        'root' => $root,
        'home' => $root . '/home/cust',
        'site_id' => $siteId,
        'user_id' => $userId,
    ];
}

/** ตั้งโควตาดิสก์ของบัญชีทดสอบ */
function policySetQuota(array $fixture, int $limitMb, int $usedMb): void
{
    $fixture['db']->update(
        'users',
        ['disk_quota_mb' => $limitMb, 'disk_used_mb' => $usedMb],
        ['id' => $fixture['user_id']],
    );
}

test('โควตาเต็มต้องปฏิเสธก่อนเขียนไฟล์ พร้อมบอกว่าใช้ไปเท่าไรจากเท่าไร', static function (): void {
    /*
     * **ต้องปฏิเสธก่อนเริ่มเขียน ไม่ใช่เขียนจนดิสก์เต็ม** — ไฟล์สำรองของเว็บมีขนาดเท่า
     * เว็บทั้งเว็บ · การเขียนต่อทั้งที่เต็มแปลว่าเว็บที่ยังให้บริการอยู่เขียน session
     * แคช หรือไฟล์อัปโหลดไม่ได้กลางคัน ซึ่งเสียหายกว่าการไม่มีสำเนาของคืนนี้มาก
     *
     * และข้อความต้องบอก "เหลือเท่าไร" เพื่อให้ตัดสินใจได้ว่าจะลบไฟล์เก่ากี่ไฟล์หรือ
     * ขอขยายโควตาเท่าไร ไม่ใช่แค่บอกว่า "เต็ม" แล้วปล่อยให้เดา
     */
    $fixture = policyFixture();
    policySetQuota($fixture, 100, 100);

    $create = new BackupCreate();
    $thrown = null;

    try {
        $create->run(
            $create->validate(['type' => 'site', 'site_id' => $fixture['site_id']]),
            new RealExecutor(),
            $fixture['context'],
        );
    } catch (ValidationError $e) {
        $thrown = $e;
    }

    assertTrue($thrown !== null, 'โควตาเต็มต้องถูกปฏิเสธ');
    assertTrue(str_contains($thrown->getMessage(), '100'), 'ข้อความต้องบอกตัวเลขที่ใช้ไป/เพดาน');

    assertSame(
        [],
        glob($fixture['home'] . '/backup/*') ?: [],
        'ต้องไม่มีไฟล์ไหนถูกเขียนลงไปเลยเมื่อถูกปฏิเสธ',
    );

    // ยังไม่เต็มต้องผ่านจริง ไม่ใช่ปฏิเสธทุกกรณีแล้วเทสต์ผ่าน
    policySetQuota($fixture, 100, 99);

    $result = $create->run(
        $create->validate(['type' => 'site', 'site_id' => $fixture['site_id']]),
        new RealExecutor(),
        $fixture['context'],
    );

    assertSame(1, $result['count'], 'โควตายังเหลือต้องสำรองได้');
    assertTrue(is_file($result['created'][0]['path']), 'ต้องมีไฟล์อยู่จริงบนดิสก์');
});

test('กู้คืนต้องถามใบแจ้งข้อมูลว่าไฟล์นี้เป็นของเว็บไหน ไม่ใช่เชื่อชื่อไฟล์', static function (): void {
    /*
     * **โฟลเดอร์เป็นของลูกค้า เขาเปลี่ยนชื่อไฟล์เองได้และคัดลอกไฟล์จากที่อื่นเข้ามาวางได้**
     * · ชื่อที่ขึ้นต้นด้วย `quota.example.com-files-` จึงไม่ใช่คำสัญญาว่าข้างในเป็นของ
     * เว็บนั้น · กู้ผิดตัวคือการเขียนทับเว็บที่ให้บริการอยู่ด้วยไฟล์ของเว็บอื่น
     */
    $fixture = policyFixture();
    policySetQuota($fixture, -1, 0);

    $executor = new RealExecutor();

    // ไฟล์ของเว็บอื่นจริง ๆ (ใบแจ้งข้อมูลข้างในบอกคนละโดเมน) แต่ตั้งชื่อให้ดูเหมือน
    // เป็นของเว็บนี้ — เหมือนกับที่ลูกค้าคัดลอกไฟล์จากเครื่องเก่ามาแล้วเปลี่ยนชื่อ
    $other = new Phpcp\Domain\Site(
        99,
        'other.example.com',
        Phpcp\Domain\UserAccount::fromRow(
            $fixture['db']->first('SELECT * FROM users WHERE id = :id', ['id' => $fixture['user_id']]),
        ),
        '8.4',
    );

    $foreign = (new BackupManager())->backupSite($executor, $other);
    $disguised = $fixture['home'] . '/backup/quota.example.com-files-20260814-000000-deadbe.tar.gz';
    rename($foreign['path'], $disguised);

    $restore = new BackupRestore();

    assertRejects(
        ValidationError::class,
        static fn () => $restore->run(
            $restore->validate([
                'site_id' => $fixture['site_id'],
                'file' => basename($disguised),
                'confirm' => 'quota.example.com',
            ]),
            $executor,
            $fixture['context'],
        ),
        'ไฟล์ที่ข้างในเป็นของเว็บอื่นต้องกู้คืนไม่ได้ แม้ชื่อจะตรง',
    );

    // ไฟล์เว็บของเดิมต้องไม่ถูกแตะเลย
    assertSame(
        '<?php echo "ของจริง";',
        (string) file_get_contents($fixture['home'] . '/public_html/index.php'),
        'ต้องไม่มีอะไรถูกเขียนทับ',
    );
});

test('กู้คืนไฟล์ฐานข้อมูลต้องถูกปฏิเสธ ไม่ใช่แตกทับ docroot', static function (): void {
    // `.sql.gz` ไม่ใช่ archive ของไฟล์เว็บ · การปล่อยให้เดินเส้นทางเดียวกันคือการเอา
    // ไฟล์ SQL ไปแตกทับรากเว็บ แล้วเว็บหายทั้งเว็บโดยที่ผู้ใช้กดปุ่มที่หน้าจอให้กด
    $fixture = policyFixture();
    $restore = new BackupRestore();

    assertRejects(
        ValidationError::class,
        static fn () => $restore->run(
            $restore->validate([
                'site_id' => $fixture['site_id'],
                'file' => 'quota.example.com-db-x-20260814-000000-aabbcc.sql.gz',
                'confirm' => 'quota.example.com',
            ]),
            new RealExecutor(),
            $fixture['context'],
        ),
        'ไฟล์ฐานข้อมูลต้องกู้คืนผ่านเส้นทางนี้ไม่ได้',
    );
});

test('ไฟล์สำรองฐานข้อมูลต้องเป็น gzip จริงที่ zcat อ่านได้', static function (): void {
    /*
     * ข้อ B9 · นามสกุล `.sql.gz` ที่ข้างในเป็นข้อความดิบคือกับดัก: ลูกค้าดาวน์โหลด
     * ไปแล้ว `gunzip` ล้ม หรือแย่กว่านั้นคือ import เข้าฐานข้อมูลไม่ได้ในวันที่ต้องใช้
     *
     * ตรวจจากไบต์จริงในไฟล์ ไม่ใช่จากนามสกุล — และต้องคลายกลับมาได้เหมือนเดิมทุกไบต์
     */
    $root = sys_get_temp_dir() . '/phpcp-gz-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $executor = new Phpcp\Agent\Executor\SandboxExecutor($root);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $target = '/var/lib/phpcp/backups/example_db-20260814.sql.gz';
    $bytes = (new MariaDbManager())->dump($executor, 'example_db', $target);

    $raw = (string) file_get_contents($executor->path($target));

    assertTrue($bytes > 0, 'ต้องรายงานขนาดไฟล์ที่เขียนจริง');
    assertSame(strlen($raw), $bytes, 'ขนาดที่รายงานต้องเป็นขนาดของไฟล์ที่บีบแล้ว');
    assertSame("\x1f\x8b", substr($raw, 0, 2), 'สองไบต์แรกต้องเป็นลายเซ็นของ gzip');

    $sql = @gzdecode($raw);

    assertTrue(is_string($sql), 'ต้องคลายกลับได้');
    assertTrue(str_contains((string) $sql, 'example_db'), 'เนื้อ SQL ต้องยังอยู่ครบหลังคลาย');

    // นามสกุลที่ไม่ใช่ .gz ต้องถูกปฏิเสธตั้งแต่ต้น — ไม่ใช่เขียนไฟล์บีบอัดโดยใช้ชื่อ .sql
    assertRejects(
        Phpcp\Agent\ExecutionFailed::class,
        static fn () => (new MariaDbManager())->dump($executor, 'example_db', '/var/lib/phpcp/backups/x.sql'),
        'ชื่อไฟล์ที่ไม่ลงท้ายด้วย .gz ต้องถูกปฏิเสธ',
    );
});

test('รอบอัตโนมัติต้องหยิบเฉพาะบัญชีที่ผู้ดูแลเปิดสวิตช์ไว้', static function (): void {
    /*
     * ค่าเริ่มต้นคือ **ไม่สำรอง** (ข้อ B7) — ไฟล์สำรองนับในโควตาของลูกค้า การเปิดให้
     * ทุกบัญชีอัตโนมัติเท่ากับหักพื้นที่ของเขาโดยที่ไม่มีใครสั่ง
     *
     * และเมื่อเปิดแล้ว **เว็บใหม่ของบัญชีนั้นต้องเข้ารอบเอง** โดยไม่ต้องมีใครกลับมา
     * ตั้งอะไรเพิ่ม ซึ่งเป็นเหตุผลทั้งหมดที่สวิตช์อยู่ที่บัญชีไม่ใช่ที่เว็บ
     */
    $fixture = policyFixture();
    policySetQuota($fixture, -1, 0);

    $method = new ReflectionMethod(BackupRun::class, 'targets');
    $run = new BackupRun();

    $fixture['db']->update('users', ['backup_files' => 0, 'backup_database' => 0], ['id' => $fixture['user_id']]);

    assertSame([], $method->invoke($run, $fixture['context']), 'ปิดอยู่ = ไม่มีอะไรเข้ารอบ');

    $fixture['db']->update('users', ['backup_files' => 1], ['id' => $fixture['user_id']]);

    $targets = $method->invoke($run, $fixture['context']);

    assertSame(1, count($targets), 'เปิดสวิตช์ไฟล์แล้วต้องมีเว็บเข้ารอบ');
    assertSame(['site'], $targets[0]['types'], 'ต้องสำรองเฉพาะไฟล์ ไม่ใช่ฐานข้อมูลด้วย');

    // เว็บที่สร้างทีหลังต้องเข้ารอบเองทันที ไม่ต้องมีใครมาเพิ่มตารางเวลาให้
    $fixture['db']->insert('sites', [
        'primary_domain' => 'later.example.com', 'docroot' => $fixture['home'] . '/later.example.com',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $fixture['user_id'], 'docroot_override' => '',
        'created_at' => time(), 'updated_at' => time(),
    ]);

    assertSame(
        2,
        count($method->invoke($run, $fixture['context'])),
        'เว็บที่เพิ่มทีหลังต้องเข้ารอบเองโดยไม่ต้องตั้งค่าเพิ่ม',
    );

    // เว็บที่ไม่มีฐานข้อมูลต้องไม่ถูกสั่งสำรองฐานข้อมูล — ไม่งั้นรายงานว่าล้มเหลว
    // ทุกคืนจนรายการที่ล้มจริงจมหายไปในนั้น
    $fixture['db']->update('users', ['backup_database' => 1], ['id' => $fixture['user_id']]);

    foreach ($method->invoke($run, $fixture['context']) as $target) {
        assertSame(['site'], $target['types'], 'เว็บที่ไม่มีฐานข้อมูลต้องไม่ถูกสั่งสำรองฐานข้อมูล');
    }
});
