<?php

declare(strict_types=1);

/**
 * ไฟล์สำรองเป็น **ของลูกค้า อยู่ในบ้านของลูกค้า** — PLAN-BACKUP-V2
 *
 * ชุดนี้เฝ้าข้อตกลงที่เป็นเหตุผลทั้งหมดของการรื้อครั้งนี้ · ทุกข้อเคยพังจริงหรือกำลัง
 * จะพังถ้าไม่มีอะไรเฝ้า และทุกข้อล้มเหลวแบบ **"รายงานว่าสำเร็จ"** ทั้งสิ้น:
 *
 *   1. **ไฟล์ต้องไปอยู่ในบ้านของเจ้าของข้อมูล ไม่ใช่พื้นที่ของ panel** — ไม่งั้นลูกค้า
 *      ดาวน์โหลดสำเนาของตัวเองไม่ได้ และขนาดไฟล์ไม่ถูกนับเป็นต้นทุนของใครเลย
 *   2. **ไฟล์ที่สร้างเสร็จต้องเป็นของลูกค้าจริง ๆ** — agent เป็น root ไฟล์ที่มันสร้าง
 *      จึงเป็นของ root:root โหมด 0600 ถ้าไม่สั่ง · ลูกค้าเปิด SFTP เข้ามาแล้วเห็นไฟล์
 *      แต่ดาวน์โหลดไม่ได้ ซึ่งแย่กว่าไม่เห็นเลยเพราะดูเหมือนระบบทำงานปกติ
 *   3. **โควตาต้องกันไว้ก่อนเขียน** — ไฟล์สำรองของเว็บมีขนาดเท่าเว็บทั้งเว็บ การเขียน
 *      จนเต็มแปลว่าเว็บที่ยังให้บริการอยู่เขียนไฟล์ไม่ได้กลางคัน
 *   4. **กู้คืนต้องถามใบแจ้งข้อมูลว่าไฟล์นี้เป็นของเว็บไหน** — โฟลเดอร์เป็นของลูกค้า
 *      เขาเปลี่ยนชื่อไฟล์เองได้ ชื่อจึงไม่ใช่คำสัญญาว่าข้างในเป็นของเว็บนั้น
 */

use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteLayout;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\BackupManager;
use Phpcp\Kernel\Paths;

group('ไฟล์สำรองเป็นของลูกค้า — อยู่ในบ้าน นับในโควตา หยิบเองได้');

/**
 * บ้านผู้ใช้จำลองใต้ temp dir พร้อมไฟล์เว็บจริง
 *
 * @return array{site:Site,users:string,home:string}
 */
function ownershipFixture(string $domain = 'shop.example.com'): array
{
    $root = sys_get_temp_dir() . '/phpcp-owner-' . getmypid() . '-' . bin2hex(random_bytes(4));

    Paths::useUsersDir($root);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $owner = new UserAccount(4, 'cust', SiteLayout::Cpanel, $domain);
    $site = new Site(1, $domain, $owner, '8.4');

    mkdir($site->docroot(), 0755, true);
    file_put_contents($site->docroot() . '/index.php', '<?php echo "ของจริง";');

    return ['site' => $site, 'users' => $root, 'home' => $owner->home()];
}

test('ไฟล์สำรองต้องลงในบ้านของเจ้าของ ไม่ใช่พื้นที่ของ panel', static function (): void {
    /*
     * นี่คือข้อ B1 ทั้งข้อ · ที่เก็บเดิม (`/var/lib/phpcp/backups`) เป็นพื้นที่ของ panel
     * ที่ `SelfProtection` กันไว้ทั้งก้อน — ลูกค้าเอาสำเนาของตัวเองออกมาไม่ได้เลย
     * และต้องเจาะรูใน CP เพื่อให้แม้แต่ผู้ดูแลเปิดดูได้
     */
    $fixture = ownershipFixture();
    $result = (new BackupManager())->backupSite(new RealExecutor(), $fixture['site']);

    assertSame(
        $fixture['home'] . '/backup',
        dirname($result['path']),
        'ไฟล์ต้องอยู่ใน <บ้าน>/backup ของเจ้าของ',
    );

    assertTrue(
        !str_contains($result['path'], '/var/lib/phpcp'),
        'ต้องไม่มีไฟล์สำรองไหนกลับไปอยู่ในพื้นที่ของ panel อีก',
    );

    // ชื่อไฟล์ต้องขึ้นต้นด้วยโดเมน — รายการอ่านจากโฟลเดอร์จริงและจับคู่ไฟล์กับเว็บ
    // จากชื่อ ถ้ารูปแบบเปลี่ยน ไฟล์ทุกไฟล์จะกลายเป็น "ไม่รู้ว่าเป็นของเว็บไหน" ทันที
    assertSame(
        'site',
        BackupFiles::typeOf(basename($result['path'])),
        'นามสกุลต้องบอกได้ว่าเป็นไฟล์เว็บ',
    );
    assertSame(
        'shop.example.com',
        BackupFiles::domainOf(basename($result['path']), ['shop.example.com']),
        'ชื่อไฟล์ต้องจับคู่กับโดเมนของมันได้',
    );
});

