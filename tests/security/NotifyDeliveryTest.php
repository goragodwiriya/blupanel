<?php

declare(strict_types=1);

/**
 * บั๊กที่พบตอนทดสอบบนเครื่องจริง หลังเฟส E6 ผ่านเทสต์ 100% แล้ว — ทุกข้อ**เงียบสนิท**
 *
 * เทสต์เดิมทั้งชุดผ่านหมดทั้งที่ห้าเรื่องนี้พังอยู่ เพราะทุกเรื่องอยู่ในชั้นที่
 * `DryRunExecutor` "สำเร็จเสมอ" หรืออยู่ในการต่อสายที่ไม่มีใครตรวจ:
 *
 *   1. **`AlertCheck` กรองด้วยคีย์ `load` ที่ `ServiceProbe` ไม่เคยคืน** → เงื่อนไข
 *      ไม่มีทางเป็นจริง · ระบบยิงแจ้งเตือนรวดเดียว 6 ข้อความเรื่อง php-fpm เวอร์ชัน
 *      ที่ไม่ได้ลงไว้บนเครื่อง — คือสแปมแบบที่ทั้งเฟสนี้ตั้งใจกัน
 *   2. **`probeFallback()` คืน `installed => true` ให้ unit ที่ systemd บอกว่าไม่มี**
 *      เพราะมองหาคำว่า `unrecognized service` แต่ systemd ตอบ `could not be found`
 *   3. **`Dispatcher::notify()` ไม่ส่ง executor** → อีเมลถูกข้ามทุกครั้งอย่างเงียบ ๆ
 *      ผู้ดูแลที่ตั้งค่าครบถูกต้องไม่ได้รับอะไรเลยสักฉบับ
 *   4. **`service.start` ไม่อยู่ในตาราง NOTIFY** → หยุดบริการแล้วดัง เปิดคืนแล้วเงียบ
 *      ทิ้งความกังวลค้างไว้ว่าตกลงเปิดคืนหรือยัง
 *   5. **ไม่มีใครเฝ้า agent** → `alert.check` รันผ่าน agent เอง จึงเงียบสนิทพอดี
 *      ในจังหวะที่ต้องส่งข้อความมากที่สุด
 */

use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Agent\Dispatcher;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Kernel\Mode;
use Phpcp\Domain\AgentHealth;
use Phpcp\Domain\Notifier;

group('การส่งแจ้งเตือน — บั๊กที่เจอบนเครื่องจริง');

/**
 * Executor ที่จำลองเครื่องซึ่ง **ไม่มี** unit ที่ถูกถาม
 *
 * ทั้ง `systemctl show` และ `service ... status` ตอบข้อความเดียวกัน เหมือนของจริง —
 * ทางสำรอง (`probeFallback`) จึงถูกเดินจริงในเทสต์ ไม่ใช่ถูกข้ามไปเพราะ DryRunExecutor
 * ตอบว่าสำเร็จเสมอ · นี่คือชั้นที่บั๊กเดิมซ่อนอยู่พอดี
 */
final class MissingUnitExecutor implements Executor
{
    private DryRunExecutor $inner;

    public function __construct(
        private readonly string $message,
        private readonly int $exitCode,
    ) {
        $this->inner = new DryRunExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $binary = basename((string) ($argv[0] ?? ''));

        if (in_array($binary, ['systemctl', 'service'], true)) {
            return new ExecResult(
                argv: $argv,
                exitCode: $this->exitCode,
                stdout: '',
                stderr: $this->message,
                durationMs: 0,
            );
        }

        return $this->inner->exec($argv, $timeout, $cwd, $stdin);
    }

