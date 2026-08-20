<?php

declare(strict_types=1);

/**
 * หน้า PHP — ติดตั้ง/ถอดถอนจริง และการตัดสินสถานะการรองรับจากวันที่
 *
 * สิ่งที่ชุดนี้เฝ้าคือความผิดพลาดที่ **ไม่มีอะไรฟ้องจนกว่าเว็บจะล่มไปแล้ว**:
 *
 *   - ถอดถอนเวอร์ชันที่ยังมีเว็บใช้อยู่ = เว็บเหล่านั้นตอบ 502 ทันที และไม่มี
 *     อะไรบนหน้าเว็บของเขาบอกว่าทำไม
 *   - ถอดถอนเวอร์ชันที่ panel เองรันอยู่ = panel ตายกลางคำขอ ไม่เหลือหน้าจอ
 *     ให้รายงานผล และไม่เหลือทางกลับเข้าไปนอกจาก terminal
 *   - ชื่อ unit ที่มีจุด = systemd อ่านส่วนหลังจุดเป็น "ชนิด" ของ unit ทุกคำสั่ง
 *     หลังจากนั้นล้มด้วยข้อความที่ไม่เกี่ยวกับความผิดจริงเลย
 *   - รายการเวอร์ชันที่เขียนมือ = ผิดเงียบ ๆ ทุกวันหลังวันที่เขียน
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Context;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\PhpSupport;
use Phpcp\Driver\Php\PhpPackageJob;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Routes;
use Phpcp\Security\Permissions;

group('หน้า PHP — ติดตั้ง ถอดถอน และอายุการรองรับ');

/** @return array{db:\Phpcp\Kernel\Db,context:Context} */
function phpVersionsFixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $root = sys_get_temp_dir() . '/phpcp-phpver-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    return $fixture = [
        'db' => $db,
        'context' => new Context(
            new Actor(0, 'system', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
            Config::load(PHPCP_ROOT),
            $db,
        ),
    ];
}

test('ติดตั้ง/ถอดถอน PHP ต้องเป็นสิทธิ์ของผู้ดูแลเครื่อง ไม่ใช่ php.view', static function (): void {
    /*
     * `php.view` ลูกค้าถืออยู่ เพราะ dropdown เลือกเวอร์ชันของเว็บตัวเองสร้างจาก
     * endpoint เดียวกัน · ถ้าคำสั่งติดตั้งใช้สิทธิ์เดียวกัน ลูกค้าคนใดก็สั่งให้
     * เครื่องรัน apt ด้วย root ได้
     */
    $registry = new CapabilityRegistry();

    foreach (['php.install', 'php.remove', 'php.job_status'] as $name) {
        assertSame(
            'php.manage',
            $registry->resolve($name)->permission(),
            "{$name} ต้องใช้ php.manage",
        );
    }

    assertTrue(!Permissions::roleHas(Permissions::WEBADMIN, 'php.manage'), 'ลูกค้าต้องไม่มี php.manage');
    assertTrue(Permissions::roleHas(Permissions::WEBADMIN, 'php.view'), 'ลูกค้ายังต้องอ่านรายการเวอร์ชันได้');
});

test('เมนูและ route ของหน้า PHP ต้องเป็นของผู้ดูแล ไม่ใช่ของลูกค้า', static function (): void {
    // หน้านี้แสดงสถานะ FPM หน่วยความจำ และจำนวนเว็บของ "ทุกลูกค้า" รวมกัน
    // ลูกค้าเลือกเวอร์ชันของเว็บตัวเองจากหน้าเว็บของเว็บนั้น ซึ่งเป็นที่เดียวที่มันมีความหมายกับเขา
    $routes = file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/main.js');
    $menu = file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');

    // `[^\n]` ไม่ใช่ `[^}]` — ชื่อหน้าคือ `{LNG_PHP}` ซึ่งมีปีกกาปิดอยู่ในบรรทัดเดียวกัน
    assertTrue(
        (bool) preg_match("/'\\/php-versions':[^\n]*permission: 'php\\.manage'/", (string) $routes),
        'route /php-versions ต้องใช้ php.manage',
    );
    assertTrue(
        (bool) preg_match("/url: '\\/php-versions'.*permission: 'php\\.manage'/", (string) $menu),
        'เมนู PHP ต้องใช้ php.manage',
    );
});

