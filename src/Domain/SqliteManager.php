<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * SQLite database introspection and query management
 *
 * Provides read-only access to the panel's own SQLite database structure
 * and data. All write operations (INSERT, UPDATE, DELETE, DROP, ALTER)
 * are deliberately blocked — the panel's schema is managed through
 * migrations only, and its data through the proper repositories.
 *
 * SQL queries submitted through this class are validated to ensure
 * they start with SELECT, PRAGMA, or EXPLAIN. Everything else is
 * rejected before it reaches PDO.
 */
final class SqliteManager
{
    /** Only these statement prefixes are allowed through execute() */
    private const ALLOWED_PREFIXES = ['SELECT', 'PRAGMA', 'EXPLAIN', 'WITH'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Overview of the entire database
     *
     * @return array<string,mixed>
     */
    public function databaseInfo(): array
    {
        $file = $this->db->file();
        $pdo = $this->db->pdo();

        $journalMode = $this->queryOne('PRAGMA journal_mode');
        $pageCount = (int) $this->queryOne('PRAGMA page_count');
        $pageSize = (int) $this->queryOne('PRAGMA page_size');
        $walEnabled = $this->db->walEnabled();

        $tableInfos = $this->tablesWithRowCounts();

        $fileSize = is_file($file) ? filesize($file) : 0;
        $walFile = $file . '-wal';
        $shmFile = $file . '-shm';
        $walSize = is_file($walFile) ? filesize($walFile) : 0;
        $shmSize = is_file($shmFile) ? filesize($shmFile) : 0;

        return [
            'file' => $file,
            'file_size' => $fileSize,
            'wal_size' => $walSize,
            'shm_size' => $shmSize,
            'journal_mode' => $journalMode,
            'wal_enabled' => $walEnabled,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'total_tables' => count($tableInfos),
            'tables' => $tableInfos,
            'foreign_keys' => $this->queryOne('PRAGMA foreign_keys'),
            'busy_timeout' => (int) $this->queryOne('PRAGMA busy_timeout'),
        ];
    }

    /**
     * All user-created table names (excludes sqlite internal tables)
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->db->all(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /**
     * All user tables with their row counts
     *
     * @return list<array{name: string, row_count: int}>
     */
    public function tablesWithRowCounts(): array
    {
        $tables = [];
        foreach ($this->tables() as $table) {
            $tables[] = [
                'name' => $table,
                'row_count' => $this->rowCount($table),
            ];
        }

        return $tables;
    }

    /**
     * All view names
     *
     * @return list<string>
     */
    public function views(): array
    {
        $rows = $this->db->all(
            "SELECT name FROM sqlite_master WHERE type='view' ORDER BY name"
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /**
     * All index names
     *
     * @return list<string>
     */
    public function indexes(): array
    {
        $rows = $this->db->all(
            "SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY tbl_name, name"
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'table' => (string) $r['tbl_name'],
        ], $rows);
    }

    /**
     * All triggers
     *
     * @return list<string>
     */
    public function triggers(): array
    {
        $rows = $this->db->all(
            "SELECT name FROM sqlite_master WHERE type='trigger' ORDER BY name"
        );

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /**
     * Detailed schema information for one table
     *
     * @return array<string,mixed>
     */
    public function tableSchema(string $tableName): array
    {
        $this->assertTableExists($tableName);

        $columns = $this->db->all('PRAGMA table_info(' . $this->quoteIdentifier($tableName) . ')');

        $columnDefs = [];
        foreach ($columns as $col) {
            $columnDefs[] = [
                'cid' => (int) $col['cid'],
                'name' => (string) $col['name'],
                'type' => (string) $col['type'],
                'notnull' => (bool) $col['notnull'],
                'default_value' => $col['dflt_value'],
                'primary_key' => (bool) $col['pk'],
            ];
        }

        $foreignKeys = $this->db->all('PRAGMA foreign_key_list(' . $this->quoteIdentifier($tableName) . ')');
        $fkDefs = [];
        foreach ($foreignKeys as $fk) {
            $fkDefs[] = [
                'id' => (int) $fk['id'],
                'from' => (string) $fk['from'],
                'table' => (string) $fk['table'],
                'to' => (string) $fk['to'],
                'on_update' => (string) $fk['on_update'],
                'on_delete' => (string) $fk['on_delete'],
            ];
        }

        $indexes = $this->db->all('PRAGMA index_list(' . $this->quoteIdentifier($tableName) . ')');
        $indexDefs = [];
        foreach ($indexes as $idx) {
            $indexColumns = $this->db->all(
                'PRAGMA index_info(' . $this->quoteIdentifier((string) $idx['name']) . ')'
            );
            $indexDefs[] = [
                'name' => (string) $idx['name'],
                'unique' => (bool) $idx['unique'],
                'origin' => (string) $idx['origin'],
                'columns' => array_map(static fn (array $c): string => (string) $c['name'], $indexColumns),
            ];
        }

        $sql = $this->db->first(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name = :name",
            ['name' => $tableName]
        );

        return [
            'name' => $tableName,
            'sql' => $sql !== null ? (string) $sql['sql'] : null,
            'columns' => $columnDefs,
            'foreign_keys' => $fkDefs,
            'indexes' => $indexDefs,
            'row_count' => $this->rowCount($tableName),
        ];
    }

    /**
     * Browse rows from a table with pagination
     *
     * @return array{rows: list<array<string,mixed>>, columns: list<string>, total: int}
     */
    public function browseTable(string $tableName, int $page = 1, int $perPage = 50, ?string $sort = null, bool $sortDesc = false): array
    {
        $this->assertTableExists($tableName);

        $allowedColumns = $this->columnNames($tableName);
        $total = $this->rowCount($tableName);

        $orderClause = '';
        if ($sort !== null && in_array($sort, $allowedColumns, true)) {
            $direction = $sortDesc ? 'DESC' : 'ASC';
            $orderClause = ' ORDER BY ' . $this->quoteIdentifier($sort) . ' ' . $direction;
        }

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->quoteIdentifier($tableName) . $orderClause . ' LIMIT :limit OFFSET :offset',
            ['limit' => $perPage, 'offset' => $offset]
        );

        return [
            'rows' => $rows,
            'columns' => $allowedColumns,
            'total' => $total,
        ];
    }

    /**
     * Execute a read-only SQL query
     *
     * Only SELECT, PRAGMA, EXPLAIN, and WITH (CTE) statements are allowed.
     * Write operations are blocked at this level before reaching PDO.
     *
     * @return array{columns: list<string>, rows: list<array<string,mixed>>, row_count: int}
     */
    public function executeQuery(string $sql, int $limit = 200): array
    {
        $this->assertReadOnly($sql);

        try {
            $statement = $this->db->pdo()->prepare($sql);

            if (preg_match('/^\s*(SELECT|WITH|EXPLAIN)/i', $sql)) {
                $statement->execute();

                $columns = [];
                for ($i = 0, $count = $statement->columnCount(); $i < $count; $i++) {
                    $meta = $statement->getColumnMeta($i);
                    $columns[] = $meta['name'] ?? "col{$i}";
                }

                $rows = [];
                while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                    $rows[] = $row;
                }
                $statement->closeCursor();

                if (count($rows) > $limit) {
                    $rows = array_slice($rows, 0, $limit);
                }

                return [
                    'columns' => $columns,
                    'rows' => $rows,
                    'row_count' => count($rows),
                    'truncated' => count($rows) >= $limit,
                ];
            }

            // PRAGMA or EXPLAIN (non-SELECT variants)
            $statement->execute();

            $columns = [];
            for ($i = 0, $count = $statement->columnCount(); $i < $count; $i++) {
                $meta = $statement->getColumnMeta($i);
                $columns[] = $meta['name'] ?? "col{$i}";
            }

            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $statement->closeCursor();

            return [
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => count($rows),
                'truncated' => false,
            ];
        } catch (\PDOException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Count rows in a table
     */
    public function rowCount(string $tableName): int
    {
        $this->assertTableExists($tableName);

        return (int) $this->db->value(
            'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($tableName)
        );
    }

    /**
     * Column names of a table
     *
     * @return list<string>
     */
    public function columnNames(string $tableName): array
    {
        $this->assertTableExists($tableName);

        $columns = $this->db->all('PRAGMA table_info(' . $this->quoteIdentifier($tableName) . ')');

        return array_map(static fn (array $c): string => (string) $c['name'], $columns);
    }

    /**
     * Export a table's data as an array of rows (for JSON/CSV export)
     *
     * @return list<array<string,mixed>>
     */
    public function exportTable(string $tableName, int $limit = 10000): array
    {
        $this->assertTableExists($tableName);

        return $this->db->all(
            'SELECT * FROM ' . $this->quoteIdentifier($tableName) . ' LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * Search for a value across all tables
     *
     * @return list<array{table: string, column: string, row: array<string,mixed>}>
     */
    public function search(string $term, int $limit = 50): array
    {
        $results = [];
        $tables = $this->tables();

        foreach ($tables as $table) {
            $columns = $this->columnNames($table);
            $conditions = [];
            $params = [];

            foreach ($columns as $column) {
                $paramKey = 'search_' . str_replace([' ', '.'], '_', $column);
                $conditions[] = $this->quoteIdentifier($column) . ' LIKE :' . $paramKey;
                $params[$paramKey] = '%' . $term . '%';
            }

            if ($conditions === []) {
                continue;
            }

            $sql = 'SELECT * FROM ' . $this->quoteIdentifier($table)
                . ' WHERE ' . implode(' OR ', $conditions)
                . ' LIMIT :search_limit';
            $params['search_limit'] = $limit;

            $rows = $this->db->all($sql, $params);

            foreach ($rows as $row) {
                $results[] = [
                    'table' => $table,
                    'row' => $row,
                ];

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Run a single PRAGMA or introspection query that returns one scalar value
     */
    private function queryOne(string $sql): string
    {
        return (string) $this->db->value($sql);
    }

    /**
     * Quote a SQLite identifier (table/column name) to prevent injection
     *
     * Uses double-quote escaping as per the SQLite specification.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Assert the table actually exists — throws if not
     *
     * The table name is quoted as an identifier so a crafted name can't
     * inject SQL, but it's still validated against the known table list
     * to prevent accessing system tables through aliases or tricks.
     */
    private function assertTableExists(string $tableName): void
    {
        $tables = $this->tables();

        if (!in_array($tableName, $tables, true)) {
            throw new \RuntimeException("Table '{$tableName}' does not exist");
        }
    }

    /**
     * Assert a query is read-only — throws if it contains a write statement
     *
     * Checks the first keyword and also scans for dangerous patterns
     * that might appear in subqueries or CTEs.
     */
    private function assertReadOnly(string $sql): void
    {
        $trimmed = ltrim($sql);
        $firstWord = strtoupper(strtok($trimmed, " \t\n\r(;"));

        if (!in_array($firstWord, self::ALLOWED_PREFIXES, true)) {
            throw new \RuntimeException(
                "Only read-only queries are allowed (SELECT, PRAGMA, EXPLAIN). "
                . "Got: {$firstWord}"
            );
        }

        // Block write operations even if hidden inside a CTE or subquery
        $upper = strtoupper($sql);
        $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'REPLACE', 'ATTACH', 'DETACH'];

        foreach ($blocked as $keyword) {
            // Match as a whole word, not as part of another word
            if (preg_match('/\b' . $keyword . '\b/', $upper)) {
                throw new \RuntimeException(
                    "Write operations are not allowed through the database manager. Found: {$keyword}"
                );
            }
        }
    }
}
