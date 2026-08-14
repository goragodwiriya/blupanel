<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * ฐานร่วมของปลายทางที่คุยผ่าน OpenSSH — `sftp` และ `rsync`
 *
 * **ทำไมเป็น OpenSSH ไม่ใช่ไลบรารี:** โปรเจกต์ไม่มี Composer และ `ext-ssh2` ไม่ได้ติดมา
 * กับ PHP มาตรฐาน · `ssh`/`sftp`/`rsync` มีอยู่บนทุกเครื่องที่ระบบนี้รองรับอยู่แล้ว
 * และเดินผ่าน `Executor` เหมือน capability อื่นทั้งหมด จึงได้ audit กับโหมด dryrun มาด้วย
 *
 * **ยืนยันตัวตนด้วยกุญแจเท่านั้น — ไม่รองรับรหัสผ่านโดยตั้งใจ**
 *
 * private key ที่เข้ารหัสไว้ในฐานข้อมูลถูกเขียนลงไฟล์ชั่วคราวสิทธิ์ 0600 เฉพาะตอนใช้
 * แล้วลบทุกกรณีรวมทั้งตอนคำสั่งล้มเหลว
 *
 * รหัสผ่านถูกตัดออกด้วยเหตุผลสามข้อ: ต้องติดตั้ง `sshpass` เพิ่ม · ส่งผ่าน
 * อาร์กิวเมนต์ไม่ได้เพราะ `/proc/<pid>/cmdline` ผู้ใช้อื่นบนเครื่องอ่านได้ · และ
 * ส่งผ่านตัวแปรแวดล้อมก็ทำไม่ได้เพราะ `Executor::exec()` ไม่รับ env ซึ่งเป็นข้อจำกัด
 * ที่**ไม่ควรขยายเพื่อรองรับวิธียืนยันตัวตนที่อ่อนกว่าอยู่แล้ว** · กุญแจยังเป็นสิ่งที่
 * ถอนคืนได้ทีละใบโดยไม่กระทบบัญชีอื่น ซึ่งรหัสผ่านทำไม่ได้
 *
 * **`StrictHostKeyChecking` เปิดไว้เสมอโดยตั้งใจ** — ปิดมันทำให้ทุกการส่งไฟล์สำรอง
 * ถูกดักกลางทางแล้วรับไปได้โดยไม่มีอะไรฟ้อง ซึ่งเป็นการยกข้อมูลทั้งระบบให้ผู้โจมตี ·
 * ราคาที่จ่ายคือผู้ดูแลต้องใส่ host key ตอนตั้งค่า ซึ่ง `test()` บอกวิธีไว้ในข้อความ error
 */
abstract class SshDestination implements Destination
{
    protected const SSH_OPTIONS = [
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15',
        '-o', 'ServerAliveInterval=15',
    ];

    /**
     * เส้นทางไฟล์ known_hosts ชั่วคราวระหว่างที่คำสั่งกำลังทำงาน
     *
     * `$knownHosts` เก็บ **เนื้อหา** (ผลของ `ssh-keyscan`) ไม่ใช่เส้นทางไฟล์ — เพราะ
     * ผู้ดูแลวางไฟล์บนเครื่อง panel เองไม่ได้ (systemd hardening จำกัดที่เขียนได้) และ
     * ไม่มีช่องในหน้าเว็บให้กรอกเส้นทาง · เนื้อหาถูกเขียนลงไฟล์ชั่วคราวตอนใช้แบบเดียว
     * กับ private key แล้วลบทิ้งทุกกรณี · ค่านี้ถูกตั้งใน withKey() และล้างใน finally
     */
    private ?string $knownHostsFile = null;

    public function __construct(
        protected readonly string $host,
        protected readonly int $port,
        protected readonly string $user,
        protected readonly string $path,
        protected readonly string $privateKey = '',
        protected readonly string $knownHosts = '',
    ) {
        if ($this->host === '') {
            throw new ValidationError('ต้องระบุชื่อเครื่องปลายทาง');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new ValidationError('พอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
        }

        if ($this->user === '') {
            throw new ValidationError('ต้องระบุชื่อผู้ใช้ของเครื่องปลายทาง');
        }

        if ($this->path === '' || !str_starts_with($this->path, '/')) {
            throw new ValidationError('เส้นทางปลายทางต้องเป็นเส้นทางเต็มที่ขึ้นต้นด้วย /');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->path) === 1) {
            throw new ValidationError('เส้นทางปลายทางต้องไม่มี ..');
        }

        if ($this->privateKey === '') {
            throw new ValidationError('ต้องมีกุญแจส่วนตัวสำหรับเข้าเครื่องปลายทาง');
        }
    }