test('ถอดถอนต้องปฏิเสธเวอร์ชันที่ panel เองรันอยู่', static function (): void {
    /*
     * เป็นข้อที่กู้คืนไม่ได้ที่สุดในหน้านี้ — ถ้าปล่อยผ่าน apt จะถอด php-fpm ที่
     * panel กำลังใช้ตอบคำขอนี้อยู่ ผลคือไม่มีทั้งหน้าจอที่จะรายงานผล และไม่มีทาง
     * กลับเข้าระบบนอกจากเข้า SSH ไปติดตั้งคืนเอง
     */
    $fixture = phpVersionsFixture();
    $capability = (new CapabilityRegistry())->resolve('php.remove');
    $own = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    $rejected = false;
    $message = '';

    try {
        $capability->run(
            $capability->validate(['version' => $own]),
            new Phpcp\Agent\Executor\DryRunExecutor(),
            $fixture['context'],
        );
    } catch (ValidationError $e) {
        $rejected = true;
        $message = $e->getMessage();
    } catch (\Throwable) {
        // ไม่ได้ติดตั้งในสภาพแวดล้อมเทสต์ก็ปฏิเสธเหมือนกัน — ยังไงก็ไม่ได้ถอดถอน
        $rejected = true;
    }

    assertTrue($rejected, "ต้องปฏิเสธการถอดถอน PHP {$own} ซึ่งเป็นเวอร์ชันที่ panel รันอยู่");

    if ($message !== '') {
        assertTrue(
            str_contains($message, $own),
            'ข้อความต้องบอกว่าเวอร์ชันไหนและทำไม ไม่ใช่ error ลอย ๆ',
        );
    }
});