    public function mode(): Mode { return Mode::DryRun; }
    public function isSimulated(): bool { return true; }
    public function simulatedCommands(): array { return $this->inner->simulatedCommands(); }
    public function path(string $absolutePath): string { return $absolutePath; }
    public function readFile(string $path): string { return $this->inner->readFile($path); }
    public function writeFile(string $path, string $content, int $mode = 0644): void { $this->inner->writeFile($path, $content, $mode); }
    public function exists(string $path): bool { return $this->inner->exists($path); }
    public function makeDirectory(string $path, int $mode = 0755): void { $this->inner->makeDirectory($path, $mode); }
    public function diskSpace(string $path): array { return $this->inner->diskSpace($path); }
    public function realPath(string $path): ?string { return $this->inner->realPath($path); }
    public function listDirectory(string $path): array { return $this->inner->listDirectory($path); }
    public function stat(string $path): ?array { return $this->inner->stat($path); }
    public function rename(string $from, string $to): void { $this->inner->rename($from, $to); }
    public function copyPath(string $from, string $to): void { $this->inner->copyPath($from, $to); }
    public function removePath(string $path): void { $this->inner->removePath($path); }
    public function changeMode(string $path, int $mode): void { $this->inner->changeMode($path, $mode); }
    public function zip(array $sources, string $base, string $archive): array { return $this->inner->zip($sources, $base, $archive); }
    public function unzip(string $archive, string $destination): array { return $this->inner->unzip($archive, $destination); }
    public function asUser(?string $systemUser, callable $work): array { return $this->inner->asUser($systemUser, $work); }
}

test('AlertCheck ต้องกรองด้วยคีย์ที่ ServiceProbe คืนจริงเท่านั้น', static function (): void {
    // ต้นตอของสแปม 6 ข้อความ: เงื่อนไขที่อ้างคีย์ผิดจะประเมินเป็น false ตลอดกาล
    // โดยไม่มี error ให้เห็น — PHP คืน null แล้ว `?? ''` กลบไปเงียบ ๆ
    $probe = ServiceProbe::parse('php8.5-fpm', ['LoadState' => 'not-found']);

    assertTrue(!array_key_exists('load', $probe), 'ServiceProbe ไม่เคยคืนคีย์ชื่อ load');
    assertTrue(array_key_exists('installed', $probe), 'คีย์จริงคือ installed');
    assertTrue(array_key_exists('status', $probe), 'และ status');

    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/AlertCheck.php');

    assertTrue(
        !str_contains($source, "\$status['load']"),
        'ห้ามกรองด้วยคีย์ load ที่ไม่มีอยู่จริง',
    );
    assertTrue(
        str_contains($source, "\$status['status']") || str_contains($source, "'not_installed'"),
        'ต้องกรองด้วย status/not_installed ซึ่งเป็นค่าที่คำนวณจาก LoadState จริง',
    );
});

test('บริการที่ไม่ได้ติดตั้งต้องถูกรายงานว่า not_installed ไม่ใช่ "หยุดทำงาน"', static function (): void {
    // ความต่างนี้สำคัญมาก: "หยุดทำงาน" = critical ปลุกคนกลางดึก
    // ส่วน "ไม่ได้ติดตั้ง" = เรื่องปกติของเครื่องที่ใช้ Apache แล้วไม่ได้ลง nginx
    $missing = ServiceProbe::parse('php8.5-fpm', ['LoadState' => 'not-found']);

    assertSame('not_installed', $missing['status'], 'unit ที่ไม่มีต้องเป็น not_installed');
    assertTrue($missing['installed'] === false, 'installed ต้องเป็น false');
    assertTrue($missing['running'] === false, 'และต้องไม่ถือว่าทำงานอยู่');

    // ของจริงที่หยุดอยู่ต้องยังเป็น stopped เหมือนเดิม ไม่ใช่ถูกกลบไปด้วย
    $stopped = ServiceProbe::parse('apache2', ['LoadState' => 'loaded', 'ActiveState' => 'inactive']);
    assertSame('stopped', $stopped['status'], 'บริการที่ติดตั้งแล้วแต่หยุด ต้องเป็น stopped');
    assertTrue($stopped['installed'], 'และต้องนับว่าติดตั้งแล้ว');
});

