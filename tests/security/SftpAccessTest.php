<?php

declare(strict_types=1);

/**
 * SFTP ต่อบัญชีโฮสติ้ง — PLAN-V2 เฟส E4
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *   1. **SFTP ต้องไม่กลายเป็น shell access** — `ForceCommand internal-sftp` และการตัด
 *      forwarding ทุกชนิดคือสิ่งเดียวที่กันไม่ให้บัญชีลูกค้ากลายเป็นทางเข้าเครื่อง
 *   2. **`Match` block ต้องปิดด้วย `Match all`** — scope ของ Match ไหลข้ามไฟล์ ถ้าไม่ปิด
 *      ค่าตั้ง sshd ที่เหลือของทั้งเครื่องจะกลายเป็นของกลุ่ม SFTP โดยไม่มีใครรู้
 *   3. **บ้านชั้นบนสุดต้องเป็นของ root** — เงื่อนไขบังคับของ ChrootDirectory ที่ถ้าผิด
 *      OpenSSH ปฏิเสธการเชื่อมต่อทั้งหมด (และถ้าผู้ใช้เขียนได้ = หนีออกจาก chroot ได้)
 *   4. **รหัสผ่านต้องไม่ไปโผล่ใน argv** — `/proc/<pid>/cmdline` ผู้ใช้อื่นอ่านได้
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Agent\Capability\SftpDisable;
use Phpcp\Agent\Capability\SftpEnable;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Mode;
use Phpcp\Security\Permissions;

group('SftpAccess — เปิด/ปิด SFTP ต่อบัญชีโฮสติ้ง');

/**
 * Executor ที่ควบคุมได้ว่า systemctl รายงานว่าบริการทำงานอยู่หรือไม่
 *
 * ต้องมีตัวนี้เพราะ `DryRunExecutor` คืน exit 0 ให้ทุกคำสั่ง — `ServiceProbe` จึงสรุปว่า
 * ทุกบริการทำงานอยู่เสมอ แยกกรณี "sshd ไม่ทำงาน" ไม่ออกเลย · ที่เหลือ delegate ไป
 * DryRunExecutor ทั้งหมดเพื่อไม่ให้ต้องจำลองพฤติกรรมซ้ำ
 */
final class SshdStateExecutor implements Executor
{
    private DryRunExecutor $inner;

    public function __construct(private readonly bool $sshdRunning)
    {
        $this->inner = new DryRunExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $binary = basename((string) ($argv[0] ?? ''));

        // exit 3 = "inactive" ตามธรรมเนียมของ systemctl · ครอบ `service` ที่ ServiceProbe
        // ใช้เป็นทางสำรองด้วย ไม่งั้นมันจะถอยไปใช้ทางนั้นแล้วเห็นว่า exit 0 = ทำงานอยู่
        if (!$this->sshdRunning && in_array($binary, ['systemctl', 'service'], true)) {
            return new ExecResult(argv: $argv, exitCode: 3, stdout: 'ActiveState=inactive', stderr: '', durationMs: 0);
        }

        return $this->inner->exec($argv, $timeout, $cwd, $stdin);
    }

    public function mode(): Mode { return Mode::DryRun; }
    public function isSimulated(): bool { return true; }
    public function simulatedCommands(): array { return $this->inner->simulatedCommands(); }
    public function path(string $absolutePath): string { return $absolutePath; }