test('ไฟล์ที่สร้างเสร็จต้องถูกยกให้เจ้าของ ไม่ใช่ค้างเป็นของ root', static function (): void {
    /*
     * **ข้อนี้คือความต่างระหว่าง "มีไฟล์สำรอง" กับ "ลูกค้าเอาไฟล์สำรองไปใช้ได้"**
     *
     * agent รันเป็น root · tar ที่มันเรียกสร้างไฟล์เป็น root:root โหมด 0600 ·
     * ลูกค้าเปิด SFTP เข้ามาเห็นไฟล์อยู่ในโฟลเดอร์ตัวเองแต่ดาวน์โหลดไม่ได้และลบไม่ได้
     * — ล้มเป้าหมายทั้งหมดของการรื้อครั้งนี้โดยที่ทุกหน้าจอบอกว่าสำเร็จ
     *
     * ตรวจจากคำสั่งจริงที่ถูกสั่ง ไม่ใช่ grep ซอร์ส — เทสต์รันโดยไม่มี root จึง chown
     * จริงไม่ได้ แต่ "สั่งหรือไม่สั่ง" คือสิ่งที่ต้องพิสูจน์
     */
    $fixture = ownershipFixture('own.example.com');
    $recorder = new OwnershipRecordingExecutor();

    $result = (new BackupManager())->backupSite($recorder, $fixture['site'], 'cust:www-data');

    $chowns = array_values(array_filter(
        $recorder->commands,
        static fn (string $c): bool => str_starts_with($c, 'chown '),
    ));

    assertTrue($chowns !== [], 'ต้องมีคำสั่ง chown อย่างน้อยหนึ่งครั้ง');

    assertTrue(
        in_array('chown cust:www-data ' . $fixture['home'] . '/backup', $chowns, true),
        'ต้องตั้งเจ้าของของโฟลเดอร์สำรองด้วย — บัญชีเก่าที่ยังไม่มีโฟลเดอร์นี้จะได้ของ root',
    );

    assertTrue(
        in_array('chown cust:www-data ' . $result['path'], $chowns, true),
        'ต้องตั้งเจ้าของของตัวไฟล์ที่เพิ่งสร้าง',
    );

    assertTrue(
        in_array('chmod 0640 ' . $result['path'], $recorder->commands, true),
        'โหมดต้องเป็น 0640 — 0600 ที่ tar ให้มาแปลว่ากลุ่มของเว็บเซิร์ฟเวอร์อ่านไม่ได้',
    );
});

test('ชื่อไฟล์ที่รับจากผู้เรียกต้องเป็นชื่อล้วนและเป็นไฟล์สำรองจริง', static function (): void {
    /*
     * ค่านี้ถูกต่อเข้ากับเส้นทางบ้านของลูกค้าแล้วเอาไปลบหรือแตกไฟล์ทับเว็บ · ยอมให้มี
     * `/` หรือ `..` แม้แต่ตัวเดียวแปลว่าผู้เรียกเลือกได้ว่าจะให้ระบบไปหยิบไฟล์ไหน
     * บนเครื่อง แล้วสั่งลบมันด้วยสิทธิ์ root
     */
    $bad = [
        '../.ssh/authorized_keys',
        'sub/dir.tar.gz',
        '..',
        '',
        '   ',
        'index.php',
        'notes.txt',
        "evil\0.tar.gz",
    ];

    foreach ($bad as $name) {
        assertRejects(
            Phpcp\Agent\ValidationError::class,
            static fn () => BackupFiles::assertName($name),
            "ชื่อ '{$name}' ต้องถูกปฏิเสธ",
        );
    }

    // ชื่อที่ถูกต้องต้องผ่านจริง ไม่ใช่ปฏิเสธทุกอย่างแล้วเทสต์ผ่าน
    foreach (['a.example.com-files-20260814-010101-aabbcc.tar.gz', 'a.example.com-db-x-20260814-010101-aa.sql.gz'] as $ok) {
        assertSame($ok, BackupFiles::assertName($ok), "ชื่อ '{$ok}' ต้องผ่าน");
    }
});

