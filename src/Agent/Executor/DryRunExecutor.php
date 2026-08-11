<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Kernel\Mode;

/**
 * โหมดจำลอง — ไม่รันอะไรเลย บันทึกว่า "จะรันอะไร" แล้วคืนผลลัพธ์เปล่า
 *
 * ใช้กับ capability ที่เปลี่ยนแปลงระบบเท่านั้น ส่วน capability ที่อ่านอย่างเดียว
 * agent จะจ่าย RealExecutor ให้แทน (ดู ExecutorFactory) — ผู้ใช้จึงเห็นสถานะจริง
 * ของเครื่องควบคู่กับคำสั่งที่ระบบ "จะ" ทำ ซึ่งเป็นสิ่งที่ทำให้โหมดนี้มีประโยชน์จริง
 *
 * ข้อจำกัดที่ยอมรับ: capability ที่ต้องอ่าน stdout ของคำสั่งที่ตัวเองเพิ่งรันเพื่อทำงานต่อ
 * จะได้ค่าว่างในโหมดนี้ ถือว่ายอมรับได้เพราะจุดประสงค์คือดูคำสั่ง ไม่ใช่ดูผลลัพธ์
 */
final class DryRunExecutor implements Executor
{
    /** @var list<string> */
    private array $recorded = [];

    public function __construct(private readonly RealExecutor $real = new RealExecutor())
    {
    }

    public function mode(): Mode
    {
        return Mode::DryRun;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * @return mixed
     */
    public function simulatedCommands(): array
    {
        return $this->recorded;
    }

    /**
     * @param string $absolutePath
     * @return mixed
     */
    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    /**
     * @param array $argv
     * @param int $timeout
     * @param string $cwd
     * @param string|null $stdin
     * @return mixed
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult {
        $result = new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 0,
            simulated: true,
        );

        $this->recorded[] = $result->commandLine();

        return $result;
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function readFile(string $path): string
    {
        // การอ่านไม่เปลี่ยนอะไร จึงให้อ่านของจริงเพื่อให้ผลลัพธ์มีความหมาย
        return $this->real->readFile($path);
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->recorded[] = sprintf('เขียนไฟล์ %s (%s ไบต์ สิทธิ์ %o)', $path, number_format(strlen($content)), $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function exists(string $path): bool
    {
        return $this->real->exists($path);
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->recorded[] = sprintf('สร้างไดเรกทอรี %s (สิทธิ์ %o)', $path, $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function diskSpace(string $path): array
    {
        return $this->real->diskSpace($path);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function realPath(string $path): ?string
    {
        return $this->real->realPath($path);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function listDirectory(string $path): array
    {
        return $this->real->listDirectory($path);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function stat(string $path): ?array
    {
        return $this->real->stat($path);
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function rename(string $from, string $to): void
    {
        $this->recorded[] = sprintf('ย้าย %s ไปเป็น %s', $from, $to);
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function copyPath(string $from, string $to): void
    {
        $this->recorded[] = sprintf('คัดลอก %s ไปเป็น %s', $from, $to);
    }

    /**
     * @param string $path
     */
    public function removePath(string $path): void
    {
        $this->recorded[] = sprintf('ลบ %s', $path);
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        $this->recorded[] = sprintf('เปลี่ยนสิทธิ์ %s เป็น %o', $path, $mode);
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        $this->recorded[] = sprintf('บีบอัด %d รายการจาก %s เป็น %s', count($sources), $base, $archive);

        return ['entries' => 0, 'bytes' => 0];
    }

    /**
     * @param string $archive
     * @param string $destination
     */
    public function unzip(string $archive, string $destination): array
    {
        $this->recorded[] = sprintf('แตกไฟล์ %s ลงใน %s', $archive, $destination);

        return ['entries' => 0, 'bytes' => 0, 'skipped' => 0];
    }

    /**
     * ไม่ลดสิทธิ์จริงเพราะไม่มีการแตะไฟล์เกิดขึ้น — งานข้างในบันทึกคำสั่งอย่างเดียว
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = ขอบเขตระดับเซิร์ฟเวอร์ ทำงานด้วยสิทธิ์ของ agent เอง
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        $this->recorded[] = sprintf('ลดสิทธิ์เป็นผู้ใช้ %s ก่อนทำงานไฟล์', $systemUser);

        return $work();
    }
}