test('unit ที่ระบบบอกว่าไม่มี ต้องไม่ถูกรายงานว่าติดตั้งแล้ว ไม่ว่าใช้สำนวนไหน', static function (): void {
    // ทดสอบ**พฤติกรรมจริง**ผ่าน read() ไม่ใช่ค้นคำในไฟล์ — เทสต์รุ่นแรกของข้อนี้ค้นคำ
    // ในซอร์ส แล้วมันผ่านทั้งที่โค้ดจริงถูกถอดออก เพราะคำเดียวกันยังอยู่ใน**คอมเมนต์**
    // ที่อธิบายบั๊กนั้นเอง · บทเรียน: เทสต์ที่ grep ซอร์สพิสูจน์ได้แค่ว่ามีใครพิมพ์คำนั้นไว้
    //
    // สำนวนจริงที่เก็บจากเครื่อง Ubuntu: `service php8.5-fpm status` ตอบ
    // "Unit php8.5-fpm.service could not be found." แล้วออกด้วยรหัส 4
    $cases = [
        'Unit php8.5-fpm.service could not be found.' => 4,
        'php8.5-fpm: unrecognized service' => 1,
        'Failed to get unit file state: No such file or directory' => 1,
    ];

    foreach ($cases as $message => $exitCode) {
        $probe = ServiceProbe::read(new MissingUnitExecutor($message, $exitCode), 'php8.5-fpm');

        assertSame('not_installed', $probe['status'], "สำนวน \"{$message}\" ต้องได้ not_installed");
        assertTrue($probe['installed'] === false, "สำนวน \"{$message}\" ต้องไม่ถือว่าติดตั้งแล้ว");
        assertTrue($probe['running'] === false, "สำนวน \"{$message}\" ต้องไม่ถือว่าทำงานอยู่");
    }
});

test('unit ที่ไม่มีไฟล์และคำสั่งล้มเหลว ต้องไม่ถูกเดาว่าติดตั้งแล้ว', static function (): void {
    // กรณีที่ข้อความไม่ตรงสำนวนไหนเลย — เดาไม่ออกจึงต้องไม่เดา
    // การคืน installed=true ตรงนี้อันตรายกว่าการยอมรับว่าไม่รู้ เพราะ alert.check
    // จะเห็นเป็น "ติดตั้งแล้วแต่ไม่ทำงาน" แล้วปลุกคนกลางดึกเรื่องบริการที่ไม่มีอยู่
    $probe = ServiceProbe::read(new MissingUnitExecutor('ข้อความที่ไม่เคยเจอมาก่อน', 4), 'php9.9-fpm');

    assertSame('not_installed', $probe['status'], 'เดาไม่ออกต้องตอบว่าไม่ได้ติดตั้ง');
});

test('Dispatcher ต้องส่ง executor ต่อให้ Notifier ไม่งั้นอีเมลถูกข้ามเงียบ ๆ', static function (): void {
    // `Notifier::send()` ข้ามช่องอีเมลเมื่อไม่มี executor เพราะ `sendmail` ต้องเดินผ่าน
    // Executor เท่านั้น · ผลคือผู้ดูแลที่ตั้งค่าครบถูกต้องไม่ได้รับอีเมลเลยสักฉบับ
    // และไม่มีอะไรฟ้อง — ไม่มี error ไม่มี log ไม่มีอะไรทั้งนั้น
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Dispatcher.php');

    assertSame(
        0,
        preg_match('/new Notifier\(\s*\$this->db\s*\)/', $source),
        'ห้ามสร้าง Notifier โดยไม่ส่ง executor',
    );
    assertSame(
        1,
        preg_match('/new Notifier\(\$this->db,\s*\$executor\)/', $source),
        'ต้องส่ง executor ต่อไปด้วย',
    );

    // ทั้งสองจุดที่เรียก notify() (สำเร็จและล้มเหลว) ต้องส่ง executor ครบ
    preg_match_all('/\$this->notify\([^;]+;/', $source, $calls);
    assertSame(2, count($calls[0]), 'ต้องมีจุดเรียก notify() สองจุด');

    foreach ($calls[0] as $call) {
        assertTrue(str_contains($call, '$executor'), 'ทุกจุดเรียกต้องส่ง executor: ' . trim($call));
    }
});

