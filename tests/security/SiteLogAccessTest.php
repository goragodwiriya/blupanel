<?php

declare(strict_types=1);

/**
 * log ของแต่ละเว็บไซต์ — ผู้ดูแลต้องเปิดดูได้ โดยไม่เปิดทางให้อ่านไฟล์ใดก็ได้
 *
 * ทำไมต้องมีชุดนี้: vhost ทุกตัวที่ panel สร้างเขียน log ลง**บ้านของเจ้าของเว็บ**
 * (`Site::accessLog()`) ไม่ใช่ `/var/log/apache2` · ก่อนหน้านี้ `LogCatalog` มีแต่
 * เส้นทางระดับเครื่อง ผู้ดูแลจึงเปิดดูทราฟฟิกของลูกค้าไม่ได้เลยแม้แต่รายเดียว
 *
 * การเปิดทางนี้ต้องไม่ทำลายหลักที่ทำให้ `system.logs_tail` ปลอดภัยมาแต่แรก คือ
 * **ผู้เรียกส่งคีย์ ไม่เคยส่งเส้นทาง** · สิ่งเดียวที่เขาเลือกได้เพิ่มคือ "เลขเว็บ"
 * ซึ่งต้องถูกตรวจทั้งรูปแบบ (ชั้น validate) และความเป็นเจ้าของ (ชั้น run)
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\LogTail;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserRepository;
use Phpcp\Kernel\Config;
use Phpcp\Security\Permissions;

group('SiteLogAccess — log รายเว็บเปิดให้ผู้ดูแล แต่ไม่เปิดไฟล์อื่นบนเครื่อง');

/**
 * บัญชีโฮสติ้งสองรายคนละเว็บ — ใช้พิสูจน์ว่าคนหนึ่งอ่านของอีกคนไม่ได้
 *
 * @return array{db:\Phpcp\Kernel\Db,site_a:int,site_b:int,user_a:int,user_b:int}
 */
