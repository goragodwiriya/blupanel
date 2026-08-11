<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * เขียนไฟล์ config หลายไฟล์แบบย้อนกลับได้ — ARCHITECTURE §10
 *
 * แก้ config เว็บเซิร์ฟเวอร์ผิดแล้ว reload คืออุบัติเหตุอันดับหนึ่งของ control panel
 * เพราะทำให้เว็บ "ทุกเว็บ" บนเครื่องดับพร้อมกัน ไม่ใช่แค่เว็บที่กำลังแก้
 *
 * ลำดับที่บังคับ:
 *   1. สำรองไฟล์เดิมทุกไฟล์ที่จะแตะ
 *   2. เขียนไฟล์ใหม่ทั้งหมด
 *   3. ตรวจ config ด้วยเครื่องมือของบริการนั้น ๆ
 *   4a. ผ่าน    → reload → ลบไฟล์สำรอง
 *   4b. ไม่ผ่าน → คืนไฟล์เดิมทั้งหมด → ไม่ reload → โยน error พร้อม stderr จริง
 *
 * ข้อ 4b คือเหตุผลทั้งหมดที่คลาสนี้มีอยู่
 */
final class ConfigTransaction
{
    /** @var array<string,string|null> path => เนื้อหาเดิม (null = ไม่เคยมีไฟล์นี้) */
    private array $backups = [];

    /** @var list<string> ไฟล์ที่ถูกเขียนหรือลบไปแล้วในทรานแซกชันนี้ */
    private array $touched = [];

    private bool $finished = false;

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * เขียนไฟล์ config หนึ่งไฟล์ (ยังไม่ถือว่าสำเร็จจนกว่าจะ commit)
     *
     * @param string $path เส้นทางแบบระบบจริง — จะถูกแมปตามโหมดให้เอง
     */
    public function write(string $path, string $content, int $mode = 0644): void
    {
        $this->assertOpen();

        $resolved = $this->executor->path($path);
        $this->backup($path, $resolved);

        $this->executor->writeFile($resolved, $content, $mode);
        $this->touched[] = $path;
    }

    public function delete(string $path): void
    {
        $this->assertOpen();

        $resolved = $this->executor->path($path);
        $this->backup($path, $resolved);

        if ($this->executor->exists($resolved)) {
            // ลบด้วยการเขียนทับเป็นไฟล์ว่างไม่ได้ — Apache จะยัง include ไฟล์ว่างอยู่
            // จึงต้องลบจริง แล้วอาศัย backup ในการคืนค่า
            @unlink($resolved);
        }

        $this->touched[] = $path;
    }

    /**
     * ตรวจแล้วยืนยัน — $validate ต้องคืน [ok, ข้อความผิดพลาด]
     *
     * @param callable():array{0:bool,1:string} $validate
     */
    public function commit(callable $validate): void
    {
        $this->assertOpen();

        [$ok, $error] = $validate();

        if (!$ok) {
            $this->rollback();

            throw new ExecutionFailed(
                "การตั้งค่าที่สร้างขึ้นไม่ผ่านการตรวจสอบ จึงคืนค่าเดิมทั้งหมดแล้ว\n\n" . trim($error),
            );
        }

        $this->finished = true;
        $this->backups = [];
    }

    /** ยืนยันโดยไม่ต้องตรวจ — ใช้กับไฟล์ที่ไม่มีเครื่องมือตรวจ เช่น pool ของ FPM */
    public function commitWithoutValidation(): void
    {
        $this->assertOpen();

        $this->finished = true;
        $this->backups = [];
    }

    /** คืนไฟล์ทุกไฟล์กลับสู่สภาพก่อนเริ่มทรานแซกชัน */
    public function rollback(): void
    {
        foreach ($this->backups as $path => $original) {
            $resolved = $this->executor->path($path);

            if ($original === null) {
                // เดิมไม่มีไฟล์นี้ — ลบทิ้งให้กลับไปเหมือนเดิม
                if ($this->executor->exists($resolved)) {
                    @unlink($resolved);
                }
                continue;
            }

            $this->executor->writeFile($resolved, $original);
        }

        $this->backups = [];
        $this->finished = true;
    }

    /** @return list<string> */
    public function touched(): array
    {
        return $this->touched;
    }

    private function backup(string $path, string $resolved): void
    {
        if (array_key_exists($path, $this->backups)) {
            return;   // สำรองไปแล้วในทรานแซกชันนี้ อย่าทับด้วยเนื้อหาที่เพิ่งเขียน
        }

        $this->backups[$path] = $this->executor->exists($resolved)
            ? $this->executor->readFile($resolved)
            : null;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new \LogicException('ทรานแซกชันนี้จบไปแล้ว');
        }
    }
}
