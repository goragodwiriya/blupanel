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
        'manager' => new BackupManager($root . '/backups'),
        'docroot' => $site->docroot(),
        'state' => $site->root(),
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

test('ชื่อไฟล์ที่นำเข้าต้องเป็นชื่อล้วน — กันการหยิบไฟล์อื่นบนเครื่องปลายทาง', static function (): void {
    // ค่านี้ถูกต่อเข้ากับเส้นทางของปลายทาง · ยอมให้มี / หรือ .. แปลว่าผู้เรียก
    // เลือกได้ว่าจะให้ไปอ่านไฟล์ไหนบนเครื่องนั้น
    $capability = new Phpcp\Agent\Capability\BackupImport();

    foreach (['../../etc/passwd', 'sub/dir.tar.gz', '', '  '] as $bad) {
        $rejected = false;

        try {
            $capability->validate(['destination_id' => 1, 'remote_name' => $bad]);
        } catch (\Phpcp\Agent\ValidationError) {
            $rejected = true;
        }

        // ชื่อที่ผ่าน validate() ต้องถูกด่านที่สองใน run() จับ — ตรวจด้วยการประกอบเส้นทาง
        if (!$rejected) {
            $method = new ReflectionMethod($capability, 'remotePath');
            $method->setAccessible(true);
            $path = $method->invoke($capability, ['config' => ['path' => '/srv/backups']], basename(trim($bad)));

            $inside = str_starts_with($path, '/srv/backups/')
                && !str_contains(substr($path, strlen('/srv/backups/')), '/')
                && !str_contains($path, '..');

            assertTrue($inside, "ชื่อ '{$bad}' ต้องไม่ทำให้เส้นทางหลุดออกนอกไดเรกทอรีของปลายทาง");
        }
    }
});
