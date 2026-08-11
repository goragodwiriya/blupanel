<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * ผลของการรันคำสั่งหนึ่งครั้ง
 *
 * เก็บ argv ไว้ด้วยเพราะโหมด dryrun ต้องเอาไปแสดงให้ผู้ใช้เห็นว่า "จะรันอะไร"
 * และ audit log ต้องบันทึกคำสั่งจริงที่ถูกเรียก
 */
final readonly class ExecResult
{
    /** @param list<string> $argv */
    public function __construct(
        public array $argv,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $durationMs,
        public bool $timedOut = false,
        public bool $simulated = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function output(): string
    {
        return trim($this->stdout);
    }

    /** @return list<string> */
    public function lines(): array
    {
        $out = trim($this->stdout);

        return $out === '' ? [] : (preg_split('/\R/', $out) ?: []);
    }

    /**
     * แปลงผลลัพธ์รูปแบบ key=value (เช่น systemctl show) เป็น array
     *
     * @return array<string,string>
     */
    public function keyValues(): array
    {
        $result = [];
        foreach ($this->lines() as $line) {
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $result[substr($line, 0, $pos)] = substr($line, $pos + 1);
        }

        return $result;
    }

    public function commandLine(): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => preg_match('/[\s"\'$`\\\\]/', $part) === 1
                ? "'" . str_replace("'", "'\\''", $part) . "'"
                : $part,
            $this->argv,
        ));
    }
}