test('Notifier ที่ไม่มี executor ต้องยังส่ง Telegram/webhook ได้', static function (): void {
    // ข้อจำกัดนี้ต้องแคบอยู่ที่อีเมลเท่านั้น — ถ้าเผลอทำให้ทั้งก้อนเงียบเมื่อไม่มี
    // executor ชั้นเว็บจะไม่แจ้งอะไรเลย ซึ่งแย่กว่าเดิมมาก
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/Notifier.php');

    assertTrue(
        str_contains($source, '$this->executor !== null && $this->settings->bool(\'notify.email.enabled\')'),
        'เงื่อนไข executor ต้องผูกกับช่องอีเมลเท่านั้น',
    );

    // Telegram กับ webhook ต้องไม่มีเงื่อนไข executor มาเกี่ยวข้อง
    $telegramBlock = substr($source, (int) strpos($source, "notify.telegram.enabled"), 300);
    assertTrue(!str_contains($telegramBlock, 'executor'), 'Telegram ต้องไม่ต้องพึ่ง executor');
});

test('การหยุดและเริ่มบริการต้องแจ้งทั้งคู่ — การแจ้งที่ไม่ปิดวงจรทิ้งความกังวลไว้', static function (): void {
    // ผู้ดูแลที่ได้ข้อความ "หยุดบริการสำเร็จ" ตอนตีสอง แล้วไม่ได้ข้อความตอนเปิดคืน
    // ต้องเปิดเครื่องมาตรวจเองว่าตกลงมีคนเปิดคืนหรือยัง
    $reflection = new ReflectionClass(Dispatcher::class);
    $notify = $reflection->getConstant('NOTIFY');

    foreach (['service.stop', 'service.start', 'service.restart'] as $capability) {
        assertTrue(isset($notify[$capability]), "{$capability} ต้องอยู่ในรายการแจ้งเตือน");
        assertSame('service', $notify[$capability], "{$capability} ต้องอยู่หมวด service");
    }

    // ทุกชื่อในตารางต้องเป็น capability ที่มีจริง — ชื่อที่พิมพ์ผิดจะเงียบตลอดไป
    $registry = new Phpcp\Agent\CapabilityRegistry();
    foreach (array_keys($notify) as $name) {
        assertTrue($registry->has($name), "capability {$name} ต้องมีอยู่จริงในทะเบียน");
    }

    // ทุกหมวดต้องเป็นหมวดที่เปิด/ปิดได้จริง ไม่งั้น send() จะ return false ทันที
    foreach ($notify as $name => $event) {
        assertTrue(isset(Notifier::EVENTS[$event]), "หมวด {$event} ของ {$name} ต้องมีอยู่จริง");
    }
});

test('เว็บเซิร์ฟเวอร์กับฐานข้อมูลต้องตัดสินทั้งกลุ่ม ไม่ใช่ทีละตัว', static function (): void {
    // เครื่องหนึ่งเสิร์ฟเว็บด้วย Apache **หรือ** Nginx ไม่ใช่ทั้งคู่ — ตัวที่ไม่ได้ใช้
    // จะสตาร์ตไม่ขึ้นเพราะพอร์ตชนกัน ซึ่งเป็นสภาพปกติ ไม่ใช่เหตุที่ต้องปลุกใคร
    //
    // เจอบนเครื่องจริง: nginx ติดตั้งไว้ enabled ด้วย แต่ failed มาตั้งแต่เช้าเพราะ
    // Apache ถือพอร์ต 80 อยู่ · ถ้าเตือนทีละตัวจะเตือนทุก 6 ชั่วโมงตลอดไป
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/AlertCheck.php');

    assertTrue(str_contains($source, 'service-kind:'), 'ต้องมีคีย์ระดับกลุ่ม');
    assertTrue(
        str_contains($source, 'ServiceCatalog::KIND_WEBSERVER')
        && str_contains($source, 'ServiceCatalog::KIND_DATABASE'),
        'ต้องจัดกลุ่มทั้งเว็บเซิร์ฟเวอร์และฐานข้อมูล',
    );

    // php-fpm ต้องยังแยกทีละเวอร์ชัน — เว็บที่ตั้งไว้ใช้ 8.4 ล่มทันทีที่ 8.4 ตาย
    // ไม่ว่าเวอร์ชันอื่นจะยังทำงานอยู่หรือไม่ · ถ้าเผลอเหมารวมจะไม่มีใครรู้เลย
    assertTrue(
        !str_contains($source, 'KIND_PHP'),
        'php-fpm ต้องไม่ถูกจัดกลุ่ม — แต่ละเวอร์ชันแยกกันจริง',
    );

    // ทั้งสองชนิดต้องมีอยู่จริงใน ServiceCatalog ไม่งั้นการจัดกลุ่มไม่มีผลอะไรเลย
    $kinds = array_column(Phpcp\Domain\ServiceCatalog::all(), 'kind');
    assertTrue(in_array(Phpcp\Domain\ServiceCatalog::KIND_WEBSERVER, $kinds, true), 'ต้องมีชนิดเว็บเซิร์ฟเวอร์');
    assertTrue(in_array(Phpcp\Domain\ServiceCatalog::KIND_DATABASE, $kinds, true), 'ต้องมีชนิดฐานข้อมูล');

    // และต้องมีมากกว่าหนึ่งตัวในกลุ่มเว็บเซิร์ฟเวอร์ ไม่งั้นการจัดกลุ่มไม่มีความหมาย
    $webservers = array_filter(
        Phpcp\Domain\ServiceCatalog::all(),
        static fn (array $m): bool => ($m['kind'] ?? '') === Phpcp\Domain\ServiceCatalog::KIND_WEBSERVER,
    );
    assertTrue(count($webservers) > 1, 'ต้องมีเว็บเซิร์ฟเวอร์ให้เลือกมากกว่าหนึ่งตัว');
});

