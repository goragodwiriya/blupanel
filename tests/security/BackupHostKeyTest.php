<?php

declare(strict_types=1);

/**
 * Host key ของปลายทาง sftp/rsync — ทางที่ผู้ดูแลตั้งค่าให้ผ่านด่านความปลอดภัยได้
 *
 * **เจอจากการใช้งานจริง (2026-08-14):** ส่งไฟล์สำรองไปเครื่องจริงครั้งแรกล้มด้วย
 * "No ED25519 host key is known ... Host key verification failed" · ข้อความบอกให้
 * รัน `ssh-keyscan` แล้วใส่ผลลัพธ์ใน "ช่อง known_hosts" — แต่**ในฟอร์มไม่มีช่องนั้นเลย**
 * และค่าที่เก็บเดิม (`known_hosts_file`) เป็นเส้นทางไฟล์ ที่ผู้ดูแลวางบนเครื่อง panel
 * เองไม่ได้เพราะ systemd hardening · ทางที่บอกให้ทำจึงเป็นทางตัน
 *
 * แก้ให้ `known_hosts` เก็บ **เนื้อหา** ของ ssh-keyscan แล้วเขียนลงไฟล์ชั่วคราวตอนใช้
 * แบบเดียวกับ private key · เทสต์นี้ตรึงพฤติกรรมนั้น: เนื้อหาต้องไปถึง ssh จริงในรูป
 * `UserKnownHostsFile` และไฟล์ชั่วคราวต้องถูกลบทุกกรณี — host key ที่ค้างใน /tmp
 * ไม่ใช่ความลับ แต่ไฟล์ที่สะสมไม่รู้จบคือบั๊กในตัวมันเอง
 *
 * `StrictHostKeyChecking=yes` ต้องยังเปิดอยู่เสมอ — ปิดมันทำให้ทุกการส่งไฟล์สำรอง
 * ถูกดักกลางทางได้โดยไม่มีอะไรฟ้อง · การมีช่อง host key คือ**ทางที่ถูก**ในการผ่านด่านนี้
 */

use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Backup\SftpDestination;
use Phpcp\Kernel\Mode;

group('ปลายทางสำรอง — host key ที่ผู้ดูแลใส่เอง');

/** ผลของ ssh-keyscan จริง (ตัดให้สั้น) — รูปแบบที่ผู้ดูแลจะวางลงช่อง */
const SAMPLE_HOST_KEY = "[18.142.27.80]:22 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIabcdef0123456789example";

/**
 * เรียก sshOptions() ผ่าน withKey() แล้วคืนทั้ง argv และร่องรอยไฟล์ที่จับได้
 *
 * ต้องเดินผ่าน withKey() จริง เพราะเส้นทางไฟล์ known_hosts ถูกตั้งที่นั่น ไม่ใช่ค่าคงที่
 *
 * @return array{options:list<string>,writes:array<string,string>,removed:list<string>}
 */
function hostKeyRun(string $knownHosts): array
{
    $destination = new SftpDestination(
        host: '18.142.27.80',
        port: 22,
        user: 'backup',
        path: '/srv/backups',
        privateKey: "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----",
        knownHosts: $knownHosts,
    );

    $executor = new HostKeyExecutor();

    $withKey = new ReflectionMethod($destination, 'withKey');
    $sshOptions = new ReflectionMethod($destination, 'sshOptions');

    /** @var list<string> $options */
    $options = $withKey->invoke($destination, $executor, static function (string $keyFile) use ($sshOptions, $destination): array {
        return $sshOptions->invoke($destination, $keyFile);
    });

    return [
        'options' => $options,
        'writes' => $executor->writes,
        'removed' => $executor->removed,
    ];
}

/** ค่าที่ตามหลัง UserKnownHostsFile= ใน argv (null = ไม่มีตัวเลือกนี้) */
function knownHostsArg(array $options): ?string
{
    foreach ($options as $option) {
        if (str_starts_with($option, 'UserKnownHostsFile=')) {
            return substr($option, strlen('UserKnownHostsFile='));
        }
    }

    return null;
}

