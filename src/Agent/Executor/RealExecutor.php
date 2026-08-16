<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Mode;

/**
 * Runs real commands on the system — used in production mode, and for read-only capabilities in dryrun mode
 *
 * Every security rule enforced right here (SECURITY §2.4):
 *   - proc_open with an array argv → PHP skips /bin/sh entirely, no interpreting ; | $() ` &&
 *   - the binary must be a real absolute path, inside an allowed directory, and not a file anyone can write to
 *   - env is scrubbed             → no leftover LD_PRELOAD, IFS, BASH_ENV, or a fake PATH
 *   - stdin closes if not given   → a command waiting on input never hangs
 *   - a timeout is always enforced → past it, SIGTERM, then SIGKILL
 *   - output size is capped        → a command that spews data forever can't eat memory until the machine falls over
 */
final class RealExecutor implements Executor
{
    // MAX_OUTPUT_BYTES has moved to being declared on Executor — it's a contract
    // the caller needs to know, not an internal detail · `self::MAX_OUTPUT_BYTES`
    // below refers to that same value

    /** The most entries returned for one directory — guards against a directory with hundreds of thousands of files */
    private const MAX_ENTRIES = 5000;

    /** The data ceiling for a compress/extract job — guards against a zip bomb */
    private const MAX_ARCHIVE_BYTES = 536_870_912; // 512 MB

    private const MAX_ARCHIVE_ENTRIES = 20_000;

    /** Directories a binary is allowed to run from */
    private const BINARY_DIRS = [
        '/usr/bin',
        '/usr/sbin',
        '/bin',
        '/sbin',
        '/usr/local/bin',
        '/usr/local/sbin'
    ];

