<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Mode;

/**
 * รันคำสั่งจริงบนระบบ — ใช้ในโหมด production และใช้กับ capability ที่อ่านอย่างเดียวในโหมด dryrun
 *
 * กฎความปลอดภัยที่บังคับที่นี่ทั้งหมด (SECURITY §2.4):
 *   - proc_open ด้วย array argv  → PHP ข้าม /bin/sh ให้ ไม่มีการตีความ ; | $() ` &&
 *   - binary ต้องเป็น absolute path จริง อยู่ในไดเรกทอรีที่อนุญาต และไม่ใช่ไฟล์ที่ใครก็เขียนได้
 *   - env ถูกล้าง                → ไม่มี LD_PRELOAD, IFS, BASH_ENV, PATH ปลอม ตกค้าง
 *   - stdin ปิดถ้าไม่ได้ระบุ      → คำสั่งที่รอ input จะไม่ค้าง
 *   - timeout บังคับเสมอ         → เกินเวลา SIGTERM แล้ว SIGKILL
 *   - จำกัดขนาด output           → คำสั่งที่พ่นข้อมูลไม่หยุดจะไม่กินหน่วยความจำจนเครื่องล่ม
 */
final class RealExecutor implements Executor
{
    // MAX_OUTPUT_BYTES ย้ายไปประกาศใน Executor แล้ว — เป็นสัญญาที่ผู้เรียกต้องรู้
    // ไม่ใช่รายละเอียดภายใน · `self::MAX_OUTPUT_BYTES` ข้างล่างอ้างถึงค่านั้น

    /** จำนวนรายการสูงสุดที่คืนต่อหนึ่งไดเรกทอรี — กันไดเรกทอรีที่มีไฟล์เป็นแสน */
    private const MAX_ENTRIES = 5000;

    /** เพดานข้อมูลของงานบีบอัด/แตกไฟล์ — กัน zip bomb */
    private const MAX_ARCHIVE_BYTES = 536_870_912; // 512 MB

    private const MAX_ARCHIVE_ENTRIES = 20_000;

    /** ไดเรกทอรีที่ยอมให้รัน binary ได้ */
    private const BINARY_DIRS = [
        '/usr/bin',
        '/usr/sbin',
        '/bin',
        '/sbin',
        '/usr/local/bin',
        '/usr/local/sbin'
    ];

