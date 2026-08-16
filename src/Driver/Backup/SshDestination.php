<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * The shared base for destinations that talk over OpenSSH — `sftp` and `rsync`
 *
 * **Why OpenSSH, not a library:** this project has no Composer, and
 * `ext-ssh2` doesn't ship with standard PHP · `ssh`/`sftp`/`rsync` already
 * exist on every machine this system supports, and they run through
 * `Executor` exactly like every other capability, which gets audit logging and dryrun mode for free.
 *
 * **Authenticates with a key only — password support is deliberately not
 * included**
 *
 * The private key, encrypted in the database, is written to a temporary
 * file with 0600 permissions only while it's being used, then deleted in
 * every case, including when the command fails.
 *
 * A password was cut for three reasons: it needs `sshpass` installed
 * separately · it can't be passed as an argument, since other users on the
 * machine can read `/proc/<pid>/cmdline` · and it can't be passed through
 * an environment variable either, since `Executor::exec()` doesn't accept
 * env at all — a limitation that **should never be widened just to support
 * an authentication method that's already weaker** · a key is also
 * something that can be revoked one at a time without affecting any other account, which a password can't do.
 *
 * **`StrictHostKeyChecking` is deliberately always on** — turning it off
 * would let every backup push be intercepted and accepted with nothing
 * ever complaining, handing the entire system's data to an attacker · the
 * price paid is that an admin has to supply the host key at setup time, which `test()` explains how to do in its error message.
 */
abstract class SshDestination implements Destination
{
    protected const SSH_OPTIONS = [
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15',
        '-o', 'ServerAliveInterval=15',
    ];

    /**
     * The temporary known_hosts file path while a command is running
     *
     * `$knownHosts` stores **content** (the result of `ssh-keyscan`), never
     * a file path — because an admin can't place a file on the panel's own
     * machine (systemd hardening restricts what's writable), and there's no
     * field on the web page to type a path into · the content is written to
     * a temp file only while in use, the same as the private key, and
     * deleted in every case · this value is set in withKey() and cleared in its `finally`.
     */
    private ?string $knownHostsFile = null;

    public function __construct(
        protected readonly string $host,
        protected readonly int $port,
        protected readonly string $user,
        protected readonly string $path,
        protected readonly string $privateKey = '',
        protected readonly string $knownHosts = '',
    ) {
        if ($this->host === '') {
            throw new ValidationError('A destination hostname must be specified');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new ValidationError('Port must be between 1 and 65535');
        }

        if ($this->user === '') {
            throw new ValidationError('A destination username must be specified');
        }

        if ($this->path === '' || !str_starts_with($this->path, '/')) {
            throw new ValidationError('The destination path must be a full path starting with /');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->path) === 1) {
            throw new ValidationError('The destination path must not contain ..');
        }

