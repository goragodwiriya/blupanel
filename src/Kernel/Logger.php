<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * Plain file logging — no external dependency
 *
 * Format: time level message {context as JSON}
 * Readable by eye and grep-able, which matters more than looking nice for a server's logs.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];

    public function __construct(
        private readonly string $file,
        private readonly string $minLevel = 'info',
        private readonly bool $alsoStderr = false,
    ) {
    }

    public static function null(): self
    {
        return new self('/dev/null', 'error');
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $message, array $context = []): void
    {
        $this->write('warn', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 20) < (self::LEVELS[$this->minLevel] ?? 20)) {
            return;
        }

        $line = sprintf(
            '%s [%-5s] %s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === []
                ? ''
                : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);

        if ($this->alsoStderr && defined('STDERR')) {
            fwrite(STDERR, $line . "\n");
        }
    }
}
