<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Logger;
use Phpcp\Security\AuditLog;

/**
 * phpcp-agentd — โปรเซสเดียวในระบบที่ทำงานด้วยสิทธิ์สูง
 *
 * รูปแบบ: ฟัง unix socket → fork ลูกหนึ่งตัวต่อ connection → ลูกจัดการ request เดียวแล้วตาย
 * เลือกแบบนี้เพราะสถานะไม่ค้างข้าม request ต่อให้ capability ตัวใดรั่วหน่วยความจำ
 * หรือทำ state พัง ผลกระทบจบที่ลูกตัวนั้น
 *
 * ชั้นการป้องกันของตัว daemon เอง:
 *   - socket 0660 เจ้าของ root กลุ่ม phpcp → user ของเว็บไซต์ที่โฮสต์ต่อไม่ได้เลย
 *   - ตรวจ uid ของ peer ด้วย SO_PEERCRED เป็นชั้นที่สอง
 *   - จำกัดจำนวนลูกพร้อมกัน กัน fork bomb
 *   - systemd ตัด capability ที่ไม่จำเป็นทิ้งหมด (ARCHITECTURE §13)
 */
final class Server
{
    private const MAX_CHILDREN = 16;
    private const BACKLOG = 64;
    private const READ_TIMEOUT_SEC = 15;

    /** @var resource|\Socket|null */
    private mixed $socket = null;