test('AgentHealth ต้องไม่พึ่ง agent — ไม่งั้นเงียบพอดีตอนที่ต้องส่งมากที่สุด', static function (): void {
    // นี่คือหัวใจทั้งหมดของคลาสนี้: `alert.check` เป็น capability ที่รันผ่าน agent
    // พอ agent ตาย งานตรวจก็เรียกไม่ได้ — ระบบเฝ้าระวังตายพร้อมกับสิ่งที่มันเฝ้า
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/AgentHealth.php');

    assertTrue(!str_contains($source, '->call('), 'ห้ามเรียก capability ผ่าน agent');
    assertTrue(!str_contains($source, '->data('), 'ห้ามสั่งงานผ่าน agent');
    assertTrue(str_contains($source, 'isAvailable()'), 'ต้องตรวจ socket ตรง ๆ');

    // **ห้ามพยายามส่งข้อความจากที่นี่** — scheduler ถูกล็อกไว้ที่ AF_UNIX (ยิง Telegram
    // ไม่ได้) และ NoNewPrivileges (เข้าคิวเมลไม่ได้) · เคยเขียนให้ส่งแล้วพบว่ามันแค่
    // ทำให้ทุกรอบช้าไป 15 วินาทีรอ sendmail ที่ถูกปฏิเสธ โดยไม่มีข้อความออกไปเลย
    assertTrue(!str_contains($source, 'Notifier'), 'ห้ามส่งข้อความจาก scheduler');

    // ต้องถูกเรียกจาก scheduler ซึ่งเป็นคนละโปรเซสและยังทำงานอยู่ตอน agent ล่ม
    $scheduler = (string) file_get_contents(PHPCP_ROOT . '/bin/phpcp-scheduler');
    assertTrue(str_contains($scheduler, 'AgentHealth'), 'scheduler ต้องเรียกตัวเฝ้า');

    // และต้องอยู่ก่อน runDue() — ไม่งั้นงานทั้งชุดล้มเรียงกันก่อนโดยไม่มีใครบอกสาเหตุ
    $healthAt = strpos($scheduler, 'new AgentHealth');
    $runDueAt = strpos($scheduler, '$scheduler->runDue()');
    assertTrue($healthAt !== false && $runDueAt !== false, 'ต้องมีทั้งสองอย่าง');
    assertTrue($healthAt < $runDueAt, 'การตรวจสุขภาพต้องอยู่ก่อนการสั่งงาน');
});

