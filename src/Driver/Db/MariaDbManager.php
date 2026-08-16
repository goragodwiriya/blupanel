<?php

declare (strict_types = 1);

namespace Phpcp\Driver\Db;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Manages MariaDB/MySQL through the mysql/mysqldump commands — ARCHITECTURE §4.3
 *
 * This class's most important safety property: SQL sent out is assembled
 * only from identifiers that already passed an allowlist regex, and sent
 * through stdin, never through argv.
 *
 * Why stdin: a process's argv is visible via /proc/<pid>/cmdline to every
 * user on the machine — a password sent through argv would leak to a
 * compromised website.
 *
 * An identifier is also wrapped in backticks as a second layer, in case the
 * regex ever slips (the same double-guard approach used for the argv array in RealExecutor).
 */
final class MariaDbManager
{
    private const CLIENT = '/usr/bin/mariadb';
    private const CLIENT_FALLBACK = '/usr/bin/mysql';
    private const DUMP = '/usr/bin/mariadb-dump';
    private const DUMP_FALLBACK = '/usr/bin/mysqldump';

    /** Database and user names the system must never touch — these belong to MariaDB itself */
    private const RESERVED = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * MariaDB's own admin credential file
     *
     * Checks /root/.my.cnf first, then /etc/mysql/debian.cnf or
     * /etc/my.cnf · read from a file instead of sending a password through
     * argv, since every process's argv is readable via /proc/<pid>/cmdline
     * by every user on the machine.
     *
     * Returns null when no such file is found — never returns a path that
     * doesn't genuinely exist, because a `--defaults-file` pointing at a
     * missing file makes the client die instantly with "Could not open
     * required defaults file", even though it would connect fine without that flag at all.
     *
     * When null is returned, the caller (the agent, running as root)
     * connects through unix_socket instead, Debian/Ubuntu's own default, which root can already use with no password.
     */
    private function defaultsFile(Executor $executor): ?string
    {
        foreach (['/root/.my.cnf', '/etc/mysql/debian.cnf', '/etc/my.cnf'] as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The --defaults-file flag, only when a genuine credential file exists
     *
     * @return list<string>
     */
    private function defaultsFlag(Executor $executor): array
    {
        $file = $this->defaultsFile($executor);

        return $file === null ? [] : ['--defaults-file='.$executor->path($file)];
    }

    /** The selectable privilege levels, translated into a real GRANT list only here */
    private const PRIVILEGES = [
        'readonly' => 'SELECT, SHOW VIEW',
        'readwrite' => 'SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, SHOW VIEW, EXECUTE',
        'full' => 'ALL PRIVILEGES'
    ];

    public function isInstalled(Executor $executor): bool
    {
        return $this->client($executor) !== null;
    }

    /** A database name must pass both the regex and the denylist */
    public static function assertDatabaseName(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/i', $name) !== 1) {
            throw new ValidationError(
                'Database name must start with a letter, followed by letters, digits, or _, 2-64 characters long',
            );
        }

        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw new ValidationError("Name {$name} is a system database and cannot be used");
        }

        return $name;
    }

    /**
     * @param string $name
     */
    public static function assertUserName(string $name): string
    {
        // MariaDB limits usernames to 80 characters, but this is stricter, to stay easy to reason about and safe
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/i', $name) !== 1) {
            throw new ValidationError(
                'Database username must start with a letter, followed by letters, digits, or _, 2-32 characters long',
            );
        }

        if (in_array(strtolower($name), ['root', 'mysql', 'mariadb.sys', 'debian-sys-maint'], true)) {
            throw new ValidationError("Name {$name} is a system user and cannot be used");
        }