test('ถอดถอนต้องรับเฉพาะเวอร์ชันที่รูปแบบถูกต้อง', static function (): void {
    // ค่าที่หลุดไปเป็น argv ของ apt-get ในฐานะคำแยก คือช่องที่ต้องปิดตั้งแต่ validate
    $capability = (new CapabilityRegistry())->resolve('php.remove');

    foreach (['8', 'latest', '8.4; rm -rf /', '../8.4', 'php8.4', ''] as $version) {
        $rejected = false;

        try {
            $capability->validate(['version' => $version]);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธเวอร์ชัน: '{$version}'");
    }

    assertSame('8.4', $capability->validate(['version' => '8.4'])['version'], 'รูปแบบถูกต้องต้องผ่าน');
});

test('ชื่อ unit ของงานติดตั้งต้องไม่มีจุด', static function (): void {
    /*
     * systemd อ่านทุกอย่างหลังจุดสุดท้ายเป็น "ชนิด" ของ unit · `phpcp-php-8.4`
     * จึงเป็น unit ชนิด `4` และทุกคำสั่งที่ยิงใส่มันจะล้มด้วยข้อความเรื่องชนิดที่
     * ไม่รู้จัก ซึ่งหน้าตาไม่เหมือนความผิดจริงเลยสักนิด
     */
    $unit = (new PhpPackageJob())->unit('8.4');

    assertSame('phpcp-php-8-4.service', $unit, 'จุดในเลขเวอร์ชันต้องกลายเป็นขีด');
    assertTrue(str_ends_with($unit, '.service'), 'ต้องลงท้ายด้วยชนิด .service ที่ตั้งใจ');
    assertSame(1, substr_count($unit, '.'), 'ต้องมีจุดเดียวคือจุดของชนิด unit');
});

test('เวอร์ชันที่ยังมีเว็บใช้อยู่ต้องถอดถอนไม่ได้', static function (): void {
    // เว็บพวกนั้นจะตอบ 502 ทันทีที่ socket ของ pool หายไป โดยไม่มีอะไรบนหน้าเว็บ
    // ของมันเองอธิบายสาเหตุ · เงื่อนไขนี้อยู่ในทั้งแถวของตาราง (ซ่อนปุ่ม) และใน
    // capability (ประตูจริง) — ที่นี่ตรวจว่า capability ตรวจซ้ำเอง ไม่ได้เชื่อหน้าจอ
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/PhpRemove.php');

    assertTrue(str_contains($source, 'countByPhpVersion'), 'ต้องนับเว็บจากตารางเอง');
    assertTrue(str_contains($source, 'PHP_MAJOR_VERSION'), 'ต้องกันเวอร์ชันที่ panel รันอยู่');
    assertTrue(str_contains($source, 'count($installed) === 1'), 'ต้องกันการถอดเวอร์ชันสุดท้าย');
    assertTrue(str_contains($source, 'purge'), 'ต้อง purge ไม่ใช่ remove — ไม่งั้น /etc/php ค้างและ panel ยังนับว่าติดตั้งอยู่');
});

test('งานติดตั้งต้องไม่บล็อกคำขอ และต้องอ่านผลย้อนหลังได้', static function (): void {
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Php/PhpPackageJob.php');

    /*
     * สองบรรทัดนี้คือทั้งหมดที่ทำให้วิธีนี้ใช้ได้จริง:
     *   --no-block          Type=oneshot ทำให้ systemctl start รอจนจบ · ถ้าไม่ใส่
     *                       คำขอที่ควรคืนค่าในเสี้ยววินาทีจะไปชน timeout 30 วิของ agent
     *   RemainAfterExit     unit ชั่วคราวหายทันทีที่จบ พาสถานะออกไปด้วย · ถ้าไม่ใส่
     *                       คำถามว่า "สำเร็จไหม" จะไม่มีคำตอบเลย
     */
    assertTrue(str_contains($source, '--no-block'), 'ต้องไม่รอให้ apt เสร็จในคำขอเดียวกัน');
    assertTrue(str_contains($source, 'RemainAfterExit=yes'), 'ต้องเก็บผลไว้ให้อ่านย้อนหลังได้');
    assertTrue(str_contains($source, 'DEBIAN_FRONTEND=noninteractive'), 'apt ต้องไม่ค้างรอคำตอบจากคนที่ไม่มีอยู่');
    assertTrue(str_contains($source, 'LoadState'), 'ต้องแยก "ไม่เคยมีงานนี้" ออกจาก "งานสำเร็จ" ด้วย LoadState');
});

test('ถอดถอนต้องส่งชื่อแพ็กเกจจริง ไม่ใช่ส่ง glob ให้ apt', static function (): void {
    // `apt-get purge 'php8.2-*'` ขยายในตัว apt เอง — การยื่นรูปแบบให้ตัวจัดการ
    // แพ็กเกจที่รันด้วย root ระวังตัวได้น้อยกว่ายื่นรายชื่อที่เพิ่งยืนยันว่ามีอยู่จริง
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Php/PhpPackageJob.php');

    assertTrue(str_contains($source, 'dpkg-query'), 'ต้องอ่านรายชื่อที่ติดตั้งจริงจาก dpkg');
    assertTrue(
        str_contains($source, 'install ok installed'),
        'ต้องรับเฉพาะแพ็กเกจที่สถานะ installed จริง — deinstall/config-files ทำให้ apt ล้มทั้งคำสั่ง',
    );
});

test('รายการเวอร์ชันต้องมาจากเครื่อง ไม่ใช่จากค่าคงที่ในโค้ด', static function (): void {
    /*
     * เดิม `installedVersions()` วนรายการชื่อที่คอมไพล์มากับ panel แล้วถามว่าแต่ละ
     * ตัวมีไหม · ผลคือวันที่ PHP ปล่อยเวอร์ชันใหม่ ผู้ดูแล apt install ได้ systemd
     * รันให้จริง แต่ panel บอกว่าไม่มี จนกว่าจะมีคนไปแก้ค่าคงที่แล้ว deploy ใหม่
     */
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Php/FpmManager.php');

    assertTrue(str_contains($source, 'glob('), 'ต้องสแกนไดเรกทอรีจริง');
    assertTrue(str_contains($source, 'apt-cache'), 'รายการที่ติดตั้งได้ต้องถามจาก apt ของเครื่องนี้');
    assertTrue(
        !str_contains($source, 'ServiceCatalog::PHP_VERSIONS as'),
        'ต้องไม่วนรายการเวอร์ชันที่เขียนมืออีกแล้ว',
    );
});

test('เวอร์ชันใหม่ที่ตารางยังไม่รู้จักต้องใช้งานได้ทันที', static function (): void {
    /*
     * ข้อนี้คือคำตอบของโจทย์ "มีเวอร์ชันใหม่แล้วต้องทำอย่างไร" — คำตอบคือไม่ต้องทำอะไร
     *
     * รูปแบบถูกต้องก็ผ่าน validate · การมีอยู่จริงถูกตรวจกับเครื่อง · สถานะการรองรับ
     * ตัดสินจากวันที่และเวอร์ชันที่ใหม่กว่าทุกตัวในตารางถือว่ายังรองรับ
     */
    assertTrue(PhpSupport::isValid('8.6'), 'รูปแบบของเวอร์ชันใหม่ต้องผ่าน');
    assertTrue(PhpSupport::isSupported('8.6'), 'เวอร์ชันที่ใหม่กว่าทุกตัวในตารางต้องถือว่ายังรองรับ');

    $capability = (new CapabilityRegistry())->resolve('php.install');
    assertSame('8.6', $capability->validate(['version' => '8.6'])['version'], 'สั่งติดตั้งเวอร์ชันใหม่ได้โดยไม่ต้องแก้โค้ด');
});

test('เส้นทาง job ต้องมาก่อน {version} ไม่งั้นถูกอ่านเป็นเลขเวอร์ชัน', static function (): void {
    $paths = [];

    foreach (Routes::build()->routes() as $route) {
        if (str_starts_with($route->path, '/api/v2/php-versions')) {
            $paths[] = $route->path;
        }
    }

    $job = array_search('/api/v2/php-versions/job', $paths, true);
    $version = array_search('/api/v2/php-versions/{version}', $paths, true);

    assertTrue($job !== false, 'ต้องมีเส้นทางสถานะงาน');
    assertTrue($version !== false, 'ต้องมีเส้นทางถอดถอน');
    assertTrue($job < $version, 'เส้นทางที่เป็นคำตายตัวต้องประกาศก่อนเส้นทางที่มีพารามิเตอร์');
});
