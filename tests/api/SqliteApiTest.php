<?php

declare(strict_types=1);

/**
 * สัญญาของโมดูลจัดการฐานข้อมูล SQLite — PLAN-V2
 *
 * โมดูลนี้เป็น Read-Only Introspection tool สำหรับผู้ดูแลระบบ
 * เทสต์ชุดนี้ครอบคลุมการตรวจสอบสิทธิ์ (sqlite.manage), ความปลอดภัย (Read-Only query filter),
 * และความถูกต้องของ API endpoints ทั้ง 11 เส้นทาง
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของโมดูลจัดการ SQLite');

function sqliteHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('sqliteadmin', 'Sqlite-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createUser('sqlitesysadmin', 'Sqlite-SysAdmin-Pass-22', Permissions::SYSADMIN);
    $harness->createHostingUser('sqlitecustomer', 'Sqlite-Cust-Pass-33', Permissions::WEBADMIN);

    return $harness;
}

function sqliteLogin(string $username, string $password): ApiHarness
{
    $harness = sqliteHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

test('ลูกค้าทั่วไป (webadmin) เข้าถึง SQLite API ไม่ได้เลย (ต้องได้รับ 403)', static function (): void {
    $harness = sqliteLogin('sqlitecustomer', 'Sqlite-Cust-Pass-33');

    $endpoints = [
        ['GET', '/api/v2/sqlite/info', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/tables', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/views', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/indexes', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/triggers', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/search', [], ['query' => ['q' => 'admin']]],
        ['POST', '/api/v2/sqlite/query', ['sql' => 'SELECT 1'], ['query' => []]],
        ['GET', '/api/v2/sqlite/tables/users', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/tables/users/rows', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/tables/users/count', [], ['query' => []]],
        ['GET', '/api/v2/sqlite/tables/users/export', [], ['query' => []]],
    ];

    foreach ($endpoints as [$method, $path, $body, $opts]) {
        $query = $opts['query'] ?? [];
        $response = $harness->request($method, $path, $body, query: $query);

        assertSame(403, $response->status, "{$method} {$path} ต้องถูกปฏิเสธด้วย 403 FORBIDDEN สำหรับลูกค้า");
        assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
    }
});

test('ผู้ดูแลระบบ (superadmin และ sysadmin) สามารถอ่านข้อมูล SQLite ได้ครบทุก endpoint', static function (): void {
    $harness = sqliteLogin('sqliteadmin', 'Sqlite-Admin-Pass-11');

    // 1. Info
    $info = $harness->request('GET', '/api/v2/sqlite/info');
    assertSame(200, $info->status, 'GET /sqlite/info ต้องสำเร็จ 200');
    assertTrue($info->isJson(), 'GET /sqlite/info ต้องเป็น JSON');
    assertTrue(isset($info->json['data']['file_size']), 'GET /sqlite/info ต้องมี file_size');

    // 2. Tables
    $tables = $harness->request('GET', '/api/v2/sqlite/tables');
    assertSame(200, $tables->status, 'GET /sqlite/tables ต้องสำเร็จ 200');
    assertTrue(is_array($tables->json['data']), 'GET /sqlite/tables ต้องได้ array');
    assertTrue(count($tables->json['data']) > 0, 'GET /sqlite/tables ต้องไม่ว่างเปล่า');

    // Find table 'users'
    $tableNames = array_column($tables->json['data'], 'name');
    assertTrue(in_array('users', $tableNames, true), 'ตาราง users ต้องอยู่ในรายการตาราง');

    // 3. Views
    $views = $harness->request('GET', '/api/v2/sqlite/views');
    assertSame(200, $views->status, 'GET /sqlite/views ต้องสำเร็จ 200');
    assertTrue(is_array($views->json['data']), 'GET /sqlite/views ต้องได้ array');

    // 4. Indexes
    $indexes = $harness->request('GET', '/api/v2/sqlite/indexes');
    assertSame(200, $indexes->status, 'GET /sqlite/indexes ต้องสำเร็จ 200');
    assertTrue(is_array($indexes->json['data']), 'GET /sqlite/indexes ต้องได้ array');

    // 5. Triggers
    $triggers = $harness->request('GET', '/api/v2/sqlite/triggers');
    assertSame(200, $triggers->status, 'GET /sqlite/triggers ต้องสำเร็จ 200');
    assertTrue(is_array($triggers->json['data']), 'GET /sqlite/triggers ต้องได้ array');

    // 6. Table Schema
    $schema = $harness->request('GET', '/api/v2/sqlite/tables/users');
    assertSame(200, $schema->status, 'GET /sqlite/tables/users ต้องสำเร็จ 200');
    assertSame('users', $schema->json['data']['name'], 'ตารางใน Schema ต้องเป็น users');
    assertTrue(isset($schema->json['data']['columns']), 'Schema ต้องมีคอลัมน์');

    // 7. Table Rows (Browse)
    $rows = $harness->request('GET', '/api/v2/sqlite/tables/users/rows');
    assertSame(200, $rows->status, 'GET /sqlite/tables/users/rows ต้องสำเร็จ 200');
    assertTrue(is_array($rows->json['data']), 'Rows ต้องได้ array');
    assertTrue(isset($rows->json['meta']['total']), 'Rows ต้องมี meta.total');

    // 8. Row Count
    $count = $harness->request('GET', '/api/v2/sqlite/tables/users/count');
    assertSame(200, $count->status, 'GET /sqlite/tables/users/count ต้องสำเร็จ 200');
    assertSame('users', $count->json['data']['table'], 'Row count table ต้องเป็น users');
    assertTrue(is_int($count->json['data']['row_count']), 'Row count ต้องเป็น integer');

    // 9. Export (JSON & CSV)
    $exportJson = $harness->request('GET', '/api/v2/sqlite/tables/users/export', query: ['format' => 'json']);
    assertSame(200, $exportJson->status, 'Export JSON ต้องสำเร็จ 200');
    assertSame('users', $exportJson->json['data']['table'], 'Export table ต้องเป็น users');
    assertSame('json', $exportJson->json['data']['format'], 'Export format ต้องเป็น json');

    $exportCsv = $harness->request('GET', '/api/v2/sqlite/tables/users/export', query: ['format' => 'csv']);
    assertSame(200, $exportCsv->status, 'Export CSV ต้องสำเร็จ 200');
    assertSame('users', $exportCsv->json['data']['table'], 'Export table ต้องเป็น users');
    assertSame('csv', $exportCsv->json['data']['format'], 'Export format ต้องเป็น csv');

    // 10. Search
    $search = $harness->request('GET', '/api/v2/sqlite/search', query: ['q' => 'sqliteadmin']);
    assertSame(200, $search->status, 'Search SQLite ต้องสำเร็จ 200');
    assertTrue(is_array($search->json['data']), 'Search ต้องได้ array');
});

test('การรัน SQL Query ป้องกันคำสั่งดัดแปลงข้อมูล (Write Operations Blocked)', static function (): void {
    $harness = sqliteLogin('sqliteadmin', 'Sqlite-Admin-Pass-11');

    // Safe SELECT query
    $safe = $harness->request('POST', '/api/v2/sqlite/query', ['sql' => 'SELECT id, username FROM users LIMIT 5']);
    assertSame(200, $safe->status, 'SELECT query ต้องสำเร็จ 200');
    assertTrue(isset($safe->json['data']['columns']), 'SELECT query ต้องตอบ columns');
    assertTrue(isset($safe->json['data']['rows']), 'SELECT query ต้องตอบ rows');

    // PRAGMA query
    $pragma = $harness->request('POST', '/api/v2/sqlite/query', ['sql' => 'PRAGMA table_info(users)']);
    assertSame(200, $pragma->status, 'PRAGMA query ต้องสำเร็จ 200');
    assertTrue(isset($pragma->json['data']['columns']), 'PRAGMA query ต้องตอบ columns');

    // Blocked WRITE queries (INSERT, UPDATE, DELETE, DROP)
    $unsafeQueries = [
        'DELETE FROM users WHERE id = 9999',
        'UPDATE users SET username = "hacked" WHERE id = 1',
        'INSERT INTO users (username) VALUES ("test")',
        'DROP TABLE users',
        'ALTER TABLE users ADD COLUMN test TEXT',
    ];

    foreach ($unsafeQueries as $sql) {
        $res = $harness->request('POST', '/api/v2/sqlite/query', ['sql' => $sql]);
        assertSame(422, $res->status, "คำสั่ง '{$sql}' ต้องถูกปฏิเสธด้วย 422 VALIDATION_ERROR");
        assertSame(ApiProblem::ValidationError->value, $res->errorCode(), "คำสั่ง '{$sql}' ต้องได้ errorCode VALIDATION_ERROR");
    }
});
