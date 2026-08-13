<?php

declare(strict_types=1);

/**
 * ไฟล์ตั้งค่าเพิ่มเติมของ BIND9 — ขอบเขตที่พลาดแล้ว DNS ล่มทั้งเครื่อง
 *
 * ## ทำไมขอบเขตนี้ต้องมีชุดเทสต์ของตัวเอง
 *
 * ไฟล์ส่วนเสริมของบริการอื่นถูกผูกด้วย include ที่ทนไฟล์หาย (`IncludeOptional` ของ
 * Apache, `!include_try` ของ Dovecot) หรือไม่ใช้ include เลย (Postfix ผนวกลงท้าย
 * `main.cf`) · **BIND ไม่มีทั้งสองทาง** — `include` ของมันเข้มงวด ไฟล์ที่ถูกอ้างแต่ไม่มี
 * อยู่ทำให้ named ไม่สตาร์ต และมันไปโผล่ตอนรีบูตโดยไม่มีใครเฝ้า
 *
 * ชุดนี้จึงเฝ้าสองคุณสมบัติที่ไม่มีในขอบเขตอื่น:
 *
 *   1. **ไม่มีไฟล์ ต้องไม่มีบรรทัด include** — เครื่องที่ไม่เคยใช้ฟีเจอร์นี้ต้องไม่มี
 *      อะไรให้พังเลย และไฟล์ที่ถูกลบด้วยมือต้องถูกถอด include ออกให้เองรอบถัดไป
 *   2. **การคืนค่าต้องคืนสองไฟล์พร้อมกัน** — RollbackGuard *ลบ* ไฟล์ทิ้งเมื่อเดิมไม่มี
 *      ไฟล์นั้น แล้วสั่ง `systemctl reload-or-restart` ต่อทันที · คืนแค่ไฟล์ส่วนเสริม
 *      แปลว่ากลไกที่มีไว้กันเครื่องพังจะกลายเป็นตัวที่ทำ DNS ทั้งเครื่องล่มเสียเอง
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\DnsConfigRead;
use Phpcp\Agent\Capability\DnsCustomConfig;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Mode;
use Phpcp\Security\Permissions;

group('BindCustomConfig — ไฟล์ตั้งค่าเพิ่มเติมของ BIND9');

/**
 * Executor สำหรับเทสต์ — จำ "เนื้อไฟล์" ที่ถูกเขียน และตอบเรื่องการมีอยู่ของไฟล์ตามที่
 * เทสต์กำหนด ไม่ใช่ตามดิสก์ของเครื่องที่รันเทสต์
 *
 * `DryRunExecutor` บันทึกแค่ชื่อไฟล์กับขนาด และถามดิสก์จริงว่ามีไฟล์ไหม — ทั้งสองอย่าง
 * ใช้ตรวจเรื่องนี้ไม่ได้ เพราะสิ่งที่ต้องพิสูจน์คือ *เนื้อ* `named.conf.local` เปลี่ยนไป
 * ตามการมีอยู่ของไฟล์ส่วนเสริม ซึ่งบนเครื่องที่รันเทสต์ไม่มีทั้งสองไฟล์อยู่แล้ว
 */
final class BindFakeExecutor implements Executor
{
    /** @var array<string,string> เนื้อไฟล์ที่ถูกเขียน เรียงตามลำดับการเขียน */
    public array $written = [];

    /** @var array<string,string> ไฟล์ที่ถือว่ามีอยู่แล้วก่อนเริ่มทำงาน */
    private array $files;

    /**
     * เครื่องมือของ BIND ถือว่ามีอยู่เสมอ — `BinaryPath::resolve()` ถามผ่าน `exists()`
     * และมันคือคำถามว่า "ติดตั้งแพ็กเกจแล้วหรือยัง" ไม่ใช่เรื่องที่ชุดนี้ตรวจ
     * (`BinaryPathTest` ตรึงเรื่องนั้นไว้แยกอยู่แล้ว)
     */
    private const BINARIES = [
        '/usr/bin/named-checkzone', '/usr/bin/named-checkconf', '/usr/sbin/rndc',
    ];

    /** @param array<string,string> $existing เส้นทาง => เนื้อไฟล์ */
    public function __construct(array $existing = [])
    {
        $this->files = $existing + array_fill_keys(self::BINARIES, '');
    }

