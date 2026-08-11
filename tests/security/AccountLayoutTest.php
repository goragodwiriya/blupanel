<?php

declare(strict_types=1);

/**
 * เลย์เอาต์แบบหนึ่งบัญชีต่อผู้ใช้ — เฟส M2/M3
 *
 * สิ่งที่เทสต์ชุดนี้เฝ้าไว้คือกฎที่ถ้าพลาดแล้วเสียหายเป็นวงกว้าง:
 *   1. **ลูกค้าต่างรายต้องแยกขาดจากกัน** — คนละ uid คนละ pool คนละ open_basedir
 *      (การแยกเว็บของลูกค้า*คนเดียวกัน*ถูกยกเลิกโดยตั้งใจ แต่ขอบเขตนี้ห้ามเปลี่ยน)
 *   2. **การลบหรือแก้เว็บหนึ่งเว็บต้องไม่ทำให้เว็บพี่น้องดับ** — pool ใช้ร่วมกัน
 *      การลบไฟล์ pool ทิ้งแบบไม่คิดจะทำให้เว็บอื่นของลูกค้าคนเดียวกันตายทั้งหมด
 *   3. **ชื่อบัญชีที่ชนกับผู้ใช้ระบบต้องถูกปฏิเสธ** — ไม่งั้นเรา chown ไฟล์ลูกค้า
 *      ไปให้ uid ของคนอื่นที่มีอยู่ก่อนแล้ว
 */

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\AccountProvisioner;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Security\Permissions;

group('AccountLayout — หนึ่งผู้ใช้ = หนึ่ง uid = หนึ่งบ้าน = หลายเว็บ');

/**
 * Executor ที่บันทึกคำสั่งไว้ให้ตรวจ และตอบ getent ได้ตามที่กำหนด
 *
 * สืบทอดจากตัวจำลองใน SitePathsTest ไม่ได้เพราะต้องคุมคำตอบของ getent เอง
 * — จึงยืมโครงเดียวกันมาแล้วเปลี่ยนเฉพาะ exec()
 */
final class AccountExecutor implements Executor
{
    /** @var list<string> */
    public array $commands = [];

    /** @param array<string,string> $passwd ชื่อผู้ใช้ => บรรทัดใน /etc/passwd */
    public function __construct(private readonly array $passwd = [])
    {
    }

