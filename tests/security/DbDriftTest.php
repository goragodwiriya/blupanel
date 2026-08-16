<?php

declare(strict_types=1);

/**
 * เมื่อ panel กับ MariaDB ไม่ตรงกัน — แถวที่มี แต่ฐานข้อมูลไม่มี
 *
 * **เจอบนเครื่องจริง:** `Failed to back up database: mariadb-dump: Got error: 1049:
 * "Unknown database 'bluprint_excel'"` · สองฝั่งเพี้ยนจากกันได้ตามปกติ — ลบฐานจาก
 * mysql client ตรง ๆ หรือกู้ `panel.db` รุ่นก่อนหน้ากลับมา
 *
 * ที่ทำให้มันแย่กว่าที่ควรคือ **ทางตัน**: สำรองก็ไม่ได้ (ไม่มีอะไรให้ dump) และลบแถว
 * ทิ้งก็ไม่ได้ เพราะการลบสำรองก่อนเสมอ แล้วการสำรองนั้นล้มด้วย error เดียวกัน ·
 * รอบสำรองอัตโนมัติจึงล้มซ้ำทุกคืนโดยที่ผู้ดูแลทำอะไรกับมันไม่ได้เลยผ่านหน้าเว็บ
 *
 * สามอย่างที่ชุดนี้เฝ้า:
 *   1. ข้อความต้องบอกว่าฝั่งไหนผิดและต้องทำอะไร ไม่ใช่ยก stderr ของ mysqldump มาวาง
 *   2. แถวค้างต้องลบทิ้งได้ และต้องบอกตรง ๆ ว่าลบแค่แถว ไม่ได้ลบข้อมูลอะไร
 *   3. **ฐานที่มีอยู่จริงต้องยังสำรองก่อนลบเสมอ** — ทางลัดนี้เปิดได้เฉพาะกรณีที่
 *      เครื่องยืนยันว่าไม่มีฐานนั้น ไม่ใช่ทุกครั้งที่การสำรองล้ม
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\DbDrop;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('ฐานข้อมูลที่ panel รู้จักแต่เครื่องไม่มี');

/**
 * สภาพแวดล้อมทดสอบพร้อม MariaDB จำลองที่มีฐานข้อมูลของตัวเอง
 *
 * @return array{context:Context,executor:SandboxExecutor,db:\Phpcp\Kernel\Db}
 */
function driftFixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $root = sys_get_temp_dir() . '/phpcp-drift-' . getmypid() . '-' . bin2hex(random_bytes(4));
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = migratedDb();

    return $fixture = [
        'context' => new Context(
            new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
            Config::load(PHPCP_ROOT),
            $db,
        ),
        'executor' => new SandboxExecutor($root),
        'db' => $db,
    ];
}

test('สำรองฐานที่ไม่มีอยู่จริง ต้องบอกว่าต้องทำอะไร ไม่ใช่ยก error ของ mysqldump มาวาง', static function (): void {
    /*
     * ข้อความเดิมคือ stderr ดิบ ๆ: `Got error: 1049: Unknown database 'x'` ซึ่งจริง
     * แต่ไม่ได้บอกว่าฝั่งไหนผิด (panel จำผิด หรือฐานหายไปจริง) และไม่ได้บอกว่าต้อง
     * ทำอะไรต่อ · ในรอบอัตโนมัติที่ไม่มีคนดู มันคือข้อความเดียวกันซ้ำทุกคืน
     */
    $fixture = driftFixture();
    $manager = new MariaDbManager();

    $thrown = null;

    try {
        $manager->dump($fixture['executor'], 'ghost_db', '/tmp/ghost.sql.gz');
    } catch (ExecutionFailed $e) {
        $thrown = $e;
    }

    assertTrue($thrown !== null, 'ต้องถูกปฏิเสธ');
    assertTrue(str_contains($thrown->getMessage(), 'ghost_db'), 'ต้องบอกว่าเป็นฐานไหน');
    assertTrue(
        str_contains($thrown->getMessage(), 'panel still has a record'),
        'ต้องบอกว่า panel จำไว้แต่เครื่องไม่มี — ไม่ใช่แค่ "unknown database"',
    );
    assertTrue(
        !str_contains($thrown->getMessage(), 'Got error'),
        'ต้องไม่ยก stderr ของ mysqldump มาวางเฉย ๆ',
    );

    // ฐานที่มีอยู่จริงต้องสำรองได้ตามปกติ — ไม่ใช่ปฏิเสธทุกกรณีแล้วเทสต์ผ่าน
    $bytes = $manager->dump($fixture['executor'], 'example_db', '/tmp/phpcp-drift-real.sql.gz');

    assertTrue($bytes > 0, 'ฐานที่มีอยู่จริงต้องยังสำรองได้');
});