function siteLogFixture(): array
{
    $db = migratedDb();
    $users = new UserRepository($db);
    $now = time();

    $made = [];

    foreach (['loga' => 'a.example.com', 'logb' => 'b.example.com'] as $username => $domain) {
        $userId = $users->createHostingAccount(
            $username,
            'Site-Log-Password-11',
            $username . '@example.com',
            ['domains' => 5, 'subdomains' => 5, 'aliases' => 5, 'emails' => 5, 'databases' => 5, 'ftp_users' => 0],
        );

        $made[$username] = [
            'user' => $userId,
            'site' => $db->insert('sites', [
                'primary_domain' => $domain,
                'docroot' => '/srv/phpcp/users/' . $username . '/domains/' . $domain . '/public',
                'php_version' => '8.4',
                'owner_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
        ];
    }

    return [
        'db' => $db,
        'site_a' => $made['loga']['site'], 'user_a' => $made['loga']['user'],
        'site_b' => $made['logb']['site'], 'user_b' => $made['logb']['user'],
    ];
}

function siteLogContext(array $fixture, int $userId, string $role): Context
{
    return new Context(
        new Actor($userId, 'tester', $role, '127.0.0.1', 'test'),
        Config::load(PHPCP_ROOT),
        $fixture['db'],
    );
}

// --- 1. คีย์ยังเป็นคีย์ ไม่ใช่ช่องกรอกเส้นทาง -------------------------------

test('คีย์ของ log รายเว็บรับเฉพาะรูปแบบเดียว', static function (): void {
    /*
     * คีย์ไปโผล่ในการเลือกไฟล์ที่จะอ่าน การยอมรับรูปแบบหลวม ๆ จึงเท่ากับเปิดช่อง
     * ให้ค่าที่ไม่คาดคิดไหลลงไปถึงชั้นประกอบเส้นทาง
     */
    $good = ['site:1:access', 'site:42:error', 'site:999999999:php'];

    foreach ($good as $key) {
        assertTrue(LogCatalog::parseSiteKey($key) !== null, "คีย์ที่ถูกต้องต้องผ่าน: {$key}");
    }

    $bad = [
        'site:0:access',            // ไม่มีเว็บเลข 0
        'site:-1:access',           // ค่าติดลบไม่มีความหมาย
        'site:01:access',           // เลขนำหน้าด้วยศูนย์ = คีย์เดียวกันเขียนได้หลายแบบ
        'site:1:shadow',            // ชนิดที่ไม่มีในรายการ
        'site:1:access:extra',
        'site:1:../../etc/passwd',
        'site::access',
        'site:1',
        'sites:1:access',
        'SITE:1:access',
        'site:1:ACCESS',
        'site:9999999999:access',   // ยาวเกินช่วงที่ยอมรับ
    ];

    foreach ($bad as $key) {
        assertSame(null, LogCatalog::parseSiteKey($key), "คีย์ที่ผิดรูปแบบต้องถูกปฏิเสธ: {$key}");
    }
});

test('คีย์รายเว็บชนกับคีย์ระดับเครื่องไม่ได้', static function (): void {
    // ถ้าชนกันได้ การเพิ่มเว็บใหม่จะกลายเป็นการแย่งคีย์ของ log ระบบไปเงียบ ๆ
    foreach (array_keys(LogCatalog::all()) as $key) {
        assertSame(null, LogCatalog::parseSiteKey($key), "คีย์ระดับเครื่องต้องไม่ถูกอ่านเป็นคีย์รายเว็บ: {$key}");
    }

    foreach (LogCatalog::siteKinds() as $kind => $meta) {
        $key = LogCatalog::siteKey(7, $kind);

        assertTrue(!LogCatalog::has($key), "คีย์รายเว็บต้องไม่อยู่ในรายการระดับเครื่อง: {$key}");
        assertSame(['site_id' => 7, 'kind' => $kind], LogCatalog::parseSiteKey($key), 'คีย์ที่สร้างเองต้องแกะกลับได้');
    }
});

test('ชนิด log รายเว็บทุกชนิดต้องมีเส้นทางรองรับจริง', static function (): void {
    /*
     * `siteKinds()` กับ `match` ใน LogTail เป็นรายการคู่กัน · เพิ่มชนิดที่ข้างหนึ่ง
     * แล้วลืมอีกข้างจะได้ ValidationError ตอนผู้ใช้กดเลือก ซึ่งเจอที่หน้างานเท่านั้น
     */
    $fixture = siteLogFixture();
    $context = siteLogContext($fixture, 1, Permissions::SUPERADMIN);
    $capability = new LogTail();

    foreach (array_keys(LogCatalog::siteKinds()) as $kind) {
        $key = LogCatalog::siteKey($fixture['site_a'], $kind);

        $result = $capability->run(
            $capability->validate(['source' => $key]),
            new DryRunExecutor(),
            $context,
        );

        assertSame($key, $result['source'], "ชนิด {$kind} ต้องอ่านผ่าน");
        assertTrue(str_contains($result['path'], 'a.example.com') || str_contains($result['path'], '/loga/'),
            "เส้นทางของชนิด {$kind} ต้องอยู่ในบ้านของเจ้าของ: {$result['path']}");
    }
});

// --- 2. เส้นทางต้องชี้เข้าบ้านเจ้าของ ไม่ใช่ /var/log ------------------------

test('log ของเว็บต้องอ่านจากบ้านของเจ้าของ ไม่ใช่ไฟล์ระดับเครื่อง', static function (): void {
    /*
     * นี่คือเหตุผลทั้งหมดของงานนี้ · ถ้าเส้นทางยังชี้ `/var/log/apache2/access.log`
     * หน้าจอจะดูเหมือนทำงานแต่แสดงไฟล์ที่ไม่มีทราฟฟิกของลูกค้าอยู่เลยสักบรรทัด
     */
    $fixture = siteLogFixture();
    $capability = new LogTail();

    $result = $capability->run(
        $capability->validate(['source' => LogCatalog::siteKey($fixture['site_a'], 'access')]),
        new DryRunExecutor(),
        siteLogContext($fixture, 1, Permissions::SUPERADMIN),
    );

    assertTrue(
        !str_starts_with($result['path'], '/var/log/'),
        "log ของเว็บต้องไม่ชี้ไปที่ /var/log — ได้ {$result['path']}",
    );
    assertTrue(str_ends_with($result['path'], '/access.log'), "ต้องเป็นไฟล์ access.log — ได้ {$result['path']}");
    assertTrue(str_contains($result['label'], 'a.example.com'), 'ป้ายต้องบอกว่าเป็นของเว็บไหน');
});

test('เว็บที่ไม่มีอยู่ต้องถูกปฏิเสธ ไม่ใช่ได้เส้นทางเปล่า', static function (): void {
    // เส้นทางที่ประกอบจากเจ้าของที่ไม่มีตัวตนเคยออกมาเป็น /srv/phpcp/users//… มาแล้ว
    $fixture = siteLogFixture();
    $capability = new LogTail();

    assertRejects(
        ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['source' => 'site:987654:access']),
            new DryRunExecutor(),
            siteLogContext($fixture, 1, Permissions::SUPERADMIN),
        ),
        'เว็บที่ไม่มีอยู่ต้องถูกปฏิเสธ',
    );
});

