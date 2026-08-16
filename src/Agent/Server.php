<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Domain\SettingsRepository;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Logger;
use Phpcp\Security\AuditLog;

/**
 * phpcp-agentd — the one process in the system that runs with elevated privileges
 *
 * Shape: listen on a unix socket → fork one child per connection → the child
 * handles a single request, then dies. Chosen this way because no state carries
 * over between requests — even if some capability leaks memory or corrupts its own
 * state, the damage stops at that one child.
 *
 * The daemon's own layers of protection:
 *   - the socket is 0660, owned by root:phpcp → a hosted website's own user can
 *     never connect to it at all
 *   - the peer's uid is checked with SO_PEERCRED as a second layer
 *   - the number of concurrent children is capped, against a fork bomb
 *   - systemd strips every capability that isn't needed (ARCHITECTURE §13)
 */
final class Server
{
    private const MAX_CHILDREN = 16;
    private const BACKLOG = 64;
    private const READ_TIMEOUT_SEC = 15;

    /** @var resource|\Socket|null */
    private mixed $socket = null;

    private bool $running = false;
    private int $children = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly CapabilityRegistry $registry = new CapabilityRegistry(),
    ) {
    }

    public function run(): int
    {
        $path = $this->config->agentSocket();
        $this->listen($path);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->shutdown(...));
        pcntl_signal(SIGINT, $this->shutdown(...));
        pcntl_signal(SIGCHLD, $this->reap(...));

        $this->running = true;
        $this->logger->info('agent started', [
            'socket' => $path,
            'mode' => $this->config->mode->value,
            'uid' => posix_geteuid(),
            'capabilities' => count($this->registry->names()),
        ]);

        while ($this->running) {
            // Must wait with a timed select, not an accept that blocks forever
            //
            // Why: pcntl_async_signals can only deliver a signal to its handler
            // while the VM is between PHP instructions. Stuck inside a syscall that
            // blocks forever, the handler never runs at all, and `systemctl stop`
            // hangs until it gets SIGKILL.
            $read = [$this->socket];
            $write = null;
            $except = null;

            $ready = @socket_select($read, $write, $except, 1);

            if ($ready === false) {
                continue;   // Interrupted by a signal — loop back and check running
            }

            if ($ready === 0) {
                continue;   // Nobody connected within 1 second — loop back and check running
            }

            $connection = @socket_accept($this->socket);

            if ($connection === false) {
                continue;
            }

            // The parent socket is non-blocking, so the accepted child connection
            // has to be set back to blocking — otherwise SO_RCVTIMEO has no effect
            // and socket_read returns empty immediately
            socket_set_block($connection);

            if ($this->children >= self::MAX_CHILDREN) {
                $this->respond($connection, Protocol::encodeError(
                    'busy',
                    'The agent is at full capacity, please try again',
                ));
                socket_close($connection);
                continue;
            }

            if (!$this->peerAllowed($connection)) {
                $this->respond($connection, Protocol::encodeError(
                    'permission_denied',
                    'Connections from this user are not allowed',
                ));
                socket_close($connection);
                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->logger->error('fork failed');
                socket_close($connection);
                continue;
            }

            if ($pid === 0) {
                // --- Child ---
                //
                // Signal handlers have to be reset to their defaults before anything else.
                //
                // The child inherits the parent's handlers, and the parent's
                // SIGCHLD handler calls pcntl_waitpid(-1) to reap the parent's own
                // children. Left in place, when a process started by proc_open
                // finished, this handler would reap its exit status first, and
                // proc_close() would then return -1 — making the system believe
                // every command had failed, even when it had actually succeeded and
                // printed the right output.
                //
                // This symptom doesn't surface until the first capability that runs
                // a real process through the agent, since before that every command
                // was either simulated or just read a file.
                pcntl_signal(SIGCHLD, SIG_DFL);
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGINT, SIG_DFL);

                socket_close($this->socket);
                $this->handle($connection);
                socket_close($connection);
                exit(0);
            }

            // --- Parent ---
            $this->children++;
            socket_close($connection);
        }

        $this->cleanup($path);
        $this->logger->info('agent stopped');

        return 0;
    }

    private function listen(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create the socket directory: {$dir}");
        }

        // A stale socket left over from an unclean shutdown must be removed before binding
        if (file_exists($path)) {
            if ($this->isSocketAlive($path)) {
                throw new \RuntimeException("An agent is already running at {$path}");
            }
            @unlink($path);
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($socket === false) {
            throw new \RuntimeException('Failed to create the socket: ' . socket_strerror(socket_last_error()));
        }

        // Set umask so the socket file can never end up world-accessible between bind and chmod
        $previousUmask = umask(0o177);
        $bound = @socket_bind($socket, $path);
        umask($previousUmask);

        if ($bound === false) {
            throw new \RuntimeException("Failed to bind the socket at {$path}: " . socket_strerror(socket_last_error($socket)));
        }

        if (@socket_listen($socket, self::BACKLOG) === false) {
            throw new \RuntimeException('Failed to listen: ' . socket_strerror(socket_last_error($socket)));
        }

        socket_set_nonblock($socket);

        $this->applySocketPermissions($path);
        $this->socket = $socket;
    }

    /**
     * The socket must be 0660 group phpcp — so a hosted website's own user can never connect to it
     * Running as non-root (development mode), the group can't be set, so 0600 is used instead, which is stricter still
     */
    private function applySocketPermissions(string $path): void
    {
        if (posix_geteuid() === 0 && posix_getgrnam('phpcp') !== false) {
            @chgrp($path, 'phpcp');
            @chmod($path, 0660);

            return;
        }

        @chmod($path, 0600);
    }

    private function isSocketAlive(string $path): bool
    {
        $probe = @stream_socket_client('unix://' . $path, $errno, $errstr, 1);
        if ($probe === false) {
            return false;
        }
        fclose($probe);

        return true;
    }

    /**
     * Checks who is connecting — the second layer, after the socket's own file permissions
     *
     * SO_PEERCRED on Linux returns a `ucred` struct, of which PHP can only read the
     * first field, pid — so the uid has to be read separately from /proc/<pid>/status.
     *
     * @param resource|\Socket $connection
     */
    private function peerAllowed(mixed $connection): bool
    {
        $uid = $this->peerUid($connection);

        if ($uid === null) {
            $this->logger->warn('Could not determine the connecting peer\'s uid — rejecting as a precaution');

            return false;
        }

        if (in_array($uid, $this->allowedUids(), true)) {
            return true;
        }

        $this->logger->warn('Rejected a connection from a uid not on the allowed list', ['uid' => $uid]);

        return false;
    }

    /**
     * uids allowed to connect to the socket — **closed by default, not open**
     *
     * An empty list used to mean "skip the check", which was every machine's
     * default · this second layer therefore never actually did anything in
     * practice, leaving the socket's own file permissions (0660 root:phpcp) as the
     * only layer — meaning anything that could get into the `phpcp` group could
     * send `role=superadmin, user_id=0` and command anything on the whole machine.
     *
     * Three entries are always on the list, and why each one is there:
     *
     *   - **root** — the `phpcp` CLI and scheduled jobs run with this identity
     *   - **the agent's own uid** — in portable mode there's no root at all; the
     *     web tier and the agent are the same user · without this entry, that kind
     *     of install could never connect to its own socket, from the very first
     *     second
     *   - **`agent.allowed_users`** — the web tier's username (default
     *     `phpcp-web`), turned into a uid at check time, not at install time,
     *     because a system account's uid differs from machine to machine and can
     *     change on a move — a number baked into a config file would silently
     *     become someone else's number
     *
     * **A limit worth knowing:** this layer stops "some other process that
     * happens to be in the same group", but it cannot stop "a web tier that has
     * already been compromised" — that process genuinely **is** `phpcp-web`, and
     * holds no secret it doesn't already have (config.php is 0640 root:phpcp) ·
     * adding an HMAC on top of this would do nothing for that case at all · the
     * agent's only real defense against a compromised web tier is each
     * capability's own `permission()` plus its ownership checks — which is exactly
     * why {@see Actor::fromArray()} must never accept "user id 0 + a customer role".
     *
     * @return list<int>
     */
    private function allowedUids(): array
    {
        $allowed = [0, posix_geteuid()];

        foreach ($this->config->list('agent.allowed_uids') as $uid) {
            $allowed[] = (int) $uid;
        }

        foreach ($this->config->list('agent.allowed_users') as $name) {
            $identity = @posix_getpwnam((string) $name);

            if ($identity !== false) {
                $allowed[] = (int) $identity['uid'];
            }
        }

        return array_values(array_unique($allowed));
    }

    /** @param resource|\Socket $connection */
    private function peerUid(mixed $connection): ?int
    {
        // 17 = SO_PEERCRED on Linux (PHP doesn't declare a constant for this)
        $pid = @socket_get_option($connection, SOL_SOCKET, 17);
        if (!is_int($pid) || $pid <= 0) {
            return null;
        }

        $status = @file_get_contents("/proc/{$pid}/status");
        if ($status === false) {
            return null;
        }

        // Line format: Uid:\t<real>\t<effective>\t<saved>\t<fs>
        if (preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $m) !== 1) {
            return null;
        }

        return (int) $m[2];
    }

    /** @param resource|\Socket $connection */
    private function handle(mixed $connection): void
    {
        $actor = Actor::system('unknown');

        try {
            socket_set_option($connection, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::READ_TIMEOUT_SEC, 'usec' => 0]);

            $line = $this->readLine($connection);
            $request = Protocol::decodeRequest($line);
            $actor = $request['actor'];

            // The DB connection is only ever created inside the child — a PDO handle can't survive a fork
            $db = new Db($this->config->paths->database());

            /*
             * **A value the admin set from the screen has to actually reach the capability**
             *
             * `Config::useStoredSettings()` used to be called from only one place,
             * `App::db()`, which is a **web-tier-only** path · the agent doesn't go
             * through `App` — it builds its own `Db` right here, inside the child
             * process. The result was that **the agent never saw the `settings`
             * table at all**, and every capability could only read values from
             * `config.php`.
             *
             * The real-world symptom (found on the server on 2026-08-14): an admin
             * flipped the DNS switch on the settings page, `dns.enabled = 1` was
             * saved to the database cleanly, the screen reported success — but
             * `BindZoneManager`, running inside the agent, read `dnsEnabled()` as
             * `false` from config.php and **silently returned a no-op** every
             * single time. The record was saved to the database, but not one zone
             * file was ever written, with no error message explaining why.
             *
             * This affects every value read through `Config` inside a capability,
             * not just DNS — `sites.layout` travels the exact same path.
             *
             * Reloaded on every request on purpose, never cached across requests:
             * the agent is a long-running process, and caching would mean a value
             * changed from the web page needs an agent restart to take effect —
             * the same trap in a new shape. One query per request is far cheaper
             * than that.
             */
            try {
                Config::useStoredSettings((new SettingsRepository($db))->all());
            } catch (\Throwable) {
                // The settings table doesn't exist yet — normal on a freshly installed machine; keep using the file's values
            }

            $audit = new AuditLog($db, $this->config->paths->logFile('audit'));
            $dispatcher = new Dispatcher($this->registry, $this->config, $db, $audit);

            $result = $dispatcher->dispatch($request['cap'], $request['args'], $actor);

            $this->respond($connection, Protocol::encodeSuccess($result['data'], $result['meta']));
        } catch (EmptyConnection) {
            // Not a failure, nothing to log — see readLine() for why
            return;
        } catch (AgentException $e) {
            $this->logger->warn('Command rejected', [
                'code' => $e->code(),
                'message' => $e->getMessage(),
                'actor' => $actor->username,
            ]);
            $this->respond($connection, Protocol::encodeError($e->code(), $e->getMessage()));
        } catch (\Throwable $e) {
            // The real message only goes to the log — the web side gets a generic message, so nothing internal leaks
            $this->logger->error('An unexpected error occurred', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->respond($connection, Protocol::encodeError('internal_error', 'An internal error occurred'));
        }
    }

    /** @param resource|\Socket $connection */
    private function readLine(mixed $connection): string
    {
        $buffer = '';

        while (true) {
            $chunk = @socket_read($connection, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            if (str_contains($chunk, "\n")) {
                break;
            }

            if (strlen($buffer) > Protocol::MAX_FRAME) {
                throw new TransportError('Message exceeds the size limit');
            }
        }

        if (trim($buffer) === '') {
            /*
             * A connection came in and closed without sending anything — this is
             * **a check for whether the agent is still there**, not a broken
             * command · {@see Client::isAvailable()} opens a socket and calls
             * fclose() immediately, which is the correct way to ask "can this
             * accept a connection?"
             *
             * This case used to be logged as a WARN "command rejected" every
             * single time — the scheduler calls it every minute, and the web page
             * calls it on every load, so agent.log filled up with a fake warning
             * line per minute until real failures drowned in it · **that's exactly
             * why real problems couldn't be traced on the production machine** —
             * an admin would open the log, see nothing but "command rejected", and
             * conclude the system was broken when it was actually fine.
             */
            throw new EmptyConnection();
        }

        return $buffer;
    }

    /** @param resource|\Socket $connection */
    private function respond(mixed $connection, string $payload): void
    {
        $length = strlen($payload);
        $written = 0;

        while ($written < $length) {
            $sent = @socket_write($connection, substr($payload, $written), $length - $written);
            if ($sent === false) {
                return;
            }
            $written += $sent;
        }
    }

    private function shutdown(int $signal): void
    {
        $this->running = false;

        // Wakes up a blocked accept so it exits the loop
        if ($this->socket !== null) {
            @socket_shutdown($this->socket, 2);
        }
    }

    private function reap(int $signal): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $this->children = max(0, $this->children - 1);
        }
    }

    private function cleanup(string $path): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }

        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