    /**
     * เตรียมไฟล์ความลับชั่วคราวแล้วรันงาน — ลบไฟล์ทุกกรณี
     *
     * รับ callback แทนที่จะคืนเส้นทางไฟล์ออกไป เพื่อให้ **ไม่มีทางลืมลบ** ·
     * private key ที่ค้างอยู่ใน /tmp คือกุญแจเข้าเครื่องสำรองที่ใครก็อ่านได้
     *
     * @param callable(string): mixed $work รับเส้นทางไฟล์กุญแจชั่วคราว
     */
    protected function withKey(Executor $executor, callable $work): mixed
    {
        $keyFile = sys_get_temp_dir() . '/phpcp-key-' . bin2hex(random_bytes(8));
        $hostsFile = null;

        try {
            $executor->writeFile($executor->path($keyFile), rtrim($this->privateKey, "\n") . "\n", 0600);

            // host key ที่ผู้ดูแลวางไว้ → ไฟล์ชั่วคราวที่ UserKnownHostsFile ชี้ไป
            if (trim($this->knownHosts) !== '') {
                $hostsFile = sys_get_temp_dir() . '/phpcp-known-' . bin2hex(random_bytes(8));
                $executor->writeFile($executor->path($hostsFile), rtrim($this->knownHosts, "\n") . "\n", 0600);
                $this->knownHostsFile = $hostsFile;
            }

            return $work($keyFile);
        } finally {
            // ล้างสถานะก่อนเสมอ ไม่งั้นครั้งถัดไปอ้างเส้นทางไฟล์ที่ลบไปแล้ว
            $this->knownHostsFile = null;

            if ($executor->exists($executor->path($keyFile))) {
                $executor->removePath($executor->path($keyFile));
            }

            if ($hostsFile !== null && $executor->exists($executor->path($hostsFile))) {
                $executor->removePath($executor->path($hostsFile));
            }
        }
    }

    /** ตัวเลือกของ ssh ที่ใช้ร่วมกันทุกคำสั่ง */
    protected function sshOptions(string $keyFile): array
    {
        $options = self::SSH_OPTIONS;

        if ($this->knownHostsFile !== null) {
            $options[] = '-o';
            $options[] = 'UserKnownHostsFile=' . $this->knownHostsFile;
        }

        // IdentitiesOnly กัน ssh ไปหยิบกุญแจอื่นของ root มาลองแทน ซึ่งจะทำให้
        // "ตั้งค่าผิดแต่ใช้งานได้" แล้วพังตอนย้ายเครื่อง
        return [...$options, '-i', $keyFile, '-o', 'IdentitiesOnly=yes'];
    }

