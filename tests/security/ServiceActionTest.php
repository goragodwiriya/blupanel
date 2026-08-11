<?php

declare(strict_types=1);

/**
 * ServiceAction — ข้อความผิดพลาดต้องบอกสาเหตุจริง ไม่ใช่ส่งประโยคของ systemd ต่อ
 *
 * **เจอจากการใช้งานจริง (2026-08-11):** กดเริ่มบริการ nginx จากหน้า Services ขณะที่
 * Apache ถือพอร์ต 80/443 อยู่ · panel คืนข้อความของ systemd ตรง ๆ:
 *
 *   "Job for nginx.service failed because the control process exited with error code.
 *    See systemctl status nginx.service and journalctl -xeu nginx.service for details."
 *
 * ซึ่งไม่ได้บอกอะไรเลยนอกจากให้ไปเปิดเทอร์มินัลหาต่อเอง — ทั้งที่สาเหตุจริงคือพอร์ตชนกัน
 * ซึ่งระบบรู้ได้เองทั้งหมดว่าพอร์ตไหนและใครถืออยู่
 */

use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\Executor\ExecResult;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Kernel\Mode;

group('ServiceAction — ต้องบอกสาเหตุจริงเมื่อเริ่มบริการไม่สำเร็จ');

/**
 * Executor ที่ตอบ journalctl และ ss ด้วยข้อความจริงจากเครื่องที่เจอปัญหา
 */
final class PortClashExecutor implements Executor
{
    private DryRunExecutor $inner;

    public function __construct(private readonly bool $withSs = true)
    {
        $this->inner = new DryRunExecutor();
    }

    public function exec(array $argv, int $timeout = 30, ?string $cwd = null, ?string $stdin = null): ExecResult
    {
        $binary = basename((string) ($argv[0] ?? ''));

        if ($binary === 'journalctl') {
            return new ExecResult(
                argv: $argv,
                exitCode: 0,
                stdout: "Starting nginx.service - A high performance web server...\n"
                    . "░░ Subject: A start job has begun\n"
                    . "nginx: [emerg] bind() to 0.0.0.0:80 failed (98: Address already in use)\n"
                    . "nginx: [emerg] still could not bind()\n"
                    . "nginx.service: Failed with result 'exit-code'.\n",
                stderr: '',
                durationMs: 0,
            );
        }

        if ($binary === 'ss') {
            return new ExecResult(
                argv: $argv,
                exitCode: 0,
                stdout: $this->withSs
                    ? "LISTEN 0 511 *:80 *:* users:((\"apache2\",pid=1234,fd=4))\n"
                        . "LISTEN 0 511 *:443 *:* users:((\"apache2\",pid=1234,fd=6))\n"
                    : '',
                stderr: '',
                durationMs: 0,
            );
        }

        return new ExecResult(argv: $argv, exitCode: 1, stdout: '', stderr: 'ล้มเหลว', durationMs: 0);
    }

    // เส้นทางที่ code หาคือ /usr/bin/journalctl กับ /usr/bin/ss — ตอบว่ามีเสมอ
    // เพื่อให้เทสต์ไม่ขึ้นกับว่าเครื่องที่รันเทสต์ติดตั้งอะไรไว้บ้าง
    public function exists(string $path): bool
    {
        return str_ends_with($path, 'journalctl') || str_ends_with($path, '/ss');
    }

    // ที่เหลือไม่เกี่ยวกับสิ่งที่เทสต์ชุดนี้ตรวจ — ส่งต่อให้ DryRunExecutor ทั้งหมด
    public function mode(): Mode { return $this->inner->mode(); }
    public function isSimulated(): bool { return true; }
    public function simulatedCommands(): array { return $this->inner->simulatedCommands(); }
    public function path(string $absolutePath): string { return $absolutePath; }
    public function readFile(string $path): string { return $this->inner->readFile($path); }
    public function writeFile(string $path, string $content, int $mode = 0644): void { $this->inner->writeFile($path, $content, $mode); }
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
    public function asUser(?string $systemUser, callable $work): array { return $work(); }
}

function explainOf(Executor $executor, string $unit = 'nginx'): string
{
    $method = new ReflectionMethod(Phpcp\Agent\Capability\ServiceStart::class, 'explainFailure');

    return (string) $method->invoke(new Phpcp\Agent\Capability\ServiceStart(), $executor, $unit);
}

test('พอร์ตชนกันต้องบอกหมายเลขพอร์ตและใครถืออยู่', static function (): void {
    $message = explainOf(new PortClashExecutor());

    assertTrue(str_contains($message, 'พอร์ต 80'), 'ต้องบอกหมายเลขพอร์ตที่ชน: ' . $message);
    assertTrue(str_contains($message, 'apache2'), 'ต้องบอกว่าใครถืออยู่: ' . $message);
});

test('ต้องบอกทางออกที่กดทำต่อได้ ไม่ใช่แค่บอกว่าพัง', static function (): void {
    $message = explainOf(new PortClashExecutor());

    assertTrue(str_contains($message, 'nginx-proxy'), 'ต้องเสนอโหมดที่ใช้ทั้งสองตัวพร้อมกันได้: ' . $message);
});

test('หา ss ไม่ได้ก็ยังต้องบอกพอร์ต — ไม่ใช่เงียบทั้งข้อความ', static function (): void {
    $message = explainOf(new PortClashExecutor(withSs: false));

    assertTrue(str_contains($message, 'พอร์ต 80'), 'ยังต้องบอกพอร์ตแม้ไม่รู้ว่าใครถือ: ' . $message);
    assertTrue(!str_contains($message, ' โดย '), 'ต้องไม่เดาชื่อโปรเซสเมื่อหาไม่ได้: ' . $message);
});

test('บรรทัดตกแต่งของ systemd ต้องไม่ปนเข้ามาในข้อความ', static function (): void {
    $message = explainOf(new PortClashExecutor());

    assertTrue(!str_contains($message, '░'), 'บรรทัด ░░ เป็นคำอธิบายของ systemd ไม่ใช่สาเหตุ: ' . $message);
});