test('host key ที่ใส่มาต้องไปถึง ssh ในรูป UserKnownHostsFile', static function (): void {
    $run = hostKeyRun(SAMPLE_HOST_KEY);

    $hostsFile = knownHostsArg($run['options']);

    assertTrue($hostsFile !== null, 'ต้องมี -o UserKnownHostsFile=... เมื่อผู้ดูแลใส่ host key');
    assertTrue(
        isset($run['writes'][$hostsFile]),
        'เส้นทางที่ ssh อ่าน ต้องเป็นไฟล์ที่เพิ่งเขียน ไม่ใช่เส้นทางลอย ๆ',
    );
    assertTrue(
        str_contains($run['writes'][$hostsFile], SAMPLE_HOST_KEY),
        'เนื้อไฟล์ต้องเป็น host key ที่ผู้ดูแลวางมา',
    );
});

test('StrictHostKeyChecking ต้องยังเปิดอยู่แม้ใส่ host key แล้ว', static function (): void {
    // ช่อง host key คือทางที่ถูกในการผ่านด่าน ไม่ใช่การปิดด่าน
    $joined = implode(' ', hostKeyRun(SAMPLE_HOST_KEY)['options']);

    assertTrue(str_contains($joined, 'StrictHostKeyChecking=yes'), 'ด่านตรวจ host key ต้องไม่ถูกปิด');
    assertTrue(
        !str_contains($joined, 'StrictHostKeyChecking=no') && !str_contains($joined, 'StrictHostKeyChecking=accept-new'),
        'ต้องไม่มีการผ่อนด่านเป็น no หรือ accept-new',
    );
});

test('ไฟล์ known_hosts ชั่วคราวต้องถูกลบหลังใช้เสร็จ', static function (): void {
    $run = hostKeyRun(SAMPLE_HOST_KEY);

    $hostsFile = knownHostsArg($run['options']);

    assertTrue(
        in_array($hostsFile, $run['removed'], true),
        'ไฟล์ host key ชั่วคราวต้องถูกลบ — ไฟล์ที่สะสมใน /tmp คือบั๊กในตัวมันเอง',
    );
});

test('ไม่ใส่ host key ต้องไม่มี UserKnownHostsFile — ให้ด่านเดิมทำงานแล้วแนะนำวิธีแก้', static function (): void {
    // เว้นว่าง = ยังไม่ตั้ง · ssh จะใช้ known_hosts ของระบบซึ่งไม่มี host key นี้ แล้ว
    // ล้มด้วยข้อความที่ explain() แปลงเป็นคำแนะนำให้ ตามที่ผู้ใช้เจอจริง
    $run = hostKeyRun('');

    assertSame(null, knownHostsArg($run['options']), 'ไม่ใส่ host key ต้องไม่โผล่ตัวเลือกนี้');

    // key file ยังถูกเขียนและลบตามปกติ — ที่ต้องไม่มีคือไฟล์ host key โดยเฉพาะ
    $hostKeyFiles = array_filter($run['removed'], static fn (string $p): bool => str_contains($p, 'phpcp-known'));

    assertSame([], array_values($hostKeyFiles), 'ไม่ได้ใส่ host key ก็ไม่ควรมีไฟล์ host key ให้เขียนหรือลบ');
});

test('ข้อความเมื่อ host key ยังไม่ถูกตั้ง ต้องบอกวิธีแก้ที่ทำตามได้', static function (): void {
    // สิ่งที่ผู้ใช้เจอจริง — ต้องบอกทั้งคำสั่งที่ต้องรันและช่องที่ต้องวางผลลัพธ์
    $destination = new SftpDestination(
        host: '18.142.27.80',
        port: 22,
        user: 'backup',
        path: '/srv/backups',
        privateKey: "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----",
    );

    $explain = new ReflectionMethod($destination, 'explain');

    $message = (string) $explain->invoke($destination, 'Host key verification failed.');

    assertTrue(str_contains($message, 'known_hosts'), 'ต้องบอกว่าค่าไปอยู่ช่องไหน');
    assertTrue(
        str_contains($message, 'อ่านจากเครื่องปลายทาง'),
        'ต้องชี้ไปที่ปุ่มที่ทำให้เลย ไม่ใช่ให้ไปรันคำสั่งเองแล้ว copy กลับมา',
    );
});