    public function mode(): Mode
    {
        return Mode::DryRun;
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        return new ExecResult(argv: $argv, exitCode: 0, stdout: '', stderr: '', durationMs: 0, simulated: true);
    }

    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function readFile(string $path): string
    {
        return $this->files[$path] ?? '';
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->written[$path] = $content;
        $this->files[$path] = $content;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
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
        return isset($this->files[$path]) ? $path : null;
    }

    public function listDirectory(string $path): array
    {
        return [];
    }

    public function stat(string $path): ?array
    {
        if (!isset($this->files[$path])) {
            return null;
        }

        return [
            'type' => 'file', 'size' => strlen($this->files[$path]), 'mode' => 0644,
            'mtime' => time(), 'uid' => 0, 'gid' => 0, 'link' => null,
        ];
    }

    public function rename(string $from, string $to): void
    {
        $this->files[$to] = $this->files[$from] ?? '';
        unset($this->files[$from]);
    }

    public function copyPath(string $from, string $to): void
    {
        $this->files[$to] = $this->files[$from] ?? '';
    }

    public function removePath(string $path): void
    {
        unset($this->files[$path]);
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

/**
 * Config สำหรับเทสต์ชุดนี้ — เหมือน `dnsTestConfig()` แต่ชี้ `templates/` กลับมาที่ repo
 *
 * `Paths::templates()` คือ `<root>/templates` และ root ของ config ชั่วคราวเป็นไดเรกทอรี
 * ว่าง · ถ้าไม่ผูกกลับมา `seed()` จะคืนค่าว่างเงียบ ๆ แล้วเทสต์ไฟล์ตั้งต้นจะผ่านโดยไม่ได้
 * ตรวจอะไรเลย
 */
function bindTestConfig(array $dnsOverrides = []): Config
{
    $root = sys_get_temp_dir() . '/phpcp-bind-cfg-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root . '/etc', 0700, true);
    symlink(PHPCP_ROOT . '/templates', $root . '/templates');

    file_put_contents($root . '/etc/config.php', sprintf(
        "<?php return %s;\n",
        var_export(['mode' => 'sandbox', 'layout' => 'portable', 'dns' => $dnsOverrides], true),
    ));

    $previous = getenv('PHPCP_CONFIG');
    putenv('PHPCP_CONFIG=' . $root . '/etc/config.php');

    $config = Config::load($root);

    putenv($previous === false ? 'PHPCP_CONFIG' : 'PHPCP_CONFIG=' . $previous);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return $config;
}

function bindTestContext(?Db $db = null, ?Config $config = null): Context
{
    return new Context(
        new Actor(0, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        $config ?? bindTestConfig(['enabled' => true]),
        $db ?? dnsZoneFixture()['db'],
    );
}

// --- ทะเบียนกับเส้นทาง ---------------------------------------------------------

test('ทะเบียน DNS ต้องแยกไฟล์ที่แก้ได้ออกจากไฟล์ที่ระบบสร้าง', static function (): void {
    $config = bindTestConfig(['enabled' => true]);
    $files = ConfigFileCatalog::forDns(BindZoneManager::customConfigPath($config), ['/etc/bind/named.conf.local']);

    $writable = array_values(array_filter(
        $files,
        static fn (array $f): bool => $f['kind'] === ConfigFileCatalog::KIND_WRITABLE,
    ));

    assertSame(1, count($writable), 'ต้องมีไฟล์ที่แก้ได้ไฟล์เดียว');
    assertSame('dns.bind.custom', $writable[0]['key'], 'คีย์ของไฟล์ที่แก้ได้ต้องคงที่');
    /*
     * **ต้องอยู่ในไดเรกทอรีของ BIND ไม่ใช่ใต้ `/etc/phpcp` แบบบริการอื่น**
     *
     * named ทิ้งสิทธิ์ root ทันทีที่สตาร์ตแล้วเหลือแค่ cap_net_bind_service กับ
     * cap_sys_resource — ไม่มี cap_dac_read_search · `/etc/phpcp` เป็น 750 root:phpcp
     * มันจึงเดินผ่านไม่ได้ แล้ว `rndc reload` ล้มด้วย permission denied ทุกครั้ง
     * ทั้งที่ไฟล์ถูกเขียนสำเร็จและเทสต์ผ่านหมด (วัดจาก /proc/<pid>/status ของ named จริง)
     */
    assertSame('/etc/bind/phpcp-custom.conf', $writable[0]['path'], 'ต้องอยู่ในไดเรกทอรีที่ named อ่านได้');
    assertTrue(
        !str_starts_with($writable[0]['path'], CustomConfig::ROOT),
        'ห้ามอยู่ใต้ /etc/phpcp — named ไม่มีสิทธิ์เดินผ่านไดเรกทอรีนั้น',
    );

    /*
     * **`named.conf.local` ต้องแก้ไม่ได้** — panel เขียนทับทั้งไฟล์ทุกครั้งที่มีการเพิ่ม
     * หรือลบ zone · ถ้าเปิดให้แก้ ค่าที่ผู้ดูแลเขียนจะหายไปเงียบ ๆ ในวันที่มีคนสร้างโดเมนใหม่
     */
    $generated = array_values(array_filter(
        $files,
        static fn (array $f): bool => $f['path'] === '/etc/bind/named.conf.local',
    ));

    assertSame(1, count($generated), 'named.conf.local ต้องอยู่ในทะเบียนให้เปิดดูได้');
    assertSame(ConfigFileCatalog::KIND_GENERATED, $generated[0]['kind'], 'named.conf.local ต้องแก้ไม่ได้');
});

test('ตัวเขียนต้องปฏิเสธคีย์ของ named.conf.local ไม่ใช่แค่ไม่แสดงปุ่ม', static function (): void {
    // คำขอที่ประกอบเองส่งคีย์อะไรมาก็ได้ — ด่านจริงอยู่ที่ capability ไม่ใช่ที่หน้าจอ
    $capability = new DnsCustomConfig();
    $rejected = '';

    try {
        $capability->run(
            ['key' => 'dns.generated.0', 'content' => "// x\n", 'window' => 120],
            new DryRunExecutor(),
            bindTestContext(),
        );
    } catch (ValidationError $e) {
        $rejected = $e->getMessage();
    }

    assertTrue($rejected !== '', 'ต้องปฏิเสธคีย์ของไฟล์ที่ระบบสร้าง');
    assertTrue(str_contains($rejected, 'หายไปเงียบ'), 'ต้องบอกเหตุผลที่แก้ไม่ได้ ไม่ใช่แค่ปฏิเสธ: ' . $rejected);
});

test('สิทธิ์ต้องเป็นระดับเครื่อง ไม่ใช่สิทธิ์ของเจ้าของโดเมน', static function (): void {
    /*
     * เนื้อไฟล์นี้ถูกอ่านตอน named สตาร์ต และมีผลกับ zone ของลูกค้าทุกรายพร้อมกัน ·
     * ผู้ดูแลเว็บไซต์ที่มี `domain.manage` สำหรับโดเมนตัวเองต้องแตะไฟล์นี้ไม่ได้
     * ด้วยเหตุผลเดียวกับที่ `dns.reload` ไม่ยอมให้แตะ
     */
    assertSame('settings.manage', (new DnsCustomConfig())->permission(), 'การเขียนต้องใช้สิทธิ์ระดับเครื่อง');
    assertSame('settings.manage', (new DnsConfigRead())->permission(), 'การอ่านก็เป็นค่าตั้งระดับเครื่องเช่นกัน');
    assertSame(false, (new DnsConfigRead())->isMutating(), 'การอ่านต้องไม่ถูกนับเป็นคำสั่งที่เปลี่ยนระบบ');
});

// --- บรรทัด include ที่ต้องผูกกับสภาพจริงบนดิสก์ --------------------------------

test('ไม่มีไฟล์ส่วนเสริม ต้องไม่มีบรรทัด include ใน named.conf.local', static function (): void {
    /*
     * **คุณสมบัติที่กันเครื่องพังทั้งเครื่อง**
     *
     * `include` ของ BIND ไม่ทนไฟล์หาย (พิสูจน์กับ named-checkconf 9.18 แล้ว: ได้
     * `parsing failed: file not found`) · ถ้าบรรทัดนี้ถูกเขียนตายตัว เครื่องที่ไม่เคย
     * ใช้ฟีเจอร์นี้เลยจะมี named.conf.local ที่ชี้ไปไฟล์ที่ไม่มีอยู่ แล้ว named
     * จะไม่สตาร์ตในการรีบูตครั้งถัดไป โดยที่ไม่มีใครแตะอะไรเลย
     */
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'nocustom.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.5');

    $config = bindTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $executor = new BindFakeExecutor();

    (new BindZoneManager($executor, $config, $fixture['db']))
        ->writeZone(['id' => $domain['id'], 'domain' => 'nocustom.test', 'zone_serial' => 0]);

    $namedConfLocal = $executor->written['/etc/bind/named.conf.local'] ?? '';

    assertTrue($namedConfLocal !== '', 'โดเมนใหม่ต้องเขียน named.conf.local');
    assertTrue(
        !str_contains($namedConfLocal, 'include'),
        "ไฟล์ส่วนเสริมยังไม่มี จึงต้องไม่มีบรรทัด include เลย ได้:\n" . $namedConfLocal,
    );
});

test('มีไฟล์ส่วนเสริมอยู่ ต้องมีบรรทัด include ชี้ไปเส้นทางจริงของมัน', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'withcustom.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.7');

    $config = bindTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $customPath = BindZoneManager::customConfigPath($config);
    $executor = new BindFakeExecutor([$customPath => "// ของผู้ดูแล\n"]);

    (new BindZoneManager($executor, $config, $fixture['db']))
        ->writeZone(['id' => $domain['id'], 'domain' => 'withcustom.test', 'zone_serial' => 0]);

    $namedConfLocal = $executor->written['/etc/bind/named.conf.local'] ?? '';

    assertTrue(
        str_contains($namedConfLocal, sprintf('include "%s";', $customPath)),
        "ต้อง include ไฟล์ส่วนเสริมด้วยเส้นทางเต็ม ได้:\n" . $namedConfLocal,
    );

    /*
     * ลำดับสำคัญ: ไฟล์ของผู้ดูแลต้องถูกอ่าน **หลัง** zone ที่ panic จัดการ — ไม่ใช่เพราะ
     * "ค่าหลังชนะ" (BIND ไม่มีกติกานั้น zone ซ้ำคือข้อผิดพลาด) แต่เพราะข้อความผิดพลาดของ
     * named-checkconf ชี้ไปที่ไฟล์ที่ประกาศ *ทีหลัง* ผู้ดูแลจึงเห็นชื่อไฟล์ของตัวเอง
     * ในข้อความ แทนที่จะเห็นไฟล์ที่ panel สร้างซึ่งเขาแก้ไม่ได้
     */
    $includePos = strpos($namedConfLocal, 'include "');
    $lastZonePos = strrpos($namedConfLocal, 'zone "');

    assertTrue(
        $includePos !== false && $lastZonePos !== false && $includePos > $lastZonePos,
        'บรรทัด include ต้องอยู่ท้าย zone ทั้งหมด',
    );
});