// --- 3. ขอบเขต: ใครอ่าน log ของใครได้ ---------------------------------------

test('ผู้ดูแลเว็บไซต์อ่าน log ไม่ได้เลย แม้เป็นเว็บของตัวเอง', static function (): void {
    /*
     * นโยบายปัจจุบัน: `log.view` เป็นสิทธิ์หมวด SERVER ที่ผู้ดูแลเว็บไซต์ไม่มี
     * (ดู `Permissions::forRole()`) · เทสต์นี้ตรึงนโยบายไว้ ไม่ใช่แค่บันทึกว่ามันเป็นแบบนี้
     * — การเปิด log ให้ลูกค้าต้องเป็นการตัดสินใจที่มีคนเปลี่ยนเทสต์นี้ด้วยมือ
     */
    $fixture = siteLogFixture();
    $capability = new LogTail();

    assertRejects(
        PermissionDenied::class,
        static fn () => $capability->run(
            $capability->validate(['source' => LogCatalog::siteKey($fixture['site_a'], 'access')]),
            new DryRunExecutor(),
            siteLogContext($fixture, $fixture['user_a'], Permissions::WEBADMIN),
        ),
        'ผู้ดูแลเว็บไซต์ต้องอ่าน log ของเว็บตัวเองไม่ได้ตราบที่ยังไม่มี log.view',
    );
});

test('บทบาทที่มี log.view ต้องเป็นบทบาทที่เห็นเว็บของทุกคนเท่านั้น', static function (): void {
    /*
     * **ตัวดักนโยบาย ไม่ใช่ตัวดักโค้ด**
     *
     * `LogTail::siteLog()` ตรวจความเป็นเจ้าของไว้สำหรับผู้ที่มี `log.view` แต่ไม่ได้
     * เห็นเว็บของทุกคน ซึ่งวันนี้ยังไม่มีบทบาทไหนเข้าเงื่อนไขนั้น · เทสต์นี้คือสิ่งที่
     * ทำให้ความจริงข้อนั้นไม่เงียบหาย: วันที่มีคนให้ `log.view` กับบทบาทที่เห็นเฉพาะ
     * เว็บตัวเอง เทสต์จะล้มทันทีเพื่อบังคับให้ไปพิสูจน์ด่านเจ้าของว่ากันได้จริง
     */
    foreach (array_keys(Permissions::roleLabels()) as $role) {
        if (!Permissions::roleHas($role, LogCatalog::SITE_PERMISSION)) {
            continue;
        }

        assertTrue(
            Permissions::seesAllSites($role),
            "บทบาท {$role} มี log.view แต่ไม่ได้เห็นเว็บของทุกคน — ต้องพิสูจน์การกรองตามเจ้าของก่อน",
        );
    }

    // ฝั่งตรงข้ามต้องจริงด้วย ไม่งั้นเงื่อนไขข้างบนผ่านเพราะไม่มีใครเข้าเงื่อนไขเลย
    assertTrue(Permissions::seesAllSites(Permissions::SUPERADMIN), 'ผู้ดูแลระบบต้องเห็นเว็บของทุกคน');
    assertTrue(Permissions::seesAllSites(Permissions::SYSADMIN), 'ผู้ดูแลเซิร์ฟเวอร์ต้องเห็นเว็บของทุกคน');
    assertTrue(!Permissions::seesAllSites(Permissions::WEBADMIN), 'ผู้ดูแลเว็บไซต์ต้องเห็นเฉพาะเว็บตัวเอง');
});

test('รายการเว็บแบบย่อกรองตามเจ้าของได้จริง', static function (): void {
    // ตัวกรองนี้คือสิ่งที่ทำให้รายการแหล่ง log ของแต่ละคนไม่ปนกัน
    $fixture = siteLogFixture();
    $sites = new SiteRepository($fixture['db']);

    $all = $sites->listBrief();
    assertSame(2, count($all), 'ไม่ระบุเจ้าของต้องได้ทุกเว็บ');

    $mine = $sites->listBrief($fixture['user_a']);
    assertSame(1, count($mine), 'ระบุเจ้าของต้องได้เฉพาะเว็บของคนนั้น');
    assertSame('a.example.com', $mine[0]['domain'], 'ต้องเป็นเว็บของเจ้าของที่ระบุ');
    assertSame('loga', $mine[0]['owner'], 'ต้องมีชื่อเจ้าของติดมาด้วยเพื่อจัดกลุ่มในรายการ');
});