    /**
     * ตอบจากค่าคงที่ ไม่อ่าน /etc ของเครื่องที่รันเทสต์
     *
     * เทสต์นี้เคยผ่านเพราะ**บังเอิญ**: เครื่องที่เคยติดตั้ง phpcp มี `phpcp-sftp.conf`
     * ตรงกับที่ระบบจะเขียนพอดี `ensureConfig()` จึง early-return แล้วไหลไปถึง reload
     * · พอ `sites.users_dir` เปลี่ยน (ซึ่งอยู่ในเนื้อไฟล์ผ่าน ChrootDirectory) ความบังเอิญ
     * นั้นหายไป แล้วเทสต์ล้มทั้งที่โค้ดที่มันตรวจไม่ได้เปลี่ยนเลย
     */
    public function readFile(string $path): string
    {
        if ($path === SftpAccessManager::CONFIG_FILE) {
            return "# ของเดิมที่ล้าสมัย\n";
        }

        if ($path === Phpcp\Driver\SshManager::CONFIG) {
            return "Include /etc/ssh/sshd_config.d/*.conf\nPort 22\n";
        }

        return $this->inner->readFile($path);
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void { $this->inner->writeFile($path, $content, $mode); }
    public function exists(string $path): bool
    {
        return in_array($path, [SftpAccessManager::CONFIG_FILE, Phpcp\Driver\SshManager::CONFIG], true)
            || $this->inner->exists($path);
    }
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

function sftpFixture(): array
{
    $root = sys_get_temp_dir() . '/phpcp-sftp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);

    $db = new Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return ['root' => $root, 'db' => $db, 'config' => Config::load(PHPCP_ROOT)];
}

/** @return array{id:int,username:string} */
function seedSftpAccount(
    array $fixture,
    string $username,
    int $quotaFtp = 5,
    ?string $systemUser = null,
    string $role = Permissions::WEBADMIN,
): array {
    $id = $fixture['db']->insert('users', [
        'username' => $username,
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => $role,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'system_user' => $systemUser,
        'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => $quotaFtp,
        'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    return ['id' => $id, 'username' => $username];
}

function sftpContext(array $fixture): Context
{
    return new Context(
        new Actor(1, 'admin', Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        $fixture['config'],
        $fixture['db'],
    );
}

/** เนื้อไฟล์ config ที่ manager จะเขียน — อ่านผ่าน reflection เพราะเป็น private */
function sftpConfigContent(): string
{
    $manager = new SftpAccessManager(new DryRunExecutor());

    return (string) (new ReflectionMethod($manager, 'configContent'))->invoke($manager);
}

// --- 1. ไฟล์ config ต้องกัน shell access ทุกทาง -------------------------------

test('config บังคับ internal-sftp และตัด forwarding ทุกชนิด', static function (): void {
    $conf = sftpConfigContent();

    assertTrue(str_contains($conf, 'ForceCommand internal-sftp'), 'ต้องบังคับ internal-sftp ไม่งั้นได้ shell: ' . $conf);
    assertTrue(str_contains($conf, 'ChrootDirectory'), 'ต้องขังไว้ในบ้านตัวเอง');

    foreach ([
        'AllowTcpForwarding no',
        'AllowAgentForwarding no',
        'AllowStreamLocalForwarding no',
        'PermitTunnel no',
        'X11Forwarding no',
    ] as $directive) {
        assertTrue(str_contains($conf, $directive), "ต้องมี `{$directive}` — ไม่งั้น SFTP กลายเป็นทางออกสู่เครือข่ายภายใน");
    }
});

test('Match block ต้องปิดด้วย Match all เสมอ — scope ของ Match ไหลข้ามไฟล์', static function (): void {
    // ถ้าไม่ปิด ทุก directive ใน sshd_config หลักที่ include ทีหลังจะตกอยู่ใน Match นี้
    // แปลว่าค่าตั้งของทั้งเครื่องกลายเป็นของกลุ่ม SFTP เงียบ ๆ — พังแบบที่หาสาเหตุยากมาก
    $conf = sftpConfigContent();
    $lines = array_values(array_filter(array_map('trim', explode("\n", $conf)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));

    assertSame('Match all', end($lines), 'บรรทัดที่มีความหมายบรรทัดสุดท้ายต้องเป็น `Match all`');

    // ต้องมี Match สองตัวเท่านั้น: ตัวเปิด scope และตัวปิด
    $matches = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Match ')));
    assertSame(2, count($matches), 'ต้องมี Match พอดีสองตัว (เปิดกลุ่ม + ปิด scope) ได้: ' . implode(' | ', $matches));
    assertTrue(str_starts_with($matches[0], 'Match Group '), 'ตัวแรกต้องจับด้วยกลุ่ม ไม่ใช่ผู้ใช้รายตัว');
});

test('ไม่ใช้ Match User รายคน — ไฟล์เดียวใช้ได้กับทุกบัญชี', static function (): void {
    $conf = sftpConfigContent();

    // ชื่อลูกค้าไม่ควรโผล่ในไฟล์ config ของระบบ และไม่ต้องเขียนไฟล์ใหม่ทุกครั้งที่เพิ่มบัญชี
    assertTrue(!str_contains($conf, 'Match User '), 'ต้องไม่ใช้ Match User รายคน: ' . $conf);
    assertTrue(str_contains($conf, '-d /%u'), 'ต้องพาเข้าบ้านตัวเองด้วย %u ที่ OpenSSH แทนให้เอง: ' . $conf);
});

// --- 2. chroot ต้องชี้ไดเรกทอรีแม่ ไม่ใช่บ้านของผู้ใช้ --------------------------

test('chroot ชี้ไดเรกทอรีแม่ (root:root 0711) ไม่ใช่บ้านของผู้ใช้ — ไม่งั้นเว็บตอบ 403 ทั้งเว็บ', static function (): void {
    // **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เดิม chroot ที่บ้านของผู้ใช้ ซึ่งบังคับ
    // ให้บ้านเป็นของ root (เงื่อนไขของ OpenSSH) — แต่บ้านต้องเป็น <user>:www-data เพื่อให้
    // เว็บเซิร์ฟเวอร์เดินผ่านไปถึง docroot ได้ · สองข้อนี้ขัดกันโดยตรง ทางออกเดียวที่ไม่ต้อง
    // เลือกระหว่าง "เว็บ 403" กับ "เปิด other ให้ลูกค้าเห็นกัน" คือ chroot ที่ไดเรกทอรีแม่
    $conf = sftpConfigContent();
    $usersDir = rtrim(Phpcp\Kernel\Paths::usersDir(), '/');

    assertTrue(
        str_contains($conf, "ChrootDirectory {$usersDir}\n"),
        "ChrootDirectory ต้องเป็น {$usersDir} เฉย ๆ (ไม่มี /%u ต่อท้าย): {$conf}",
    );
    assertTrue(
        !str_contains($conf, "ChrootDirectory {$usersDir}/%u"),
        'ห้าม chroot ที่บ้านของผู้ใช้ — บ้านเป็นของผู้ใช้และกลุ่ม www-data จึงไม่ผ่านเงื่อนไขของ OpenSSH',
    );
});

test('AccountProvisioner ต้องไม่เปลี่ยนเจ้าของบ้านเป็น root — www-data ต้องเดินผ่านได้', static function (): void {
    // ถ้าเปลี่ยนเป็น root:<user> จะทำให้ www-data ไม่มีสิทธิ์เดินผ่านไปถึง docroot เลย
    // ผลคือไฟล์สแตติกทุกไฟล์ตอบ 403 รวมถึงไฟล์ตรวจสอบของ Let's Encrypt
    $templates = new Phpcp\Driver\Template(PHPCP_ROOT . '/templates');
    $provisioner = new Phpcp\Driver\AccountProvisioner(
        new Phpcp\Driver\WebServer\ApacheDriver($templates),
    );

    $executor = new DryRunExecutor();
    $account = new UserAccount(1, 'sftpuser');

    (new ReflectionMethod($provisioner, 'setOwnership'))->invoke($provisioner, $executor, $account);

    $commands = implode(' | ', $executor->simulatedCommands());

    assertTrue(
        !str_contains($commands, 'chown root:'),
        'ต้องไม่ chown บ้านเป็นของ root — เว็บเซิร์ฟเวอร์จะเดินผ่านไม่ได้: ' . $commands,
    );
    assertTrue(
        str_contains($commands, 'sftpuser:www-data'),
        'บ้านต้องเป็น <user>:www-data ตามเดิมเพื่อให้เว็บเซิร์ฟเวอร์เดินผ่านได้: ' . $commands,
    );
});

// --- 3. โควตาและเงื่อนไขก่อนเปิด ---------------------------------------------

test('โควตา FTP เป็น 0 ต้องเปิด SFTP ไม่ได้', static function (): void {
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'noftp', quotaFtp: 0, systemUser: 'noftp');

    $capability = new SftpEnable();

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['user_id' => $account['id'], 'password' => 'LongEnoughPass123']),
            new DryRunExecutor(),
            sftpContext($fixture),
        ),
        'โควตา 0 = แพ็กเกจไม่รวม SFTP ต้องปฏิเสธ',
    );
});

test('บัญชีที่ยังไม่มีบัญชีระบบ (ยังไม่เคยสร้างเว็บ) เปิด SFTP ไม่ได้', static function (): void {
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'nohome', quotaFtp: 5, systemUser: null);

    $capability = new SftpEnable();

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['user_id' => $account['id'], 'password' => 'LongEnoughPass123']),
            new DryRunExecutor(),
            sftpContext($fixture),
        ),
        'ไม่มีบัญชีระบบ = ไม่มีบ้านให้ chroot ต้องปฏิเสธพร้อมบอกวิธีแก้',
    );
});

