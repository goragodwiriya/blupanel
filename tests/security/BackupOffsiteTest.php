<?php

declare(strict_types=1);

/**
 * ไฟล์สำรองนอกเครื่องและนโยบายเก็บย้อนหลัง — PLAN-V2 เฟส E1
 *
 * เฟสนี้แก้ความเสี่ยงที่แผนระบุว่าสูงที่สุดในระบบ: ไฟล์สำรองอยู่บนดิสก์ก้อนเดียวกับ
 * ข้อมูลจริง ดิสก์พังครั้งเดียวจึงเสียทั้งสองอย่างพร้อมกัน
 *
 * สิ่งที่ชุดนี้เฝ้า เรียงตามความเสียหายถ้าพลาด:
 *   1. **ความลับของปลายทางต้องไม่หลุดออกมากับแถวข้อมูล** — กุญแจ ssh ที่รั่วคือสิทธิ์
 *      เข้าเครื่องสำรองซึ่งเก็บข้อมูลของทุกเว็บบนเครื่องนี้
 *   2. **ตัวเก็บกวาดต้องไม่ลบไฟล์ที่ยังไม่มีสำเนานอกเครื่อง** — นั่นคือการทำข้อมูลหาย
 *      ด้วยมือตัวเองในงานที่รันตอนตี 5 โดยไม่มีใครดู
 *   3. **เรียกซ้ำแล้วต้องได้ผลเท่าเดิม** — agent เป็นโปรเซสที่รันค้างเป็นเดือน สถานะที่
 *      สะสมข้ามการเรียกทำให้รอบหลัง ๆ ลบเกินโดยไม่มีอะไรฟ้อง
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\BackupPrune;
use Phpcp\Agent\Capability\BackupPush;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\Backup\LocalDestination;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;

group('BackupOffsite — ไฟล์สำรองต้องออกไปอยู่นอกดิสก์ก้อนเดิมได้จริง');

/** สภาพแวดล้อมทดสอบที่แยกจากข้อมูลจริง — ฐานข้อมูลชั่วคราวของตัวเอง */
function offsiteFixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $root = sys_get_temp_dir() . '/phpcp-offsite-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root . '/remote', 0750, true);

    // ไฟล์สำรอง "ต้นทาง" ต้องอยู่ในไดเรกทอรีสำรองจริงของการติดตั้งนี้ เพราะ
    // `BackupManager` ปฏิเสธการลบไฟล์นอกไดเรกทอรีนั้นโดยเจตนา (กันการลบไฟล์ใดก็ได้
    // บนเครื่องผ่านหน้าเว็บ) · เทสต์จึงต้องเดินบนเส้นทางเดียวกับของจริง ไม่ใช่หลบไป
    // ใช้ที่ชั่วคราวแล้วทดสอบเส้นทางที่ผู้ใช้ไม่เคยเดิน
    $backups = Config::load(PHPCP_ROOT)->paths->backups();
    mkdir($backups, 0750, true);

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    // `backups.site_id` → `sites.id` → `sites.owner_user_id` → `users.id`
    // ต้องมีทั้งสายให้ครบ ไม่งั้นการ insert จะล้มด้วย FK แล้วเทสต์พังตามกันทั้งชุด
    $db->insert('users', [
        'id' => 1,
        'username' => 'tester',
        'display_name' => 'ผู้ทดสอบ',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'role' => Permissions::SUPERADMIN,
        'totp_enabled' => 0,
        'must_change_password' => 0,
        'status' => 'active',
        'failed_attempts' => 0,
        'email' => '',
        'service_status' => 'active',
        'uid' => 0,
        'gid' => 0,
        'quota_domains' => -1,
        'quota_subdomains' => -1,
        'quota_aliases' => -1,
        'quota_emails' => -1,
        'quota_databases' => -1,
        'quota_ftp_users' => -1,
        'disk_used_mb' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    foreach ([1, 42, 43, 44, 45, 46] as $siteId) {
        $db->insert('sites', [
            'id' => $siteId,
            'name' => 'เว็บทดสอบ ' . $siteId,
            'primary_domain' => 'test' . $siteId . '.example.com',
            'docroot' => '/srv/phpcp/sites/test' . $siteId,
            'php_version' => '8.4',
            'ssl_mode' => 'off',
            'status' => 'active',
            'disk_used_mb' => 0,
            'owner_user_id' => 1,
            'docroot_override' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    $context = new Context(
        new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $db,
    );

    register_shutdown_function(static function () use ($root, $backups): void {
        exec('rm -rf ' . escapeshellarg($root));

        // เก็บกวาดเฉพาะไฟล์ที่เทสต์นี้สร้าง — ไดเรกทอรีสำรองจริงอาจมีของอื่นอยู่
        foreach (glob($backups . '/phpcp-test-*') ?: [] as $leftover) {
            @unlink($leftover);
        }
    });

    return $fixture = [
        'root' => $root,
        'backups' => $backups,
        'db' => $db,
        'context' => $context,
        'executor' => new RealExecutor(),
        // `generateKey()` คืน base64 ส่วน constructor รับไบต์ดิบ 32 ตัว — เหมือนที่
        // `Config::secretKey()` ถอดให้ก่อนส่งเข้าไปในโค้ดจริง
        'repo' => new BackupDestinationRepository($db, new Secret((string) base64_decode(Secret::generateKey(), true))),
    ];
}

/** ใส่แถวไฟล์สำรองพร้อมไฟล์จริงบนดิสก์ */
function seedBackup(
    array $fixture,
    string $name,
    int $createdAt,
    int $siteId = 1,
    string $offsite = 'none',
    ?int $destinationId = null,
): int {
    $path = $fixture['backups'] . '/phpcp-test-' . bin2hex(random_bytes(6)) . '.tar.gz';
    file_put_contents($path, str_repeat($name, 20));

    return $fixture['db']->insert('backups', [
        'name' => $name,
        'type' => 'site',
        'site_id' => $siteId,
        'path' => $path,
        'size_bytes' => filesize($path),
        'checksum' => hash_file('sha256', $path),
        'status' => 'ok',
        'created_at' => $createdAt,
        'offsite_status' => $offsite,
        'destination_id' => $destinationId,
    ]);
}

test('ปลายทางคืนแถวออกมาโดยไม่มีความลับติดไปด้วย', static function (): void {
    // กุญแจ ssh ที่หลุดออก API คือสิทธิ์เข้าเครื่องที่เก็บข้อมูลของทุกเว็บบนเครื่องนี้
    $fixture = offsiteFixture();
    $id = $fixture['repo']->create('มีกุญแจ', 'sftp', ['host' => 'backup.example.com', 'user' => 'phpcp', 'path' => '/srv/backups'], 'PRIVATE-KEY-HERE', 30, 7);

    $row = $fixture['repo']->find($id);

    assertTrue(!array_key_exists('secret_enc', $row), 'แถวต้องไม่มีคอลัมน์ความลับ');
    assertTrue(!str_contains(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '', 'PRIVATE-KEY-HERE'), 'ความลับต้องไม่โผล่ที่ใดในแถว');
    assertSame(true, $row['has_secret'], 'ต้องบอกได้ว่ามีความลับเก็บไว้แล้ว');

    // ถอดกลับได้เฉพาะทางเมธอดที่ตั้งใจให้ใช้
    assertSame('PRIVATE-KEY-HERE', $fixture['repo']->secretFor($id), 'ตัวสร้าง driver ต้องยังอ่านความลับได้');
});

test('แก้ไขปลายทางโดยไม่ส่งความลับมา ต้องไม่ล้างของเดิมทิ้ง', static function (): void {
    // หน้าจอแก้ไขส่งฟอร์มทั้งชุดกลับมาโดยที่ช่องกุญแจว่างเสมอ (เพราะเราไม่เคยส่งค่าเดิมออกไป)
    // ถ้าตีความว่า "ล้าง" ผู้ดูแลจะทำปลายทางพังทุกครั้งที่แก้แค่ชื่อ
    $fixture = offsiteFixture();
    $id = $fixture['repo']->create('แก้ชื่อ', 'sftp', ['host' => 'h', 'user' => 'u', 'path' => '/srv/b'], 'KEEP-ME', 30, 7);

    $fixture['repo']->update($id, ['name' => 'ชื่อใหม่', 'secret' => '']);

    assertSame('ชื่อใหม่', $fixture['repo']->find($id)['name'], 'ชื่อต้องเปลี่ยน');
    assertSame('KEEP-ME', $fixture['repo']->secretFor($id), 'กุญแจเดิมต้องยังอยู่');
});

test('ส่งไฟล์ออกไปแล้วต้องยืนยันได้ว่าถึงจริงและเนื้อหาตรง', static function (): void {
    $fixture = offsiteFixture();
    $destination = new LocalDestination($fixture['root'] . '/remote', $fixture['backups']);

    $source = $fixture['backups'] . '/phpcp-test-probe.tar.gz';
    file_put_contents($source, str_repeat('ข้อมูลสำรอง', 500));

    $remote = $destination->push($fixture['executor'], $source, 'probe.tar.gz');

    assertTrue(is_file($remote), 'ไฟล์ต้องไปอยู่ที่ปลายทางจริง');
    assertSame(hash_file('sha256', $source), hash_file('sha256', $remote), 'เนื้อหาต้องตรงกันทุกไบต์');

    // ดึงกลับได้ — ปลายทางที่ส่งออกได้อย่างเดียวยังไม่นับว่าแก้ปัญหา
    $back = $fixture['backups'] . '/phpcp-test-probe-back.tar.gz';
    $destination->pull($fixture['executor'], $remote, $back);
    assertSame(hash_file('sha256', $source), hash_file('sha256', $back), 'ไฟล์ที่ดึงกลับต้องตรงกับต้นฉบับ');

    // ลบซ้ำต้องไม่ล้ม — ตัวเก็บกวาดเรียกซ้ำได้
    $destination->delete($fixture['executor'], $remote);
    $destination->delete($fixture['executor'], $remote);
    assertTrue(!is_file($remote), 'ไฟล์ต้องถูกลบที่ปลายทาง');
});

test('ปลายทางปฏิเสธเส้นทางที่ออกนอกขอบเขตของตัวเอง', static function (): void {
    // ปลายทางที่ลบไฟล์นอกขอบเขตได้ = ช่องลบไฟล์อะไรก็ได้บนเครื่องผ่านหน้าเว็บ
    $fixture = offsiteFixture();
    $destination = new LocalDestination($fixture['root'] . '/remote', $fixture['backups']);

    foreach (['/etc/passwd', $fixture['root'] . '/remote/../x.tar.gz', $fixture['backups'] . '/x.tar.gz'] as $outside) {
        assertRejects(
            Phpcp\Agent\ValidationError::class,
            static fn () => $destination->delete($fixture['executor'], $outside),
            "ต้องปฏิเสธการลบที่ {$outside}",
        );
    }
});

test('ตัวเก็บกวาดต้องไม่ลบไฟล์ที่ยังไม่มีสำเนานอกเครื่อง', static function (): void {
    // ข้อนี้คือหัวใจของทั้งเฟส — ลบสำเนาเดียวที่มีอยู่ทิ้งตอนตี 5 คือการทำข้อมูลหายเอง
    $fixture = offsiteFixture();
    $fixture['db']->run('DELETE FROM backups');

    // นโยบาย 1 วัน / ไม่เก็บชุดล่าสุดเลย — ตัดกฎ "เก็บ N ชุดล่าสุด" ออกจากสมการ
    // เพื่อให้เทสต์นี้วัดเรื่องสำเนานอกเครื่องอย่างเดียว
    $destinationId = $fixture['repo']->create('ปลายทางที่เปิดอยู่', 'local', ['path' => $fixture['root'] . '/remote'], '', 1, 0);

    $old = time() - 365 * 86400;
    seedBackup($fixture, 'ยังไม่ได้ส่ง', $old, 1, 'none', $destinationId);
    seedBackup($fixture, 'ส่งไม่สำเร็จ', $old, 1, 'failed', $destinationId);
    seedBackup($fixture, 'ส่งแล้ว', $old, 1, 'ok', $destinationId);

    $prune = new BackupPrune();
    $result = $prune->run($prune->validate(['dry_run' => true]), $fixture['executor'], $fixture['context']);

    $names = array_column($result['removed'], 'name');

    assertSame(['ส่งแล้ว'], $names, 'ต้องลบเฉพาะไฟล์ที่มีสำเนานอกเครื่องแล้วเท่านั้น');
});

test('ตัวเก็บกวาดเก็บ N ชุดล่าสุดไว้เสมอ แม้ทุกชุดจะเก่าเกินกำหนด', static function (): void {
    // เครื่องที่ไม่ได้สำรองมานานต้องไม่ตื่นมาแล้วพบว่าไฟล์สำรองถูกลบเกลี้ยง
    $fixture = offsiteFixture();
    $fixture['db']->run('DELETE FROM backups');

    $old = time() - 365 * 86400;
    for ($i = 0; $i < 10; $i++) {
        seedBackup($fixture, 'ชุด ' . $i, $old + $i, 42, 'ok');
    }

    $prune = new BackupPrune();
    $result = $prune->run($prune->validate(['days' => 30, 'keep' => 3, 'dry_run' => true]), $fixture['executor'], $fixture['context']);

    assertSame(7, $result['removed_count'], 'ต้องเหลือ 3 ชุดล่าสุดไว้');

    $kept = array_diff(array_map(static fn (int $i): string => 'ชุด ' . $i, range(0, 9)), array_column($result['removed'], 'name'));
    assertSame(['ชุด 7', 'ชุด 8', 'ชุด 9'], array_values($kept), 'ชุดที่เก็บไว้ต้องเป็นชุดที่ใหม่ที่สุด');
});

test('เรียกตัวเก็บกวาดซ้ำในโปรเซสเดียวกัน ต้องได้ผลเท่าเดิม', static function (): void {
    // agent รันค้างเป็นเดือน · เคยใช้ static ในเมธอดตรวจ ทำให้ตัวนับสะสมข้ามการเรียก
    // แล้วรอบที่สองเห็นว่าทุกกลุ่มครบโควตาตั้งแต่แถวแรก จึงลบไฟล์ที่ต้องเก็บไว้
    $fixture = offsiteFixture();
    $fixture['db']->run('DELETE FROM backups');

    $old = time() - 365 * 86400;
    for ($i = 0; $i < 6; $i++) {
        seedBackup($fixture, 'ซ้ำ ' . $i, $old + $i, 43, 'ok');
    }

    $prune = new BackupPrune();
    $args = $prune->validate(['days' => 30, 'keep' => 4, 'dry_run' => true]);

    $first = $prune->run($args, $fixture['executor'], $fixture['context']);
    $second = $prune->run($args, $fixture['executor'], $fixture['context']);

    assertSame($first['removed_count'], $second['removed_count'], 'สองรอบต้องเลือกลบเท่ากัน');
    assertSame(2, $first['removed_count'], 'เก็บ 4 จาก 6 จึงลบ 2');
});

test('ตัวเก็บกวาดลบทั้งแถวและไฟล์จริงบนดิสก์', static function (): void {
    $fixture = offsiteFixture();
    $fixture['db']->run('DELETE FROM backups');

    $destinationId = $fixture['repo']->create('ปลายทางลบจริง', 'local', ['path' => $fixture['root'] . '/remote'], '', 1, 0);
    $id = seedBackup($fixture, 'ลบจริง', time() - 365 * 86400, 44, 'ok', $destinationId);
    $path = (string) $fixture['db']->value('SELECT path FROM backups WHERE id = :id', ['id' => $id]);

    assertTrue(is_file($path), 'ต้องมีไฟล์อยู่ก่อนลบ');

    // ไม่ระบุ days/keep — ให้ใช้ค่าของปลายทางที่ผูกไว้ (1 วัน / ไม่เก็บชุดล่าสุด)
    $prune = new BackupPrune();
    $prune->run($prune->validate([]), $fixture['executor'], $fixture['context']);

    assertTrue(!is_file($path), 'ไฟล์บนดิสก์ต้องถูกลบด้วย ไม่ใช่ลบแต่แถว');
    assertSame(null, $fixture['db']->first('SELECT id FROM backups WHERE id = :id', ['id' => $id]), 'แถวต้องหายไป');
});

test('ส่งไฟล์ที่ checksum ไม่ตรง ต้องถูกปฏิเสธก่อนออกจากเครื่อง', static function (): void {
    // ไฟล์เสียที่ถูกส่งออกไปเก็บ = ไฟล์สำรองปลอมที่ไม่มีใครรู้ว่าปลอมจนถึงวันที่ต้องใช้
    $fixture = offsiteFixture();
    $destinationId = $fixture['repo']->create('ปลายทางตรวจ', 'local', ['path' => $fixture['root'] . '/remote'], '', 30, 7);

    $id = seedBackup($fixture, 'ไฟล์เสีย', time(), 45);
    $fixture['db']->update('backups', ['checksum' => str_repeat('0', 64)], ['id' => $id]);

    $push = new BackupPush();

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $push->run(
            $push->validate(['backup_id' => $id, 'destination_id' => $destinationId]),
            $fixture['executor'],
            $fixture['context'],
        ),
        'ไฟล์ที่ checksum ไม่ตรงต้องไม่ถูกส่งออก',
    );
});

test('ผู้ดูแลเว็บไซต์ส่งไฟล์สำรองออกนอกเครื่องไม่ได้', static function (): void {
    // ปลายทางเป็นทรัพยากรของทั้งเครื่อง — เลือกปลายทางได้เท่ากับเลือกได้ว่าจะส่ง
    // ข้อมูลของเว็บไซต์ออกไปที่ไหน
    $fixture = offsiteFixture();
    $destinationId = $fixture['repo']->create('ปลายทางสิทธิ์', 'local', ['path' => $fixture['root'] . '/remote'], '', 30, 7);
    $id = seedBackup($fixture, 'ของลูกค้า', time(), 46);

    $webadmin = new Context(
        new Actor(9, 'somchai', Permissions::WEBADMIN, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $fixture['db'],
    );

    $push = new BackupPush();

    assertRejects(
        Phpcp\Agent\PermissionDenied::class,
        static fn () => $push->run(
            $push->validate(['backup_id' => $id, 'destination_id' => $destinationId]),
            $fixture['executor'],
            $webadmin,
        ),
        'ผู้ดูแลเว็บไซต์ต้องส่งไฟล์สำรองออกนอกเครื่องไม่ได้',
    );
});

test('ทุก capability ของเรื่องนี้ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ', static function (): void {
    // ไม่ถูกทำเครื่องหมาย = ไม่เข้า audit และทำงานจริงในโหมด dryrun ทั้งที่ไม่ควร
    $registry = new Phpcp\Agent\CapabilityRegistry();

    foreach (['backup.push', 'backup.prune', 'backup.destination_test'] as $name) {
        $capability = $registry->resolve($name);

        assertTrue($capability->isMutating(), "{$name} ต้องถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ");
        // `backup.offsite` ไม่ใช่ `backup.manage` โดยเจตนา — `backup.manage` เป็นสิทธิ์
        // หมวด Hosting ที่ผู้ดูแลเว็บไซต์มี ส่วนปลายทางนอกเครื่องเป็นของทั้งเครื่อง
        assertSame('backup.offsite', $capability->permission(), "{$name} ต้องใช้สิทธิ์ backup.offsite");
    }
});