    public function mode(): \Phpcp\Kernel\Mode
    {
        return \Phpcp\Kernel\Mode::Sandbox;
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $this->commands[] = implode(' ', $argv);
        $binary = basename($argv[0]);

        if ($binary === 'getent') {
            $user = $argv[2] ?? '';

            return new ExecResult(
                argv: $argv,
                exitCode: isset($this->passwd[$user]) ? 0 : 2,
                stdout: $this->passwd[$user] ?? '',
                stderr: '',
                durationMs: 0,
                simulated: true,
            );
        }

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: $binary === 'id' ? '2001' : '',
            stderr: '',
            durationMs: 0,
            simulated: true,
        );
    }

    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->commands[] = "mkdir {$path} ".decoct($mode);
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
        return null;
    }

    public function rename(string $from, string $to): void
    {
    }

    public function copyPath(string $from, string $to): void
    {
    }

    public function removePath(string $path): void
    {
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

function layoutSite(string $owner, string $domain, string $php = '8.4'): Site
{
    return new Site(
        id: crc32($domain) % 1000,
        name: $domain,
        domain: $domain,
        owner: new UserAccount(crc32($owner) % 100, $owner),
        phpVersion: $php,
    );
}

test('ลูกค้าต่างรายแยกขาดจากกันทุกชั้น', static function (): void {
    $mine = layoutSite('alice', 'alice-shop.test');
    $theirs = layoutSite('bob', 'bob-shop.test');

    assertTrue($mine->systemUser() !== $theirs->systemUser(), 'ต้องคนละ uid');
    assertTrue($mine->fpmSocket() !== $theirs->fpmSocket(), 'ต้องคนละ socket');
    assertTrue($mine->fpmPoolFile() !== $theirs->fpmPoolFile(), 'ต้องคนละไฟล์ pool');
    assertTrue($mine->owner->home() !== $theirs->owner->home(), 'ต้องคนละบ้าน');

    // บ้านของอีกฝ่ายต้องไม่อยู่ใต้บ้านของเรา — ถ้าอยู่ open_basedir จะครอบไปถึง
    assertTrue(
        !str_starts_with($theirs->owner->home().'/', $mine->owner->home().'/'),
        'บ้านของลูกค้ารายหนึ่งต้องไม่ซ้อนอยู่ในบ้านของอีกราย',
    );

    $templates = new Template(PHPCP_ROOT.'/templates');
    $pool = (new FpmManager($templates))->renderPool($mine, 'www-data', new AccountExecutor());

    assertTrue(str_contains($pool, 'user  = alice'), 'pool ต้องรันด้วย uid ของเจ้าของ');
    assertTrue(
        !str_contains($pool, $theirs->owner->home()),
        'open_basedir ของ alice ต้องไม่มีเส้นทางของ bob โผล่มาเลย',
    );
    assertTrue(
        !preg_match('/open_basedir\] = \/srv\/phpcp\/users:/', $pool),
        'open_basedir ต้องไม่กว้างถึงโฟลเดอร์แม่ที่เก็บบ้านของทุกคน',
    );
});

test('เว็บของเจ้าของคนเดียวกันใช้ pool ร่วมกัน แต่ยังแยกโฟลเดอร์', static function (): void {
    $first = layoutSite('alice', 'one.test');
    $second = layoutSite('alice', 'two.test');

    assertSame($first->fpmPoolFile(), $second->fpmPoolFile(), 'ใช้ไฟล์ pool เดียวกัน');
    assertSame($first->fpmSocket(), $second->fpmSocket(), 'ใช้ socket เดียวกัน');
    assertTrue($first->root() !== $second->root(), 'แต่ไฟล์ยังแยกโฟลเดอร์');

    // และ open_basedir ต้องครอบทั้งสองเว็บ ไม่ใช่เว็บใดเว็บหนึ่ง
    $pool = (new FpmManager(new Template(PHPCP_ROOT.'/templates')))
        ->renderPool($first, 'www-data', new AccountExecutor());

    preg_match('/open_basedir\] = ([^\n]+)/', $pool, $m);
    $allowed = explode(':', $m[1] ?? '');

    foreach ([$first->root(), $second->root()] as $root) {
        $covered = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with($root.'/', rtrim($prefix, '/').'/')) {
                $covered = true;
            }
        }
        assertTrue($covered, "open_basedir ต้องครอบ {$root}");
    }
});

test('Domain Pointer ของทุกเว็บในบัญชีต้องอยู่ใน open_basedir ของ pool ที่ใช้ร่วมกัน', static function (): void {
    // เว็บ A อยู่ในบ้านตามปกติ · เว็บ B ชี้ docroot ออกไปนอกบ้าน
    // ทั้งคู่ใช้ pool เดียวกัน ถ้า open_basedir มีแค่บ้าน เว็บ B จะขึ้น 500 ทันที
    $inHome = layoutSite('alice', 'in-home.test');

    $pool = (new FpmManager(new Template(PHPCP_ROOT.'/templates')))->renderPool(
        $inHome,
        'www-data',
        new AccountExecutor(),
        ['/mnt/legacy/project-b'],
    );

    assertTrue(str_contains($pool, '/srv/phpcp/users/alice:'), 'ต้องมีบ้านของเจ้าของ');
    assertTrue(str_contains($pool, ':/mnt/legacy/project-b:'), 'ต้องมีโฟลเดอร์ที่ Domain Pointer ชี้ไป');
});