        if ($this->privateKey === '') {
            throw new ValidationError('A private key is required to access the destination machine');
        }
    }

    /**
     * Prepares temporary secret files and runs the work — deletes the files in every case
     *
     * Takes a callback rather than returning the file path, so **there's no
     * way to forget to delete it** · a private key left behind in /tmp is
     * the key to the backup machine, readable by anyone.
     *
     * @param callable(string): mixed $work receives the temporary key file path
     */
    protected function withKey(Executor $executor, callable $work): mixed
    {
        $keyFile = sys_get_temp_dir() . '/phpcp-key-' . bin2hex(random_bytes(8));
        $hostsFile = null;

        try {
            $executor->writeFile($executor->path($keyFile), rtrim($this->privateKey, "\n") . "\n", 0600);

            // The host key an admin supplied → a temp file UserKnownHostsFile points at
            if (trim($this->knownHosts) !== '') {
                $hostsFile = sys_get_temp_dir() . '/phpcp-known-' . bin2hex(random_bytes(8));
                $executor->writeFile($executor->path($hostsFile), rtrim($this->knownHosts, "\n") . "\n", 0600);
                $this->knownHostsFile = $hostsFile;
            }

            return $work($keyFile);
        } finally {
            // State is always cleared first, or the next call would reference an already-deleted file path
            $this->knownHostsFile = null;

            if ($executor->exists($executor->path($keyFile))) {
                $executor->removePath($executor->path($keyFile));
            }

            if ($hostsFile !== null && $executor->exists($executor->path($hostsFile))) {
                $executor->removePath($executor->path($hostsFile));
            }
        }
    }

    /** The ssh options shared by every command */
    protected function sshOptions(string $keyFile): array
    {
        $options = self::SSH_OPTIONS;

        if ($this->knownHostsFile !== null) {
            $options[] = '-o';
            $options[] = 'UserKnownHostsFile=' . $this->knownHostsFile;
        }

        // IdentitiesOnly stops ssh from trying one of root's other keys
        // instead, which would produce "misconfigured but somehow works" that breaks the moment it moves machines
        return [...$options, '-i', $keyFile, '-o', 'IdentitiesOnly=yes'];
    }

    protected function remote(string $name): string
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ValidationError('The destination filename must be a name only, no directory');
        }

        return rtrim($this->path, '/') . '/' . $name;
    }

    protected function assertInsidePath(string $remotePath): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $remotePath) === 1) {
            throw new ValidationError('The destination file path must not contain ..');
        }

        if (!str_starts_with($remotePath, rtrim($this->path, '/') . '/')) {
            throw new ValidationError('This path is outside the configured backup destination');
        }
    }

    /**
     * Turns ssh's own error message into actionable advice
     *
     * OpenSSH's raw message already states the real cause, but doesn't say
     * what to do next · the two cases below cover nearly everything genuinely encountered during first-time setup.
     */
    protected function explain(string $stderr): string
    {
        $text = trim($stderr);

        if (str_contains($text, 'Host key verification failed')) {
            return $text . "\n\nThe destination machine is not on the trusted list yet — "
                . 'click "read from destination machine" next to the known_hosts field to fetch the key automatically';
        }

        /*
         * **"Permission denied" carries two entirely unrelated meanings**
         *
         * This used to match that phrase alone and always advise fixing
         * `authorized_keys` · but when authentication had already
         * succeeded and the real problem was **the destination
         * directory's own permissions** (e.g. path set to `/backup`,
         * sitting at the filesystem root where an ordinary user can't
         * create anything), that advice sent people entirely the wrong
         * way — an admin would sit there re-checking a key that was never the problem.
         *
         * Distinguished by the context ssh/sftp themselves print: a failed
         * authentication carries `(publickey` or `Authentication failed`,
         * while a permissions problem arrives alongside the name of the
         * failed operation (`remote mkdir` / `dest open` / `scp:`).
         */
        $authFailed = str_contains($text, '(publickey')
            || str_contains($text, 'Authentication failed')
            || str_contains($text, 'Too many authentication failures');

        if ($authFailed) {
            return $text . "\n\nAuthentication failed — check that the public key has been added to "
                . "~{$this->user}/.ssh/authorized_keys on the destination machine";
        }

        if (str_contains($text, 'Permission denied')) {
            return $text . "\n\nAuthentication succeeded, but user {$this->user} "
                . "cannot create or write files in {$this->path} on the destination machine"
                . "\n\nA path sitting at the filesystem root (e.g. /backup) can't be created by an ordinary user — "
                . "use a path under that user's own home instead, e.g. /home/{$this->user}/backups, "
                . "or have the destination machine's admin create the folder and chown it to {$this->user} first";
        }

        if (str_contains($text, 'No such file or directory')) {
            return $text . "\n\nDirectory {$this->path} was not found on the destination machine, and could not be created — "
                . "check that the path is correct and that user {$this->user} has write permission on its parent";
        }

        return $text === '' ? 'The command failed with no explanation given' : $text;
    }

    /**
     * A `-mkdir` command for every level of the destination path, top-down
     *
     * `sftp` can only create one level at a time, unlike the `mkdir -p`
     * rsync can use · setting path to `/home/ubuntu/backups/phpcp` with an
     * intermediate level missing would fail even though the user has write
     * permission at every level — a symptom that looks like "insufficient
     * permission" when it's really just creation order.
     *
     * The leading `-` means "a failure here is fine", so a level that already exists doesn't fail the whole batch.
     */
    protected function makeDirectoryScript(): string
    {
        $parts = array_values(array_filter(explode('/', trim($this->path, '/')), static fn (string $p): bool => $p !== ''));
        $script = '';
        $walked = '';

        foreach ($parts as $part) {
            $walked .= '/' . $part;
            $script .= '-mkdir ' . $this->quotePath($walked) . "\n";
        }

        return $script;
    }

    /** Quotes a value the way sftp understands — our own paths never contain a " anyway, but this guards against it */
    protected function quotePath(string $value): string
    {
        return '"' . str_replace('"', '', $value) . '"';
    }

    /** @param array<string,mixed> $result */
    protected function assertOk(mixed $result, string $action): void
    {
        if (!$result->ok()) {
            throw new ExecutionFailed($action . ': ' . $this->explain($result->stderr));
        }
    }

    public function test(Executor $executor): array
    {
        $name = '.phpcp-probe-' . bin2hex(random_bytes(4));
        $local = sys_get_temp_dir() . '/' . $name;
        $content = 'phpcp destination probe ' . time();

        $executor->writeFile($executor->path($local), $content, 0600);

        try {
            $remotePath = $this->push($executor, $local, $name);

            $roundTrip = $local . '.back';
            $this->pull($executor, $remotePath, $roundTrip);

            $readBack = $executor->readFile($executor->path($roundTrip));
            $executor->removePath($executor->path($roundTrip));

            if ($readBack !== $content) {
                throw new ExecutionFailed('Pushed the test file successfully, but pulling it back returned different content');
            }

            $this->delete($executor, $remotePath);

            return [
                'host' => $this->host,
                'port' => $this->port,
                'user' => $this->user,
                'path' => $this->path,
                'auth' => 'key',
            ];
        } finally {
            if ($executor->exists($executor->path($local))) {
                $executor->removePath($executor->path($local));
            }
        }
    }
}