test('บัญชีผู้ดูแล (ไม่ใช่ webadmin) เปิด SFTP ผ่านเส้นทางนี้ไม่ได้', static function (): void {
    // เส้นทาง customer.* ยอมรับเฉพาะ role=webadmin — กัน sysadmin แตะบัญชีผู้ดูแลด้วยกัน
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'anadmin', quotaFtp: 5, systemUser: 'anadmin', role: Permissions::SYSADMIN);

    $capability = new SftpEnable();

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => $capability->run(
            $capability->validate(['user_id' => $account['id'], 'password' => 'LongEnoughPass123']),
            new DryRunExecutor(),
            sftpContext($fixture),
        ),
        'บัญชีที่ไม่ใช่ webadmin ต้องถูกปฏิเสธเหมือนไม่มีอยู่',
    );
});

// --- 3b. sshd ต้องทำงานอยู่ก่อน — เจอจากการทดสอบบนเซิร์ฟเวอร์จริง ---------------

test('sshd ที่ไม่ได้ทำงานอยู่ต้องถูกจับตั้งแต่ก่อนแตะไฟล์ พร้อมบอกคำสั่งที่ต้องรัน', static function (): void {
    // **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เครื่องที่ไม่ได้เปิด sshd ทำให้
    // `sshd -t` ล้มด้วย "Missing privilege separation directory: /run/sshd" (systemd สร้าง
    // /run/sshd ตอน start เท่านั้น) — ผู้ดูแลเห็น "การตั้งค่าไม่ผ่านการตรวจสอบ" ซึ่งชี้ผิดทาง
    // ทั้งที่ config ถูกต้องทุกบรรทัด
    //
    $manager = new SftpAccessManager(new SshdStateExecutor(sshdRunning: false));

    try {
        $manager->enable(new UserAccount(1, 'nosshd'), 'LongEnoughPass123');
        assertTrue(false, 'ต้องถูกปฏิเสธเมื่อ sshd ไม่ทำงาน');
    } catch (Phpcp\Agent\ExecutionFailed $e) {
        $message = $e->getMessage();

        assertTrue(str_contains($message, "SSH service isn't running"), 'ต้องบอกสาเหตุจริงว่า sshd ไม่ทำงาน: ' . $message);
        assertTrue(str_contains($message, 'systemctl enable --now ssh'), 'ต้องบอกคำสั่งที่ต้องรันให้ด้วย: ' . $message);
    }
});