test('ชื่อบัญชีที่ชนกับผู้ใช้ระบบที่มีอยู่แล้วต้องถูกปฏิเสธ', static function (): void {
    // รายชื่อต้องห้ามที่เขียนตายตัวย่อมตกหล่นบัญชีที่ผู้ดูแลสร้างเองทีหลัง
    // ด่านที่เชื่อถือได้จริงคือถาม /etc/passwd ของเครื่องนั้น ๆ
    $provisioner = new AccountProvisioner(new ApacheDriver(new Template(PHPCP_ROOT.'/templates')));

    $executor = new AccountExecutor([
        'deploy' => 'deploy:x:1001:1001:คนของบริษัท:/home/deploy:/bin/bash',
    ]);

    assertRejects(
        ExecutionFailed::class,
        static fn () => $provisioner->ensure($executor, new UserAccount(9, 'deploy')),
        'ชื่อที่ชนกับบัญชีของระบบต้องถูกปฏิเสธก่อนแตะไฟล์ใด ๆ',
    );

    assertTrue(
        !in_array('/usr/sbin/useradd', array_map(
            static fn (string $c): string => explode(' ', $c)[0],
            $executor->commands,
        ), true),
        'ต้องหยุดก่อนเรียก useradd ไม่ใช่หยุดหลังจากสร้างไปแล้ว',
    );
});

test('บัญชีที่ panel สร้างไว้เองต้องใช้ซ้ำได้ ไม่ใช่ถูกปฏิเสธว่าชื่อซ้ำ', static function (): void {
    // เว็บที่สองของลูกค้าคนเดิมต้องสร้างได้ — ตัดสินจาก home directory ใน /etc/passwd
    // ซึ่งเป็นหลักฐานเดียวที่บอกได้ว่าบัญชีนี้เป็นของ panel จริง
    $provisioner = new AccountProvisioner(new ApacheDriver(new Template(PHPCP_ROOT.'/templates')));
    $account = new UserAccount(9, 'alice');

    $executor = new AccountExecutor([
        'alice' => 'alice:x:2001:2001:phpcp hosting account alice:'.$account->home().':/usr/sbin/nologin',
    ]);

    $identity = $provisioner->ensure($executor, $account);

    assertSame(2001, $identity['uid'], 'ต้องอ่าน uid เดิมกลับมาได้');
    assertTrue(
        in_array('mkdir '.$account->tmpDir().' 700', $executor->commands, true),
        'tmp ต้องเป็น 0700 — session ของ PHP อยู่ในนั้น การให้กลุ่มอ่านได้คือการยอมให้สวมสิทธิ์',
    );
    assertTrue(
        in_array('mkdir '.\Phpcp\Kernel\Paths::usersDir().' 711', $executor->commands, true),
        'โฟลเดอร์แม่ต้องเป็น 0711 — เดินผ่านได้แต่ ls ดูรายชื่อลูกค้ารายอื่นไม่ได้',
    );
});

test('ชื่อผู้ใช้ที่ถูกสงวนไว้ต้องสร้างไม่ได้ตั้งแต่ชั้นฐานข้อมูล', static function (): void {
    $users = new UserRepository(migratedDb());

    foreach (['root', 'www-data', 'mysql', 'phpcp', 'nobody'] as $reserved) {
        assertRejects(
            InvalidArgumentException::class,
            static fn () => $users->createHostingAccount($reserved, 'Reserved-Name-Pass-11', '', 'a@example.com'),
            "ชื่อ {$reserved} ต้องถูกสงวนไว้",
        );
    }

    // และชื่อที่ใช้เป็นชื่อโฟลเดอร์/ชื่อบัญชี Linux ไม่ได้ ก็ต้องถูกปฏิเสธเช่นกัน
    foreach (['Alice', 'has.dot', 'ab', 'has space', '1abc'] as $bad) {
        assertRejects(
            InvalidArgumentException::class,
            static fn () => $users->createHostingAccount($bad, 'Bad-Name-Password-11', '', 'a@example.com'),
            "ชื่อ {$bad} ต้องถูกปฏิเสธ",
        );
    }

    $id = $users->createHostingAccount('somchai_a', 'Good-Name-Password-11', 'สมชาย', 'somchai@example.com');
    assertTrue($id > 0, 'ชื่อที่ถูกกฎต้องสร้างได้');
    assertSame(Permissions::WEBADMIN, $users->find($id)['role'], 'บัญชีโฮสติ้งต้องเป็น webadmin');
});
