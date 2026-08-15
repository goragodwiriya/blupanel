<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use PDO;
use PDOStatement;

/**
 * The panel's SQLite database — decision D4: no dependency on MariaDB, because the
 * panel is the one that controls MariaDB.
 *
 * WAL is enabled so reads work while a write is in progress (an SSE stream that's
 * polling must not be blocked by an audit-log write).
 */
final class Db
{
    private PDO $pdo;

    /** @var array<string,PDOStatement> prepared-statement cache, per process */
    private array $statements = [];

    private bool $walAvailable = true;

    /**
     * true while one of our own transaction() calls is open
     *
     * Tracked by hand because `PDO::inTransaction()` has no idea about a transaction
     * opened with `exec('BEGIN IMMEDIATE')` — PDO only sees transactions it opened
     * itself through beginTransaction().
     */
    private bool $inTransaction = false;

    public function __construct(private readonly string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create the database directory: {$dir}");
        }

        $isNew = !is_file($file);

        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // The database file holds password hashes and sessions — must never be world-readable
        if ($isNew) {
            @chmod($file, 0600);
        }

        // Order matters: busy_timeout has to be set before anything else.
        //
        // Changing journal_mode needs an exclusive lock if busy_timeout isn't set
        // yet — concurrent connections (an SSE stream held open, plus several
        // workers) would fail immediately with "database is locked" instead of
        // waiting their turn.
        // 15 seconds, not 5 — several processes write concurrently (the web tier,
        // the agent forking a child per request, the CLI), and on a filesystem
        // without full locking, like FUSE/NFS, waiting longer beats failing partway
        // through and leaving work half-done.
        $this->pdo->exec('PRAGMA busy_timeout = 15000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->enableWal();

        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    /**
     * Turns on WAL mode where possible — lets reads happen alongside a write without blocking
     *
     * Checked first whether it's already in this mode, because re-issuing it on
     * every connection would request a stronger lock than necessary.
     *
     * WAL needs shared memory, which some filesystems (FUSE, NFS, NTFS mounted
     * through FUSE) don't fully support. In that case it falls back to the normal
     * mode instead of taking the whole system down.
     */
    private function enableWal(): void
    {
        try {
            $current = (string) $this->pdo->query('PRAGMA journal_mode')->fetchColumn();

            if (strtolower($current) === 'wal') {
                return;
            }

            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } catch (\PDOException) {
            // Keeps using the default mode — slower, but still correct
            $this->walAvailable = false;
        }
    }

    public function walEnabled(): bool
    {
        return $this->walAvailable;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function file(): string
    {
        return $this->file;
    }

    /** @param array<string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->statements[$sql] ??= $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $statement = $this->run($sql, $params);
        $row = $statement->fetch();

        // The cursor must be closed by hand — see value() for the full reasoning
        $statement->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * A single value from the first column of the first row
     *
     * The cursor has to be closed every time, never left standing — this was a real
     * bug once: a `fetch()`/`fetchColumn()` that hadn't stepped to the end left a
     * read transaction open on this connection. When another process was holding a
     * write lock (the agent writing the audit log in a forked child), our very next
     * write got `SQLITE_BUSY` **immediately**, never waiting out `busy_timeout` at
     * all, because SQLite treats that as a lock-upgrade deadlock, not an ordinary
     * queue wait.
     *
     * What showed up was "database is locked" or "bad parameter or other API
     * misuse", at random, only when two processes happened to run at once — very
     * hard to trace back to the cause.
     *
     * @param array<string,mixed> $params
     */
    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $statement = $this->run($sql, $params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? $default : $value;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :set_{$c}", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => "{$c} = :where_{$c}", array_keys($where)));

        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        foreach ($where as $key => $value) {
            $params['where_' . $key] = $value;
        }

        return $this->run("UPDATE {$table} SET {$set} WHERE {$cond}", $params)->rowCount();
    }

    /**
     * Wraps work in a transaction that is **always IMMEDIATE**
     *
     * **Why IMMEDIATE, not a plain `beginTransaction()`:** PDO starts with `BEGIN
     * DEFERRED`, which reserves no lock at all yet. If the first statement in the
     * block is a **read**, the connection acquires a read lock, and when it later
     * needs to **write** it has to upgrade that lock — if another connection is
     * already holding a write lock at that moment, SQLite returns `SQLITE_BUSY`
     * **immediately**, **never honouring `busy_timeout` at all**, because waiting in
     * that state is a deadlock.
     *
     * `BEGIN IMMEDIATE` reserves the write lock from the start, so waiting only ever
     * happens at the one point where waiting is safe, and `busy_timeout` behaves as
     * configured.
     *
     * **The symptom before this was fixed:** `RateLimiter::allow()` and
     * `AuditLog::write()` both use a transaction and run on every request, both
     * reading before writing. Eight concurrent requests failed seven of them with
     * "database is locked", on both ext4 and FUSE. The old HTML UI fired one request
     * per page, so nobody ever hit it — the SPA fires several requests per page at
     * once, so the symptom appeared right away.
     *
     * Supports nesting: an inner block reuses the outer transaction rather than
     * opening a new one (SQLite has no real nested transactions, and a savepoint
     * isn't needed here).
     */
    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction) {
            return $work($this);
        }

        // Uses exec directly, since PDO has no way to tell SQLite which kind of transaction to open
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;

        try {
            $result = $work($this);
            $this->pdo->exec('COMMIT');
            $this->inTransaction = false;

            return $result;
        } catch (\Throwable $e) {
            $this->inTransaction = false;

            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
                // The transaction was already cancelled by SQLite itself — nothing
                // more to do, and this must never mask the real error about to be thrown
            }

            throw $e;
        }
    }

    /**
     * Runs migrations that haven't run yet, in filename order
     *
     * @return list<string> the filenames just run
     */
    public function migrate(string $directory): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at INTEGER NOT NULL
            )'
        );

        $applied = array_column($this->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Could not read migration file: {$file}");
            }

            $this->runMigration($version, $sql);

            $ran[] = $version;
        }

        return $ran;
    }

    /**
     * Marks that this migration needs to rebuild a table from scratch
     *
     * Placed as any line at all inside the .sql file
     */
    private const REBUILD_DIRECTIVE = '-- phpcp:rebuild-tables';

    /**
     * Runs one migration file
     *
     * Normally just wrapped in a transaction — but a migration that needs to
     * **rebuild a table from scratch** (dropping a UNIQUE constraint declared at
     * CREATE time, say, which `ALTER TABLE DROP COLUMN` can't do) has to turn off
     * foreign_keys first, or `DROP TABLE` on the parent table would fire the child
     * tables' `ON DELETE CASCADE`/`SET NULL` and genuinely lose data — and SQLite
     * forbids `PRAGMA foreign_keys` in the middle of a transaction, so it has to be
     * issued here instead.
     *
     * This sequence is SQLite's own documented approach (manual section "Making
     * Other Kinds Of Table Schema Changes") — atomicity still holds because the
     * transaction still wraps everything inside, and the `foreign_key_check` run
     * before commit catches any relationship left broken along the way.
     */
    private function runMigration(string $version, string $sql): void
    {
        $rebuild = str_contains($sql, self::REBUILD_DIRECTIVE);

        if ($rebuild) {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }

        // SQLite can run several statements in one exec, but the transaction is wrapped by hand so a failure never leaves it half-done
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec($sql);

            if ($rebuild) {
                $broken = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);

                if ($broken !== []) {
                    throw new \RuntimeException(
                        'Rebuilding the table left '.count($broken)
                        .' relationship(s) pointing at rows that no longer exist',
                    );
                }
            }

            $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, time()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw new \RuntimeException("Migration failed at {$version}: ".$e->getMessage(), 0, $e);
        } finally {
            // Always turned back on, even if the migration failed — otherwise the
            // whole process keeps running with no foreign key protection at all,
            // more dangerous than the failed migration itself
            if ($rebuild) {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    public function pendingMigrations(string $directory): int
    {
        $table = $this->value(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='schema_migrations'"
        );
        $applied = ((int) $table) === 0
            ? []
            : array_column($this->all('SELECT version FROM schema_migrations'), 'version');

        $files = glob($directory . '/*.sql') ?: [];
        $versions = array_map(static fn (string $f): string => basename($f, '.sql'), $files);

        return count(array_diff($versions, $applied));
    }
}