test('ข้อความ Missing privilege separation directory ถูกแปลให้ชี้ไปที่ต้นตอจริง', static function (): void {
    $manager = new SftpAccessManager(new DryRunExecutor());
    $explain = (new ReflectionMethod($manager, 'explainSshdTest'));

    $translated = (string) $explain->invoke($manager, 'Missing privilege separation directory: /run/sshd');
    assertTrue(str_contains($translated, 'not a problem with the config file'), 'ต้องบอกว่าไม่ใช่ความผิดของไฟล์ที่เพิ่งเขียน: ' . $translated);

    // ข้อความอื่นต้องไม่ถูกแต่งเติมจนหาต้นฉบับไม่เจอ
    $plain = (string) $explain->invoke($manager, 'line 42: Bad configuration option');
    assertTrue(str_contains($plain, 'Bad configuration option'), 'ข้อความอื่นต้องยังเห็นต้นฉบับ: ' . $plain);
});

test('เขียน config แล้วต้องสั่ง sshd อ่านค่าใหม่ — ไม่งั้น Match block ถูกมองข้ามทั้งหมด', static function (): void {
    // **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เดิมไม่ได้สั่ง reload เลยเพราะเข้าใจผิด
    // ว่า "การเชื่อมต่อใหม่อ่าน config ใหม่เอง" · sshd อ่าน config ตอน start เท่านั้นแล้ว fork
    // ตัวลูกจากภาพในหน่วยความจำนั้น — ผลคือผู้ใช้ได้ shell nologin แทน internal-sftp
    // แล้ว sftp client รายงาน "Received message too long" ซึ่งชี้ไปคนละทางกับต้นตอ
    $executor = new SshdStateExecutor(sshdRunning: true);
    $manager = new SftpAccessManager($executor);

    try {
        $manager->enable(new UserAccount(1, 'reloaduser'), 'LongEnoughPass123');
    } catch (\Throwable) {
        // อาจล้มที่ขั้นอื่นในโหมดจำลอง — ที่ต้องตรวจคือมีคำสั่ง reload อยู่ในชุดคำสั่ง
    }

    $commands = implode(' | ', $executor->simulatedCommands());

    assertTrue(
        str_contains($commands, 'systemctl reload ssh'),
        'ต้องสั่ง `systemctl reload ssh` หลังเขียน config · ได้: ' . $commands,
    );
    // reload ไม่ใช่ restart — restart ตัดการเชื่อมต่อของผู้ดูแลที่อาจกำลัง ssh อยู่
    assertTrue(
        !str_contains($commands, 'systemctl restart ssh'),
        'ต้องใช้ reload ไม่ใช่ restart เพื่อไม่ตัดเซสชันที่ทำงานอยู่: ' . $commands,
    );
});

