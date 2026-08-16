<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Kernel\Mode;

/**
 * Sandbox mode — ARCHITECTURE §6.3
 *
 * Does two things:
 *   1. Maps every path into the prefix → config files can be written for real without touching the machine's own /etc
 *   2. Simulates commands that change system state (systemctl, useradd, ufw, certbot)
 *
 * A command with no simulator "runs for real" on purpose, since its path is
 * already mapped and therefore safe. An important example: `apache2ctl -t` checks
 * the vhost that was just generated — the part that breaks most often — so it's
 * still tested against the real thing even in sandbox mode.
 */
final class SandboxExecutor implements Executor
{
    /** These paths are never mapped, because they must read the real thing, or are where a binary lives */
    private const PASSTHROUGH = [
        '/proc',
        '/sys',
        '/dev',
        '/usr/bin',
        '/usr/sbin',
        '/bin',
        '/sbin',
        '/usr/local/bin',
        '/usr/local/sbin',
        '/usr/share/zoneinfo',
        '/etc/os-release'
    ];

    /** @var list<Simulator> */
    private array $simulators;

    private SandboxState $state;

    /** @var list<string> */
    private array $simulated = [];

    public function __construct(
        private readonly string $prefix,
        private readonly RealExecutor $real = new RealExecutor(),
    ) {
        $this->state = new SandboxState($prefix);
        $this->simulators = [
            new SystemctlSimulator(),
            new SystemUserSimulator(),
            // Needed for security, not just convenience — without simulating it,
            // database commands would create/drop databases on the machine's real
            // MariaDB during a test run
            new MariaDbSimulator(),
            // Same reason — /usr/sbin is on the passthrough list, so the real ufw
            // command could enable the firewall on the machine being tested, if it
            // weren't intercepted here
            new UfwSimulator(),
            // Same reason again — without intercepting it, a test run would fire a
            // real request at Let's Encrypt, burning through its per-domain quota
            // and potentially locking a real domain of the user's
            new CertbotSimulator(),
        ];
    }

    public function mode(): Mode
    {
        return Mode::Sandbox;
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
        return $this->simulated;
    }

    /**
     * @return mixed
     */
    public function state(): SandboxState
    {
        return $this->state;
    }

    /**
     * Maps an absolute path into the prefix — this method must be idempotent,
     * since some capabilities call path() themselves and then pass the result to
     * readFile(), which maps it again
     */
    public function path(string $absolutePath): string
    {
        if ($absolutePath === '' || !str_starts_with($absolutePath, '/')) {
            return $absolutePath;
        }

        if (str_starts_with($absolutePath, $this->prefix.'/') || $absolutePath === $this->prefix) {
            return $absolutePath;
        }

        foreach (self::PASSTHROUGH as $keep) {
            if ($absolutePath === $keep || str_starts_with($absolutePath, $keep.'/')) {
                return $absolutePath;
            }
        }

        return $this->prefix.$absolutePath;
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
        if ($argv === []) {
            throw new ExecutionFailed('Empty command');
        }

        foreach ($this->simulators as $simulator) {
            if ($simulator->handles($argv[0])) {
                $result = $simulator->simulate($argv, $this->state, $stdin);
                $this->simulated[] = $result->commandLine();

                return $result;
            }
        }

        return $this->real->exec($argv, $timeout, $cwd === null ? null : $this->path($cwd), $stdin);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function readFile(string $path): string
    {
        return $this->real->readFile($this->path($path));
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $this->real->writeFile($this->path($path), $content, $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function exists(string $path): bool
    {
        return $this->real->exists($this->path($path));
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->real->makeDirectory($this->path($path), $mode);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function diskSpace(string $path): array
    {
        // Disk space always reports the real thing — so the dashboard's graph stays meaningful even in sandbox mode
        return $this->real->diskSpace($path);
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function realPath(string $path): ?string
    {
        return $this->real->realPath($this->path($path));
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function listDirectory(string $path): array
    {
        return $this->real->listDirectory($this->path($path));
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function stat(string $path): ?array
    {
        return $this->real->stat($this->path($path));
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function rename(string $from, string $to): void
    {
        $this->real->rename($this->path($from), $this->path($to));
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function copyPath(string $from, string $to): void
    {
        $this->real->copyPath($this->path($from), $this->path($to));
    }

    /**
     * @param string $path
     */
    public function removePath(string $path): void
    {
        $this->real->removePath($this->path($path));
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        $this->real->changeMode($this->path($path), $mode);
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     * @return mixed
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        return $this->real->zip(
            array_map($this->path(...), $sources),
            $this->path($base),
            $this->path($archive),
        );
    }

    /**
     * @param string $archive
     * @param string $destination
     * @return mixed
     */
    public function unzip(string $archive, string $destination): array
    {
        return $this->real->unzip($this->path($archive), $this->path($destination));
    }

    /**
     * Sandbox mode has no real website system user, so privileges can't be
     * dropped and don't need to be — every file already lives under a prefix
     * owned by whoever is running the test.
     *
     * Path checking still works exactly like real mode, so traversal tests stay meaningful.
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = server-level scope, runs with the agent's own privileges
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        return $work();
    }
}
