<?php

declare(strict_types=1);

/**
 * สำรองและกู้คืนไฟล์เว็บไซต์ — สิ่งที่ต้องถูกต้องตอนที่ไม่มีใครมองอยู่
 *
 * **เจอจากการตรวจก่อนทดสอบจริง (2026-08-14):** ทั้งสองข้อล้วนเป็นความล้มเหลวที่
 * **รายงานว่าสำเร็จ** — ชนิดที่รู้ตัวตอนต้องใช้ไฟล์สำรองจริง ซึ่งสายเกินไปตามนิยาม
 *
 *   1. **สำรองผิดไดเรกทอรี** — `backupSite()` เคยเก็บ `root()` ซึ่งใช้ได้ตอนมีเลย์เอาต์
 *      เดียว (phpcp เก็บ `public/` ไว้ข้างใน) · ตั้งแต่ cpanel เป็นมาตรฐาน (migration
 *      0020) `root()` กลายเป็น `.phpcp/<โดเมน>` ที่มีแต่ `__suspended.html` — ไฟล์สำรอง
 *      ทุกไฟล์บนเครื่องจริงจึงไม่มีไฟล์เว็บอยู่เลยสักไฟล์
 *
 *   2. **เจ้าของไฟล์หลังกู้คืน** — `--strip-components 1` ตัดรายการไดเรกทอรีบนสุดทิ้ง
 *      เจ้าของกับสิทธิ์ของมันจึงไม่ถูกใช้กับไดเรกทอรีที่แตกไฟล์ลงไป · รากเว็บกลายเป็น
 *      root:root ที่ FPM pool เดินผ่านไม่ได้ · และ tar ที่รันด้วย root คืนค่า uid ตาม
 *      ที่ฝังมาใน archive ซึ่งเป็น uid ของ**เครื่องต้นทาง** — กู้ข้ามเครื่องแล้วไฟล์
 *      กลายเป็นของผู้ใช้คนอื่น
 *
 * เทสต์นี้ใช้ tar จริงและไฟล์จริงใต้ temp dir ไม่ต้องมี root — เพราะสิ่งที่ต้องพิสูจน์
 * คือ "ไฟล์ไหนอยู่ในนั้น" ซึ่ง mock ตอบแทนไม่ได้
 */

use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteLayout;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\BackupManager;
use Phpcp\Kernel\Paths;

group('สำรอง/กู้คืนเว็บไซต์ — ไฟล์ที่อยู่ในไฟล์สำรองจริง ๆ');

/**
 * บ้านผู้ใช้จำลองใต้ temp dir พร้อมไฟล์เว็บและกล่องสถานะที่แยกกันคนละที่
 *
 * @return array{site:Site,manager:BackupManager,docroot:string,state:string,restore:string}
 */
function backupFixture(SiteLayout $layout, string $domain = 'example.com'): array
{
    static $original = null;

    $root = sys_get_temp_dir() . '/phpcp-backup-' . getmypid() . '-' . bin2hex(random_bytes(4));

    if ($original === null) {
        $original = Paths::usersDir();

        register_shutdown_function(static function () use ($original): void {
            Paths::useUsersDir($original);
        });
    }

    Paths::useUsersDir($root);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    // โดเมนหลักของเจ้าของ — ตัวตัดสินว่า cpanel ให้ public_html หรือโฟลเดอร์ชื่อโดเมน
    $owner = new UserAccount(1, 'cust', $layout, $domain);
    $site = new Site(1, $domain, $owner, '8.4');

    mkdir($site->docroot(), 0755, true);

    // เลย์เอาต์ phpcp วาง docroot ไว้ **ใต้** root() อยู่แล้ว — สร้างซ้ำไม่ได้
    if (!is_dir($site->root())) {
        mkdir($site->root(), 0755, true);
    }

    // ไฟล์เว็บจริง — สิ่งเดียวที่ผู้ใช้สนใจว่าจะกู้กลับมาได้ไหม
    file_put_contents($site->docroot() . '/index.php', '<?php echo "ของจริง";');
    mkdir($site->docroot() . '/wp-content', 0755, true);
    file_put_contents($site->docroot() . '/wp-content/config.php', 'ของในโฟลเดอร์ย่อย');

    // กล่องสถานะ — ไม่ใช่ไฟล์เว็บ และไม่ควรเป็นสิ่งที่ถูกสำรองแทน
    file_put_contents($site->root() . '/__suspended.html', '<html>ระงับบริการ</html>');

    return [
        'site' => $site,
        // ไม่มี "ไดเรกทอรีสำรองของระบบ" ให้ส่งอีกแล้ว — ตัวจัดการเขียนลง
        // `$site->backupDir()` ซึ่งอยู่ในบ้านของเจ้าของเสมอ
        'manager' => new BackupManager(),
        'docroot' => $site->docroot(),
        'state' => $site->root(),
        'backups' => $site->backupDir(),
        'restore' => $root,
    ];
}