    private const SAFE_ENV = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
        'LC_ALL' => 'C',
        'LANG' => 'C',
        'HOME' => '/',
        'SHELL' => '/usr/sbin/nologin'
    ];

    public function mode(): Mode
    {
        return Mode::Production;
    }

    /**
     * @param string $absolutePath
     * @return mixed
     */
    public function path(string $absolutePath): string
    {
        return $absolutePath;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function simulatedCommands(): array
    {
        return [];
    }

    /**
     * @param array $argv
     * @param int $timeout
     * @param string $cwd
     * @param string|null $stdin
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult {
        $argv = self::assertArgv($argv);
        $timeout = max(1, min($timeout, 600));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $startedAt = hrtime(true);
        $pipes = [];

        $process = @proc_open($argv, $descriptors, $pipes, $cwd, self::SAFE_ENV);
        if (!is_resource($process)) {
            throw new ExecutionFailed('Failed to invoke command: '.$argv[0]);
        }

        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeout;

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, (int) $remaining, 200_000);
            if ($ready === false) {
                break;
            }

            foreach ($open as $index => $stream) {
                if ($ready > 0 && !in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$index]);
                    continue;
                }

                if ($index === 1) {
                    $stdout = self::appendCapped($stdout, $chunk);
                } else {
                    $stderr = self::appendCapped($stderr, $chunk);
                }
            }
        }

        if ($timedOut) {
            foreach ($open as $stream) {
                @fclose($stream);
            }
            proc_terminate($process, SIGTERM);
            usleep(200_000);
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, SIGKILL);
            }
        }

        $exitCode = proc_close($process);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new ExecResult(
            argv: $argv,
            exitCode: $timedOut ? 124 : $exitCode,
            stdout: $stdout,
            stderr: $timedOut ? trim($stderr."\nCommand took longer than {$timeout} seconds") : $stderr,
            durationMs: $durationMs,
            timedOut: $timedOut,
        );
    }

    /**
     * @param string $path
     */
    public function readFile(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new ExecutionFailed("Failed to read file: {$path}");
        }

        return $content;
    }

    /**
     * @param string $path
     * @param string $content
     * @param int $mode
     */
    public function writeFile(string $path, string $content, int $mode = 0644): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ExecutionFailed("Failed to create directory: {$dir}");
        }

        // Writes to a temp file, then renames, to make the write atomic — a reader never sees a half-written file
        $temp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new ExecutionFailed("Failed to write file: {$path}");
        }
        @chmod($temp, $mode);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new ExecutionFailed("Failed to save file: {$path}");
        }
    }

    /**
     * @param string $path
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @param string $path
     * @param int $mode
     * @return null
     */
    public function makeDirectory(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new ExecutionFailed("Failed to create directory: {$path}");
        }

        @chmod($path, $mode);
    }

    /**
     * @param string $path
     */
    public function diskSpace(string $path): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return [
            'total' => is_float($total) ? (int) $total : 0,
            'free' => is_float($free) ? (int) $free : 0
        ];
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function realPath(string $path): ?string
    {
        $real = @realpath($path);

        return $real === false ? null : $real;
    }

    /**
     * @param string $path
     */
    public function listDirectory(string $path): array
    {
        if (!is_dir($path)) {
            throw new ExecutionFailed("Not a directory: {$path}");
        }

        $handle = @opendir($path);
        if ($handle === false) {
            throw new ExecutionFailed("Failed to open directory: {$path}");
        }

        $entries = [];

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $info = $this->stat($path.'/'.$name);
                if ($info === null) {
                    continue; // Deleted while reading — skip it, not an error
                }

                $entries[] = ['name' => $name] + $info;

                if (count($entries) >= self::MAX_ENTRIES) {
                    break;
                }
            }
        } finally {
            closedir($handle);
        }

        return $entries;
    }

    /**
     * @param string $path
     */
    public function stat(string $path): ?array
    {
        // lstat doesn't follow links — a symlink pointing anywhere is reported as a symlink
        $info = @lstat($path);
        if ($info === false) {
            return null;
        }

        $mode = (int) $info['mode'];
        $isLink = ($mode & 0o170000) === 0o120000;

        return [
            'type' => $isLink ? 'link' : (($mode & 0o170000) === 0o040000 ? 'dir' : 'file'),
            'size' => (int) $info['size'],
            'mode' => $mode & 0o7777,
            'mtime' => (int) $info['mtime'],
            'uid' => (int) $info['uid'],
            'gid' => (int) $info['gid'],
            'link' => $isLink ? (@readlink($path) ?: null): null
        ];
    }

    /**
     * @param string $from
     * @param string $to
     */
    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new ExecutionFailed('Failed to move or rename: '.basename($from));
        }
    }

    /**
     * @param string $from
     * @param string $to
     * @return null
     */
    public function copyPath(string $from, string $to): void
    {
        if (is_link($from)) {
            throw new ExecutionFailed('Copying a symlink is not supported: '.basename($from));
        }

        if (is_file($from)) {
            if (!@copy($from, $to)) {
                throw new ExecutionFailed('Failed to copy file: '.basename($from));
            }
            @chmod($to, (int) (@fileperms($from) & 0o777));

            return;
        }

        if (!is_dir($from)) {
            throw new ExecutionFailed('Copy source not found: '.basename($from));
        }

        $this->makeDirectory($to, (int) (@fileperms($from) & 0o777) ?: 0o750);

        foreach ($this->listDirectory($from) as $entry) {
            // Symlinks are never copied along — the target could sit outside the website's home
            if ($entry['type'] === 'link') {
                continue;
            }

            $this->copyPath($from.'/'.$entry['name'], $to.'/'.$entry['name']);
        }
    }

    /**
     * @param string $path
     * @return null
     */
    public function removePath(string $path): void
    {
        // is_link must always be checked before is_dir — otherwise a symlink
        // pointing at a directory gets its target's contents deleted instead of
        // the link itself being removed
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new ExecutionFailed('Failed to delete: '.basename($path));
            }

            return;
        }

        if (!is_dir($path)) {
            return; // Already gone = the desired outcome is already true
        }

        foreach ($this->listDirectory($path) as $entry) {
            $this->removePath($path.'/'.$entry['name']);
        }

        if (!@rmdir($path)) {
            throw new ExecutionFailed('Failed to delete directory: '.basename($path));
        }
    }

    /**
     * @param string $path
     * @param int $mode
     */
    public function changeMode(string $path, int $mode): void
    {
        if (is_link($path)) {
            throw new ExecutionFailed('Cannot change permissions on a symlink');
        }

        if (!@chmod($path, $mode)) {
            throw new ExecutionFailed('Failed to change permissions: '.basename($path));
        }
    }

    /**
     * @param array $sources
     * @param string $base
     * @param string $archive
     */
    public function zip(array $sources, string $base, string $archive): array
    {
        $zip = self::openArchive($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $entries = 0;
        $bytes = 0;
        $base = rtrim($base, '/');

        try {
            foreach ($sources as $source) {
                foreach (self::walk($source) as $path) {
                    $relative = ltrim(substr($path, strlen($base)), '/');
                    if ($relative === '') {
                        continue;
                    }

                    if (is_dir($path)) {
                        $zip->addEmptyDir($relative);
                        continue;
                    }

                    $size = (int) (@filesize($path) ?: 0);
                    if ($bytes + $size > self::MAX_ARCHIVE_BYTES) {
                        throw new ExecutionFailed('The data to compress exceeds the size limit');
                    }

                    if (!$zip->addFile($path, $relative)) {
                        throw new ExecutionFailed('Failed to add file to archive: '.$relative);
                    }

                    $entries++;
                    $bytes += $size;
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($archive);

            throw $e;
        }

        $zip->close();

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * @param string $archive
     * @param string $destination
     */
    public function unzip(string $archive, string $destination): array
    {
        $zip = self::openArchive($archive, \ZipArchive::RDONLY);

        $root = @realpath($destination);
        if ($root === false) {
            $zip->close();

            throw new ExecutionFailed('Destination directory not found');
        }

        $entries = 0;
        $skipped = 0;
        $bytes = 0;

        try {
            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new ExecutionFailed('The archive has too many entries');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if ($info === false) {
                    $skipped++;
                    continue;
                }

                $target = self::safeEntryPath($root, (string) $info['name']);
                if ($target === null) {
                    $skipped++; // Zip Slip, or a disallowed name — skip the whole entry
                    continue;
                }

                /*
                 * An entry name that's "clean" can still point outside the folder if a symlink sits in the middle
                 *
                 * `safeEntryPath()` only checks the **characters** in the name (no
                 * leading `/`, no `..`), which isn't enough, because a customer can
                 * create a symlink inside their own folder over SFTP · an entry
                 * named `logs/x.txt`, where `logs` is a symlink pointing outside,
                 * would get written straight through that link, even though the
                 * name itself has nothing wrong with it.
                 */
                if (!self::insideRoot($root, $target)) {
                    $skipped++;
                    continue;
                }

                if (str_ends_with((string) $info['name'], '/')) {
                    $this->makeDirectory($target, 0o750);
                    continue;
                }

                $bytes += (int) $info['size'];
                if ($bytes > self::MAX_ARCHIVE_BYTES) {
                    throw new ExecutionFailed('The extracted data exceeds the size limit (possibly a zip bomb)');
                }

                $stream = $zip->getStream((string) $info['name']);
                if ($stream === false) {
                    $skipped++;
                    continue;
                }

                $this->makeDirectory(dirname($target), 0o750);

                // The destination file itself could be a planted symlink too —
                // `fopen('wb')` would write straight through to the link's target,
                // not overwrite the link itself
                if (is_link($target)) {
                    $skipped++;
                    fclose($stream);
                    continue;
                }

                $out = @fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    $skipped++;
                    continue;
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                @chmod($target, 0o640);

                $entries++;
            }
        } finally {
            $zip->close();
        }

        return ['entries' => $entries, 'bytes' => $bytes, 'skipped' => $skipped];
    }

    /**
     * @param string $systemUser
     * @param $work
     * @return mixed
     */
    public function asUser(?string $systemUser, callable $work): array
    {
        // null = server-level scope, runs with the agent's own privileges
        if ($systemUser === null || $systemUser === '') {
            return $work();
        }

        /*
         * **These two cases are opposite extremes — they must never be merged into one condition**
         *
         * *Not root* — privileges can't be dropped, but they don't need to be,
         * because the privileges already held are inherently limited (an ordinary
         * user's CLI, portable mode, the test suite) · safe to keep going.
         *
         * *Root, but no pcntl* — the most dangerous case possible: root is about to
         * walk into a file tree the customer fully controls, with no privilege drop
         * at all, which is the one thing ARCHITECTURE §4.4 exists to prevent · this
         * code used to silently choose to "keep going" here, meaning a machine with
         * pcntl disabled (which looks like a *safer* configuration) actually ended
         * up with no privilege separation anywhere in the system at all, with
         * nothing to say so.
         *
         * Better to fail outright — file work that can't run is something an admin
         * sees and can fix, while file work done as root when it shouldn't be is
         * something nobody sees until it's too late.
         */
        if (posix_geteuid() !== 0) {
            return $work();
        }

        foreach (['pcntl_fork', 'pcntl_waitpid', 'socket_create_pair', 'posix_setuid', 'posix_setgid', 'posix_initgroups'] as $required) {
            if (!function_exists($required)) {
                throw new ExecutionFailed(
                    "This machine has no {$required} function, so privileges can't be dropped from root to the file's owner"
                    . ' — file work is refused rather than done as root (ARCHITECTURE §4.4)',
                );
            }
        }

        $identity = @posix_getpwnam($systemUser);
        if ($identity === false) {
            throw new ExecutionFailed("System user not found: {$systemUser}");
        }

        $pipes = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipes)) {
            throw new ExecutionFailed('Failed to create a communication channel with the child process');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ExecutionFailed('Failed to fork a process to drop privileges');
        }

        if ($pid === 0) {
            socket_close($pipes[0]);
            $this->runAsChild($identity, $work, $pipes[1]);
            exit(0); // Unreachable — runAsChild always ends with exit
        }

        socket_close($pipes[1]);

        $payload = '';
        while (($chunk = @socket_read($pipes[0], 65536, PHP_BINARY_READ)) !== false && $chunk !== '') {
            $payload .= $chunk;

            if (strlen($payload) > self::MAX_OUTPUT_BYTES * 4) {
                break;
            }
        }
        socket_close($pipes[0]);

        pcntl_waitpid($pid, $status);

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['ok'])) {
            throw new ExecutionFailed('The file job ended abnormally ('.pcntl_wexitstatus($status).')');
        }

        if ($decoded['ok'] !== true) {
            throw new ExecutionFailed((string) ($decoded['error'] ?? 'File job failed'));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * The forked child — drops privileges before touching any file; there's no going back after this
     *
     * @param array<string,mixed> $identity the result of posix_getpwnam
     * @param callable():array<string,mixed> $work
     * @param resource|\Socket $pipe
     */
    private function runAsChild(array $identity, callable $work, mixed $pipe): never
    {
        try {
            $user = (string) $identity['name'];
            $gid = (int) $identity['gid'];
            $uid = (int) $identity['uid'];

            if ($uid === 0 || $gid === 0) {
                throw new ExecutionFailed('Refusing to do file work as root');
            }

            // Order matters: supplementary groups and gid must change before uid.
            // Setting uid first would leave no permission to change group
            // membership at all, and the old group's privileges would stay behind.
            if (!posix_setgid($gid) || !posix_initgroups($user, $gid) || !posix_setuid($uid)) {
                throw new ExecutionFailed('Failed to drop privileges');
            }

            if (posix_geteuid() !== $uid || posix_getuid() !== $uid) {
                throw new ExecutionFailed('Failed to verify the privilege drop');
            }

            umask(0o027);

            $payload = ['ok' => true, 'data' => $work()];
        } catch (\Throwable $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @socket_write($pipe, $json === false ? '{"ok":false,"error":"Failed to encode the result"}' : $json);
        @socket_close($pipe);

        exit($payload['ok'] === true ? 0 : 1);
    }

    /**
     * @param string $path
     * @param int $flags
     * @return mixed
     */
    private static function openArchive(string $path, int $flags): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ExecutionFailed('The php-zip extension is not installed on this machine');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, $flags) !== true) {
            throw new ExecutionFailed('Failed to open archive: '.basename($path));
        }

        return $zip;
    }

    /**
     * Walks every file under a source (never following symlinks)
     *
     * @return \Generator<string>
     */
    private static function walk(string $source): \Generator
    {
        yield $source;

        if (is_link($source) || !is_dir($source)) {
            return;
        }

        $names = @scandir($source) ?: [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            yield from self::walk($source.'/'.$name);
        }
    }

    /**
     * Turns a zip entry's name into a real, safe path — null = this entry must be skipped
     *
     * Guards against Zip Slip by assembling the path piece by piece itself, rather
     * than relying on realpath of a file that doesn't exist yet.
     */
    /**
     * Is this path still genuinely inside `$root` once every symlink has been resolved?
     *
     * The destination doesn't exist yet at the time this is asked, so it walks up
     * to the **first level that actually exists** and resolves from there —
     * anything not yet existing will be created by us as a real directory
     * (`makeDirectory`), so any symlink sitting in the chain must already exist
     * beforehand, and walking upward is guaranteed to run into it.
     *
     * `$root` has already been through realpath by the caller, so it can be compared directly.
     */
    private static function insideRoot(string $root, string $path): bool
    {
        $probe = $path;

        // is_link too, because a symlink pointing at something that doesn't exist makes file_exists return false
        while ($probe !== '' && $probe !== '/' && !file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                break;
            }

            $probe = $parent;
        }

        $real = @realpath($probe);

        return $real !== false && ($real === $root || str_starts_with($real, $root . '/'));
    }

    private static function safeEntryPath(string $root, string $name): ?string
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')) {
            return null;
        }

        // A Windows drive letter or a backslash is treated as unsafe
        if (preg_match('/^[a-zA-Z]:/', $name) === 1 || str_contains($name, '\\')) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || strlen($part) > 255 || preg_match('/[\x00-\x1f\x7f]/', $part) === 1) {
                return null;
            }

            $parts[] = $part;
        }

        if ($parts === []) {
            return null;
        }

        return $root.'/'.implode('/', $parts);
    }

    /**
     * Validates every part of argv before it's allowed into proc_open
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function assertArgv(array $argv): array
    {
        if ($argv === []) {
            throw new ValidationError('Empty command');
        }

        foreach ($argv as $index => $part) {
            if (!is_string($part)) {
                throw new ValidationError("Argument at position {$index} is not a string");
            }
            if (str_contains($part, "\0")) {
                throw new ValidationError('An argument contains a null byte');
            }
        }

        $binary = $argv[0];
        if (!str_starts_with($binary, '/')) {
            throw new ValidationError("The binary must be an absolute path: {$binary}");
        }

        $real = realpath($binary);
        if ($real === false || !is_file($real) || !is_executable($real)) {
            throw new ExecutionFailed("Command not found or not executable: {$binary}");
        }

        $dir = dirname($real);
        if (!in_array($dir, self::BINARY_DIRS, true)) {
            throw new ValidationError("Running a binary outside a system directory is not allowed: {$real}");
        }

        // A binary anyone else can write to = an attacker can replace the file and wait for the agent to call it
        $perms = @fileperms($real);
        if ($perms !== false && ($perms & 0o002) !== 0) {
            throw new ValidationError("The binary is writable by ordinary users, which is unsafe: {$real}");
        }

        $argv[0] = $real;

        return array_values($argv);
    }

    /**
     * @param string $buffer
     * @param string $chunk
     * @return mixed
     */
    private static function appendCapped(string $buffer, string $chunk): string
    {
        if (strlen($buffer) >= self::MAX_OUTPUT_BYTES) {
            return $buffer;
        }

        return substr($buffer.$chunk, 0, self::MAX_OUTPUT_BYTES);
    }
}