    private const SAFE_ENV = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
        'LC_ALL' => 'C',
        'LANG' => 'C',
        'HOME' => '/',
        'SHELL' => '/usr/sbin/nologin'
    ];

    public function mode(): Mode
    {
        return Mode::Production;
    }

    /**
     * @param string $absolutePath
     * @return mixed
     */
    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function simulatedCommands(): array
    {
        return [];
    }

    /**
     * @param array $argv
     * @param int $timeout
     * @param string $cwd
     * @param string|null $stdin
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult {
        $argv = self::assertArgv($argv);
        $timeout = max(1, min($timeout, 600));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $startedAt = hrtime(true);
        $pipes = [];

        $process = @proc_open($argv, $descriptors, $pipes, $cwd, self::SAFE_ENV);
        if (!is_resource($process)) {
            throw new ExecutionFailed('เรียกคำสั่งไม่สำเร็จ: '.$argv[0]);
        }

        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeout;

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, (int) $remaining, 200_000);
            if ($ready === false) {
                break;
            }

            foreach ($open as $index => $stream) {
                if ($ready > 0 && !in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$index]);
                    continue;
                }

                if ($index === 1) {
                    $stdout = self::appendCapped($stdout, $chunk);
                } else {
                    $stderr = self::appendCapped($stderr, $chunk);
                }
            }
        }

        if ($timedOut) {
            foreach ($open as $stream) {
                @fclose($stream);
            }
            proc_terminate($process, SIGTERM);
            usleep(200_000);
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, SIGKILL);
            }
        }

        $exitCode = proc_close($process);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new ExecResult(
            argv: $argv,
            exitCode: $timedOut ? 124 : $exitCode,
            stdout: $stdout,
            stderr: $timedOut ? trim($stderr."\nคำสั่งใช้เวลานานเกิน {$timeout} วินาที") : $stderr,
            durationMs: $durationMs,
            timedOut: $timedOut,
        );
    }

    /**
     * @param string $path
     */
    public function readFile(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new ExecutionFailed("อ่านไฟล์ไม่ได้: {$path}");
        }

        return $content;
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ExecutionFailed("สร้างไดเรกทอรีไม่ได้: {$dir}");
        }

        // เขียนไฟล์ชั่วคราวแล้ว rename เพื่อให้เป็น atomic — ผู้อ่านจะไม่เจอไฟล์ครึ่ง ๆ
        $temp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new ExecutionFailed("เขียนไฟล์ไม่ได้: {$path}");
        }
        @chmod($temp, $mode);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new ExecutionFailed("บันทึกไฟล์ไม่สำเร็จ: {$path}");
        }
    }

    /**
     * @param string $path
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @param string $path
     * @param int $mode
     * @return null
     */
    public function makeDirectory(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new ExecutionFailed("สร้างไดเรกทอรีไม่สำเร็จ: {$path}");
        }

        @chmod($path, $mode);
    }

    /**
     * @param string $path
     */
    public function diskSpace(string $path): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return [
            'total' => is_float($total) ? (int) $total : 0,
            'free' => is_float($free) ? (int) $free : 0
        ];
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function realPath(string $path): ?string
    {
        $real = @realpath($path);

        return $real === false ? null : $real;
    }

    /**
     * @param string $path
     */
    public function listDirectory(string $path): array
    {
        if (!is_dir($path)) {
            throw new ExecutionFailed("ไม่ใช่ไดเรกทอรี: {$path}");
        }

        $handle = @opendir($path);
        if ($handle === false) {
            throw new ExecutionFailed("เปิดไดเรกทอรีไม่ได้: {$path}");
        }

        $entries = [];

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $info = $this->stat($path.'/'.$name);
                if ($info === null) {
                    continue; // ถูกลบไประหว่างอ่าน — ข้ามไป ไม่ใช่ error
                }

                $entries[] = ['name' => $name] + $info;

                if (count($entries) >= self::MAX_ENTRIES) {
                    break;
                }
            }
        } finally {
            closedir($handle);
        }

        return $entries;
    }

    /**
     * @param string $path
     */
    public function stat(string $path): ?array
    {
        // lstat ไม่ตามลิงก์ — symlink ที่ชี้ไปไหนก็ตามจึงถูกรายงานว่าเป็น symlink
        $info = @lstat($path);
        if ($info === false) {
            return null;
        }

        $mode = (int) $info['mode'];
        $isLink = ($mode & 0o170000) === 0o120000;

        return [
            'type' => $isLink ? 'link' : (($mode & 0o170000) === 0o040000 ? 'dir' : 'file'),
            'size' => (int) $info['size'],
            'mode' => $mode & 0o7777,
            'mtime' => (int) $info['mtime'],
            'uid' => (int) $info['uid'],
            'gid' => (int) $info['gid'],
            'link' => $isLink ? (@readlink($path) ?: null): null
        ];
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new ExecutionFailed('ย้ายหรือเปลี่ยนชื่อไม่สำเร็จ: '.basename($from));
        }
    }

    /**
     * @param string $from
     * @param string $to
     * @return null
     */
    public function copyPath(string $from, string $to): void
    {
        if (is_link($from)) {
            throw new ExecutionFailed('ไม่รองรับการคัดลอก symlink: '.basename($from));
        }

        if (is_file($from)) {
            if (!@copy($from, $to)) {
                throw new ExecutionFailed('คัดลอกไฟล์ไม่สำเร็จ: '.basename($from));
            }
            @chmod($to, (int) (@fileperms($from) & 0o777));

            return;
        }

        if (!is_dir($from)) {
            throw new ExecutionFailed('ไม่พบต้นทางที่จะคัดลอก: '.basename($from));
        }

        $this->makeDirectory($to, (int) (@fileperms($from) & 0o777) ?: 0o750);

        foreach ($this->listDirectory($from) as $entry) {
            // ไม่คัดลอก symlink ต่อ — ปลายทางอาจอยู่นอกบ้านของเว็บไซต์
            if ($entry['type'] === 'link') {
                continue;
            }

            $this->copyPath($from.'/'.$entry['name'], $to.'/'.$entry['name']);
        }
    }

    /**
     * @param string $path
     * @return null
     */
    public function removePath(string $path): void
    {
        // is_link ต้องตรวจก่อน is_dir เสมอ ไม่อย่างนั้นลิงก์ที่ชี้ไปยังไดเรกทอรี
        // จะถูกไล่ลบเนื้อในของปลายทางแทนที่จะลบตัวลิงก์
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new ExecutionFailed('ลบไม่สำเร็จ: '.basename($path));
            }

            return;
        }

        if (!is_dir($path)) {
            return; // ไม่มีอยู่แล้ว = ได้ผลลัพธ์ที่ต้องการแล้ว
        }

        foreach ($this->listDirectory($path) as $entry) {
            $this->removePath($path.'/'.$entry['name']);
        }

        if (!@rmdir($path)) {
            throw new ExecutionFailed('ลบไดเรกทอรีไม่สำเร็จ: '.basename($path));
        }
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new ExecutionFailed('เปลี่ยนสิทธิ์ของ symlink ไม่ได้');
        }

        if (!@chmod($path, $mode)) {
            throw new ExecutionFailed('เปลี่ยนสิทธิ์ไม่สำเร็จ: '.basename($path));
        }
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        $zip = self::openArchive($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $entries = 0;
        $bytes = 0;
        $base = rtrim($base, '/');

        try {
            foreach ($sources as $source) {
                foreach (self::walk($source) as $path) {
                    $relative = ltrim(substr($path, strlen($base)), '/');
                    if ($relative === '') {
                        continue;
                    }

                    if (is_dir($path)) {
                        $zip->addEmptyDir($relative);
                        continue;
                    }

                    $size = (int) (@filesize($path) ?: 0);
                    if ($bytes + $size > self::MAX_ARCHIVE_BYTES) {
                        throw new ExecutionFailed('ข้อมูลที่จะบีบอัดใหญ่เกินกำหนด');
                    }

                    if (!$zip->addFile($path, $relative)) {
                        throw new ExecutionFailed('เพิ่มไฟล์เข้าไฟล์บีบอัดไม่สำเร็จ: '.$relative);
                    }

                    $entries++;
                    $bytes += $size;
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($archive);

            throw $e;
        }

        $zip->close();

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * @param string $archive
     * @param string $destination
     */
    public function unzip(string $archive, string $destination): array
    {
        $zip = self::openArchive($archive, \ZipArchive::RDONLY);

        $root = @realpath($destination);
        if ($root === false) {
            $zip->close();

            throw new ExecutionFailed('ไม่พบไดเรกทอรีปลายทาง');
        }

        $entries = 0;
        $skipped = 0;
        $bytes = 0;

        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new ExecutionFailed('ไฟล์บีบอัดมีรายการมากเกินกำหนด');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if ($info === false) {
                    $skipped++;
                    continue;
                }

                $target = self::safeEntryPath($root, (string) $info['name']);
                if ($target === null) {
                    $skipped++; // Zip Slip หรือชื่อที่ไม่อนุญาต — ข้ามทั้งรายการ
                    continue;
                }

                /*
                 * ชื่อรายการที่ "สะอาด" ยังชี้ออกนอกโฟลเดอร์ได้ ถ้ามี symlink คั่นกลาง
                 *
                 * `safeEntryPath()` ตรวจแต่**ตัวอักษร**ในชื่อ (ไม่มี `/` นำหน้า ไม่มี `..`)
                 * ซึ่งไม่พอ เพราะลูกค้าสร้าง symlink ไว้ในโฟลเดอร์ของตัวเองได้เองผ่าน SFTP
                 * · รายการชื่อ `logs/x.txt` ที่ `logs` เป็น symlink ชี้ออกไปข้างนอก จะถูก
                 * เขียนทะลุออกไปตามลิงก์นั้นทันที ทั้งที่ชื่อไม่มีอะไรผิดสักตัว
                 */
                if (!self::insideRoot($root, $target)) {
                    $skipped++;
                    continue;
                }

                if (str_ends_with((string) $info['name'], '/')) {
                    $this->makeDirectory($target, 0o750);
                    continue;
                }

                $bytes += (int) $info['size'];
                if ($bytes > self::MAX_ARCHIVE_BYTES) {
                    throw new ExecutionFailed('ข้อมูลหลังแตกไฟล์ใหญ่เกินกำหนด (อาจเป็น zip bomb)');
                }

                $stream = $zip->getStream((string) $info['name']);
                if ($stream === false) {
                    $skipped++;
                    continue;
                }

                $this->makeDirectory(dirname($target), 0o750);

                // ตัวไฟล์ปลายทางเองเป็น symlink ที่วางดักไว้ได้เหมือนกัน — `fopen('wb')`
                // จะเขียนทะลุไปที่ปลายทางของลิงก์ ไม่ใช่ทับตัวลิงก์
                if (is_link($target)) {
                    $skipped++;
                    fclose($stream);
                    continue;
                }

                $out = @fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    $skipped++;
                    continue;
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                @chmod($target, 0o640);

                $entries++;
            }
        } finally {
            $zip->close();
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'skipped' => $skipped];
    }

    /**
     * @param string $systemUser
     * @param $work
     * @return mixed
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = ขอบเขตระดับเซิร์ฟเวอร์ ทำงานด้วยสิทธิ์ของ agent เอง
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        /*
         * **สองกรณีนี้ต่างกันคนละขั้ว ห้ามรวมเป็นเงื่อนไขเดียว**
         *
         * *ไม่ได้เป็น root* — ลดสิทธิ์ไม่ได้ แต่ก็ไม่จำเป็น เพราะสิทธิ์ที่มีอยู่ก็จำกัด
         * ด้วยตัวมันเองแล้ว (CLI ของผู้ใช้ธรรมดา, โหมด portable, ชุดทดสอบ) · ทำงานต่อได้
         *
         * *เป็น root แต่ไม่มี pcntl* — คือกรณีที่อันตรายที่สุดที่เป็นไปได้: root กำลังจะ
         * เดินเข้าไปในต้นไม้ไฟล์ที่ลูกค้าควบคุมได้เอง โดยไม่มีการลดสิทธิ์ใด ๆ ซึ่งเป็น
         * สิ่งเดียวที่ ARCHITECTURE §4.4 มีอยู่เพื่อป้องกัน · เดิมโค้ดนี้เลือก "ทำงานต่อ"
         * เงียบ ๆ แปลว่าเครื่องที่ปิด pcntl ไว้ (ซึ่งดูเหมือนการตั้งค่าที่ปลอดภัยกว่า)
         * กลับเป็นเครื่องที่ไม่มีการแยกสิทธิ์เลยทั้งระบบ โดยไม่มีอะไรฟ้องสักอย่าง
         *
         * ล้มไปเลยดีกว่า — งานไฟล์ที่ทำไม่ได้ ผู้ดูแลเห็นและแก้ได้ ส่วนงานไฟล์ที่ทำ
         * ด้วย root ทั้งที่ไม่ควร ไม่มีใครเห็นจนกว่าจะสายเกินไป
         */
        if (posix_geteuid() !== 0) {
            return $work();
        }

        foreach (['pcntl_fork', 'pcntl_waitpid', 'socket_create_pair', 'posix_setuid', 'posix_setgid', 'posix_initgroups'] as $required) {
            if (!function_exists($required)) {
                throw new ExecutionFailed(
                    "เครื่องนี้ไม่มีฟังก์ชัน {$required} จึงลดสิทธิ์จาก root ไปเป็นเจ้าของไฟล์ไม่ได้"
                    . ' — ปฏิเสธงานไฟล์แทนที่จะทำด้วยสิทธิ์ root (ARCHITECTURE §4.4)',
                );
            }
        }

        $identity = @posix_getpwnam($systemUser);
        if ($identity === false) {
            throw new ExecutionFailed("ไม่พบผู้ใช้ระบบ: {$systemUser}");
        }

        $pipes = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipes)) {
            throw new ExecutionFailed('สร้างช่องสื่อสารกับโปรเซสลูกไม่สำเร็จ');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ExecutionFailed('แยกโปรเซสเพื่อลดสิทธิ์ไม่สำเร็จ');
        }

        if ($pid === 0) {
            socket_close($pipes[0]);
            $this->runAsChild($identity, $work, $pipes[1]);
            exit(0); // ไม่มีทางถึงบรรทัดนี้ runAsChild จบด้วย exit เสมอ
        }

        socket_close($pipes[1]);

        $payload = '';
        while (($chunk = @socket_read($pipes[0], 65536, PHP_BINARY_READ)) !== false && $chunk !== '') {
            $payload .= $chunk;

            if (strlen($payload) > self::MAX_OUTPUT_BYTES * 4) {
                break;
            }
        }
        socket_close($pipes[0]);

        pcntl_waitpid($pid, $status);

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['ok'])) {
            throw new ExecutionFailed('งานไฟล์จบลงผิดปกติ ('.pcntl_wexitstatus($status).')');
        }

        if ($decoded['ok'] !== true) {
            throw new ExecutionFailed((string) ($decoded['error'] ?? 'งานไฟล์ล้มเหลว'));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * ลูกที่ถูก fork แล้ว — ลดสิทธิ์ก่อนแตะไฟล์ ยกกลับไม่ได้อีก
     *
     * @param array<string,mixed> $identity ผลจาก posix_getpwnam
     * @param callable():array<string,mixed> $work
     * @param resource|\Socket $pipe
     */
    private function runAsChild(array $identity, callable $work, mixed $pipe): never
    {
        try {
            $user = (string) $identity['name'];
            $gid = (int) $identity['gid'];
            $uid = (int) $identity['uid'];

            if ($uid === 0 || $gid === 0) {
                throw new ExecutionFailed('ปฏิเสธการทำงานไฟล์ด้วยสิทธิ์ root');
            }

            // ลำดับสำคัญ: กลุ่มเสริมและ gid ต้องเปลี่ยนก่อน uid
            // ถ้าตั้ง uid ก่อน จะไม่มีสิทธิ์เปลี่ยนกลุ่มอีกต่อไปและสิทธิ์กลุ่มเดิมจะค้างอยู่
            if (!posix_setgid($gid) || !posix_initgroups($user, $gid) || !posix_setuid($uid)) {
                throw new ExecutionFailed('ลดสิทธิ์ไม่สำเร็จ');
            }

            if (posix_geteuid() !== $uid || posix_getuid() !== $uid) {
                throw new ExecutionFailed('ยืนยันการลดสิทธิ์ไม่ผ่าน');
            }

            umask(0o027);

            $payload = ['ok' => true, 'data' => $work()];
        } catch (\Throwable $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @socket_write($pipe, $json === false ? '{"ok":false,"error":"แปลงผลลัพธ์ไม่สำเร็จ"}' : $json);
        @socket_close($pipe);

        exit($payload['ok'] === true ? 0 : 1);
    }

    /**
     * @param string $path
     * @param int $flags
     * @return mixed
     */
    private static function openArchive(string $path, int $flags): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ExecutionFailed('เครื่องนี้ยังไม่ได้ติดตั้งส่วนขยาย php-zip');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, $flags) !== true) {
            throw new ExecutionFailed('เปิดไฟล์บีบอัดไม่ได้: '.basename($path));
        }

        return $zip;
    }

    /**
     * ไล่ไฟล์ทั้งหมดใต้ต้นทาง (ไม่ตาม symlink)
     *
     * @return \Generator<string>
     */
    private static function walk(string $source): \Generator
    {
        yield $source;

        if (is_link($source) || !is_dir($source)) {
            return;
        }

        $names = @scandir($source) ?: [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            yield from self::walk($source.'/'.$name);
        }
    }

    /**
     * แปลงชื่อรายการใน zip เป็นเส้นทางจริงที่ปลอดภัย — null = ต้องข้ามรายการนี้
     *
     * กัน Zip Slip ด้วยการประกอบเส้นทางเองทีละส่วน ไม่พึ่ง realpath ของไฟล์ที่ยังไม่มี
     */
    /**
     * เส้นทางนี้ยังอยู่ใต้ `$root` จริงหรือไม่ หลังคลาย symlink ทุกชั้นแล้ว
     *
     * ปลายทางยังไม่มีอยู่ตอนที่ถาม จึงเดินขึ้นไปหา**ชั้นที่มีอยู่จริงชั้นแรก**แล้วคลาย
     * จากตรงนั้น — ชั้นที่ยังไม่มี เราจะสร้างเองเป็นไดเรกทอรีจริง (`makeDirectory`)
     * symlink ที่แทรกอยู่ในสายจึงต้องเป็นของที่มีอยู่ก่อนแล้วเสมอ และการเดินขึ้นไป
     * ย่อมเจอมันแน่นอน
     *
     * `$root` ถูก realpath มาแล้วโดยผู้เรียก จึงเทียบตรง ๆ ได้
     */
    private static function insideRoot(string $root, string $path): bool
    {
        $probe = $path;

        // is_link ด้วย เพราะ symlink ที่ชี้ไปที่ที่ไม่มีอยู่ ทำให้ file_exists ตอบ false
        while ($probe !== '' && $probe !== '/' && !file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                break;
            }

            $probe = $parent;
        }

        $real = @realpath($probe);

        return $real !== false && ($real === $root || str_starts_with($real, $root . '/'));
    }

    private static function safeEntryPath(string $root, string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')) {
            return null;
        }

        // ชื่อแบบ Windows drive หรือ backslash ถือว่าไม่ปลอดภัย
        if (preg_match('/^[a-zA-Z]:/', $name) === 1 || str_contains($name, '\\')) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || strlen($part) > 255 || preg_match('/[\x00-\x1f\x7f]/', $part) === 1) {
                return null;
            }

            $parts[] = $part;
        }

        if ($parts === []) {
            return null;
        }

        return $root.'/'.implode('/', $parts);
    }

    /**
     * ตรวจ argv ให้ครบทุกข้อก่อนปล่อยเข้า proc_open
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function assertArgv(array $argv): array
    {
        if ($argv === []) {
            throw new ValidationError('คำสั่งว่างเปล่า');
        }

        foreach ($argv as $index => $part) {
            if (!is_string($part)) {
                throw new ValidationError("argument ตำแหน่งที่ {$index} ไม่ใช่สตริง");
            }
            if (str_contains($part, "\0")) {
                throw new ValidationError('argument มี null byte');
            }
        }

        $binary = $argv[0];
        if (!str_starts_with($binary, '/')) {
            throw new ValidationError("binary ต้องเป็น absolute path: {$binary}");
        }

        $real = realpath($binary);
        if ($real === false || !is_file($real) || !is_executable($real)) {
            throw new ExecutionFailed("ไม่พบคำสั่งหรือรันไม่ได้: {$binary}");
        }

        $dir = dirname($real);
        if (!in_array($dir, self::BINARY_DIRS, true)) {
            throw new ValidationError("ไม่อนุญาตให้รัน binary นอกไดเรกทอรีระบบ: {$real}");
        }

        // binary ที่คนอื่นเขียนได้ = ผู้โจมตีแทนที่ไฟล์แล้วรอให้ agent เรียกได้
        $perms = @fileperms($real);
        if ($perms !== false && ($perms & 0o002) !== 0) {
            throw new ValidationError("binary ถูกเขียนได้โดยผู้ใช้ทั่วไป ไม่ปลอดภัย: {$real}");
        }

        $argv[0] = $real;

        return array_values($argv);
    }

    /**
     * @param string $buffer
     * @param string $chunk
     * @return mixed
     */
    private static function appendCapped(string $buffer, string $chunk): string
    {
        if (strlen($buffer) >= self::MAX_OUTPUT_BYTES) {
            return $buffer;
        }

        return substr($buffer.$chunk, 0, self::MAX_OUTPUT_BYTES);
    }
}