/** @return list<string> รายชื่อไฟล์ในไฟล์สำรอง */
function backupContents(string $archive): array
{
    exec('tar --list --file ' . escapeshellarg($archive) . ' 2>&1', $lines, $code);

    return $code === 0 ? $lines : [];
}

test('ไฟล์สำรองต้องมีไฟล์เว็บจริง ไม่ใช่กล่องสถานะ — ทั้งสองเลย์เอาต์', static function (): void {
    foreach ([SiteLayout::Cpanel, SiteLayout::Phpcp] as $layout) {
        $fixture = backupFixture($layout);
        $result = $fixture['manager']->backupSite(new RealExecutor(), $fixture['site']);

        $inside = implode("\n", backupContents($result['path']));
        $name = $layout->value;

        assertTrue(
            str_contains($inside, 'index.php'),
            "เลย์เอาต์ {$name}: ไฟล์สำรองต้องมี index.php — ไม่มีแปลว่าสำรองผิดไดเรกทอรี",
        );

        assertTrue(
            str_contains($inside, 'wp-content/config.php'),
            "เลย์เอาต์ {$name}: ต้องเก็บโฟลเดอร์ย่อยมาด้วย",
        );

        assertTrue(
            !str_contains($inside, '__suspended.html'),
            "เลย์เอาต์ {$name}: กล่องสถานะไม่ใช่ไฟล์เว็บ ไม่ควรอยู่ในไฟล์สำรองของเว็บ",
        );
    }
});

test('กู้คืนแล้วต้องได้ไฟล์เดิมกลับมาจริง และของที่เพิ่มทีหลังต้องหายไป', static function (): void {
    $fixture = backupFixture(SiteLayout::Cpanel);
    $executor = new RealExecutor();
    $site = $fixture['site'];

    $backup = $fixture['manager']->backupSite($executor, $site);

    // แก้ไฟล์เดิมและเพิ่มไฟล์ใหม่ — กู้คืนแล้วต้องกลับไปเป็นสภาพตอนสำรอง
    file_put_contents($site->docroot() . '/index.php', '<?php echo "ของที่พังแล้ว";');
    file_put_contents($site->docroot() . '/หลังสำรอง.txt', 'ไม่ควรรอด');

    // owner ว่าง = ข้าม chown · เทสต์รันโดยไม่มี root จึง chown ไม่ได้อยู่แล้ว
    $result = $fixture['manager']->restoreSite($executor, $site, $backup['path'], $backup['checksum'], '');

    assertSame(
        '<?php echo "ของจริง";',
        (string) file_get_contents($site->docroot() . '/index.php'),
        'เนื้อไฟล์ต้องกลับไปเป็นของตอนสำรอง',
    );

    assertTrue(
        is_file($site->docroot() . '/wp-content/config.php'),
        'โฟลเดอร์ย่อยต้องกลับมาครบ',
    );

    assertTrue(
        !file_exists($site->docroot() . '/หลังสำรอง.txt'),
        'ไฟล์ที่เพิ่มหลังสำรองต้องหายไป — ไม่งั้นแปลว่ากู้แบบผสมของเก่ากับของใหม่',
    );

    assertTrue(is_file($result['safety']), 'ต้องมีไฟล์สำรองนิรภัยของสภาพก่อนกู้คืน');
    assertSame($site->docroot(), $result['restored'], 'ต้องรายงานว่ากู้คืนที่ docroot');
});

