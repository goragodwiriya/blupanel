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

        try {
            $executor->writeFile($executor->path($keyFile), rtrim($this->privateKey, "\n") . "\n", 0600);

            return $work($keyFile);
        } finally {
            if ($executor->exists($executor->path($keyFile))) {
                $executor->removePath($executor->path($keyFile));
            }
        }
    }

    /** ตัวเลือกของ ssh ที่ใช้ร่วมกันทุกคำสั่ง */
    protected function sshOptions(string $keyFile): array
    {
        $options = self::SSH_OPTIONS;

        if ($this->knownHosts !== '') {
            $options[] = '-o';
            $options[] = 'UserKnownHostsFile=' . $this->knownHosts;
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
                . "รัน `ssh-keyscan -p {$this->port} {$this->host}` แล้วใส่ผลลัพธ์ในช่อง known_hosts";
        }

        if (str_contains($text, 'Permission denied')) {
            return $text . "\n\nยืนยันตัวตนไม่ผ่าน — ตรวจว่ากุญแจสาธารณะถูกใส่ไว้ใน "
                . "~{$this->user}/.ssh/authorized_keys ของเครื่องปลายทางแล้ว";
        }

        return $text === '' ? 'คำสั่งล้มเหลวโดยไม่มีข้อความอธิบาย' : $text;
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