/**
 * Executor ที่จำลอง Ubuntu 22.10+ — `ssh.service` inactive แต่ `ssh.socket` ฟังอยู่
 *
 * ตอบตาม unit ที่ถูกถามจริง ๆ ไม่ใช่ตอบเหมือนกันหมด เพราะสิ่งที่ต้องพิสูจน์คือ
 * ระบบแยกแยะสองตัวนี้ออกจากกันได้
 */
final class SocketActivatedSshExecutor implements Executor
{
    private DryRunExecutor $inner;

    public function __construct()
    {
        $this->inner = new DryRunExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        if (basename((string) ($argv[0] ?? '')) === 'systemctl' && ($argv[1] ?? '') === 'show') {
            $unit = (string) ($argv[2] ?? '');

            if ($unit === 'ssh.socket') {
                return new ExecResult(
                    argv: $argv,
                    exitCode: 0,
                    stdout: "LoadState=loaded\nActiveState=active\nSubState=listening\nUnitFileState=enabled\n",
                    stderr: '',
                    durationMs: 0,
                );
            }

            // ตัว `.service` ของเครื่องแบบนี้ inactive ตลอดเวลาโดยไม่ผิดปกติ
            return new ExecResult(
                argv: $argv,
                exitCode: 0,
                stdout: "LoadState=loaded\nActiveState=inactive\nSubState=dead\nUnitFileState=static\n",
                stderr: '',
                durationMs: 0,
            );
        }

        // reload ตัว service ที่ inactive อยู่ — systemd ปฏิเสธจริงบนเครื่องแบบนี้
        if (basename((string) ($argv[0] ?? '')) === 'systemctl' && ($argv[1] ?? '') === 'reload') {
            return new ExecResult(
                argv: $argv,
                exitCode: 1,
                stdout: '',
                stderr: 'Job type reload is not applicable for unit ssh.service.',
                durationMs: 0,
            );
        }

        return $this->inner->exec($argv, $timeout, $cwd, $stdin);
    }

    public function mode(): Mode { return Mode::DryRun; }
    public function isSimulated(): bool { return true; }
    public function simulatedCommands(): array { return $this->inner->simulatedCommands(); }
    public function path(string $absolutePath): string { return $absolutePath; }