test('ต้องตั้งเจ้าของไฟล์ก่อนสลับเข้าที่ ไม่ใช่หลัง', static function (): void {
    /*
     * ลำดับคือทั้งหมดของเรื่องนี้ — chown หลังสลับแปลว่ามีช่วงที่เว็บมีชีวิตอยู่ด้วย
     * รากที่เป็นของ root ซึ่ง FPM pool เดินผ่านไม่ได้ · ตรวจจากลำดับคำสั่งจริง
     * ที่ capability สั่ง ไม่ใช่จาก grep ซอร์ส
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'chown.example.com');
    $recorder = new RecordingExecutor();

    $backup = $fixture['manager']->backupSite(new RealExecutor(), $fixture['site']);
    $recorder->commands = [];

    $fixture['manager']->restoreSite(
        $recorder,
        $fixture['site'],
        $backup['path'],
        $backup['checksum'],
        'cust:www-data',
    );

    $chown = null;
    $swap = null;

    foreach ($recorder->commands as $index => $command) {
        if ($chown === null && str_contains($command, 'chown -Rh cust:www-data')) {
            $chown = $index;
        }

        if ($swap === null && str_starts_with($command, 'rename ')) {
            $swap = $index;
        }
    }

    assertTrue($chown !== null, 'ต้องมีคำสั่ง chown ที่ไฟล์ที่แตกออกมา');
    assertTrue($swap !== null, 'ต้องมีการสลับไดเรกทอรีเข้าที่');
    assertTrue($chown < $swap, 'chown ต้องมาก่อนการสลับเข้าที่');
});

test('chown ล้มต้องยกเลิกก่อนแตะของเดิม ไม่ใช่ปล่อยเว็บพังคาไว้', static function (): void {
    // filesystem ที่ chown ไม่ได้ (หรือชื่อผู้ใช้ที่ไม่มีจริง) ต้องไม่ทำให้เว็บหาย
    $fixture = backupFixture(SiteLayout::Cpanel, 'fail.example.com');
    $site = $fixture['site'];
    $backup = $fixture['manager']->backupSite(new RealExecutor(), $site);

    $recorder = new RecordingExecutor();
    $recorder->failChown = true;

    $rejected = false;

    try {
        $fixture['manager']->restoreSite($recorder, $site, $backup['path'], $backup['checksum'], 'ghost:ghost');
    } catch (\Phpcp\Agent\ExecutionFailed) {
        $rejected = true;
    }

    assertTrue($rejected, 'chown ล้มต้องโยนข้อผิดพลาด');

    $swaps = array_filter($recorder->commands, static fn (string $c): bool => str_starts_with($c, 'rename '));

    assertSame([], array_values($swaps), 'ต้องไม่มีการสลับไดเรกทอรีเลยเมื่อ chown ล้ม');

    assertTrue(is_file($site->docroot() . '/index.php'), 'ของเดิมต้องยังอยู่ครบ');
});

// --- archive ที่ลูกค้าประกอบเอง: สิ่งที่ต้องถือว่าเป็นศัตรูตั้งแต่แรก ----------
//
// ตั้งแต่โฟลเดอร์สำรองย้ายเข้าบ้านลูกค้า ไฟล์ที่มาถึง restoreSite() เป็นของที่เขาวางเอง
// ได้ทั้งก้อน · checksum ถูกคำนวณสดจากไฟล์นั้นเอง และ backup.json ข้างในก็เขียนเองได้
// ด่านทั้งสองจึงพิสูจน์ได้แค่ "ไฟล์ไม่เปลี่ยนระหว่างทาง" ไม่ได้พิสูจน์ว่าเนื้อในไว้ใจได้

/**
 * ประกอบ .tar.gz ด้วยมือ — เพราะ `tar --create` สร้างรายการที่ต้องทดสอบให้ไม่ได้
 *
 * GNU tar ตัด `/` นำหน้าและ `..` ทิ้งตั้งแต่ตอน**สร้าง** archive ที่ตั้งใจร้ายจึง
 * สร้างด้วยคำสั่ง tar ไม่ได้เลย · เขียนหัว ustar เองเป็นทางเดียวที่พิสูจน์ด่านนี้ได้จริง
 * แทนที่จะพิสูจน์แค่ว่าเราเรียก tar ด้วยธงอะไร ซึ่งไม่ได้แปลว่าด่านทำงาน
 *
 * @param list<array{name:string,type?:string,link?:string,body?:string,mode?:int}> $entries
 */
function craftArchive(string $path, array $entries): void
{
    $tar = '';

    foreach ($entries as $entry) {
        $body = $entry['body'] ?? '';

        $header = pack('a100', $entry['name'])
            . pack('a8', sprintf('%07o', $entry['mode'] ?? 0644))
            . pack('a8', sprintf('%07o', 0))          // uid
            . pack('a8', sprintf('%07o', 0))          // gid
            . pack('a12', sprintf('%011o', strlen($body)))
            . pack('a12', sprintf('%011o', time()))
            . str_repeat(' ', 8)                      // ช่อง checksum เป็นช่องว่างตอนคำนวณ
            . ($entry['type'] ?? '0')                 // 0=ไฟล์ 5=ไดเรกทอรี 2=symlink 1=hardlink 3=อุปกรณ์
            . pack('a100', $entry['link'] ?? '')
            . 'ustar' . chr(0) . '00'
            . pack('a32', 'root') . pack('a32', 'root')
            . pack('a8', '') . pack('a8', '')
            . pack('a155', '');

        $header = str_pad($header, 512, chr(0));

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }

        $tar .= substr_replace($header, sprintf('%06o', $sum) . chr(0) . ' ', 148, 8);

        if ($body !== '') {
            $tar .= str_pad($body, (int) ceil(strlen($body) / 512) * 512, chr(0));
        }
    }

    file_put_contents($path, (string) gzencode($tar . str_repeat(chr(0), 1024)));
}

/** กู้คืนแล้วคืนข้อความที่ถูกปฏิเสธ · ค่าว่าง = ไม่ถูกปฏิเสธ */
function restoreRejection(array $fixture, string $archive): string
{
    try {
        $fixture['manager']->restoreSite(
            new RealExecutor(),
            $fixture['site'],
            $archive,
            (string) hash_file('sha256', $archive),
            '',
        );
    } catch (\Phpcp\Agent\ExecutionFailed $e) {
        return $e->getMessage();
    }

    return '';
}

test('รายการที่ไต่ออกนอกโฟลเดอร์ต้องถูกปฏิเสธก่อนแตะของเดิม', static function (): void {
    $fixture = backupFixture(SiteLayout::Cpanel, 'escape.example.com');
    $site = $fixture['site'];

    $evil = $fixture['restore'] . '/escape.tar.gz';

    craftArchive($evil, [
        ['name' => 'public_html/', 'type' => '5', 'mode' => 0755],
        ['name' => 'public_html/ธรรมดา.txt', 'body' => 'ของที่ดูปกติ'],
        ['name' => 'public_html/../../../etc/cron.d/pwn', 'body' => "* * * * * root sh\n"],
    ]);

    assertTrue(
        str_contains(implode("\n", backupContents($evil)), '..'),
        'archive ที่ใช้ทดสอบต้องมีรายการไต่ออกนอกจริง ๆ ไม่งั้นเทสต์นี้ไม่ได้ทดสอบอะไรเลย',
    );

    $rejected = restoreRejection($fixture, $evil);

    assertTrue(str_contains($rejected, 'points outside'), "ต้องปฏิเสธเพราะไต่ออกนอกโฟลเดอร์ ได้: {$rejected}");

    assertTrue(
        !file_exists($site->docroot() . '/ธรรมดา.txt'),
        'ต้องไม่มีรายการไหนถูกแตกออกมาเลย — ปฏิเสธทั้ง archive ไม่ใช่ข้ามทีละรายการ',
    );

    assertSame(
        [],
        glob($site->backupDir() . '/*') ?: [],
        'ต้องปฏิเสธก่อนสร้างไฟล์นิรภัย ไม่งั้นลูกค้าเสียโควตาให้ archive ที่ไม่มีวันถูกใช้',
    );

    assertTrue(is_file($site->docroot() . '/index.php'), 'ของเดิมต้องยังอยู่ครบ');
});

test('hardlink ที่ชี้ออกนอกไฟล์สำรองต้องไม่ทำให้เว็บพัง และต้องไม่ได้ไฟล์ปลายทางมา', static function (): void {
    /*
     * ทำไม hardlink อันตรายกว่า symlink คนละชั้น: `chown -Rh` ใช้ `-h` เพื่อไม่ไล่ตาม
     * symlink แต่ hardlink ไม่มี "ตัวลิงก์" แยกจากไฟล์จริงให้ `-h` เว้นไว้ — การ chown
     * มันคือการ chown **ไฟล์ปลายทางเอง** · hardlink ที่ชี้ไป /etc/shadow จึงเท่ากับ
     * ยกไฟล์นั้นทั้งไฟล์ให้ลูกค้า โดยที่ทุกขั้นตอนรายงานว่า "สำเร็จ" ตามปกติ
     *
     * **ไม่ยืนยันข้อความจากด่านของเราเอง** เพราะ GNU tar 1.35 ล้างปลายทางของ hardlink
     * ให้ก่อนเราจะได้เห็น — ทั้ง `../../../etc/shadow`, `/etc/shadow` และ
     * `public_html/../../../../etc/shadow` ถูกย่อเหลือ `etc/shadow` ตั้งแต่ตอน `--list`
     * (ทดลองกับ tar บนเครื่องจริงแล้ว) · `assertContained()` ของเราจึงเป็นตาข่ายชั้นสอง
     * ที่จะทำงานก็ต่อเมื่อ tar เลิกล้างให้ ไม่ใช่ด่านที่กันเรื่องนี้อยู่วันนี้
     *
     * สิ่งที่ยืนยันได้จริงจึงเป็นผลลัพธ์: การกู้คืนต้องล้มเหลวอย่างชัดเจน ของเดิม
     * ต้องอยู่ครบ และต้องไม่มีไฟล์ปลายทางโผล่เข้ามาในเว็บ
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'hardlink.example.com');
    $site = $fixture['site'];

    $evil = $fixture['restore'] . '/hardlink.tar.gz';

    craftArchive($evil, [
        ['name' => 'public_html/', 'type' => '5', 'mode' => 0755],
        ['name' => 'public_html/ขโมย', 'type' => '1', 'link' => '../../../etc/shadow'],
    ]);

    $rejected = restoreRejection($fixture, $evil);

    assertTrue($rejected !== '', 'การกู้คืนต้องล้มเหลว ไม่ใช่รายงานว่าสำเร็จแล้วได้เว็บที่ผิด');

    assertTrue(
        !file_exists($site->docroot() . '/ขโมย'),
        'ต้องไม่มีไฟล์ที่ลิงก์ออกนอกโผล่เข้ามาในเว็บ',
    );

    assertSame(
        '<?php echo "ของจริง";',
        (string) file_get_contents($site->docroot() . '/index.php'),
        'ของเดิมต้องยังอยู่ครบ — ล้มกลางทางต้องไม่ทิ้งเว็บที่ผสมกัน',
    );
});

test('รายการที่ไม่ใช่ไฟล์ ไดเรกทอรี หรือลิงก์ ต้องถูกปฏิเสธ', static function (): void {
    // เว็บไซต์ไม่มีเหตุผลใดที่จะมี device node อยู่ข้างใน · tar ที่รันด้วย root
    // สร้างมันให้ได้จริงด้วย mknod
    $fixture = backupFixture(SiteLayout::Cpanel, 'device.example.com');

    $evil = $fixture['restore'] . '/device.tar.gz';

    craftArchive($evil, [
        ['name' => 'public_html/', 'type' => '5', 'mode' => 0755],
        ['name' => 'public_html/sda', 'type' => '3', 'mode' => 0666],
    ]);

    $rejected = restoreRejection($fixture, $evil);

    assertTrue(
        str_contains($rejected, 'is not a file, directory, or link'),
        "ต้องปฏิเสธ device node ได้: {$rejected}",
    );
});

test('symlink ปกติของเว็บต้องกู้คืนได้ ไม่ใช่ถูกปฏิเสธทั้งไฟล์', static function (): void {
    /*
     * ด่านที่เข้มเกินไปคือด่านที่ถูกปิด · เว็บจริงมี symlink เป็นเรื่องปกติ
     * (`public/storage` ของ Laravel, `node_modules/.bin/*`) — ปฏิเสธทั้ง archive
     * เพราะมีอย่างใดอย่างหนึ่ง แปลว่าปุ่มกู้คืนใช้กับเว็บส่วนใหญ่ไม่ได้เลย
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'symlink.example.com');
    $site = $fixture['site'];

    $archive = $fixture['restore'] . '/symlink.tar.gz';

    craftArchive($archive, [
        ['name' => 'public_html/', 'type' => '5', 'mode' => 0755],
        ['name' => 'public_html/index.php', 'body' => '<?php echo "ของจริง";'],
        ['name' => 'public_html/storage', 'type' => '2', 'link' => '../shared/storage'],
    ]);

    $rejected = restoreRejection($fixture, $archive);

    assertSame('', $rejected, "archive ที่มี symlink ปกติต้องกู้คืนได้ แต่ถูกปฏิเสธ: {$rejected}");
    assertTrue(is_link($site->docroot() . '/storage'), 'symlink ต้องกลับมาเป็น symlink');
    assertTrue(is_file($site->docroot() . '/index.php'), 'ไฟล์เว็บต้องกลับมาด้วย');
});

test('ต้องแตกไฟล์ด้วยสิทธิ์เจ้าของเว็บ พร้อม --no-same-owner --no-same-permissions', static function (): void {
    /*
     * tar ที่รันด้วย root ถือว่าเปิด `--same-owner --same-permissions` โดยปริยาย ·
     * archive ที่ใส่ไฟล์ uid 0 โหมด 4755 มาเองจึงได้ shell setuid root วางไว้ในเว็บ
     * · `chown -Rh` ล้าง setuid ให้โดยบังเอิญ แต่ไม่ทำงานเลยเมื่อ owner ว่าง
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'flags.example.com');
    $site = $fixture['site'];
    $recorder = new RecordingExecutor();

    $backup = $fixture['manager']->backupSite(new RealExecutor(), $site);
    $recorder->commands = [];

    $fixture['manager']->restoreSite($recorder, $site, $backup['path'], $backup['checksum'], 'cust:www-data');

    $extract = '';

    foreach ($recorder->commands as $command) {
        if (str_starts_with($command, 'tar --extract')) {
            $extract = $command;
        }
    }

    assertTrue($extract !== '', 'ต้องมีคำสั่งแตกไฟล์');
    assertTrue(str_contains($extract, '--no-same-owner'), 'ต้องไม่คืนค่าเจ้าของตามที่ฝังมาใน archive');
    assertTrue(str_contains($extract, '--no-same-permissions'), 'ต้องไม่คืนค่าสิทธิ์ตามที่ฝังมาใน archive');

    assertTrue(
        !file_exists($site->docroot() . '/.tar-index'),
        'ใบรายชื่อชั่วคราวต้องถูกลบก่อนแตกไฟล์ ไม่ใช่กลายเป็นไฟล์หนึ่งในเว็บ',
    );
});

/**
 * Executor ที่ทำงานจริงแต่จดคำสั่งไว้ให้ตรวจลำดับ
 *
 * ห่อ `RealExecutor` แทนที่จะสืบทอด (มันเป็น final) — tar/mkdir/rename ยังทำงานจริง
 * เพราะสิ่งที่ต้องตรวจคือ**ลำดับ**ของ chown กับการสลับไดเรกทอรี ซึ่งของปลอมทั้งตัว
 * ตอบไม่ได้ว่าไฟล์ที่ออกมาถูกต้องไหม · ดัก chown ไว้เพราะเทสต์รันโดยไม่มี root
 */
final class RecordingExecutor implements Executor
{
    /** @var list<string> */
    public array $commands = [];

    public bool $failChown = false;

    private RealExecutor $real;

    public function __construct()
    {
        $this->real = new RealExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $this->commands[] = basename($argv[0]) . ' ' . implode(' ', array_slice($argv, 1));

        if (basename($argv[0]) === 'chown') {
            return new ExecResult(
                argv: $argv,
                exitCode: $this->failChown ? 1 : 0,
                stdout: '',
                stderr: $this->failChown ? 'Operation not permitted' : '',
                durationMs: 0,
                simulated: true,
            );
        }

        return $this->real->exec($argv, $timeout, $cwd, $stdin);
    }

    public function rename(string $from, string $to): void
    {
        $this->commands[] = 'rename ' . $from . ' ' . $to;

        $this->real->rename($from, $to);
    }

    public function mode(): \Phpcp\Kernel\Mode
    {
        return $this->real->mode();
    }

    public function path(string $absolutePath): string
    {
        return $this->real->path($absolutePath);
    }

    public function readFile(string $path): string
    {
        return $this->real->readFile($path);
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->real->writeFile($path, $content, $mode);
    }

    public function exists(string $path): bool
    {
        return $this->real->exists($path);
    }

    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->real->makeDirectory($path, $mode);
    }

    public function diskSpace(string $path): array
    {
        return $this->real->diskSpace($path);
    }

    public function realPath(string $path): ?string
    {
        return $this->real->realPath($path);
    }

    public function listDirectory(string $path): array
    {
        return $this->real->listDirectory($path);
    }

    public function stat(string $path): ?array
    {
        return $this->real->stat($path);
    }

    public function copyPath(string $from, string $to): void
    {
        $this->real->copyPath($from, $to);
    }

    public function removePath(string $path): void
    {
        $this->commands[] = 'remove ' . $path;

        $this->real->removePath($path);
    }

    public function changeMode(string $path, int $mode): void
    {
        $this->real->changeMode($path, $mode);
    }

    public function zip(array $sources, string $base, string $archive): array
    {
        return $this->real->zip($sources, $base, $archive);
    }

    public function unzip(string $archive, string $destination): array
    {
        return $this->real->unzip($archive, $destination);
    }

    public function asUser(?string $systemUser, callable $work): array
    {
        return $this->real->asUser($systemUser, $work);
    }

    public function isSimulated(): bool
    {
        return $this->real->isSimulated();
    }

    public function simulatedCommands(): array
    {
        return $this->real->simulatedCommands();
    }
}

// --- ใบแจ้งข้อมูล: สิ่งที่ทำให้สำเนานอกเครื่องอ่านกลับได้ ---------------------

test('ไฟล์สำรองต้องอธิบายตัวเองได้ว่าเป็นของเว็บไหน มาจากเครื่องอะไร', static function (): void {
    /*
     * ไม่มีข้อมูลนี้ = ไฟล์ที่ส่งไปเก็บอีกเครื่องเป็นแค่ .tar.gz ที่ panel ที่นั่น
     * ไม่รู้จัก และไม่มีทางทำให้รู้จักได้ — สำเนานอกเครื่องเขียนได้อย่างเดียว
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'manifest.example.com');
    $executor = new RealExecutor();

    $backup = $fixture['manager']->backupSite($executor, $fixture['site']);
    $manifest = $fixture['manager']->readManifest($executor, $backup['path']);

    assertTrue(is_array($manifest), 'ต้องอ่านใบแจ้งข้อมูลออกมาได้');
    assertSame(BackupManager::MANIFEST_SCHEMA, $manifest['schema'], 'ต้องบอกรุ่นของรูปแบบ');
    assertSame('manifest.example.com', $manifest['domain'], 'ต้องบอกโดเมนต้นทาง');
    assertSame('cust', $manifest['system_user'], 'ต้องบอกผู้ใช้ระบบของต้นทาง');
    assertSame('cpanel', $manifest['layout'], 'ต้องบอกเลย์เอาต์ของต้นทาง');
    assertSame('public_html', $manifest['directory'], 'ต้องบอกชื่อโฟลเดอร์บนสุดใน archive');
    assertTrue((int) $manifest['created_at'] > 0, 'ต้องบอกเวลาที่สร้าง');
    assertTrue(($manifest['hostname'] ?? '') !== '', 'ต้องบอกว่ามาจากเครื่องไหน');
});

test('backup.json ต้องไม่หลุดเข้า docroot ตอนกู้คืน', static function (): void {
    /*
     * หลุดเข้าไปแล้วมันจะถูกเสิร์ฟที่ https://<โดเมน>/backup.json ทันที
     * พร้อมชื่อผู้ใช้ระบบ เส้นทางไฟล์บนเครื่อง และชื่อโฮสต์ของเครื่องต้นทาง
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'leak.example.com');
    $executor = new RealExecutor();
    $site = $fixture['site'];

    $backup = $fixture['manager']->backupSite($executor, $site);

    // ต้องอยู่ใน archive จริง ๆ ก่อน — ไม่งั้นเทสต์นี้ผ่านเพราะไม่มีอะไรให้รั่ว
    assertTrue(
        str_contains(implode("\n", backupContents($backup['path'])), BackupManager::MANIFEST),
        'ใบแจ้งข้อมูลต้องอยู่ใน archive',
    );

    $fixture['manager']->restoreSite($executor, $site, $backup['path'], $backup['checksum'], '');

    assertTrue(
        !file_exists($site->docroot() . '/' . BackupManager::MANIFEST),
        'ใบแจ้งข้อมูลต้องไม่ถูกเขียนลง docroot',
    );
    assertTrue(is_file($site->docroot() . '/index.php'), 'ไฟล์เว็บต้องยังกู้กลับมาครบ');
});

test('นำเข้าไฟล์ที่ไม่มีใบแจ้งข้อมูลต้องถูกปฏิเสธ ไม่ใช่เดาว่าเป็นของใคร', static function (): void {
    /*
     * ไฟล์สำรองที่สร้างก่อนระบบมีใบแจ้งข้อมูล (หรือ .tar.gz ที่ใครก็ไม่รู้วางไว้)
     * ต้องไม่ถูกผูกกับเว็บไซต์ไหนโดยการเดา — ผูกผิดแล้วกดกู้คืนคือเขียนทับเว็บ
     * ด้วยไฟล์ของเว็บอื่น
     */
    $fixture = backupFixture(SiteLayout::Cpanel, 'nomanifest.example.com');
    $executor = new RealExecutor();

    $stray = $fixture['restore'] . '/ของใครก็ไม่รู้.tar.gz';
    exec(sprintf(
        'tar --create --gzip --file %s --directory %s %s',
        escapeshellarg($stray),
        escapeshellarg(dirname($fixture['docroot'])),
        escapeshellarg(basename($fixture['docroot'])),
    ));

    assertSame(
        null,
        $fixture['manager']->readManifest($executor, $stray),
        'ไฟล์ที่ไม่มี backup.json ต้องคืน null ให้ผู้เรียกปฏิเสธ ไม่ใช่คืนข้อมูลมั่ว',
    );
});

test('ชื่อไฟล์ที่รับจากหน้าจอต้องเป็นชื่อล้วนเสมอ — กันการหยิบไฟล์อื่นบนเครื่อง', static function (): void {
    /*
     * ค่านี้ถูกต่อเข้ากับเส้นทางบ้านของลูกค้าแล้วเอาไปแตกทับเว็บ · ยอมให้มี `/` หรือ
     * `..` แม้แต่ตัวเดียวแปลว่าผู้เรียกเลือกได้ว่าจะให้ระบบเอาไฟล์ไหนบนเครื่องมาแตก
     * ทับเว็บที่ให้บริการอยู่
     *
     * ตรวจที่ `validate()` ของ capability จริง ไม่ใช่ที่ตัวช่วย — ด่านที่นับคือด่านที่
     * คำขอเดินผ่านจริง
     */
    $capability = new Phpcp\Agent\Capability\BackupRestore();

    foreach (['../../etc/passwd', 'sub/dir.tar.gz', '', '  ', 'x.php'] as $bad) {
        assertRejects(
            \Phpcp\Agent\ValidationError::class,
            static fn () => $capability->validate(['site_id' => 1, 'file' => $bad, 'confirm' => 'example.com']),
            "ชื่อ '{$bad}' ต้องถูกปฏิเสธตั้งแต่ชั้นตรวจค่า",
        );
    }

    // ชื่อที่ถูกต้องต้องผ่านจริง ไม่ใช่ปฏิเสธทุกอย่างแล้วเทสต์ผ่าน
    $ok = $capability->validate([
        'site_id' => 1,
        'file' => 'example.com-files-20260814-010101-aabbcc.tar.gz',
        'confirm' => 'example.com',
    ]);

    assertSame('example.com-files-20260814-010101-aabbcc.tar.gz', $ok['file'], 'ชื่อที่ถูกต้องต้องผ่าน');
});