test('"Permission denied" ต้องแยกให้ออกว่าเป็นกุญแจหรือสิทธิ์ของโฟลเดอร์', static function (): void {
    /*
     * **เจอจากการใช้งานจริง (2026-08-14):** ตั้ง path เป็น `/backup` ซึ่งอยู่ติดราก
     * ของ filesystem · ยืนยันตัวตนผ่านแล้ว แต่ `mkdir` ล้มเพราะผู้ใช้ธรรมดาสร้าง
     * ไดเรกทอรีที่รากไม่ได้ · ข้อความเดิมจับคำว่า "Permission denied" คำเดียวแล้วบอก
     * ให้ไปแก้ authorized_keys ทุกครั้ง — พาไปนั่งไล่กุญแจที่ไม่เคยมีปัญหา
     */
    $destination = new SftpDestination(
        host: '18.142.27.80',
        port: 22,
        user: 'ubuntu',
        path: '/backup',
        privateKey: "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----",
    );

    $explain = new ReflectionMethod($destination, 'explain');

    // สิ่งที่ผู้ใช้เจอจริง — สิทธิ์ของโฟลเดอร์ ไม่ใช่การยืนยันตัวตน
    $folder = (string) $explain->invoke(
        $destination,
        "remote mkdir \"/backup\": Permission denied\ndest open \"/backup/.phpcp-probe-bceccaa6\": No such file or directory",
    );

    assertTrue(
        !str_contains($folder, 'authorized_keys'),
        'สิทธิ์ของโฟลเดอร์ต้องไม่ถูกอธิบายว่าเป็นปัญหาของกุญแจ',
    );
    assertTrue(str_contains($folder, '/backup'), 'ต้องบอกว่าเส้นทางไหนที่เขียนไม่ได้');
    assertTrue(str_contains($folder, 'ubuntu'), 'ต้องบอกว่าผู้ใช้คนไหนที่ไม่มีสิทธิ์');
    assertTrue(str_contains($folder, '/home/ubuntu'), 'ต้องเสนอเส้นทางที่ใช้ได้จริงให้ด้วย');

    // การยืนยันตัวตนล้มจริง ๆ ยังต้องได้คำแนะนำเดิม
    $auth = (string) $explain->invoke($destination, 'ubuntu@18.142.27.80: Permission denied (publickey).');

    assertTrue(str_contains($auth, 'authorized_keys'), 'กุญแจไม่ผ่านต้องยังชี้ไปที่ authorized_keys');
});

test('sftp ต้องสร้างไดเรกทอรีปลายทางทีละชั้น ไม่ใช่ชั้นเดียว', static function (): void {
    /*
     * `sftp` ไม่มี `mkdir -p` · ตั้ง path ซ้อนหลายชั้นแล้วชั้นกลางยังไม่มี จะล้มทั้งที่
     * ผู้ใช้มีสิทธิ์เขียนทุกชั้น — อาการที่หน้าตาเหมือน "สิทธิ์ไม่พอ" แต่เป็นแค่ลำดับ
     * การสร้าง · rsync ไม่มีปัญหานี้เพราะมันสั่ง `mkdir -p` ผ่าน ssh อยู่แล้ว
     */
    $destination = new SftpDestination(
        host: 'example.com',
        port: 22,
        user: 'backup',
        path: '/home/backup/archives/phpcp',
        privateKey: "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----",
    );

    $method = new ReflectionMethod($destination, 'makeDirectoryScript');
    $script = (string) $method->invoke($destination);

    assertSame(
        [
            '-mkdir "/home"',
            '-mkdir "/home/backup"',
            '-mkdir "/home/backup/archives"',
            '-mkdir "/home/backup/archives/phpcp"',
        ],
        array_values(array_filter(explode("\n", $script), static fn (string $l): bool => $l !== '')),
        'ต้องสั่งสร้างทุกชั้นจากบนลงล่าง และใช้ `-` เพื่อข้ามชั้นที่มีอยู่แล้ว',
    );
});

/**
 * Executor จำลองที่จับการเขียนไฟล์และการลบ โดยไม่แตะดิสก์หรือรัน ssh จริง
 *
 * ต้องไม่ทำงานจริงเพราะเทสต์นี้พิสูจน์แค่ว่า "อะไรถูกส่งให้ ssh" กับ "ไฟล์ถูกลบไหม"
 * ไม่ใช่ว่าเชื่อมต่อสำเร็จ — ซึ่งต้องมีเครื่องปลายทางจริง
 */
final class HostKeyExecutor implements Executor
{
    /** @var array<string,string> path => content · บันทึกถาวร ไม่ลบตอน removePath เพื่อให้ตรวจย้อนได้ */
    public array $writes = [];

    /** @var array<string,bool> ไฟล์ที่ยังมีอยู่จริงตอนนี้ — แยกจาก writes เพราะ exists() ต้องสะท้อนการลบ */
    private array $present = [];