test('ไฟล์ส่วนเสริมที่ถูกลบทิ้งด้วยมือ ต้องถูกถอด include ออกให้เองรอบถัดไป', static function (): void {
    /*
     * เส้นทางฟื้นตัวที่ไม่ต้องพึ่งผู้ดูแล: ถ้าใครลบไฟล์ผ่าน SSH หรือกู้คืนแบ็กอัปทับ
     * named ที่รันอยู่ยังทำงานต่อได้ (อ่านค่าไปแล้ว) แต่จะไม่สตาร์ตในการรีสตาร์ตครั้งถัดไป ·
     * การเขียน zone ครั้งถัดไปต้องซ่อมให้เอง ไม่ใช่คัดลอกบรรทัด include เดิมต่อไปเรื่อย ๆ
     */
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'healed.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.8');

    $config = bindTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);

    // named.conf.local เดิมมีบรรทัด include ค้างอยู่ แต่ไฟล์ที่มันชี้ไปหายไปแล้ว
    $executor = new BindFakeExecutor([
        '/etc/bind/named.conf.local' => sprintf("zone \"old.test\" {\n};\ninclude \"%s\";\n", BindZoneManager::customConfigPath($config)),
    ]);

    (new BindZoneManager($executor, $config, $fixture['db']))
        ->writeZone(['id' => $domain['id'], 'domain' => 'healed.test', 'zone_serial' => 0]);

    $namedConfLocal = $executor->written['/etc/bind/named.conf.local'] ?? '';

    assertTrue(
        !str_contains($namedConfLocal, 'include'),
        "ต้องถอดบรรทัด include ที่ชี้ไปไฟล์ที่ไม่มีอยู่ออก ได้:\n" . $namedConfLocal,
    );
});