    /**
     * ตอบจากค่าคงที่ ไม่อ่าน /etc ของเครื่องที่รันเทสต์
     *
     * `DryRunExecutor` อ่านไฟล์จริงเพื่อให้ผลลัพธ์มีความหมาย ซึ่งทำให้เทสต์นี้เปลี่ยนผล
     * ตามเครื่อง: เครื่องที่เคยติดตั้ง phpcp มี `phpcp-sftp.conf` ตรงกับที่ระบบจะเขียนอยู่แล้ว
     * `ensureConfig()` จึง early-return แล้ว `sshd -t` ไม่เคยถูกเรียก — เทสต์ผ่านหรือไม่ผ่าน
     * โดยไม่เกี่ยวกับโค้ดที่กำลังตรวจเลย
     */
    public function readFile(string $path): string
    {
        // เนื้อไฟล์ที่ "ไม่ตรง" กับที่ระบบจะเขียน เพื่อบังคับให้เดินเส้นทางเขียน+ตรวจจริง
        if ($path === SftpAccessManager::CONFIG_FILE) {
            return "# ของเดิมที่ล้าสมัย\n";
        }

        if ($path === Phpcp\Driver\SshManager::CONFIG) {
            return "Include /etc/ssh/sshd_config.d/*.conf\nPort 22\n";
        }

        return $this->inner->readFile($path);
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void { $this->inner->writeFile($path, $content, $mode); }
    public function exists(string $path): bool
    {
        return in_array($path, [SftpAccessManager::CONFIG_FILE, Phpcp\Driver\SshManager::CONFIG], true)
            || $this->inner->exists($path);
    }
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

test('sshd ที่ถูกปลุกด้วย socket ต้องนับว่าทำงานอยู่ — ไม่งั้นเปิด SFTP ไม่ได้ทั้งเครื่อง', static function (): void {
    // **เจอจากเซิร์ฟเวอร์จริง (Lightsail + Ubuntu 24.04, 2026-08-13):** ตั้งแต่ Ubuntu 22.10
    // OpenSSH ใช้ socket activation เป็นค่าเริ่มต้น — `ssh.service` จึง inactive ตลอดเวลา
    // ทั้งที่พอร์ต 22 เปิดอยู่และผู้ดูแล ssh เข้ามาติดตั้ง panel ด้วยเส้นทางนั้นเอง
    // ผลเดิมคือกดเปิด SFTP แล้วได้ "บริการ SSH ไม่ได้ทำงานอยู่" บนเครื่องที่ SSH ใช้งานได้ปกติ
    $status = ServiceProbe::read(new SocketActivatedSshExecutor(), 'ssh');

    assertTrue($status['running'] === true, 'ssh.socket ที่ active ต้องทำให้ ssh นับว่าทำงานอยู่');
    assertTrue($status['status'] === 'running', 'สถานะที่ UI แสดงต้องเป็น running ไม่ใช่ stopped');
    assertTrue(($status['activation'] ?? '') === 'socket', 'ต้องบอกผู้เรียกว่าความพร้อมนี้มาจาก socket');
    // "เปิดตอนบูตไหม" ที่เป็นความจริงอยู่ที่ .socket — .service เป็น static เสมอ
    assertTrue($status['enabled'] === 'enabled', 'ต้องรายงาน enabled ตาม .socket ไม่ใช่ static ของ .service');
});

test('เครื่อง socket activation ต้องไม่สั่ง reload — systemd ปฏิเสธ แล้วผู้ดูแลจะเจอ error ที่แก้ตามไม่ได้', static function (): void {
    // การเชื่อมต่อถัดไปเกิดเป็นโปรเซส sshd ใหม่ที่อ่าน sshd_config สด ๆ อยู่แล้ว
    // จึงไม่มีอะไรต้อง reload · ถ้าฝืนสั่ง จะได้ "Job type reload is not applicable"
    // แล้วโยนทิ้งไปเป็นข้อความผิดพลาด ทั้งที่ทุกอย่างสำเร็จเรียบร้อย
    $executor = new SocketActivatedSshExecutor();
    $manager = new SftpAccessManager($executor);

    try {
        $manager->enable(new UserAccount(1, 'socketuser'), 'LongEnoughPass123');
    } catch (\Throwable $e) {
        assertTrue(
            !str_contains($e->getMessage(), 'อ่านค่าใหม่ไม่สำเร็จ'),
            'ต้องไม่ล้มเพราะ reload บนเครื่องที่ไม่มีอะไรให้ reload: ' . $e->getMessage(),
        );
        assertTrue(
            !str_contains($e->getMessage(), 'บริการ SSH ไม่ได้ทำงานอยู่'),
            'ต้องไม่บอกว่า SSH ไม่ทำงาน ทั้งที่ socket ฟังอยู่: ' . $e->getMessage(),
        );
    }

    $commands = implode(' | ', $executor->simulatedCommands());

    assertTrue(
        !str_contains($commands, 'systemctl reload'),
        'ต้องไม่สั่ง reload เลยบนเครื่อง socket activation · ได้: ' . $commands,
    );
});

test('ต้องสร้าง /run/sshd ก่อน sshd -t — ไม่งั้นการตรวจล้มทั้งที่ไฟล์ถูกต้อง', static function (): void {
    // systemd สร้างไดเรกทอรีนี้ผ่าน RuntimeDirectory=sshd ตอน start service เท่านั้น
    // บนเครื่อง socket activation ตัว service ไม่เคยรันยาว ๆ ไดเรกทอรีจึงหายได้ทุกเมื่อ
    $executor = new SocketActivatedSshExecutor();
    $manager = new SftpAccessManager($executor);

    try {
        $manager->enable(new UserAccount(1, 'runtimeuser'), 'LongEnoughPass123');
    } catch (\Throwable) {
        // สนใจแค่ว่าคำสั่งถูกออกไปก่อนการตรวจ
    }

    $commands = $executor->simulatedCommands();
    $joined = implode(' | ', $commands);

    assertTrue(str_contains($joined, '/run/sshd'), 'ต้องสร้าง /run/sshd · ได้: ' . $joined);

    $mkdirAt = null;
    $testAt = null;
    foreach ($commands as $index => $command) {
        if ($mkdirAt === null && str_contains($command, '/run/sshd')) {
            $mkdirAt = $index;
        }
        if ($testAt === null && str_contains($command, 'sshd -t')) {
            $testAt = $index;
        }
    }

    assertTrue(
        $mkdirAt !== null && $testAt !== null && $mkdirAt < $testAt,
        'ต้องสร้างไดเรกทอรีก่อนเรียก sshd -t ไม่ใช่หลัง · ได้: ' . $joined,
    );
});

test('SSH ต้องอยู่ในรายการบริการที่จัดการได้จากหน้าเว็บ', static function (): void {
    // ขาดไปตั้งแต่ต้น — SFTP ของลูกค้าทุกรายวิ่งบนตัวนี้ แต่ผู้ดูแลมองไม่เห็นสถานะ
    // และสั่ง restart จากหน้าเว็บไม่ได้เลย ต้อง ssh เข้าไปเอง ซึ่งเป็นไปไม่ได้พอดี
    // ในกรณีที่ SSH นั่นแหละคือตัวที่พัง
    $catalog = Phpcp\Domain\ServiceCatalog::all();

    assertTrue(isset($catalog['ssh']), 'ต้องมี ssh ในรายการบริการ');
    assertTrue($catalog['ssh']['critical'] === true, 'SSH คือทางเข้าเครื่องทางเดียวที่เหลือเมื่อ panel ล่ม');
});

// --- 4. รหัสผ่าน --------------------------------------------------------------

test('รหัสผ่านสั้นกว่า 12 ตัว หรือมี : ต้องถูกปฏิเสธ', static function (): void {
    $manager = new SftpAccessManager(new DryRunExecutor());
    $account = new UserAccount(1, 'pwuser');

    foreach (['สั้นไป' => 'Short1', 'มีโคลอน' => 'has:colon:in:it', 'มีอักขระควบคุม' => "line\nbreak12345"] as $why => $bad) {
        assertRejects(
            Phpcp\Agent\ValidationError::class,
            static fn () => $manager->enable($account, $bad),
            "รหัสผ่านที่{$why}ต้องถูกปฏิเสธ",
        );
    }
});

test('รหัสผ่านต้องไม่ไปโผล่ในอาร์กิวเมนต์ของคำสั่ง — /proc/<pid>/cmdline ผู้ใช้อื่นอ่านได้', static function (): void {
    // เหตุผลเดียวกับที่ SshDestination ไม่รองรับ password auth · chpasswd ต้องรับทาง stdin
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'secretpw', quotaFtp: 5, systemUser: 'secretpw');

    $executor = new DryRunExecutor();
    $secret = 'SuperSecretPassword123';

    $capability = new SftpEnable();

    try {
        $capability->run(
            $capability->validate(['user_id' => $account['id'], 'password' => $secret]),
            $executor,
            sftpContext($fixture),
        );
    } catch (\Throwable) {
        // ล้มได้ในโหมดจำลอง — ที่ต้องตรวจคือคำสั่งที่ "จะรัน" ไม่มีรหัสผ่านปนอยู่
    }

    $commands = implode(' | ', $executor->simulatedCommands());
    assertTrue(!str_contains($commands, $secret), 'รหัสผ่านต้องไม่อยู่ในอาร์กิวเมนต์ของคำสั่งใดเลย: ' . $commands);
});

// --- 5. ทะเบียน capability ----------------------------------------------------

test('sftp.enable/disable ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ และใช้สิทธิ์ระดับผู้ดูแล', static function (): void {
    $registry = new Phpcp\Agent\CapabilityRegistry();

    foreach (['sftp.enable', 'sftp.disable'] as $name) {
        $capability = $registry->resolve($name);

        assertTrue($capability->isMutating(), "{$name} แตะบัญชีระบบจริง ต้องเข้า audit");
        // การเปิดช่องเข้าเครื่องผ่าน sshd (ไม่มี rate limit/2FA แบบ panel) เป็นการตัดสินใจ
        // ของผู้ดูแลเซิร์ฟเวอร์ ไม่ใช่ของเจ้าของบัญชี — webadmin ต้องทำเองไม่ได้
        assertSame('customer.manage', $capability->permission(), "{$name} ต้องใช้ customer.manage");
    }

    assertTrue(!Permissions::roleHas(Permissions::WEBADMIN, 'customer.manage'), 'webadmin ต้องเปิด SFTP ให้ตัวเองไม่ได้');
});

// --- 6. โควตา FTP เป็นสวิตช์ ต้องสอดคล้องกันทั้งระบบ ---------------------------

test('ตัดสิทธิ์ SFTP ตามแพ็กเกจ ต้องปิดการเข้าถึงที่เปิดค้างอยู่ทันที', static function (): void {
    // **ช่องโหว่ที่เจอตอนไล่ตรวจความสอดคล้องของโควตา FTP (2026-08-10):** เดิมตั้งโควตาเป็น 0
    // แล้วบัญชียังล็อกอิน SFTP เข้าเครื่องได้ต่อไป และหน้าจอซ่อนปุ่มปิดไปด้วย
    // (`sftp_available` เป็น false) จึงปิดจากหน้าเว็บไม่ได้เลย — สิทธิ์ที่ถอนไม่ได้
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'revoked', quotaFtp: 5, systemUser: 'revoked');

    // จำลองว่าเปิด SFTP ไว้แล้ว
    $fixture['db']->update('users', ['sftp_enabled' => 1, 'sftp_enabled_at' => time()], ['id' => $account['id']]);

    $capability = new Phpcp\Agent\Capability\CustomerQuotaUpdate();
    $result = $capability->run(
        $capability->validate(['user_id' => $account['id'], 'quota_ftp_users' => 0]),
        new DryRunExecutor(),
        sftpContext($fixture),
    );

    assertSame(true, $result['sftp_revoked'], 'ต้องรายงานว่าตัดการเข้าถึงไปด้วย');
    assertSame(
        0,
        (int) $fixture['db']->value('SELECT sftp_enabled FROM users WHERE id = :id', ['id' => $account['id']]),
        'สถานะ SFTP ต้องถูกปิดจริงในฐานข้อมูล ไม่ใช่แค่โควตาเปลี่ยน',
    );
    assertTrue(str_contains($result['message'], 'revoked SFTP access'), 'ข้อความต้องบอกผู้ดูแลว่าลูกค้าจะเข้าไม่ได้แล้ว: ' . $result['message']);
});

