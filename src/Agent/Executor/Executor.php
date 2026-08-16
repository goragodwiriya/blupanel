<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Kernel\Mode;

/**
 * The single point in the system where agent-side code touches the outside world
 *
 * Why this had to be an interface from line one of the project (ARCHITECTURE
 * §6.2): letting a Capability call proc_open or file_put_contents directly, spread
 * across the codebase, would make sandbox/dryrun modes impossible, and there'd be
 * no central place left to enforce security rules.
 *
 * Never add a method to this interface that accepts a "command as a string".
 */
interface Executor
{
    /**
     * The ceiling `exec()` keeps from each stream — **anything past this is silently discarded**
     *
     * Declared right in the contract, not hidden inside RealExecutor, because it's
     * a fact a caller must know, not an internal implementation detail · a security
     * check that reads a command's output and decides based on it (checking an
     * archive's file listing before extracting, say) would only see the head and
     * let the rest pass with nothing to complain about, if it didn't know this
     * ceiling existed — meaning a malicious archive would only need to be long
     * enough to walk right through the check.
     *
     * A caller that needs the **complete** result must have the command write to a
     * file instead (`tar --index-file`, for instance), or reject the result when it
     * bumps up against this ceiling.
     */
    public const MAX_OUTPUT_BYTES = 1_048_576;

    public function mode(): Mode;

    /**
     * Runs a command — argv is always an array, never passed through a shell
     *
     * Each stream's output is truncated at {@see self::MAX_OUTPUT_BYTES}
     *
     * @param list<string> $argv  argv[0] must be the binary's absolute path
     * @param string|null  $stdin data to feed to stdin (null = close stdin immediately)
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult;

    /**
     * Turns a real system absolute path into the path that should be used in the current mode
     *
     * production/dryrun return the value unchanged · sandbox prepends its prefix.
     * A Capability must call this on every path it's about to use — this is the
     * one place that mapping happens.
     */
    public function path(string $absolutePath): string;

    /**
     * @param string $path
     */
    public function readFile(string $path): string;

    public function writeFile(string $path, string $content, int $mode = 0644): void;

    public function exists(string $path): bool;

    /** Creates a directory (including parent directories) — doesn't fail if it already exists */
    public function makeDirectory(string $path, int $mode = 0755): void;

    /**
     * Disk space of the filesystem that path lives on
     *
     * @return array{total:int,free:int}
     */
    public function diskSpace(string $path): array;

    /**
     * The real path after resolving every symlink and `..` — null when it doesn't actually exist
     *
     * The file manager must call this before deciding whether a path falls inside
     * a website's own home, because comparing strings before resolving symlinks can
     * be fooled by a link pointing outside that home (SECURITY §2.7).
     */
    public function realPath(string $path): ?string;

    /**
     * A directory's listing, one level deep (not recursive), with lstat data attached
     *
     * Uses lstat, not stat — a symlink is reported as itself, not as whatever it points to
     *
     * @return list<array{name:string,type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string}>
     */
    public function listDirectory(string $path): array;

    /** @return array{type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string}|null */
    public function stat(string $path): ?array;

    public function rename(string $from, string $to): void;

    /** Copies a file, or a whole directory */
    public function copyPath(string $from, string $to): void;

    /** Deletes a file, or a whole directory — never follows symlinks */
    public function removePath(string $path): void;

    public function changeMode(string $path, int $mode): void;

    /**
     * Compresses the given items into a zip file
     *
     * Names inside the archive are relative to $base — so extracting it back out
     * reproduces the structure seen on screen, with no full machine path carried
     * along inside it.
     *
     * @param list<string> $sources
     * @return array{entries:int,bytes:int}
     */
    public function zip(array $sources, string $base, string $archive): array;

    /**
     * Extracts a zip file into a destination directory — Zip Slip must be guarded against at this level
     *
     * @return array{entries:int,bytes:int,skipped:int}
     */
    public function unzip(string $archive, string $destination): array;

    /**
     * Does file work under the permissions of a given system user — ARCHITECTURE §4.4
     *
     * root must never touch a website's files directly, so every file job forks
     * and drops privileges first, always — the worst possible bug in path handling
     * can then only ever escape as far as that one website's own boundary.
     *
     * @param callable():array<string,mixed> $work must return a value json_encode can handle
     * @return array<string,mixed>
     */
    public function asUser(?string $systemUser, callable $work): array;

    /** true = the change was simulated, it never happened on the real system */
    public function isSimulated(): bool;

    /**
     * Records the commands that were simulated, for display in dryrun mode
     *
     * @return list<string>
     */
    public function simulatedCommands(): array;
}
