<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * The result of running one command
 *
 * argv is kept too, because dryrun mode has to show the user "what would run", and
 * the audit log has to record the actual command that was invoked.
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
     * Turns key=value output (such as `systemctl show`) into an array
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