test('ลดโควตาชนิดอื่นต้องไม่ไปแตะสถานะ SFTP', static function (): void {
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'untouched', quotaFtp: 5, systemUser: 'untouched');
    $fixture['db']->update('users', ['sftp_enabled' => 1], ['id' => $account['id']]);

    $capability = new Phpcp\Agent\Capability\CustomerQuotaUpdate();
    $result = $capability->run(
        $capability->validate(['user_id' => $account['id'], 'quota_domains' => 3]),
        new DryRunExecutor(),
        sftpContext($fixture),
    );

    assertSame(false, $result['sftp_revoked'], 'ไม่ได้แตะโควตา FTP จึงต้องไม่ตัดการเข้าถึง');
    assertSame(1, (int) $fixture['db']->value('SELECT sftp_enabled FROM users WHERE id = :id', ['id' => $account['id']]), 'SFTP ต้องยังเปิดอยู่');
});

test('โควตา FTP ถูกประกาศเป็นสวิตช์ ไม่ใช่จำนวน — หน้าจออ่านกฎจากที่เดียว', static function (): void {
    // ถ้าไม่ประกาศไว้ หน้าจอจะแสดง "0 / 5" ซึ่งสื่อว่าตั้งได้หลายบัญชี ทั้งที่มีได้บัญชีเดียว
    assertTrue(Phpcp\Domain\Quota::isToggle('ftp_users'), 'ftp_users ต้องเป็นสวิตช์');
    assertTrue(!Phpcp\Domain\Quota::isToggle('domains'), 'โดเมนยังเป็นจำนวนตามเดิม');
    assertTrue(!Phpcp\Domain\Quota::isToggle('databases'), 'ฐานข้อมูลยังเป็นจำนวนตามเดิม');
});