// --- การคืนค่า ------------------------------------------------------------------

test('การคืนค่าต้องคืน named.conf.local พร้อมไฟล์ส่วนเสริมเสมอ', static function (): void {
    /*
     * **จุดที่พลาดแล้วเสียหายหนักที่สุดของทั้งขอบเขตนี้**
     *
     * การบันทึกครั้งแรกของเครื่องหนึ่งคือกรณีที่อันตรายที่สุด: สภาพเดิมของไฟล์ส่วนเสริม
     * คือ "ยังไม่มีไฟล์นี้" ซึ่ง RollbackGuard แปลว่า **ลบทิ้ง** แล้วสั่ง
     * `systemctl reload-or-restart named` ต่อทันที · ถ้าจับคู่คืนแค่ไฟล์ส่วนเสริม
     * บรรทัด include จะยังอยู่และชี้ไปไฟล์ที่เพิ่งถูกลบ — named จะไม่กลับมา และมันจะเกิด
     * *ตอนที่กลไกความปลอดภัยทำงาน* ซึ่งเป็นเวลาที่ผู้ดูแลไม่ได้เฝ้าอยู่แล้ว
     */
    $fixture = dnsZoneFixture();
    $config = bindTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $context = bindTestContext($fixture['db'], $config);
    $customPath = BindZoneManager::customConfigPath($config);

    $result = (new DnsCustomConfig())->run(
        ['key' => 'dns.bind.custom', 'content' => "// ค่าตั้งของผู้ดูแล\n", 'window' => 120],
        new BindFakeExecutor(),
        $context,
    );

    assertTrue(($result['rollback_id'] ?? 0) > 0, 'ต้องตั้งเวลาถอนคืนไว้');

    $row = $fixture['db']->first('SELECT * FROM pending_rollbacks WHERE id = :id', ['id' => $result['rollback_id']]);
    $payload = json_decode((string) $row['payload_json'], true);
    $files = array_keys((array) ($payload['files'] ?? []));

    assertTrue(
        in_array($customPath, $files, true),
        'ต้องคืนไฟล์ส่วนเสริม: ' . implode(', ', $files),
    );
    assertTrue(
        in_array('/etc/bind/named.conf.local', $files, true),
        'ต้องคืน named.conf.local ด้วย ไม่งั้น include จะชี้ไปไฟล์ที่ถูกลบแล้ว named ไม่สตาร์ต: ' . implode(', ', $files),
    );

    // เดิมไม่มีไฟล์ส่วนเสริม = null ซึ่ง RollbackGuard แปลว่า "ลบทิ้ง" — ต้องเป็นแบบนั้นจริง
    assertSame(
        null,
        $payload['files'][$customPath],
        'ไฟล์ที่เดิมไม่มีต้องถูกบันทึกเป็น null เพื่อให้การคืนค่าลบมันทิ้ง',
    );

    assertTrue(
        in_array('named', (array) ($payload['units'] ?? []), true),
        'ต้อง reload บริการหลังคืนค่า: ' . json_encode($payload['units'] ?? []),
    );
});