    private bool $running = false;
    private int $children = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly CapabilityRegistry $registry = new CapabilityRegistry(),
    ) {
    }

    public function run(): int
    {
        $path = $this->config->agentSocket();
        $this->listen($path);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->shutdown(...));
        pcntl_signal(SIGINT, $this->shutdown(...));
        pcntl_signal(SIGCHLD, $this->reap(...));

        $this->running = true;
        $this->logger->info('agent เริ่มทำงาน', [
            'socket' => $path,
            'mode' => $this->config->mode->value,
            'uid' => posix_geteuid(),
            'capabilities' => count($this->registry->names()),
        ]);

        while ($this->running) {
            // ต้องรอด้วย select ที่มี timeout ไม่ใช่ accept ที่บล็อกไม่มีกำหนด
            //
            // เหตุผล: pcntl_async_signals ส่งสัญญาณให้ handler ได้เฉพาะตอนที่ VM
            // กลับมาอยู่ระหว่างคำสั่ง PHP ถ้าค้างอยู่ใน syscall ที่บล็อกตลอดกาล
            // handler จะไม่ถูกเรียกเลย ทำให้ `systemctl stop` ค้างจนโดน SIGKILL
            $read = [$this->socket];
            $write = null;
            $except = null;

            $ready = @socket_select($read, $write, $except, 1);

            if ($ready === false) {
                continue;   // ถูกขัดจังหวะด้วยสัญญาณ วนกลับไปเช็ค running
            }

            if ($ready === 0) {
                continue;   // ไม่มีใครต่อเข้ามาใน 1 วินาที กลับไปเช็ค running
            }

            $connection = @socket_accept($this->socket);

            if ($connection === false) {
                continue;
            }

            // socket แม่เป็น non-blocking ลูกที่ accept มาจึงต้องตั้งกลับเป็น blocking
            // ไม่อย่างนั้น SO_RCVTIMEO จะไม่มีผลและ socket_read จะคืนค่าว่างทันที
            socket_set_block($connection);

            if ($this->children >= self::MAX_CHILDREN) {
                $this->respond($connection, Protocol::encodeError(
                    'busy',
                    'agent กำลังทำงานเต็มความจุ กรุณาลองใหม่อีกครั้ง',
                ));
                socket_close($connection);
                continue;
            }

            if (!$this->peerAllowed($connection)) {
                $this->respond($connection, Protocol::encodeError(
                    'permission_denied',
                    'ไม่อนุญาตให้เชื่อมต่อจากผู้ใช้นี้',
                ));
                socket_close($connection);
                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->logger->error('fork ไม่สำเร็จ');
                socket_close($connection);
                continue;
            }

            if ($pid === 0) {
                // --- ลูก ---
                //
                // ต้องคืน SIGCHLD เป็นค่าเริ่มต้นก่อนทำอย่างอื่น
                //
                // ลูกสืบทอด handler ของพ่อมาด้วย ซึ่ง handler นั้นเรียก pcntl_waitpid(-1)
                // เพื่อเก็บศพลูก ๆ ของพ่อ ผลคือเมื่อโปรเซสที่ proc_open สร้างจบลง
                // handler จะไปเก็บสถานะออกของมันก่อน แล้ว proc_close() จะคืน -1
                // ทำให้ระบบเข้าใจผิดว่าทุกคำสั่งล้มเหลว ทั้งที่คำสั่งสำเร็จและพิมพ์ผลลัพธ์ถูกต้อง
                //
                // อาการนี้ไม่โผล่จนกว่าจะมี capability ตัวแรกที่รันโปรเซสจริงผ่าน agent
                // เพราะก่อนหน้านั้นคำสั่งทั้งหมดถูกจำลองหรือเป็นการอ่านไฟล์
                pcntl_signal(SIGCHLD, SIG_DFL);
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);

                socket_close($this->socket);
                $this->handle($connection);
                socket_close($connection);
                exit(0);
            }

            // --- พ่อ ---
            $this->children++;
            socket_close($connection);
        }

        $this->cleanup($path);
        $this->logger->info('agent หยุดทำงาน');

        return 0;
    }

    private function listen(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("สร้างไดเรกทอรี socket ไม่ได้: {$dir}");
        }

        // socket เก่าค้างจากการปิดไม่สะอาดต้องเอาออกก่อน bind
        if (file_exists($path)) {
            if ($this->isSocketAlive($path)) {
                throw new \RuntimeException("มี agent ทำงานอยู่แล้วที่ {$path}");
            }
            @unlink($path);
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($socket === false) {
            throw new \RuntimeException('สร้าง socket ไม่สำเร็จ: ' . socket_strerror(socket_last_error()));
        }

        // ตั้ง umask ให้ไฟล์ socket ไม่หลุดเป็น world-accessible ระหว่าง bind กับ chmod
        $previousUmask = umask(0o177);
        $bound = @socket_bind($socket, $path);
        umask($previousUmask);

        if ($bound === false) {
            throw new \RuntimeException("bind socket ไม่สำเร็จที่ {$path}: " . socket_strerror(socket_last_error($socket)));
        }

        if (@socket_listen($socket, self::BACKLOG) === false) {
            throw new \RuntimeException('listen ไม่สำเร็จ: ' . socket_strerror(socket_last_error($socket)));
        }

        socket_set_nonblock($socket);

        $this->applySocketPermissions($path);
        $this->socket = $socket;
    }

    /**
     * socket ต้องเป็น 0660 กลุ่ม phpcp — user ของเว็บไซต์ที่โฮสต์จึงต่อไม่ได้
     * ถ้ารันแบบไม่ใช่ root (โหมดพัฒนา) จะตั้ง group ไม่ได้ ให้ใช้ 0600 แทนซึ่งเข้มกว่า
     */
    private function applySocketPermissions(string $path): void
    {
        if (posix_geteuid() === 0 && posix_getgrnam('phpcp') !== false) {
            @chgrp($path, 'phpcp');
            @chmod($path, 0660);

            return;
        }

        @chmod($path, 0600);
    }

    private function isSocketAlive(string $path): bool
    {
        $probe = @stream_socket_client('unix://' . $path, $errno, $errstr, 1);
        if ($probe === false) {
            return false;
        }
        fclose($probe);

        return true;
    }

    /**
     * ตรวจว่าใครเป็นคนต่อเข้ามา — ชั้นที่สองถัดจากสิทธิ์ไฟล์ของ socket
     *
     * SO_PEERCRED บน Linux คืน struct ucred ซึ่ง PHP อ่านได้เฉพาะฟิลด์แรกคือ pid
     * จึงต้องไปอ่าน uid ต่อจาก /proc/<pid>/status
     *
     * @param resource|\Socket $connection
     */
    private function peerAllowed(mixed $connection): bool
    {
        $allowed = array_map(intval(...), $this->config->list('agent.allowed_uids'));
        if ($allowed === []) {
            return true;    // ไม่ได้ตั้งไว้ = พึ่งสิทธิ์ไฟล์ของ socket อย่างเดียว
        }

        $uid = $this->peerUid($connection);
        if ($uid === null) {
            $this->logger->warn('ตรวจ uid ของผู้เชื่อมต่อไม่ได้ จึงปฏิเสธไว้ก่อน');

            return false;
        }

        // root ต่อได้เสมอ ไม่อย่างนั้น phpcp CLI จะใช้งานไม่ได้
        if ($uid === 0 || in_array($uid, $allowed, true)) {
            return true;
        }

        $this->logger->warn('ปฏิเสธการเชื่อมต่อจาก uid ที่ไม่อยู่ในรายการ', ['uid' => $uid]);

        return false;
    }

    /** @param resource|\Socket $connection */
    private function peerUid(mixed $connection): ?int
    {
        // 17 = SO_PEERCRED บน Linux (PHP ไม่ได้ประกาศค่าคงที่นี้ไว้)
        $pid = @socket_get_option($connection, SOL_SOCKET, 17);
        if (!is_int($pid) || $pid <= 0) {
            return null;
        }

        $status = @file_get_contents("/proc/{$pid}/status");
        if ($status === false) {
            return null;
        }

        // บรรทัดรูปแบบ: Uid:\t<real>\t<effective>\t<saved>\t<fs>
        if (preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $m) !== 1) {
            return null;
        }

        return (int) $m[2];
    }

    /** @param resource|\Socket $connection */
    private function handle(mixed $connection): void
    {
        $actor = Actor::system('unknown');

        try {
            socket_set_option($connection, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::READ_TIMEOUT_SEC, 'usec' => 0]);

            $line = $this->readLine($connection);
            $request = Protocol::decodeRequest($line);
            $actor = $request['actor'];

            // สร้างการเชื่อมต่อ DB ในลูกเท่านั้น — PDO handle ใช้ข้าม fork ไม่ได้
            $db = new Db($this->config->paths->database());
            $audit = new AuditLog($db, $this->config->paths->logFile('audit'));
            $dispatcher = new Dispatcher($this->registry, $this->config, $db, $audit);

            $result = $dispatcher->dispatch($request['cap'], $request['args'], $actor);

            $this->respond($connection, Protocol::encodeSuccess($result['data'], $result['meta']));
        } catch (AgentException $e) {
            $this->logger->warn('คำสั่งถูกปฏิเสธ', [
                'code' => $e->code(),
                'message' => $e->getMessage(),
                'actor' => $actor->username,
            ]);
            $this->respond($connection, Protocol::encodeError($e->code(), $e->getMessage()));
        } catch (\Throwable $e) {
            // ข้อความจริงเข้า log เท่านั้น ฝั่งเว็บได้ข้อความกลาง ๆ กันข้อมูลภายในรั่ว
            $this->logger->error('เกิดข้อผิดพลาดที่ไม่ได้คาดไว้', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->respond($connection, Protocol::encodeError('internal_error', 'เกิดข้อผิดพลาดภายในระบบ'));
        }
    }

    /** @param resource|\Socket $connection */
    private function readLine(mixed $connection): string
    {
        $buffer = '';

        while (true) {
            $chunk = @socket_read($connection, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            if (str_contains($chunk, "\n")) {
                break;
            }

            if (strlen($buffer) > Protocol::MAX_FRAME) {
                throw new TransportError('ข้อความใหญ่เกินกำหนด');
            }
        }

        if (trim($buffer) === '') {
            throw new TransportError('ไม่ได้รับข้อมูล');
        }

        return $buffer;
    }

    /** @param resource|\Socket $connection */
    private function respond(mixed $connection, string $payload): void
    {
        $length = strlen($payload);
        $written = 0;

        while ($written < $length) {
            $sent = @socket_write($connection, substr($payload, $written), $length - $written);
            if ($sent === false) {
                return;
            }
            $written += $sent;
        }
    }

    private function shutdown(int $signal): void
    {
        $this->running = false;

        // ปลุก accept ที่บล็อกอยู่ให้ออกจาก loop
        if ($this->socket !== null) {
            @socket_shutdown($this->socket, 2);
        }
    }

    private function reap(int $signal): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $this->children = max(0, $this->children - 1);
        }
    }

    private function cleanup(string $path): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }

        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
