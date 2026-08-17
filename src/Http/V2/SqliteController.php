<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\SqliteManager;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * SQLite database management — `/api/v2/sqlite`
 *
 * Read-only access to the panel's own SQLite database. Provides table browsing,
 * schema inspection, and read-only SQL query execution for administrators
 * who need to inspect or debug the panel's data directly.
 *
 * **Every write operation is blocked at the domain layer** — the panel's
 * schema lives in migrations and its data in proper repositories. Allowing
 * arbitrary writes through this endpoint would bypass validation, audit
 * logging, and business rules that protect data integrity.
 */
final class SqliteController extends ApiController
{
    private SqliteManager $manager;

    private function manager(): SqliteManager
    {
        return $this->manager ??= new SqliteManager($this->app->db());
    }

    /**
     * Database overview — file info, WAL status, table summary
     */
    public function info(Request $request): Response
    {
        return $this->ok($this->manager()->databaseInfo());
    }

    /**
     * List all user tables with their row counts
     */
    public function tables(Request $request): Response
    {
        return $this->ok($this->manager()->tablesWithRowCounts());
    }

    /**
     * List all views
     */
    public function views(Request $request): Response
    {
        return $this->ok($this->manager()->views());
    }

    /**
     * List all indexes
     */
    public function indexes(Request $request): Response
    {
        return $this->ok($this->manager()->indexes());
    }

    /**
     * List all triggers
     */
    public function triggers(Request $request): Response
    {
        return $this->ok($this->manager()->triggers());
    }

    /**
     * Schema details for one table — columns, foreign keys, indexes, SQL
     */
    public function tableSchema(Request $request): Response
    {
        $table = $request->param('table');

        if ($table === '') {
            return $this->problem(ApiProblem::ValidationError, 'Table name is required');
        }

        try {
            return $this->ok($this->manager()->tableSchema($table));
        } catch (\RuntimeException $e) {
            return $this->problem(ApiProblem::NotFound, $e->getMessage());
        }
    }

    /**
     * Browse rows from a table with pagination
     */
    public function browse(Request $request): Response
    {
        $table = $request->param('table');

        if ($table === '') {
            return $this->problem(ApiProblem::ValidationError, 'Table name is required');
        }

        ['page' => $page, 'per_page' => $perPage] = $this->pagination($request);

        try {
            $manager = $this->manager();

            // The table's own columns are the sort allowlist — ORDER BY must only
            // ever see a name from this list, never the raw query string
            $columns = $manager->columnNames($table);
            $sort = $this->sort($request, $columns, '');

            $data = $manager->browseTable(
                $table,
                $page,
                $perPage,
                $sort['field'] !== '' ? $sort['field'] : null,
                $sort['desc'],
            );

            // The screen's table has no static <thead> — its columns differ per
            // SQLite table, so they travel with the response and Now.js's
            // TableManager builds the headers itself (data-dynamic-columns).
            // `sort` carries the field name to ORDER BY (not a boolean flag —
            // TableManager copies it straight into the th's data-sort).
            // Column names are SQLite identifiers, never prose — no translation.
            return Response::json([
                'ok' => true,
                'data' => $data['rows'],
                'columns' => array_map(
                    static fn (string $name): array => [
                        'field' => $name,
                        'label' => $name,
                        'cellClass' => 'mono',
                        'sort' => $name,
                    ],
                    $columns,
                ),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $data['total'],
                    'total_pages' => $perPage > 0 ? (int) ceil($data['total'] / $perPage) : 0,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->problem(ApiProblem::NotFound, $e->getMessage());
        }
    }

    /**
     * Row count for one table
     */
    public function rowCount(Request $request): Response
    {
        $table = $request->param('table');

        if ($table === '') {
            return $this->problem(ApiProblem::ValidationError, 'Table name is required');
        }

        try {
            return $this->ok([
                'table' => $table,
                'row_count' => $this->manager()->rowCount($table),
            ]);
        } catch (\RuntimeException $e) {
            return $this->problem(ApiProblem::NotFound, $e->getMessage());
        }
    }

    /**
     * Execute a read-only SQL query
     *
     * Accepts SELECT, PRAGMA, EXPLAIN, and WITH (CTE) statements.
     * Write operations are rejected at the domain layer before reaching PDO.
     */
    public function query(Request $request): Response
    {
        $sql = $request->payloadString('sql');

        if ($sql === '') {
            return $this->problem(ApiProblem::ValidationError, 'SQL query is required', [
                'sql' => 'The "sql" field must contain a SELECT, PRAGMA, or EXPLAIN statement',
            ]);
        }

        $limit = min(
            max(1, (int) $request->payload('limit', 200)),
            self::PER_PAGE_MAX,
        );

        try {
            $result = $this->manager()->executeQuery($sql, $limit);

            // Column descriptors for TableManager's dynamic headers — field
            // names straight from the statement, never prose, never translated.
            // `sort` is the field name (TableManager puts it in data-sort and
            // sorts the result table client-side, since it has no data-source)
            $result['columns'] = array_map(
                static fn (string $name): array => [
                    'field' => $name,
                    'label' => $name,
                    'cellClass' => 'mono',
                    'sort' => $name,
                ],
                $result['columns'],
            );

            return $this->ok($result);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['sql' => $e->getMessage()]);
        }
    }

    /**
     * Export a table's data
     *
     * Returns all rows (up to a limit) for export purposes.
     */
    public function export(Request $request): Response
    {
        $table = $request->param('table');

        if ($table === '') {
            return $this->problem(ApiProblem::ValidationError, 'Table name is required');
        }

        $format = $request->get('format', 'json');
        $limit = min(
            max(1, $request->queryInt('limit', 10000)),
            50000,
        );

        try {
            $manager = $this->manager();

            if ($format === 'csv') {
                $rows = $manager->exportTable($table, $limit);
                $columns = $manager->columnNames($table);

                if ($columns === []) {
                    return $this->ok(['columns' => [], 'rows' => [], 'total' => 0]);
                }

                $csv = fopen('php://temp', 'r+');
                fputcsv($csv, $columns);

                foreach ($rows as $row) {
                    fputcsv($csv, array_map(static fn ($v) => (string) ($v ?? ''), $row));
                }

                rewind($csv);
                $csvContent = stream_get_contents($csv);
                fclose($csv);

                return Response::json([
                    'ok' => true,
                    'data' => [
                        'format' => 'csv',
                        'table' => $table,
                        'total' => count($rows),
                        'content' => $csvContent,
                    ],
                ])->withHeader('Content-Type', 'application/json; charset=UTF-8');
            }

            $rows = $manager->exportTable($table, $limit);

            return $this->ok([
                'format' => 'json',
                'table' => $table,
                'columns' => $manager->columnNames($table),
                'total' => count($rows),
                'rows' => $rows,
            ]);
        } catch (\RuntimeException $e) {
            return $this->problem(ApiProblem::NotFound, $e->getMessage());
        }
    }

    /**
     * Search for a value across all tables
     */
    public function search(Request $request): Response
    {
        $term = $this->searchTerm($request);

        if ($term === '') {
            return $this->problem(ApiProblem::ValidationError, 'Search term is required', [
                'q' => 'Provide a search term using the "q" or "search" parameter',
            ]);
        }

        if (strlen($term) < 2) {
            return $this->problem(ApiProblem::ValidationError, 'Search term must be at least 2 characters');
        }

        if (strlen($term) > 100) {
            return $this->problem(ApiProblem::ValidationError, 'Search term must not exceed 100 characters');
        }

        return $this->ok($this->manager()->search($term));
    }
}