test('เขียนไม่ได้เมื่อยังไม่ได้เปิดใช้งาน DNS', static function (): void {
    /*
     * `dns.enabled = false` แปลว่า panel ยังไม่ได้จัดการ named.conf.local ของเครื่องนี้ ·
     * การเขียนบรรทัด include ลงไปตอนนั้นคือการเข้าไปยึดไฟล์ที่เป็นของผู้ดูแลหรือของดิสโทร
     * โดยที่เขาไม่ได้ขอ — และไฟล์นั้นอาจมี zone ที่ตั้งเองอยู่ซึ่งจะหายไปทั้งหมด
     */
    $fixture = dnsZoneFixture();
    $config = bindTestConfig(['enabled' => false]);
    $rejected = '';

    try {
        (new DnsCustomConfig())->run(
            ['key' => 'dns.bind.custom', 'content' => "// x\n", 'window' => 120],
            new BindFakeExecutor(),
            bindTestContext($fixture['db'], $config),
        );
    } catch (ValidationError $e) {
        $rejected = $e->getMessage();
    }

    assertTrue($rejected !== '', 'ต้องปฏิเสธเมื่อ dns.enabled = false');
    assertTrue(str_contains($rejected, 'dns.enabled'), 'ต้องบอกชื่อค่าที่ต้องเปิด: ' . $rejected);

    $pending = (int) $fixture['db']->value('SELECT COUNT(*) FROM pending_rollbacks');
    assertSame(0, $pending, 'ปฏิเสธแล้วต้องไม่ทิ้งรายการรอยืนยันค้างไว้');
});

