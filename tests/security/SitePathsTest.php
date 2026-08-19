<?php

declare(strict_types=1);

/**
 * ที่เก็บไฟล์เว็บไซต์ที่ตั้งค่าได้ · Domain Pointer · โหมด shared_owner
 *
 * สามเรื่องนี้ทดสอบรวมกันเพราะเป็นความเสี่ยงชุดเดียวกัน — ทั้งหมดคือการยอมให้
 * ค่าจากไฟล์ config กำหนดเส้นทางที่ถูกเขียนลง vhost, FPM pool และ open_basedir
 *
 * ความเสี่ยงที่ต้องกัน:
 *   1. เส้นทางที่มี .. หรืออักขระควบคุม หลุดเข้าไปในไฟล์ตั้งค่า
 *   2. Domain Pointer ชี้ออกนอกขอบเขต แล้วเสิร์ฟ /etc หรือ /root ผ่านเว็บ
 *   3. shared_owner (ปิดการแยกสิทธิ์ระหว่างเว็บ) ถูกเปิดบนเซิร์ฟเวอร์จริงโดยไม่ตั้งใจ
 */

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Kernel\Mode;
use Phpcp\Kernel\Paths;
use Phpcp\Support\Validator;

group('SitePaths — ที่เก็บไฟล์ที่ตั้งค่าได้ Domain Pointer และ shared_owner');

/**
 * Executor ปลอมที่ควบคุมได้ว่า chown "ติด" หรือไม่
 *
 * ต้องปลอมทั้งตัว ไม่ใช้ SandboxExecutor เพราะประเด็นที่ทดสอบคือพฤติกรรมของ
 * filesystem ซึ่ง sandbox จำลอง chown ให้สำเร็จเสมอ จนแยกสองกรณีไม่ออก
 */
class OwnershipProbeExecutor implements Executor
{
    /** @var array<string,int> path => uid ที่ stat จะรายงาน */
    private array $owners = [];

    /** @param bool $chownWorks true = filesystem เก็บเจ้าของไฟล์ได้ (ext4) */
    public function __construct(public readonly bool $chownWorks, public readonly int $siteUid = 1234)
    {
    }

    public function mode(): Mode
    {
        return Mode::Production;
    }

    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $binary = basename($argv[0]);

