<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Kernel\Mode;

/**
 * Simulation mode — runs nothing at all, records "what would run", and returns an empty result
 *
 * Used only for capabilities that change the system; a read-only capability gets a
 * RealExecutor from the agent instead (see ExecutorFactory) — so the user sees the
 * machine's real state alongside the commands the system "would" run, which is
 * what actually makes this mode useful.
 *
 * An accepted limitation: a capability that needs to read the stdout of a command
 * it just ran, to keep working, gets an empty value in this mode. Considered
 * acceptable, since the point here is to see the commands, not their output.
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
        // Reading doesn't change anything, so it reads for real — that keeps the result meaningful
        return $this->real->readFile($path);
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->recorded[] = sprintf('write file %s (%s bytes, mode %o)', $path, number_format(strlen($content)), $mode);
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
        $this->recorded[] = sprintf('create directory %s (mode %o)', $path, $mode);
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
        $this->recorded[] = sprintf('move %s to %s', $from, $to);
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function copyPath(string $from, string $to): void
    {
        $this->recorded[] = sprintf('copy %s to %s', $from, $to);
    }

    /**
     * @param string $path
     */
    public function removePath(string $path): void
    {
        $this->recorded[] = sprintf('delete %s', $path);
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        $this->recorded[] = sprintf('change permissions of %s to %o', $path, $mode);
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        $this->recorded[] = sprintf('compress %d item(s) from %s into %s', count($sources), $base, $archive);

        return ['entries' => 0, 'bytes' => 0];
    }

    /**
     * @param string $archive
     * @param string $destination
     */
    public function unzip(string $archive, string $destination): array
    {
        $this->recorded[] = sprintf('extract %s into %s', $archive, $destination);

        return ['entries' => 0, 'bytes' => 0, 'skipped' => 0];
    }

    /**
     * Doesn't actually drop privileges, since no file work happens here — the
     * inner work just records commands
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = server-level scope, runs with the agent's own privileges
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        $this->recorded[] = sprintf('drop privileges to user %s before file work', $systemUser);

        return $work();
    }
}