test('เปิด SFTP แล้วต้องนับเป็นการใช้งานหนึ่งรายการ ไม่ใช่ 0 ตลอดไป', static function (): void {
    // เดิม usage() คืน 0 เสมอเพราะ "ยังไม่มีตารางของตัวเอง" — หน้าจอจึงแสดงว่าไม่ได้ใช้งาน
    // ทั้งที่เปิดอยู่จริง
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'counted', quotaFtp: 5, systemUser: 'counted');
    $users = new Phpcp\Domain\UserRepository($fixture['db']);

    assertSame(0, $users->usage($account['id'])['ftp_users'], 'ยังไม่เปิด = 0');

    $fixture['db']->update('users', ['sftp_enabled' => 1], ['id' => $account['id']]);

    assertSame(1, $users->usage($account['id'])['ftp_users'], 'เปิดแล้วต้องนับเป็น 1');
});

test('ปิด SFTP ของบัญชีที่ไม่เคยเปิด ต้องไม่ล้ม (เรียกซ้ำได้)', static function (): void {
    $fixture = sftpFixture();
    $account = seedSftpAccount($fixture, 'neveron', quotaFtp: 5, systemUser: 'neveron');

    $capability = new SftpDisable();
    $result = $capability->run(
        $capability->validate(['user_id' => $account['id']]),
        new DryRunExecutor(),
        sftpContext($fixture),
    );

    assertSame('neveron', $result['username'], 'ต้องสำเร็จแม้ไม่เคยเปิดมาก่อน');
    assertSame(0, (int) $fixture['db']->value('SELECT sftp_enabled FROM users WHERE id = :id', ['id' => $account['id']]), 'สถานะต้องเป็นปิด');
});