test('โดเมนที่มีขีดกลางหรือคำว่า files ในตัวต้องยังจับคู่ถูก', static function (): void {
    /*
     * การแยกชื่อไฟล์ด้วยขีดกลางเป็นวิธีที่ดูง่ายและผิดเงียบ ๆ — `my-files-shop.com`
     * เป็นชื่อโดเมนที่ถูกต้อง และไฟล์ของมันจะถูกตัดผิดที่ทันที · จึงเทียบกับรายชื่อ
     * โดเมนจริงของบัญชีแทน และเลือกอันที่ยาวที่สุดที่เข้าเงื่อนไข
     */
    $domains = ['example.com', 'shop.example.com', 'my-files-db.com'];

    assertSame(
        'shop.example.com',
        BackupFiles::domainOf('shop.example.com-files-20260814-010101-aa.tar.gz', $domains),
        'ต้องเลือกโดเมนที่ยาวที่สุดที่ตรง ไม่ใช่ example.com',
    );

    assertSame(
        'my-files-db.com',
        BackupFiles::domainOf('my-files-db.com-db-shopdb-20260814-010101-aa.sql.gz', $domains),
        'โดเมนที่มีคำว่า files/db ในตัวต้องไม่ถูกตัดผิดที่',
    );

    assertSame(
        '',
        BackupFiles::domainOf('ของที่ลูกค้าเอามาเอง.tar.gz', $domains),
        'ไฟล์ที่จับคู่ไม่ได้ต้องคืนค่าว่าง ไม่ใช่เดา',
    );
});

test('รายการต้องอ่านจากโฟลเดอร์จริง และไม่แตะไฟล์ที่ไม่ใช่ไฟล์สำรอง', static function (): void {
    $fixture = ownershipFixture('list.example.com');
    $executor = new RealExecutor();
    $manager = new BackupManager();

    $manager->backupSite($executor, $fixture['site']);

    // ของที่ลูกค้าเอามาวางเอง — ต้องไม่ถูกนับเป็นไฟล์สำรองของระบบ
    file_put_contents($fixture['home'] . '/backup/บันทึกของฉัน.txt', 'ไม่ใช่ไฟล์สำรอง');

    $files = BackupFiles::listFor($executor, $fixture['site']->owner, ['list.example.com']);

    assertSame(1, count($files), 'ต้องเห็นเฉพาะไฟล์ที่เป็นไฟล์สำรองจริง');
    assertSame('list.example.com', $files[0]['domain'], 'ต้องจับคู่กับเว็บได้');
    assertTrue($files[0]['restorable'], 'ไฟล์เว็บที่จับคู่ได้ต้องกู้คืนได้');
    assertTrue($files[0]['bytes'] > 0, 'ต้องบอกขนาดจริงจากดิสก์');
});

test('โฟลเดอร์ที่ยังไม่เคยมีไฟล์สำรองต้องคืนรายการว่าง ไม่ใช่ล้ม', static function (): void {
    // บัญชีที่เพิ่งสร้างยังไม่มีโฟลเดอร์นี้ · หน้าจอต้องขึ้น "ยังไม่มีไฟล์สำรอง"
    // ไม่ใช่ข้อความผิดพลาดที่ทำให้ผู้ใช้คิดว่าระบบพัง
    $fixture = ownershipFixture('empty.example.com');

    assertSame(
        [],
        BackupFiles::listFor(new RealExecutor(), $fixture['site']->owner, ['empty.example.com']),
        'ยังไม่เคยสำรอง = รายการว่าง',
    );
});

/** Executor ที่ทำงานจริงแต่จดคำสั่งไว้ · chown/chmod ถูกดักเพราะเทสต์รันโดยไม่มี root */
final class OwnershipRecordingExecutor implements Executor
{
    /** @var list<string> */
    public array $commands = [];

    private RealExecutor $real;

    public function __construct()
    {
        $this->real = new RealExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $this->commands[] = basename($argv[0]) . ' ' . implode(' ', array_slice($argv, 1));

        if (basename($argv[0]) === 'chown') {
            return new ExecResult(argv: $argv, exitCode: 0, stdout: '', stderr: '', durationMs: 0, simulated: true);
        }

        return $this->real->exec($argv, $timeout, $cwd, $stdin);
    }

    public function changeMode(string $path, int $mode): void
    {
        $this->commands[] = sprintf('chmod %04o %s', $mode, $path);

        $this->real->changeMode($path, $mode);
    }

    public function rename(string $from, string $to): void
    {
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
        $this->real->removePath($path);
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