        return $name;
    }

    /**
     * @param string $level
     * @return mixed
     */
    public static function assertPrivilege(string $level): string
    {
        if (!isset(self::PRIVILEGES[$level])) {
            throw new ValidationError('Invalid privilege level');
        }

        return $level;
    }

    /** @return list<string> */
    public static function privilegeLevels(): array
    {
        return array_keys(self::PRIVILEGES);
    }

    /**
     * @param string $level
     */
    public static function privilegeLabel(string $level): string
    {
        return match ($level) {
            'readonly' => 'Read-only',
            'readwrite' => 'Read and write',
            'full' => 'Full privileges',
            default => $level,
        };
    }

    /**
     * Runs SQL and returns the result as rows — SQL is sent through stdin, never argv
     *
     * @return list<array<string,string>>
     */
    public function query(Executor $executor, string $sql): array
    {
        $result = $this->run($executor, $sql, ['--batch', '--raw']);

        $lines = $result->lines();
        if ($lines === []) {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            $values = explode("\t", $line);
            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** Runs SQL that doesn't need a result */
    public function execute(Executor $executor, string $sql): void
    {
        $this->run($executor, $sql, ['--batch']);
    }

    /**
     * Each database's size, from information_schema
     *
     * @return array<string,int> database name => bytes
     */
    public function sizes(Executor $executor): array
    {
        $rows = $this->query($executor, <<<'SQL'
            SELECT table_schema AS db, COALESCE(SUM(data_length + index_length), 0) AS bytes
            FROM information_schema.TABLES
            GROUP BY table_schema
            SQL);

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[$row['db'] ?? ''] = (int) ($row['bytes'] ?? 0);
        }

        return $sizes;
    }

    /** @return list<string> */
    public function databases(Executor $executor): array
    {
        $rows = $this->query($executor, 'SHOW DATABASES');
        $names = array_map(static fn(array $r): string => reset($r) ?: '', $rows);

        return array_values(array_filter(
            $names,
            static fn(string $n): bool => $n !== '' && !in_array(strtolower($n), self::RESERVED, true),
        ));
    }

    /**
     * @param Executor $executor
     * @param string $name
     * @param string $charset
     */
    public function createDatabase(Executor $executor, string $name, string $charset = 'utf8mb4'): void
    {
        self::assertDatabaseName($name);
        $collation = $charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : $charset.'_general_ci';

        $this->execute($executor, sprintf(
            'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
            $name,
            self::assertCharset($charset),
            $collation,
        ));
    }

    /**
     * @param Executor $executor
     * @param string $name
     */
    public function dropDatabase(Executor $executor, string $name): void
    {
        self::assertDatabaseName($name);

        $this->execute($executor, sprintf('DROP DATABASE `%s`', $name));
    }

    /**
     * @param Executor $executor
     * @param string $user
     * @param string $host
     * @param string $password
     */
    /**
     * Does this MariaDB user already exist on the machine?
     *
     * Checked directly against mysql.user, never against the panel's own
     * table — the two can disagree (e.g. an old panel.db was restored while MariaDB stayed current).
     */
    public function userExists(Executor $executor, string $user, string $host): bool
    {
        self::assertUserName($user);
        self::assertHost($host);

        $rows = $this->query($executor, sprintf(
            "SELECT 1 AS found FROM mysql.user WHERE User = '%s' AND Host = '%s'",
            self::escapeString($user),
            self::escapeString($host),
        ));

        return $rows !== [];
    }

    public function createUser(Executor $executor, string $user, string $host, string $password): void
    {
        self::assertUserName($user);
        self::assertHost($host);

        // The password is the one value here that isn't an identifier — it must be string-escaped
        $this->execute($executor, sprintf(
            "CREATE USER '%s'@'%s' IDENTIFIED BY '%s'",
            $user,
            $host,
            self::escapeString($password),
        ));
    }

    /**
     * @param Executor $executor
     * @param string $user
     * @param string $host
     * @param string $password
     */
    public function setPassword(Executor $executor, string $user, string $host, string $password): void
    {
        self::assertUserName($user);
        self::assertHost($host);

        $this->execute($executor, sprintf(
            "ALTER USER '%s'@'%s' IDENTIFIED BY '%s'",
            $user,
            $host,
            self::escapeString($password),
        ));
    }

    /**
     * @param Executor $executor
     * @param string $user
     * @param string $host
     */
    public function dropUser(Executor $executor, string $user, string $host): void
    {
        self::assertUserName($user);
        self::assertHost($host);

        $this->execute($executor, sprintf("DROP USER '%s'@'%s'", $user, $host));
    }

    /**
     * @param Executor $executor
     * @param string $database
     * @param string $user
     * @param string $host
     * @param string $level
     */
    public function grant(Executor $executor, string $database, string $user, string $host, string $level): void
    {
        self::assertDatabaseName($database);
        self::assertUserName($user);
        self::assertHost($host);
        self::assertPrivilege($level);

        $this->execute($executor, sprintf(
            "GRANT %s ON `%s`.* TO '%s'@'%s'",
            self::PRIVILEGES[$level],
            $database,
            $user,
            $host,
        ));

        $this->execute($executor, 'FLUSH PRIVILEGES');
    }

    /**
     * Does this account hold server-wide privileges (`ON *.*`)?
     *
     * Read from `SHOW GRANTS`, never from the panel's own table — the real privileges live in MariaDB, and can be edited by hand outside the panel.
     */
    public function hasGlobalPrivileges(Executor $executor, string $user, string $host): bool
    {
        self::assertUserName($user);
        self::assertHost($host);

        $rows = $this->query($executor, sprintf(
            "SHOW GRANTS FOR '%s'@'%s'",
            self::escapeString($user),
            self::escapeString($host),
        ));

        foreach ($rows as $row) {
            foreach ($row as $grant) {
                if (str_contains((string) $grant, 'ALL PRIVILEGES ON *.*')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Grants or revokes server-wide privileges
     *
     * **Used only for a server admin's account** — a privilege equivalent
     * to MariaDB's own root in practice · the reason it's allowed at all is
     * that an admin has to manage every database on the machine through
     * phpMyAdmin without ever having to set a password on the root
     * account, which currently uses `unix_socket` and can only ever be
     * touched by the operating system's own root — setting a password on
     * root would turn the single most powerful account into something
     * password-guessable, far worse than this approach.
     *
     * `WITH GRANT OPTION` is included because without it, phpMyAdmin's own
     * user-management page only half works (can create a user but can't
     * grant privileges), which is more confusing than not offering it at all.
     */
    public function setGlobalPrivileges(Executor $executor, string $user, string $host, bool $granted): void
    {
        self::assertUserName($user);
        self::assertHost($host);

        $current = $this->hasGlobalPrivileges($executor, $user, $host);

        // Nothing is run when the state already matches — a REVOKE with
        // nothing to revoke fails with error 1141, and swallowing that
        // error would also swallow a genuine failure along with it
        if ($current === $granted) {
            return;
        }

        $this->execute($executor, sprintf(
            $granted
                ? "GRANT ALL PRIVILEGES ON *.* TO '%s'@'%s' WITH GRANT OPTION"
                : "REVOKE ALL PRIVILEGES, GRANT OPTION ON *.* FROM '%s'@'%s'",
            self::escapeString($user),
            self::escapeString($host),
        ));

        $this->execute($executor, 'FLUSH PRIVILEGES');
    }

    /**
     * Backs up a database into a `.sql.gz` file, returning the actual bytes written
     *
     * **Always compressed, never optional** — this file lives in a
     * customer's home and counts against their own quota (PLAN-BACKUP-V2
     * item B9) · SQL text compresses roughly 5-10× — the difference is
     * space a customer would otherwise pay for on a file that repeats every night.
     *
     * Compressed with zlib in-process rather than piping to the `gzip`
     * command, because this project's own `exec()` takes argv only, with
     * no shell to pipe through (deliberately — see RealExecutor) · writing
     * the raw file to disk first and calling gzip after would create a
     * window where the file sits **uncompressed** against a customer's
     * quota, and would leave a stray file behind if it failed partway
     * through · the output is a standard gzip file, readable by `gunzip`/`zcat` the same either way.
     */
    public function dump(Executor $executor, string $database, string $target): int
    {
        self::assertDatabaseName($database);

        if (!str_ends_with($target, '.gz')) {
            throw new ExecutionFailed('Database backup file must end with .gz');
        }

        $binary = $executor->exists(self::DUMP) ? self::DUMP : self::DUMP_FALLBACK;

        if (!$executor->exists($binary)) {
            throw new ExecutionFailed('No command for backing up databases was found on this machine');
        }

        /*
         * **Checked before dumping, so the operator gets an answer instead of a driver error**
         *
         * The panel's own table and MariaDB drift apart in ordinary ways: a
         * database dropped straight from the mysql client, a panel.db
         * restored from a backup taken before one was removed. What the
         * operator saw then was the raw line `mysqldump: Got error: 1049:
         * Unknown database 'x'` — true, but it says nothing about which of the
         * two sides is wrong, or what to do about it.
         *
         * It matters most in the automatic round, which runs unattended and
         * would otherwise report that same driver error every night with no
         * hint that the fix is to remove one stale row.
         */
        if (!in_array($database, $this->databases($executor), true)) {
            throw new ExecutionFailed(sprintf(
                'Database %s no longer exists on this machine, but the panel still has a record of it'
                . ' — remove that record from the Databases page, or restore the database, and try again',
                $database,
            ));
        }

        $result = $executor->exec([
            $binary,
            ...$this->defaultsFlag($executor),
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
            $database
        ], timeout: 600);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to back up database: '.trim($result->stderr));
        }

        $compressed = gzencode($result->stdout, 6);

        if ($compressed === false) {
            throw new ExecutionFailed('Failed to compress the database backup file');
        }

        $executor->writeFile($executor->path($target), $compressed, 0600);

        return strlen($compressed);
    }

    /**
     * Runs the client, sending SQL through stdin
     *
     * @param list<string> $flags
     */
    private function run(Executor $executor, string $sql, array $flags): \Phpcp\Agent\Executor\ExecResult
    {
        $client = $this->client($executor);

        if ($client === null) {
            throw new ExecutionFailed('No MariaDB client was found on this machine');
        }

        // The admin credential is read from a system file, never sent as a
        // password through argv, which other users on the machine can read
        // via /proc/<pid>/cmdline · the credential file's path must always
        // be mapped through the executor.
        //
        // Sending the raw path instead would make sandbox mode read the
        // machine's genuine /etc/mysql/debian.cnf and go create/delete
        // databases on the machine's real MariaDB, breaking that mode's core promise
        $argv = [$client, ...$this->defaultsFlag($executor), ...$flags];

        $result = $executor->exec($argv, timeout: 120, stdin: $sql);

        if (!$result->ok()) {
            throw new ExecutionFailed('Database command failed: '.trim($result->stderr));
        }

        return $result;
    }

    /**
     * @param Executor $executor
     * @return mixed
     */
    private function client(Executor $executor): ?string
    {
        foreach ([self::CLIENT, self::CLIENT_FALLBACK] as $candidate) {
            if ($executor->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param string $charset
     * @return mixed
     */
    private static function assertCharset(string $charset): string
    {
        if (preg_match('/^[a-z0-9_]{2,32}$/i', $charset) !== 1) {
            throw new ValidationError('Invalid character set');
        }

        return $charset;
    }

    /**
     * @param string $host
     * @return mixed
     */
    private static function assertHost(string $host): string
    {
        // Restricted narrower than what MariaDB itself would accept — only a few host values are genuinely used
        if (!in_array($host, ['localhost', '127.0.0.1', '%'], true)) {
            throw new ValidationError('A database user\'s host must be localhost, 127.0.0.1, or %');
        }

        return $host;
    }

    /** Escapes a string for use inside SQL's single quotes */
    private static function escapeString(string $value): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        );
    }
}
