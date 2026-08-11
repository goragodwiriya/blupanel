<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * จำลอง MariaDB client ในโหมด sandbox
 *
 * จำเป็นด้วยเหตุผลด้านความปลอดภัยเป็นหลัก ไม่ใช่แค่ความสะดวก:
 * ถ้าไม่จำลอง คำสั่งจะไปเรียก /usr/bin/mariadb ตัวจริง แล้วสร้างหรือลบฐานข้อมูล
 * บน MariaDB จริงของเครื่อง ซึ่งขัดกับสัญญาของโหมด sandbox โดยตรง
 *
 * รับ SQL ทาง stdin เหมือน client จริง แล้วตีความเฉพาะคำสั่งที่ระบบใช้จริง
 * คำสั่งที่ไม่รู้จักจะถูกปฏิเสธ ไม่ใช่เงียบผ่าน — จะได้รู้ทันทีเมื่อมีการเพิ่มคำสั่งใหม่
 */
final class MariaDbSimulator implements Simulator
{
    public function handles(string $binary): bool
    {
        return in_array(basename($binary), ['mariadb', 'mysql', 'mariadb-dump', 'mysqldump'], true);
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        $binary = basename($argv[0]);

        if ($binary === 'mariadb-dump' || $binary === 'mysqldump') {
            return $this->dump($argv, $state);
        }

        $sql = trim((string) $stdin);

        return match (true) {
            $sql === '' => $this->ok($argv),
            str_starts_with(strtoupper($sql), 'SHOW DATABASES') => $this->showDatabases($argv, $state),
            str_contains(strtoupper($sql), 'INFORMATION_SCHEMA.TABLES') => $this->sizes($argv, $state),
            preg_match('/^CREATE DATABASE `([^`]+)`/i', $sql, $m) === 1 => $this->createDatabase($argv, $state, $m[1]),
            preg_match('/^DROP DATABASE `([^`]+)`/i', $sql, $m) === 1 => $this->dropDatabase($argv, $state, $m[1]),
            preg_match('/^CREATE USER \'([^\']+)\'@\'([^\']+)\'/i', $sql, $m) === 1 => $this->createUser($argv, $state, $m[1], $m[2]),
            preg_match('/^DROP USER \'([^\']+)\'@\'([^\']+)\'/i', $sql, $m) === 1 => $this->dropUser($argv, $state, $m[1], $m[2]),
            preg_match('/^ALTER USER /i', $sql) === 1 => $this->ok($argv),
            preg_match('/^(GRANT|REVOKE|FLUSH) /i', $sql) === 1 => $this->ok($argv),
            default => $this->fail($argv, 'sandbox: ยังไม่รองรับคำสั่ง SQL นี้ — ' . mb_substr($sql, 0, 60)),
        };
    }

    private function showDatabases(array $argv, SandboxState $state): ExecResult
    {
        $rows = ['Database', ...array_keys($this->databases($state))];

        return $this->rows($argv, $rows);
    }

    private function sizes(array $argv, SandboxState $state): ExecResult
    {
        $lines = ["db\tbytes"];

        foreach ($this->databases($state) as $name => $info) {
            $lines[] = $name . "\t" . ($info['bytes'] ?? 0);
        }

        return $this->rows($argv, $lines);
    }

    private function createDatabase(array $argv, SandboxState $state, string $name): ExecResult
    {
        $databases = $this->databases($state);

        if (isset($databases[$name])) {
            return $this->fail($argv, "ERROR 1007: Can't create database '{$name}'; database exists", 1);
        }

        $databases[$name] = ['bytes' => 0, 'created_at' => time()];
        $state->write('databases', $databases);

        return $this->ok($argv);
    }

    private function dropDatabase(array $argv, SandboxState $state, string $name): ExecResult
    {
        $databases = $this->databases($state);

        if (!isset($databases[$name])) {
            return $this->fail($argv, "ERROR 1008: Can't drop database '{$name}'; database doesn't exist", 1);
        }

        unset($databases[$name]);
        $state->write('databases', $databases);

        return $this->ok($argv);
    }

    private function createUser(array $argv, SandboxState $state, string $user, string $host): ExecResult
    {
        $users = $state->read('dbusers');
        $key = $user . '@' . $host;

        if (isset($users[$key])) {
            return $this->fail($argv, "ERROR 1396: Operation CREATE USER failed for '{$user}'@'{$host}'", 1);
        }

        $users[$key] = ['created_at' => time()];
        $state->write('dbusers', $users);

        return $this->ok($argv);
    }

    private function dropUser(array $argv, SandboxState $state, string $user, string $host): ExecResult
    {
        $users = $state->read('dbusers');
        unset($users[$user . '@' . $host]);
        $state->write('dbusers', $users);

        return $this->ok($argv);
    }

    private function dump(array $argv, SandboxState $state): ExecResult
    {
        $database = '';
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '-')) {
                $database = $arg;
            }
        }

        if (!isset($this->databases($state)[$database])) {
            return $this->fail($argv, "mysqldump: Got error: Unknown database '{$database}'", 2);
        }

        $sql = "-- sandbox: ไฟล์สำรองจำลองของฐานข้อมูล {$database}\n"
            . '-- สร้างเมื่อ ' . date('Y-m-d H:i:s') . "\n"
            . "-- ไม่ใช่ข้อมูลจริง ใช้เพื่อทดสอบขั้นตอนสำรองก่อนลบเท่านั้น\n\n"
            . "CREATE DATABASE IF NOT EXISTS `{$database}`;\nUSE `{$database}`;\n";

        return new ExecResult($argv, 0, $sql, '', 40, simulated: true);
    }

    /** @return array<string,array<string,mixed>> */
    private function databases(SandboxState $state): array
    {
        $databases = $state->read('databases');

        if ($databases === []) {
            // ค่าเริ่มต้นให้ตรงกับข้อมูลตัวอย่างที่ sandbox:seed ใส่ไว้ในฐานข้อมูลของ panel
            $databases = [
                'example_db' => ['bytes' => 248_000_000],
                'shop_db' => ['bytes' => 1_450_000_000],
                'legacy_db' => ['bytes' => 92_000_000],
                'blog_db' => ['bytes' => 34_000_000],
                'demo_db' => ['bytes' => 8_400_000],
            ];
            $state->write('databases', $databases);
        }

        return $databases;
    }

    /** @param list<string> $lines */
    private function rows(array $argv, array $lines): ExecResult
    {
        return new ExecResult($argv, 0, implode("\n", $lines) . "\n", '', 5, simulated: true);
    }

    private function ok(array $argv): ExecResult
    {
        return new ExecResult($argv, 0, '', '', 5, simulated: true);
    }

    private function fail(array $argv, string $message, int $code = 1): ExecResult
    {
        return new ExecResult($argv, $code, '', $message . "\n", 5, simulated: true);
    }
}