test('agent ที่ล่มต้องบันทึกครั้งเดียว แล้วเงียบจนกว่าจะกลับมา', static function (): void {
    // ใช้กลไกกันสแปมตัวเดียวกับเกณฑ์อื่น — agent ที่ล่มค้างไว้ทั้งคืนต้องไม่นับใหม่
    // ทุกนาที (scheduler รันทุกนาที = 480 ครั้งก่อนถึงเช้า)
    $db = alertDb();

    // Client ปลอมที่ชี้ไป socket ที่ไม่มีอยู่จริง — isAvailable() จะคืน false
    $client = new Phpcp\Agent\Client(sys_get_temp_dir() . '/phpcp-ไม่มีจริง-' . bin2hex(random_bytes(4)) . '.sock', 1);
    $health = new AgentHealth($db, $client);
    $t = 1_700_000_000;

    $first = $health->check($t);
    assertTrue(!$first['available'], 'ต้องตรวจพบว่า agent ไม่ตอบ');
    assertSame('new', $first['reason'], 'ครั้งแรกต้องนับว่าเปลี่ยนสถานะ');

    foreach ([60, 120, 3600] as $offset) {
        $again = $health->check($t + $offset);
        assertTrue(!$again['changed'], "ที่วินาที {$offset} ต้องไม่นับเป็นการเปลี่ยนสถานะซ้ำ");
    }

    // สถานะต้องค้างอยู่ให้หน้า /api/v2/alerts อ่านได้ — นี่คือเหตุผลเดียวที่คลาสนี้ยังอยู่
    $rows = (new Phpcp\Domain\AlertRules($db))->active();
    assertSame(1, count($rows), 'ต้องมีสถานะค้างไว้หนึ่งแถว');
    assertSame(AgentHealth::ALERT_KEY, $rows[0]['alert_key'], 'ต้องเป็นคีย์ของ agent');
});

/**
 * อ่านไฟล์ systemd unit โดย **ตัดคอมเมนต์ทิ้ง** เหลือแต่คำสั่งจริง
 *
 * จำเป็นเพราะ unit ของ phpcp-alert มีคอมเมนต์อธิบายว่า "ห้ามใส่ NoNewPrivileges=yes"
 * — เทสต์ที่ค้นทั้งไฟล์จะเจอคำนั้นแล้วล้ม ทั้งที่โค้ดถูกต้อง · เป็นกับดักเดียวกับที่
 * ทำให้เทสต์ probeFallback รุ่นแรกผ่านทั้งที่บั๊กกลับมาแล้ว แค่กลับด้านกัน
 *
 * @return array<string,string> คำสั่ง → ค่า
 */
function unitDirectives(string $path): array
{
    $directives = [];

    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '[')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $directives[trim($key)] = trim($value);
    }

    return $directives;
}