        if ($binary === 'chown' && $this->chownWorks) {
            $this->owners[(string) end($argv)] = $this->siteUid;
        }

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: $binary === 'id' ? (string) $this->siteUid : '',
            stderr: '',
            durationMs: 0,
            simulated: true,
        );
    }

    public function stat(string $path): ?array
    {
        return [
            'type' => 'file',
            'size' => 0,
            'mode' => 0600,
            'mtime' => 0,
            // ยังไม่เคย chown ติด = เจ้าของเป็น root เหมือนตอนสร้างไฟล์
            'uid' => $this->owners[$path] ?? 0,
            'gid' => 0,
            'link' => null,
        ];
    }

    public function readFile(string $path): string
    {
        return '';
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function makeDirectory(string $path, int $mode = 0755): void
    {
    }

    public function diskSpace(string $path): array
    {
        return ['total' => 0, 'free' => 0];
    }

    public function realPath(string $path): ?string
    {
        return $path;
    }

    public function listDirectory(string $path): array
    {
        return [];
    }

    public function rename(string $from, string $to): void
    {
    }

    public function copyPath(string $from, string $to): void
    {
    }

    public function removePath(string $path): void
    {
        unset($this->owners[$path]);
    }

    public function changeMode(string $path, int $mode): void
    {
    }

    public function zip(array $sources, string $base, string $archive): array
    {
        return ['entries' => 0, 'bytes' => 0];
    }

    public function unzip(string $archive, string $destination): array
    {
        return ['entries' => 0, 'bytes' => 0, 'skipped' => 0];
    }

    public function asUser(?string $systemUser, callable $work): array
    {
        return $work();
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function simulatedCommands(): array
    {
        return [];
    }
}

function pointerSite(string $docrootOverride = ''): Site
{
    return new Site(
        id: 42,
        domain: 'example.test',
        owner: new UserAccount(7, 'sitefiles'),
        phpVersion: '8.4',
        docrootOverride: $docrootOverride,
    );
}

function pointerProvisioner(bool $sharedOwner): SiteProvisioner
{
    $templates = new Template(PHPCP_ROOT . '/templates');

    return new SiteProvisioner(new ApacheDriver($templates), new FpmManager($templates), $sharedOwner);
}

/** คืนค่า sites.dir กลับเป็นค่าเริ่มต้นเสมอ ไม่ให้เทสต์รั่วใส่กัน */
function withSitesDir(string $dir, callable $fn): void
{
    $previous = Paths::sitesDir();

    try {
        Paths::useSitesDir($dir);
        $fn();
    } finally {
        Paths::useSitesDir($previous);
    }
}

/** เช่นเดียวกันแต่สำหรับบ้านของผู้ใช้ ซึ่งเป็นที่อยู่จริงของไฟล์เว็บตั้งแต่ migration 0006 */
function withUsersDir(string $dir, callable $fn): void
{
    $previous = Paths::usersDir();

    try {
        Paths::useUsersDir($dir);
        $fn();
    } finally {
        Paths::useUsersDir($previous);
    }
}

// --- sites.dir --------------------------------------------------------------

test('sites.dir ที่ไม่ใช่เส้นทางสัมบูรณ์ต้องถูกปฏิเสธตั้งแต่ตอนอ่าน config', static function (): void {
    foreach (['srv/sites', '../sites', 'C:\\sites', ''] as $bad) {
        if ($bad === '') {
            continue;
        }

        assertRejects(
            RuntimeException::class,
            static fn () => withSitesDir($bad, static fn () => null),
            "sites.dir ต้องปฏิเสธค่า {$bad}",
        );
    }
});

test('sites.dir ที่มี .. ต้องถูกปฏิเสธ — กัน DocumentRoot หลุดออกนอกที่ตั้งใจ', static function (): void {
    foreach (['/srv/phpcp/../../etc', '/srv/../root', '/..'] as $bad) {
        assertRejects(
            RuntimeException::class,
            static fn () => withSitesDir($bad, static fn () => null),
            "sites.dir ต้องปฏิเสธค่า {$bad}",
        );
    }
});

test('เส้นทางของเว็บไซต์ทุกอย่างเลื่อนตาม sites.users_dir พร้อมกัน', static function (): void {
    withUsersDir('/mnt/Server/htdocs', static function (): void {
        $site = pointerSite();

        assertSame('/mnt/Server/htdocs/sitefiles/.phpcp/example.test', $site->root(), 'ที่เก็บของประจำเว็บ');
        assertSame('/mnt/Server/htdocs/sitefiles/public_html', $site->docroot(), 'docroot');
        assertSame('/mnt/Server/htdocs/sitefiles/logs/example.test/error.log', $site->errorLog(), 'error log');
        assertSame('/mnt/Server/htdocs/sitefiles/.phpcp/example.test/tmp', $site->tmpDir(), 'tmp');

        // log ของ PHP อยู่ระดับบัญชี ไม่ใช่ระดับเว็บ เพราะ pool เดียวรับหลายเว็บ
        assertSame('/mnt/Server/htdocs/sitefiles/logs/php-8.4-error.log', $site->phpErrorLog(), 'log ของ PHP');
    });

    assertSame(
        Paths::DEFAULT_USERS_DIR . '/sitefiles/.phpcp/example.test',
        pointerSite()->root(),
        'คืนค่าเดิมหลังจบ',
    );
});

test('ค่าเริ่มต้นคือ /home/<ผู้ใช้>/public_html — รูปที่ผู้ดูแลยูนิกซ์และลูกค้าคาดหวัง', static function (): void {
    /*
     * เดิมเป็น `/srv/phpcp/users/<ผู้ใช้>/domains/<โดเมน>/public` ซึ่งซ้อนลึกสามชั้นและ
     * ไม่ตรงกับความคาดหวังของใครเลย — ผู้ดูแลที่ ssh เข้าเครื่องหาบ้านผู้ใช้ที่ /home
     * และลูกค้าที่ย้ายมาจาก cPanel/DirectAdmin หา public_html · สคริปต์ deploy ของเขา
     * เขียนเส้นทางเต็มไว้ทุกตัวและต้องแก้ทั้งหมดถ้าเส้นทางไม่ตรงกับมาตรฐาน
     */
    assertSame('/home', Paths::DEFAULT_USERS_DIR, 'บ้านผู้ใช้อยู่ที่เดียวกับทั้งระบบยูนิกซ์');
    assertSame('/home/sitefiles/public_html', pointerSite()->docroot(), 'docroot เริ่มต้น');
});

test('เว็บทุกแห่งของเจ้าของคนเดียวกันใช้บัญชีระบบและ pool เดียวกัน', static function (): void {
    // นี่คือหัวใจของ migration 0006 · ก่อนหน้านี้ลูกค้าที่มี 5 เว็บได้ 5 uid และ 5 pool
    // ที่ไม่เกี่ยวข้องกันเลยทั้งที่เป็นคนเดียวกัน
    $owner = new UserAccount(7, 'sitefiles');

    $shop = new Site(id: 1, domain: 'shop.test', owner: $owner, phpVersion: '8.4');
    $blog = new Site(id: 2, domain: 'blog.test', owner: $owner, phpVersion: '8.4');
    $legacy = new Site(id: 3, domain: 'old.test', owner: $owner, phpVersion: '7.4');

    assertSame($shop->systemUser(), $blog->systemUser(), 'เว็บของเจ้าของคนเดียวกันใช้ uid เดียวกัน');
    assertSame($shop->fpmSocket(), $blog->fpmSocket(), 'เว็บที่ใช้ PHP เวอร์ชันเดียวกันใช้ socket เดียวกัน');
    assertSame($shop->fpmPoolFile(), $blog->fpmPoolFile(), 'และใช้ไฟล์ pool เดียวกัน');
    assertTrue($shop->sharesPoolWith($blog), 'ต้องรู้ตัวว่าใช้ pool ร่วมกัน');

    // คนละเวอร์ชัน = คนละ pool แต่ยังเป็น uid เดียวกัน
    assertSame($shop->systemUser(), $legacy->systemUser(), 'เวอร์ชัน PHP ต่างกันไม่ได้เปลี่ยน uid');
    assertTrue($shop->fpmSocket() !== $legacy->fpmSocket(), 'คนละเวอร์ชันต้องคนละ socket');
    assertTrue(!$shop->sharesPoolWith($legacy), 'คนละเวอร์ชันต้องไม่ถือว่าใช้ pool ร่วมกัน');

    // แต่ไฟล์ของแต่ละเว็บยังแยกโฟลเดอร์กันอยู่
    assertTrue($shop->root() !== $blog->root(), 'แต่ละเว็บยังมีโฟลเดอร์ของตัวเอง');

    // และลูกค้าคนละรายต้องแยกขาดจากกันทุกอย่าง
    $other = new Site(id: 4, domain: 'other.test', owner: new UserAccount(8, 'otheruser'), phpVersion: '8.4');
    assertTrue($shop->systemUser() !== $other->systemUser(), 'ลูกค้าคนละรายต้องคนละ uid');
    assertTrue($shop->fpmSocket() !== $other->fpmSocket(), 'ลูกค้าคนละรายต้องคนละ pool');
});

// --- Domain Pointer ---------------------------------------------------------

test('Domain Pointer เปลี่ยนเฉพาะ docroot — log และ tmp ยังอยู่ที่เดิม', static function (): void {
    $site = pointerSite('/mnt/Server/htdocs/legacy-project');

    assertSame('/mnt/Server/htdocs/legacy-project', $site->docroot(), 'docroot ชี้ไปโฟลเดอร์เดิม');
    assertSame('/home/sitefiles/.phpcp/example.test', $site->root(), 'ที่เก็บของประจำเว็บไม่เปลี่ยน');
    assertSame('/home/sitefiles/logs/example.test/error.log', $site->errorLog(), 'log ยังแยกตามเว็บ');
    assertSame('/home/sitefiles/.phpcp/example.test/tmp', $site->tmpDir(), 'tmp ยังแยกตามเว็บ');
});

test('เส้นทางที่ชี้ออกนอกขอบเขตต้องถูกปฏิเสธ — กันเสิร์ฟทั้งเครื่องผ่าน vhost', static function (): void {
    $allowed = ['/srv/phpcp/sites', '/mnt/Server/htdocs'];

    foreach (['/etc', '/root/.ssh', '/', '/var/lib/phpcp', '/home/poo'] as $outside) {
        assertRejects(
            ValidationError::class,
            static fn () => Validator::absolutePathWithin($outside, $allowed),
            "ต้องปฏิเสธ docroot {$outside}",
        );
    }
});

test('ขอบเขตต้องเทียบทั้งส่วน ไม่ใช่แค่ขึ้นต้นตรงกัน', static function (): void {
    // /mnt/Server/htdocs-evil ขึ้นต้นด้วย /mnt/Server/htdocs แต่เป็นคนละไดเรกทอรี
    assertRejects(
        ValidationError::class,
        static fn () => Validator::absolutePathWithin('/mnt/Server/htdocs-evil', ['/mnt/Server/htdocs']),
        'ต้องไม่ยอมให้ชื่อที่ขึ้นต้นเหมือนกันผ่านไปได้',
    );

    assertSame(
        '/mnt/Server/htdocs/shop',
        Validator::absolutePathWithin('/mnt/Server/htdocs/shop/', ['/mnt/Server/htdocs']),
        'เส้นทางที่อยู่ในขอบเขตจริงต้องผ่านและถูกตัด / ท้ายทิ้ง',
    );
});

test('docroot ที่มี .. หรืออักขระที่ใช้ในไฟล์ตั้งค่าไม่ได้ ต้องถูกปฏิเสธ', static function (): void {
    $payloads = [
        '/mnt/Server/htdocs/../../etc',
        "/mnt/Server/htdocs/x\nRequire all granted",
        "/mnt/Server/htdocs/x\r\n</Directory>",
        "/mnt/Server/htdocs/x\x00",
        '/mnt/Server/htdocs/"quoted"',
        'relative/path',
    ];

    foreach ($payloads as $payload) {
        assertRejects(
            ValidationError::class,
            static fn () => Validator::absolutePath($payload),
            'ต้องปฏิเสธ docroot ที่แทรกค่าอันตราย: ' . addcslashes($payload, "\0..\37"),
        );
    }
});

test('Domain Pointer รับชื่อโฟลเดอร์ย่อยแล้วต่อกับ pointer root ให้เอง', static function (): void {
    $allowed = ['/srv/phpcp/sites', '/mnt/Server/htdocs'];
    $pointers = ['/mnt/Server/htdocs'];

    assertSame(
        '/mnt/Server/htdocs/my-project',
        Validator::resolvePointerDocroot('my-project', $allowed, $pointers),
        'ชื่อโฟลเดอร์อย่างเดียวต้องต่อกับ root',
    );
    assertSame(
        '/mnt/Server/htdocs/shop/public',
        Validator::resolvePointerDocroot('shop/public/', $allowed, $pointers),
        'รับเส้นทางย่อยซ้อนและตัด / ท้าย',
    );
    assertSame(
        '/mnt/Server/htdocs/legacy',
        Validator::resolvePointerDocroot('/mnt/Server/htdocs/legacy', $allowed, $pointers),
        'path เต็มยังใช้ได้เหมือนเดิม',
    );
    assertSame(
        '',
        Validator::resolvePointerDocroot('', $allowed, $pointers),
        'ว่าง = ไม่ชี้ (สร้างโฟลเดอร์ใหม่)',
    );
});

test('Domain Pointer หลาย root ต้องระบุโฟลเดอร์แม่ — และกัน .. ในชื่อย่อย', static function (): void {
    $allowed = ['/srv/phpcp/sites', '/mnt/Server/htdocs', '/data/webs'];
    $pointers = ['/mnt/Server/htdocs', '/data/webs'];

    assertRejects(
        ValidationError::class,
        static fn () => Validator::resolvePointerDocroot('shop', $allowed, $pointers),
        'หลาย root โดยไม่เลือกต้องถูกปฏิเสธ',
    );

    assertSame(
        '/data/webs/shop',
        Validator::resolvePointerDocroot('shop', $allowed, $pointers, '/data/webs'),
        'เลือก root แล้วต้องต่อถูกที่',
    );

    assertRejects(
        ValidationError::class,
        static fn () => Validator::resolvePointerDocroot('../etc', $allowed, ['/mnt/Server/htdocs']),
        'ชื่อย่อยที่มี .. ต้องถูกปฏิเสธ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => Validator::resolvePointerDocroot('shop', $allowed, $pointers, '/etc'),
        'root ที่ไม่อยู่ใน pointer_roots ต้องถูกปฏิเสธ',
    );
});

test('vhost และ FPM pool ต้องใช้เส้นทางที่ชี้ไป ไม่ใช่เส้นทางปกติ', static function (): void {
    $templates = new Template(PHPCP_ROOT . '/templates');
    $site = pointerSite('/mnt/Server/htdocs/legacy-project');
    $executor = new OwnershipProbeExecutor(chownWorks: true);

    $vhost = (new ApacheDriver($templates))->renderVhost($site, $executor);
    assertTrue(
        str_contains($vhost, 'DocumentRoot /mnt/Server/htdocs/legacy-project'),
        'vhost ต้องชี้ไปยังโฟลเดอร์ที่กำหนด',
    );

    // pool ใช้ร่วมกันทั้งบัญชี open_basedir จึงต้องครอบ**ทั้ง**บ้านของเจ้าของและโฟลเดอร์
    // ที่ Domain Pointer ชี้ไป · ถ้ามีแต่บ้าน เว็บที่ชี้ออกไปข้างนอกจะเปิดไฟล์ตัวเองไม่ได้
    // ถ้ามีแต่โฟลเดอร์ที่ชี้ไป เว็บพี่น้องที่อยู่ในบ้านตามปกติจะพังแทน
    $pool = (new FpmManager($templates))->renderPool(
        $site,
        'www-data',
        $executor,
        ['/mnt/Server/htdocs/legacy-project'],
    );

    assertTrue(
        str_contains($pool, 'open_basedir] = /home/sitefiles:/mnt/Server/htdocs/legacy-project:'),
        'open_basedir ต้องมีทั้งบ้านของเจ้าของและโฟลเดอร์ที่ชี้ไป',
    );
    assertTrue(
        !str_contains($pool, ':/mnt/Server/htdocs:'),
        'open_basedir ต้องไม่กว้างจนเห็นโปรเจกต์อื่นในโฟลเดอร์แม่เดียวกัน',
    );
    assertTrue(
        !str_contains($pool, '/home:'),
        'open_basedir ต้องไม่กว้างจนเห็นบ้านของลูกค้ารายอื่น',
    );
});

// --- shared_owner ต้อง fail-closed ------------------------------------------

test('shared_owner ต้องถูกปฏิเสธเมื่อ filesystem เก็บเจ้าของไฟล์ได้จริง', static function (): void {
    // นี่คือกรณีของเซิร์ฟเวอร์จริงทุกเครื่อง (ext4/xfs/btrfs) — ต้องหยุดทำงาน
    // ไม่ใช่ทำงานต่อแบบไม่มีการแยกสิทธิ์ระหว่างเว็บ
    assertRejects(
        ExecutionFailed::class,
        static fn () => pointerProvisioner(sharedOwner: true)
            ->setOwnership(new OwnershipProbeExecutor(chownWorks: true), pointerSite()),
        'เปิด shared_owner บน filesystem ที่รองรับ ownership ต้องล้มทันที',
    );
});

test('shared_owner ทำงานได้เฉพาะเมื่อ chown ไม่ติดจริง ๆ (NTFS/exFAT)', static function (): void {
    $executor = new OwnershipProbeExecutor(chownWorks: false);

    pointerProvisioner(sharedOwner: true)->setOwnership($executor, pointerSite());

    assertTrue(true, 'filesystem ที่เก็บ ownership ไม่ได้ ต้องผ่านโดยข้ามการ chown');
});

test('ค่าเริ่มต้นต้อง chown เสมอ — การแยกสิทธิ์ต้องไม่หายไปเงียบ ๆ', static function (): void {
    $executor = new class(chownWorks: true) extends OwnershipProbeExecutor {
        /** @var list<string> */
        public array $commands = [];

        public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
        {
            $this->commands[] = implode(' ', $argv);

            return parent::exec($argv, $timeout, $cwd, $stdin);
        }
    };

    pointerProvisioner(sharedOwner: false)->setOwnership($executor, pointerSite());

    assertSame(
        ['/usr/bin/chown -R sitefiles:www-data /home/sitefiles/.phpcp/example.test',
         '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/public_html',
         '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/logs/example.test',
         '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/backup'],
        $executor->commands,
        'โหมดปกติต้อง chown บ้านของเว็บไซต์',
    );
});

test('Domain Pointer ต้อง chown โฟลเดอร์ปลายทางด้วย ไม่งั้น FPM เขียนไฟล์ไม่ได้', static function (): void {
    $executor = new class(chownWorks: true) extends OwnershipProbeExecutor {
        /** @var list<string> */
        public array $commands = [];

        public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
        {
            $this->commands[] = implode(' ', $argv);

            return parent::exec($argv, $timeout, $cwd, $stdin);
        }
    };

    pointerProvisioner(sharedOwner: false)
        ->setOwnership($executor, pointerSite('/mnt/Server/htdocs/legacy-project'));

    assertSame(
        [
            '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/.phpcp/example.test',
            '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/public_html',
            '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/logs/example.test',
            '/usr/bin/chown -R sitefiles:www-data /home/sitefiles/backup',
            '/usr/bin/chown -R sitefiles:www-data /mnt/Server/htdocs/legacy-project',
        ],
        $executor->commands,
        'ต้อง chown ทั้งบ้านของเว็บไซต์และโฟลเดอร์ที่ชี้ไป',
    );
});

test('Domain Pointer ต้องปิดอยู่จนกว่าผู้ดูแลจะเปิดเอง — ไม่มีการเติมให้', static function (): void {
    /*
     * เดิม `docrootRoots()` ใส่ `Paths::sitesDir()` เป็นรายการแรกเสมอ · Domain Pointer
     * จึงถูกเปิดบนทุกเครื่องโดยไม่มีใครขอ — ช่อง "โฟลเดอร์แม่" โผล่ในหน้าสร้างเว็บของ
     * เซิร์ฟเวอร์จริงทุกเครื่อง ชี้ไปยัง `sites.dir` ซึ่งเป็นที่เก็บของเลย์เอาต์เก่าที่
     * migration 0006 เลิกใช้ไปแล้วด้วยซ้ำ
     *
     * การยอมให้ vhost เสิร์ฟไฟล์จากนอกบ้านของผู้ใช้เป็นการผ่อนขอบเขต ต้องมาจากการ
     * ตัดสินใจของผู้ดูแล ไม่ใช่ค่าที่ติดมาเอง
     */
    $load = static function (array $roots): array {
        $root = sys_get_temp_dir() . '/phpcp-pointer-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($root . '/etc', 0750, true);
        register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

        // layout=portable บังคับให้อ่าน config ในโฟลเดอร์นี้ ไม่ใช่ /etc/phpcp ของเครื่องที่รันเทสต์
        file_put_contents(
            $root . '/etc/config.php',
            "<?php\nreturn " . var_export([
                'layout' => 'portable',
                'sites' => ['pointer_roots' => $roots],
            ], true) . ";\n",
        );

        // PHPCP_CONFIG ชนะทุกอย่างใน Config::locate() — จำเป็นเพราะ /etc/phpcp/config.php
        // ของเครื่องที่รันเทสต์มาก่อนเสมอ เทสต์จะอ่านคอนฟิกจริงแทนของที่เพิ่งเขียน
        $previous = getenv('PHPCP_CONFIG');
        putenv('PHPCP_CONFIG=' . $root . '/etc/config.php');

        try {
            return Phpcp\Kernel\Config::load($root)->docrootRoots();
        } finally {
            $previous === false ? putenv('PHPCP_CONFIG') : putenv('PHPCP_CONFIG=' . $previous);
        }
    };

    assertSame([], $load([]), 'ไม่ได้ตั้งค่า = ปิดฟีเจอร์ ไม่ใช่เปิดให้ sites.dir');
    assertSame(['/mnt/Server/htdocs'], $load(['/mnt/Server/htdocs']), 'ต้องได้เฉพาะที่ระบุ ไม่มีรายการแถม');

    // ตัวติดตั้งต้องไม่เติมให้เองด้วย
    $installer = (string) file_get_contents(PHPCP_ROOT . '/install.sh');
    assertTrue(
        !str_contains($installer, 'POINTER_ROOTS="$SITES_DIR"'),
        'ตัวติดตั้งต้องไม่ตั้ง pointer_roots ให้เองเมื่อผู้ดูแลไม่ได้ระบุ',
    );
});

// --- ปุ่มซ่อมสิทธิ์ไฟล์ (site.reset_owner) ----------------------------------

/**
 * เว็บหนึ่งเว็บพร้อมบัญชีเจ้าของจริงในฐานข้อมูล + sandbox ที่ map ทุกเส้นทางลงโฟลเดอร์ชั่วคราว
 *
 * @return array{db:Phpcp\Kernel\Db,site:int,executor:Phpcp\Agent\Executor\SandboxExecutor,context:Phpcp\Agent\Context,owner:UserAccount}
 */
function resetOwnerFixture(string $username, string $domain): array
{
    $db = migratedDb();
    $users = new Phpcp\Domain\UserRepository($db);
    $now = time();

    $ownerId = $users->createHostingAccount($username, 'Reset-Owner-Password-11', $username.'@example.com');
    $db->update('users', ['system_user' => $username, 'main_domain' => $domain], ['id' => $ownerId]);

    $siteId = $db->insert('sites', [
        'primary_domain' => $domain,
        'docroot' => '',
        'php_version' => '8.4',
        'owner_user_id' => $ownerId,
        'docroot_override' => '',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $prefix = sys_get_temp_dir().'/phpcp-reset-'.getmypid().'-'.bin2hex(random_bytes(4));
    register_shutdown_function(static fn () => exec('rm -rf '.escapeshellarg($prefix)));

    return [
        'db' => $db,
        'site' => $siteId,
        'executor' => new Phpcp\Agent\Executor\SandboxExecutor($prefix),
        'context' => new Phpcp\Agent\Context(
            new Phpcp\Agent\Actor(1, 'tester', Phpcp\Security\Permissions::SUPERADMIN, '127.0.0.1', 'test'),
            Phpcp\Kernel\Config::load(PHPCP_ROOT),
            $db,
        ),
        'owner' => new UserAccount($ownerId, $username, null, $domain),
    ];
}

test('reset owner ต้องสร้างโฟลเดอร์ที่หายไปให้ ไม่ใช่ฟ้องว่าไม่พบ', static function (): void {
    /*
     * อาการจริง: ลบบัญชีเก่าแล้วโฟลเดอร์ไม่หาย → สร้างบัญชีใหม่ → ย้ายโฟลเดอร์เดิม
     * มาเป็นบ้านของบัญชีใหม่ด้วยมือ → กด reset owner แล้วได้
     * "Website directory not found: /home/service/.phpcp/gcms.in.th"
     *
     * สถานะแบบนี้คือ**เหตุผลที่ปุ่มนี้มีอยู่** ไม่ใช่เหตุผลที่จะปฏิเสธ — ปุ่มซ่อมที่
     * ทำงานได้เฉพาะตอนที่ไม่มีอะไรเสีย ไม่ใช่ปุ่มซ่อม · ทางแก้เดียวที่เหลือคือ
     * ssh เข้าเครื่องไป mkdir เอง ซึ่งเป็นสิ่งที่ panel ต้องทำแทนได้อยู่แล้ว
     */
    $fixture = resetOwnerFixture('resetowner', 'reset.example.com');
    $executor = $fixture['executor'];
    $owner = $fixture['owner'];

    // มีแต่บ้าน — ไม่มี .phpcp/<domain>, public_html, logs/<domain> เลยสักอัน
    $executor->makeDirectory($executor->path($owner->home()), 0750);

    $result = (new Phpcp\Agent\Capability\SiteResetOwner())->run(
        ['site_id' => $fixture['site'], 'fix_permissions' => false],
        $executor,
        $fixture['context'],
    );

    $site = (new Phpcp\Domain\SiteRepository($fixture['db']))->load($fixture['site']);

    foreach ([$site->root(), $site->docroot(), $site->logDir(), $site->backupDir()] as $dir) {
        assertTrue(
            $executor->exists($executor->path($dir)),
            "ต้องสร้าง {$dir} ให้ระหว่างซ่อม",
        );
    }

    assertTrue(
        in_array($site->root(), $result['created'], true),
        'ต้องรายงานว่าโฟลเดอร์ไหนหายไปบ้าง ไม่ใช่ซ่อมเงียบ ๆ — ได้: '.implode(', ', $result['created']),
    );
    assertTrue(
        str_contains($result['message'], 'missing director'),
        'ข้อความที่ผู้ใช้เห็นต้องบอกว่ามีการสร้างโฟลเดอร์ที่หายไป — ได้: '.$result['message'],
    );
});

test('reset owner ต้องยังปฏิเสธเมื่อบ้านของบัญชีไม่มีอยู่จริง', static function (): void {
    /*
     * บ้านหายทั้งก้อน = ยังไม่เคยมีบัญชีระบบให้เจ้าของรายนี้เลย · mkdir -p ตรงนี้จะ
     * "ตอบ" ด้วยการวางโฟลเดอร์ของ root ไว้ในที่ที่ควรเป็นบ้านของผู้ใช้ แล้ว chown
     * ถัดไปก็ล้มด้วยชื่อผู้ใช้ที่ไม่มีอยู่จริงอยู่ดี — บอกสาเหตุตั้งแต่ยังไม่แตะอะไรดีกว่า
     */
    $fixture = resetOwnerFixture('nohomeowner', 'nohome.example.com');

    assertRejects(
        ValidationError::class,
        static fn () => (new Phpcp\Agent\Capability\SiteResetOwner())->run(
            ['site_id' => $fixture['site'], 'fix_permissions' => false],
            $fixture['executor'],
            $fixture['context'],
        ),
        'บ้านที่ไม่มีอยู่จริงต้องไม่ถูกสร้างขึ้นมาเอง',
    );

    assertTrue(
        !$fixture['executor']->exists($fixture['executor']->path($fixture['owner']->home())),
        'ต้องไม่สร้างบ้านให้เองระหว่างที่ปฏิเสธ',
    );
});