    protected function remote(string $name): string
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ValidationError('ชื่อไฟล์ปลายทางต้องเป็นชื่อล้วน ไม่มีไดเรกทอรี');
        }

        return rtrim($this->path, '/') . '/' . $name;
    }

    protected function assertInsidePath(string $remotePath): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $remotePath) === 1) {
            throw new ValidationError('เส้นทางไฟล์ปลายทางต้องไม่มี ..');
        }

        if (!str_starts_with($remotePath, rtrim($this->path, '/') . '/')) {
            throw new ValidationError('เส้นทางนี้อยู่นอกปลายทางสำรองที่กำหนดไว้');
        }
    }

    /**
     * แปลงข้อความผิดพลาดของ ssh ให้เป็นคำแนะนำที่ทำตามได้
     *
     * ข้อความดิบของ OpenSSH บอกสาเหตุจริงอยู่แล้ว แต่ไม่ได้บอกว่าต้องทำอะไรต่อ ·
     * สองกรณีข้างล่างคือกรณีที่เจอจริงเกือบทั้งหมดตอนตั้งค่าครั้งแรก
     */
    protected function explain(string $stderr): string
    {
        $text = trim($stderr);

        if (str_contains($text, 'Host key verification failed')) {
            return $text . "\n\nเครื่องปลายทางยังไม่อยู่ในรายการที่เชื่อถือ — "
                . "กดปุ่ม \"อ่านจากเครื่องปลายทาง\" ข้างช่อง known_hosts เพื่อดึงกุญแจมาให้อัตโนมัติ";
        }

        /*
         * **"Permission denied" มีสองความหมายที่คนละเรื่องกันสิ้นเชิง**
         *
         * เดิมจับคำนี้คำเดียวแล้วบอกให้ไปแก้ `authorized_keys` ทุกครั้ง · แต่ตอนที่
         * ยืนยันตัวตนผ่านแล้วและติดที่ **สิทธิ์ของไดเรกทอรีปลายทาง** (เช่นตั้ง path
         * เป็น `/backup` ซึ่งอยู่ที่รากของ filesystem ที่ผู้ใช้ธรรมดาสร้างอะไรไม่ได้)
         * คำแนะนำนั้นพาไปผิดทางทั้งหมด — ผู้ดูแลไปนั่งไล่กุญแจที่ไม่เคยมีปัญหา
         *
         * แยกด้วยบริบทที่ ssh/sftp พิมพ์มาเอง: การยืนยันตัวตนล้มจะมี `(publickey`
         * หรือ `Authentication failed` ส่วนสิทธิ์ของไฟล์จะมาพร้อมชื่อคำสั่งที่ล้ม
         * (`remote mkdir` / `dest open` / `scp:`)
         */
        $authFailed = str_contains($text, '(publickey')
            || str_contains($text, 'Authentication failed')
            || str_contains($text, 'Too many authentication failures');

        if ($authFailed) {
            return $text . "\n\nยืนยันตัวตนไม่ผ่าน — ตรวจว่ากุญแจสาธารณะถูกใส่ไว้ใน "
                . "~{$this->user}/.ssh/authorized_keys ของเครื่องปลายทางแล้ว";
        }

        if (str_contains($text, 'Permission denied')) {
            return $text . "\n\nยืนยันตัวตนผ่านแล้ว แต่ผู้ใช้ {$this->user} "
                . "สร้างหรือเขียนไฟล์ใน {$this->path} บนเครื่องปลายทางไม่ได้"
                . "\n\nเส้นทางที่อยู่ติดรากของ filesystem (เช่น /backup) ผู้ใช้ธรรมดาสร้างไม่ได้ — "
                . "ใช้เส้นทางใต้บ้านของผู้ใช้นั้นแทน เช่น /home/{$this->user}/backups "
                . "หรือให้ผู้ดูแลเครื่องปลายทางสร้างโฟลเดอร์แล้ว chown ให้ {$this->user} ก่อน";
        }

        if (str_contains($text, 'No such file or directory')) {
            return $text . "\n\nไม่พบไดเรกทอรี {$this->path} ที่เครื่องปลายทาง และสร้างให้ไม่ได้ — "
                . "ตรวจว่าเส้นทางถูกต้องและผู้ใช้ {$this->user} มีสิทธิ์เขียนในชั้นบนของมัน";
        }

        return $text === '' ? 'คำสั่งล้มเหลวโดยไม่มีข้อความอธิบาย' : $text;
    }

    /**
     * คำสั่ง `-mkdir` ของทุกชั้นในเส้นทางปลายทาง — เรียงจากบนลงล่าง
     *
     * `sftp` สร้างได้ทีละชั้นเท่านั้น ต่างจาก `mkdir -p` ที่ rsync ใช้ได้ · ตั้ง path
     * เป็น `/home/ubuntu/backups/phpcp` แล้วชั้นกลางยังไม่มี จะล้มทั้งที่ผู้ใช้มีสิทธิ์
     * เขียนทุกชั้น — อาการที่ดูเหมือน "สิทธิ์ไม่พอ" ทั้งที่เป็นแค่ลำดับการสร้าง
     *
     * `-` นำหน้าแปลว่า "ล้มก็ไม่เป็นไร" ชั้นที่มีอยู่แล้วจึงไม่ทำให้ทั้งชุดล้ม
     */
    protected function makeDirectoryScript(): string
    {
        $parts = array_values(array_filter(explode('/', trim($this->path, '/')), static fn (string $p): bool => $p !== ''));
        $script = '';
        $walked = '';

        foreach ($parts as $part) {
            $walked .= '/' . $part;
            $script .= '-mkdir ' . $this->quotePath($walked) . "\n";
        }

        return $script;
    }

    /** ใส่เครื่องหมายคำพูดแบบที่ sftp เข้าใจ — เส้นทางของเราไม่มี " อยู่แล้ว แต่กันไว้ */
    protected function quotePath(string $value): string
    {
        return '"' . str_replace('"', '', $value) . '"';
    }

    /** @param array<string,mixed> $result */
    protected function assertOk(mixed $result, string $action): void
    {
        if (!$result->ok()) {
            throw new ExecutionFailed($action . ': ' . $this->explain($result->stderr));
        }
    }

    public function test(Executor $executor): array
    {
        $name = '.phpcp-probe-' . bin2hex(random_bytes(4));
        $local = sys_get_temp_dir() . '/' . $name;
        $content = 'phpcp destination probe ' . time();

        $executor->writeFile($executor->path($local), $content, 0600);

        try {
            $remotePath = $this->push($executor, $local, $name);

            $roundTrip = $local . '.back';
            $this->pull($executor, $remotePath, $roundTrip);

            $readBack = $executor->readFile($executor->path($roundTrip));
            $executor->removePath($executor->path($roundTrip));

            if ($readBack !== $content) {
                throw new ExecutionFailed('ส่งไฟล์ทดสอบได้ แต่ดึงกลับมาแล้วเนื้อหาไม่ตรง');
            }

            $this->delete($executor, $remotePath);

            return [
                'host' => $this->host,
                'port' => $this->port,
                'user' => $this->user,
                'path' => $this->path,
                'auth' => 'key',
            ];
        } finally {
            if ($executor->exists($executor->path($local))) {
                $executor->removePath($executor->path($local));
            }
        }
    }
}