test('ตัวแจ้งเตือนของ systemd ต้องส่งได้ทุกช่องทางที่ scheduler ส่งไม่ได้', static function (): void {
    // phpcp-alert คือทางเดียวที่เหลือตอน agent ตาย จึงต้องมีสิทธิ์ครบตรงข้ามกับ scheduler
    $unit = unitDirectives(PHPCP_ROOT . '/templates/panel/phpcp-alert@.service.tpl');

    // ต้องยิง TCP ได้ ไม่งั้น Telegram/webhook ตาย
    assertTrue(
        str_contains($unit['RestrictAddressFamilies'] ?? '', 'AF_INET'),
        'ต้องเปิด AF_INET ให้ยิง HTTPS ได้',
    );

    // ห้ามมี NoNewPrivileges เพราะจะปิด setgid ของ postdrop → เข้าคิวเมลไม่ได้
    // (ยืนยันจาก log จริงของ scheduler: mail_queue_enter: Permission denied)
    assertTrue(!isset($unit['NoNewPrivileges']), 'ห้ามปิดการยกสิทธิ์ ไม่งั้นส่งอีเมลไม่ได้');

    // เขียนได้เฉพาะฐานข้อมูลกับคิวเมล — โปรแกรมที่ systemd เรียกตอนระบบมีปัญหา
    // ต้องแตะระบบให้น้อยที่สุด
    assertTrue(isset($unit['ReadWritePaths']), 'ต้องจำกัดที่เขียนได้');
    assertSame('strict', $unit['ProtectSystem'] ?? '', 'ต้องกันการเขียนนอกขอบเขต');
    assertSame('no', $unit['Restart'] ?? '', 'ล้มแล้วห้ามวนซ้ำ ไม่งั้นได้ลูป unit ล้มซ้อนกัน');

    // ต้องเรียก phpcp-alert จริง ไม่ใช่อย่างอื่น
    assertTrue(str_contains($unit['ExecStart'] ?? '', 'bin/phpcp-alert'), 'ต้องเรียก phpcp-alert');

    // agent ต้องชี้มาที่ตัวนี้จริง ไม่งั้นไม่มีใครเรียก
    $agentd = unitDirectives(PHPCP_ROOT . '/templates/panel/phpcp-agentd.service.tpl');
    assertSame('phpcp-alert@%n.service', $agentd['OnFailure'] ?? '', 'agentd ต้องมี OnFailure');

    // และ install.sh ต้องติดตั้งทั้งไฟล์ unit และสิทธิ์รันของสคริปต์
    $install = (string) file_get_contents(PHPCP_ROOT . '/install.sh');
    assertTrue(str_contains($install, 'phpcp-alert@.service'), 'install.sh ต้องติดตั้ง unit');
    assertTrue(str_contains($install, 'bin/phpcp-alert"'), 'install.sh ต้องให้สิทธิ์รัน');
});

test('phpcp-alert ต้องไม่รันคำสั่งอะไรบนเครื่องเลย', static function (): void {
    // โปรแกรมที่ systemd เรียกตอนระบบมีปัญหา ต้องเป็นโปรแกรมที่พังยากที่สุด
    // ไม่ใช่โปรแกรมที่พยายามกู้สถานการณ์เองแล้วทำให้แย่ลง
    //
    // ตรวจที่ **ฟังก์ชันรันโปรเซส** ไม่ใช่คำว่า "systemctl" — คำนั้นปรากฏในข้อความ
    // แนะนำผู้ใช้ ("แก้ด้วย: sudo systemctl restart phpcp-agentd") ซึ่งเป็นข้อความล้วน
    $source = (string) file_get_contents(PHPCP_ROOT . '/bin/phpcp-alert');

    foreach (['shell_exec', 'proc_open', 'popen', 'system('] as $forbidden) {
        assertTrue(!str_contains($source, $forbidden), "ห้ามใช้ {$forbidden}");
    }

    // ไม่แก้ไฟล์อะไรบนเครื่อง — เขียนแค่ฐานข้อมูลผ่าน AlertRules
    foreach (['unlink(', 'file_put_contents(', 'rename(', 'mkdir('] as $forbidden) {
        assertTrue(!str_contains($source, $forbidden), "ห้ามแก้ไฟล์ด้วย {$forbidden}");
    }

    // `passthru` ใช้ได้เฉพาะในบล็อกสลับเวอร์ชัน PHP ที่ต้นไฟล์ (เหมือน CLI ตัวอื่นทุกตัว)
    // — ต้องอยู่ก่อน require ของ bootstrap เสมอ ไม่ใช่ปนอยู่ในตรรกะการแจ้งเตือน
    $bootstrapAt = strpos($source, "require dirname(__DIR__)");
    $passthruAt = strpos($source, 'passthru(');
    assertTrue($passthruAt === false || $passthruAt < $bootstrapAt, 'passthru ต้องอยู่ในบล็อกสลับเวอร์ชันเท่านั้น');

    // ต้องกันสแปมเหมือนเกณฑ์อื่น — unit ที่ restart วนซ้ำจะเรียกโปรแกรมนี้ทุกรอบที่ล้ม
    assertTrue(str_contains($source, 'AlertRules'), 'ต้องใช้กลไกกันสแปมตัวเดียวกัน');

    // ต้องไม่ตายแบบดัง — unit ที่ล้มเหลวเพิ่มมาอีกตัวไม่ช่วยอะไร
    assertTrue(str_contains($source, 'catch (\\Throwable'), 'ต้องจับ error ทั้งหมด');
});