// --- ไฟล์ตั้งต้น ----------------------------------------------------------------

test('ไฟล์ตั้งต้นของ BIND ต้องเตือนสามข้อที่ผู้ดูแลเดาผิดแล้วเสียหาย', static function (): void {
    /*
     * สามข้อนี้ต่างจากบริการอื่นทุกตัวในระบบ ผู้ดูแลที่ชินกับไฟล์ส่วนเสริมของ Apache
     * หรือ Dovecot จะเดาผิดทั้งสามข้อ:
     *
     *   1. ลบไฟล์ทิ้งไม่ได้ (include ไม่ทนไฟล์หาย) — ที่อื่นลบได้ไม่มีผลอะไร
     *   2. ค่าซ้ำไม่ได้แปลว่าทับ แต่เป็นข้อผิดพลาด — ที่อื่นค่าหลังชนะเสมอ
     *   3. `options` เขียนที่นี่ไม่ได้เลย เพราะมีได้ครั้งเดียวทั้งเครื่อง
     */
    $content = (new CustomConfig())->seed(new Template(PHPCP_ROOT . '/templates'), 'bind');

    assertTrue($content !== '', 'ต้องมีไฟล์ตั้งต้นของ bind');
    assertTrue(!str_contains($content, '{{'), 'ไฟล์ตั้งต้นต้องไม่มีตัวแปรค้าง');

    // ทุกบรรทัดต้องเป็นคอมเมนต์หรือบรรทัดว่าง — บันทึกทันทีต้องไม่เปลี่ยนพฤติกรรมของ DNS เลย
    $active = array_values(array_filter(
        preg_split('/\R/', $content) ?: [],
        static fn (string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), '//'),
    ));

    assertSame([], $active, 'ไฟล์ตั้งต้นต้องไม่มีคำสั่งที่ทำงานจริงสักบรรทัด');

    assertTrue(str_contains($content, 'อย่าลบไฟล์นี้ทิ้ง'), 'ต้องเตือนว่าลบไฟล์แล้ว named ไม่สตาร์ต');
    assertTrue(str_contains($content, 'ข้อผิดพลาด'), 'ต้องบอกว่าค่าซ้ำเป็นข้อผิดพลาด ไม่ใช่การทับค่า');
    assertTrue(str_contains($content, 'options'), 'ต้องบอกว่า options เขียนที่นี่ไม่ได้');
    assertTrue(str_contains($content, 'ยืนยัน'), 'ต้องบอกว่าไม่กดยืนยันแล้วระบบคืนค่าเดิม');

    /*
     * ไฟล์นี้อยู่ในไดเรกทอรีของ BIND และถูกเขียนด้วยสิทธิ์ 0644 เหมือนไฟล์อื่นที่ panel
     * เขียนให้ BIND · ผู้ดูแลที่วาง TSIG key ลงไปตรง ๆ จะเปิดกุญแจให้ทุกคนบนเครื่อง
     * อ่านได้โดยไม่มีอะไรเตือน — ต้องบอกไว้ในไฟล์ เพราะเป็นที่ที่เขาจะอ่าน
     */
    assertTrue(str_contains($content, 'TSIG'), 'ต้องเตือนไม่ให้เขียนกุญแจลับลงไฟล์ที่อ่านได้ทุกคน');
});

test('ไฟล์ที่ยังไม่เคยเขียนต้องเปิดมาพร้อมไฟล์ตั้งต้น ไม่ใช่หน้าเปล่า', static function (): void {
    $fixture = dnsZoneFixture();
    $config = bindTestConfig(['enabled' => true]);

    $file = (new DnsConfigRead())->run(
        ['key' => 'dns.bind.custom'],
        new BindFakeExecutor(),
        bindTestContext($fixture['db'], $config),
    );

    assertSame(ConfigFileCatalog::KIND_WRITABLE, $file['kind'], 'ไฟล์ส่วนเสริมต้องแก้ได้');
    assertTrue(str_contains((string) $file['content'], 'BIND9'), 'ต้องได้เนื้อไฟล์ตั้งต้นมาด้วย');
    assertSame(false, $file['exists'], 'ต้องบอกตามจริงว่ายังไม่มีไฟล์นี้บนดิสก์');
});