test('แถวค้างต้องลบทิ้งได้ และต้องบอกว่าลบแค่แถว', static function (): void {
    /*
     * ก่อนหน้านี้ผู้ดูแลติดอยู่กับแถวที่ลบไม่ได้: `db.drop` สำรองก่อนเสมอ แล้วการ
     * สำรองล้มเพราะไม่มีฐานให้ dump · ทางออกเดียวคือแก้ฐานข้อมูลของ panel ด้วยมือ
     *
     * และข้อความต้องไม่พูดว่า "ลบฐานข้อมูลแล้ว" — ไม่มีข้อมูลอะไรถูกลบเลย
     */
    $fixture = driftFixture();
    $now = time();

    $fixture['db']->insert('databases_', [
        'db_name' => 'stale_db',
        'site_id' => null,
        'charset' => 'utf8mb4',
        'size_bytes' => 0,
        'created_at' => $now,
    ]);

    $capability = new DbDrop();
    $result = $capability->run(
        $capability->validate(['name' => 'stale_db', 'confirm' => 'stale_db', 'drop_user' => '1']),
        $fixture['executor'],
        $fixture['context'],
    );

    assertSame(true, $result['record_only'], 'ต้องบอกว่าเป็นการลบแค่แถว');
    assertSame('', $result['backup'], 'ไม่มีไฟล์สำรอง เพราะไม่มีอะไรให้สำรอง');
    assertTrue(
        str_contains($result['message'], 'removed the panel record only'),
        'ข้อความต้องบอกตรง ๆ ว่าลบแค่แถว ไม่ได้ลบข้อมูล',
    );

    assertSame(
        0,
        (int) $fixture['db']->value('SELECT count(*) FROM databases_ WHERE db_name = :n', ['n' => 'stale_db'], 0),
        'แถวค้างต้องหายไปจริง',
    );
});

test('ฐานที่มีอยู่จริงต้องยังถูกสำรองก่อนลบเสมอ', static function (): void {
    /*
     * **ข้อนี้คือสิ่งที่ทางลัดข้างบนห้ามทำลาย** · เงื่อนไขที่ยอมข้ามการสำรองคือ
     * "เครื่องยืนยันว่าไม่มีฐานนี้" ไม่ใช่ "สำรองแล้วล้ม" — ถ้าเป็นอย่างหลัง ฐานที่
     * สำรองไม่สำเร็จเพราะดิสก์เต็มจะถูกลบทิ้งโดยไม่มีสำเนา
     */
    $fixture = driftFixture();
    $now = time();

    $fixture['db']->insert('databases_', [
        'db_name' => 'example_db',
        'site_id' => null,
        'charset' => 'utf8mb4',
        'size_bytes' => 0,
        'created_at' => $now,
    ]);

    $capability = new DbDrop();
    $result = $capability->run(
        $capability->validate(['name' => 'example_db', 'confirm' => 'example_db', 'drop_user' => '0']),
        $fixture['executor'],
        $fixture['context'],
    );

    assertSame(false, $result['record_only'], 'ฐานที่มีอยู่จริงต้องไม่เดินทางลัด');
    assertTrue($result['backup'] !== '', 'ต้องมีไฟล์สำรองก่อนลบ');
    assertTrue($result['backup_bytes'] > 0, 'ไฟล์สำรองต้องมีเนื้อจริง');
    assertTrue(
        is_file($fixture['executor']->path($result['backup'])),
        'ไฟล์สำรองต้องอยู่บนดิสก์จริง ไม่ใช่แค่ชื่อในคำตอบ',
    );
});

test('ไฟล์สำรองก่อนลบต้องไปบ้านของเจ้าของ แม้ฐานนั้นไม่ได้ผูกกับเว็บ', static function (): void {
    /*
     * ฐานข้อมูลมีเจ้าของได้โดยไม่ต้องผูกกับเว็บ (ฟอร์มสร้างเปิดให้ทำแบบนั้นตรง ๆ) ·
     * เจ้าของอ่านได้จากคำนำหน้าของชื่อ ซึ่งเป็นเหตุผลทั้งหมดที่คำนำหน้ามีอยู่
     *
     * ไม่มีข้อนี้ ไฟล์สำรองก่อนลบของลูกค้าจะไปตกในพื้นที่ของ panel ที่เขาเอื้อมไม่ถึง
     * — สภาพเดียวกับที่ PLAN-BACKUP-V2 ทั้งฉบับเขียนขึ้นมาเพื่อเลิก
     */
    $fixture = driftFixture();
    $users = new Phpcp\Domain\UserRepository($fixture['db']);

    $ownerId = $users->createHostingAccount('driftcust', 'Drift-Cust-Password-11', 'drift@example.com');
    $fixture['db']->update('users', ['system_user' => 'driftcust'], ['id' => $ownerId]);

    $capability = new DbDrop();
    $method = new ReflectionMethod($capability, 'safetyPath');

    $path = $method->invoke($capability, $fixture['context'], null, 'driftcust_shop');

    assertTrue(
        str_contains($path, '/driftcust/backup/'),
        'ต้องลงในโฟลเดอร์สำรองของเจ้าของ ไม่ใช่พื้นที่ของ panel — ได้ ' . $path,
    );

    // ชื่อที่ไม่ตรงกับคำนำหน้าของใครเลย = ฐานของเครื่อง ตกไปที่พื้นที่ของ panel ตามเดิม
    $orphan = $method->invoke($capability, $fixture['context'], null, 'someone_elses_db');

    assertTrue(
        !str_contains($orphan, '/driftcust/'),
        'ชื่อที่ไม่ใช่ของบัญชีไหนต้องไม่ถูกยัดเข้าบ้านใครมั่ว ๆ',
    );
});