    /** @var list<string> */
    public array $removed = [];

    public function mode(): Mode
    {
        return Mode::Sandbox;
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        return new ExecResult(argv: $argv, exitCode: 0, stdout: '', stderr: '', durationMs: 0, simulated: true);
    }

    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->writes[$path] = $content;
        $this->present[$path] = true;
    }

    public function exists(string $path): bool
    {
        return $this->present[$path] ?? false;
    }

    public function removePath(string $path): void
    {
        $this->removed[] = $path;
        unset($this->present[$path]);
    }

    public function readFile(string $path): string
    {
        return $this->writes[$path] ?? '';
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

    public function stat(string $path): ?array
    {
        return isset($this->writes[$path]) ? ['size' => strlen($this->writes[$path])] : null;
    }

    public function rename(string $from, string $to): void
    {
    }

    public function copyPath(string $from, string $to): void
    {
    }

    public function changeMode(string $path, int $mode): void
    {
    }

    public function zip(array $sources, string $base, string $archive): array
    {
        return [];
    }

    public function unzip(string $archive, string $destination): array
    {
        return [];
    }

    public function asUser(?string $systemUser, callable $work): array
    {
        return [];
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

// --- ชื่อเครื่องที่กลายเป็นอาร์กิวเมนต์ของคำสั่ง -------------------------------

test('ชื่อเครื่องที่ขึ้นต้นด้วย - ต้องถูกปฏิเสธ ไม่ใช่กลายเป็นตัวเลือกของ ssh-keyscan', static function (): void {
    /*
     * `Executor::exec()` รับ argv เป็น array จึงไม่มีเชลล์มาตีความ — แต่ `ssh-keyscan`
     * เองอ่านค่าที่ขึ้นต้นด้วย `-` เป็นตัวเลือกของมัน · ค่าอย่าง `-f/etc/passwd`
     * จะกลายเป็น "อ่านรายชื่อโฮสต์จากไฟล์นี้" แทนที่จะเป็นชื่อเครื่อง
     */
    $capability = new Phpcp\Agent\Capability\BackupHostKeyScan();

    $bad = [
        'ตัวเลือกของคำสั่ง' => '-f/etc/passwd',
        'ขึ้นต้นด้วยขีด' => '-v',
        'เว้นวรรค' => 'host name',
        'ขึ้นบรรทัดใหม่' => "host\n-f/etc/passwd",
        'ว่าง' => '',
        'อัฒภาค' => 'host;id',
    ];

    foreach ($bad as $label => $host) {
        assertRejects(
            Phpcp\Agent\ValidationError::class,
            static fn () => $capability->validate(['host' => $host, 'port' => 22]),
            "ต้องปฏิเสธ: {$label}",
        );
    }

    // ค่าที่ใช้งานจริงต้องผ่าน — IPv4, ชื่อโดเมน และ IPv6 ในวงเล็บเหลี่ยม
    foreach (['18.142.27.80', 'srv.bluprint.in.th', '[2001:db8::1]'] as $good) {
        $clean = $capability->validate(['host' => $good, 'port' => 22]);

        assertSame($good, $clean['host'], "ต้องรับ: {$good}");
    }
});

test('พอร์ตนอกช่วงต้องถูกปฏิเสธ', static function (): void {
    $capability = new Phpcp\Agent\Capability\BackupHostKeyScan();

    foreach ([0, -1, 65536, 99999] as $port) {
        assertRejects(
            Phpcp\Agent\ValidationError::class,
            static fn () => $capability->validate(['host' => 'example.com', 'port' => $port]),
            "ต้องปฏิเสธพอร์ต {$port}",
        );
    }

    assertSame(22, $capability->validate(['host' => 'example.com'])['port'], 'ไม่ระบุพอร์ตต้องได้ 22');
});

test('อ่าน host key ต้องไม่ถูกนับเป็นคำสั่งที่เปลี่ยนแปลงระบบ', static function (): void {
    // ไม่แตะอะไรทั้งบนเครื่องนี้และเครื่องปลายทาง · ผลพลอยได้คือได้ Executor จริง
    // ในโหมด dryrun ซึ่งเป็นตอนที่ผู้ดูแลกำลังลองตั้งค่าอยู่พอดี
    assertTrue(
        !(new Phpcp\Agent\Capability\BackupHostKeyScan())->isMutating(),
        'ssh-keyscan อ่านอย่างเดียว',
    );
});
