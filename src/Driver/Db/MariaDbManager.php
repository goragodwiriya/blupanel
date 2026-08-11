<?php

declare (strict_types = 1);

namespace Phpcp\Driver\Db;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * จัดการ MariaDB/MySQL ผ่านคำสั่ง mysql/mysqldump — ARCHITECTURE §4.3
 *
 * ความปลอดภัยที่สำคัญที่สุดของคลาสนี้: SQL ที่ส่งไปประกอบจาก identifier
 * ที่ผ่าน allowlist regex มาแล้วเท่านั้น และส่งผ่าน stdin ไม่ใช่ผ่าน argv
 *
 * เหตุผลที่ใช้ stdin: argv ของโปรเซสมองเห็นได้จาก /proc/<pid>/cmdline
 * โดยผู้ใช้ทุกคนบนเครื่อง — รหัสผ่านที่ส่งผ่าน argv จึงรั่วให้เว็บไซต์ที่ถูกแฮ็กเห็นได้
 *
 * ตัว identifier ยังถูกครอบด้วย backtick อีกชั้นเผื่อกรณีที่ regex หลุด
 * (ป้องกันสองชั้นเหมือนที่ทำกับ argv array ใน RealExecutor)
 */
final class MariaDbManager
{
    private const CLIENT = '/usr/bin/mariadb';
    private const CLIENT_FALLBACK = '/usr/bin/mysql';
    private const DUMP = '/usr/bin/mariadb-dump';
    private const DUMP_FALLBACK = '/usr/bin/mysqldump';

    /** ชื่อฐานข้อมูลและผู้ใช้ที่ระบบห้ามแตะ — เป็นของ MariaDB เอง */
    private const RESERVED = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * ไฟล์ credential ของผู้ดูแลระบบสำหรับ MariaDB
     *
     * ตรวจสอบ /root/.my.cnf ก่อน แล้วตามด้วย /etc/mysql/debian.cnf หรือ /etc/my.cnf
     * อ่านจากไฟล์แทนการส่งรหัสผ่านทาง argv เพราะ argv ของทุกโปรเซส
     * อ่านได้จาก /proc/<pid>/cmdline โดยผู้ใช้ทุกคนบนเครื่อง
     *
     * คืน null เมื่อไม่พบไฟล์ไหนเลย — ห้ามคืนเส้นทางที่ไม่มีอยู่จริง เพราะ
     * --defaults-file ที่ชี้ไปยังไฟล์ที่ไม่มี ทำให้ client ตายทันทีด้วย
     * "Could not open required defaults file" ทั้งที่ยังต่อได้ตามปกติถ้าไม่ส่ง flag นี้
     *
     * เมื่อคืน null ผู้เรียก (agent ซึ่งรันเป็น root) จะต่อผ่าน unix_socket
     * ซึ่งเป็นค่าเริ่มต้นของ Debian/Ubuntu ที่ root ใช้ได้อยู่แล้วโดยไม่ต้องมีรหัสผ่าน
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
     * flag --defaults-file เมื่อมีไฟล์ credential จริงเท่านั้น
     *
     * @return list<string>
     */
    private function defaultsFlag(Executor $executor): array
    {
        $file = $this->defaultsFile($executor);

        return $file === null ? [] : ['--defaults-file='.$executor->path($file)];
    }

    /** สิทธิ์ที่ให้เลือกได้ แปลงเป็นรายการ GRANT จริงที่นี่ที่เดียว */
    private const PRIVILEGES = [
        'readonly' => 'SELECT, SHOW VIEW',
        'readwrite' => 'SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, SHOW VIEW, EXECUTE',
        'full' => 'ALL PRIVILEGES'
    ];

    public function isInstalled(Executor $executor): bool
    {
        return $this->client($executor) !== null;
    }

    /** ชื่อฐานข้อมูลต้องผ่านทั้ง regex และรายการต้องห้าม */
    public static function assertDatabaseName(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/i', $name) !== 1) {
            throw new ValidationError(
                'ชื่อฐานข้อมูลต้องขึ้นต้นด้วยตัวอักษร ตามด้วยตัวอักษร ตัวเลข หรือ _ ยาว 2-64 ตัว',
            );
        }

        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw new ValidationError("ชื่อ {$name} เป็นฐานข้อมูลของระบบ ใช้ไม่ได้");
        }

        return $name;
    }

    /**
     * @param string $name
     */
    public static function assertUserName(string $name): string
    {
        // MariaDB จำกัดชื่อผู้ใช้ที่ 80 ตัว แต่เราจำกัดเข้มกว่าเพื่อให้เดาง่ายและปลอดภัย
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/i', $name) !== 1) {
            throw new ValidationError(
                'ชื่อผู้ใช้ฐานข้อมูลต้องขึ้นต้นด้วยตัวอักษร ตามด้วยตัวอักษร ตัวเลข หรือ _ ยาว 2-32 ตัว',
            );
        }

        if (in_array(strtolower($name), ['root', 'mysql', 'mariadb.sys', 'debian-sys-maint'], true)) {
            throw new ValidationError("ชื่อ {$name} เป็นผู้ใช้ของระบบ ใช้ไม่ได้");
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
            throw new ValidationError('ระดับสิทธิ์ไม่ถูกต้อง');
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
            'readonly' => 'อ่านอย่างเดียว',
            'readwrite' => 'อ่านและเขียน',
            'full' => 'สิทธิ์เต็ม',
            default => $level,
        };
    }

    /**
     * รัน SQL แล้วคืนผลเป็นแถว — SQL ส่งทาง stdin ไม่ใช่ argv
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

    /** รัน SQL ที่ไม่ต้องการผลลัพธ์ */
    public function execute(Executor $executor, string $sql): void
    {
        $this->run($executor, $sql, ['--batch']);
    }

    /**
     * ขนาดของแต่ละฐานข้อมูลจาก information_schema
     *
     * @return array<string,int> ชื่อฐานข้อมูล => ไบต์
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
     * ผู้ใช้ MariaDB คนนี้มีอยู่บนเครื่องแล้วหรือยัง
     *
     * ตรวจจาก mysql.user โดยตรง ไม่ใช่จากตารางของ panel — สองอย่างนี้ไม่ตรงกันได้
     * (เช่นกู้คืน panel.db เก่ามาแต่ MariaDB ยังเป็นของปัจจุบัน)
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

        // รหัสผ่านเป็นค่าเดียวที่ไม่ใช่ identifier — ต้อง escape แบบสตริง
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
     * บัญชีนี้ถือสิทธิ์ระดับทั้งเซิร์ฟเวอร์ (`ON *.*`) อยู่หรือไม่
     *
     * อ่านจาก `SHOW GRANTS` ไม่ใช่จากตารางของ panel — สิทธิ์จริงอยู่ที่ MariaDB
     * และอาจถูกแก้ด้วยมือนอก panel ได้
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
     * ให้หรือถอนสิทธิ์ระดับทั้งเซิร์ฟเวอร์
     *
     * **ใช้กับบัญชีของผู้ดูแลระบบเท่านั้น** — เป็นสิทธิ์ที่เทียบเท่า root ของ MariaDB
     * ในทางปฏิบัติ · เหตุผลที่ยอมให้มีคือผู้ดูแลต้องจัดการฐานข้อมูลทั้งเครื่องผ่าน
     * phpMyAdmin ได้ โดยไม่ต้องไปตั้งรหัสให้บัญชี root ซึ่งปัจจุบันใช้ `unix_socket`
     * และแตะได้เฉพาะ root ของระบบปฏิบัติการเท่านั้น — การตั้งรหัสให้ root จะทำให้
     * บัญชีที่มีอำนาจสูงสุดกลายเป็นเป้าที่เดารหัสได้ ซึ่งแย่กว่าวิธีนี้มาก
     *
     * `WITH GRANT OPTION` รวมอยู่ด้วยเพราะไม่มีมันแล้วหน้าจัดการผู้ใช้ของ phpMyAdmin
     * จะทำงานได้ครึ่งเดียว (สร้างผู้ใช้ได้แต่ให้สิทธิ์ไม่ได้) ซึ่งสับสนกว่าไม่ให้เลย
     */
    public function setGlobalPrivileges(Executor $executor, string $user, string $host, bool $granted): void
    {
        self::assertUserName($user);
        self::assertHost($host);

        $current = $this->hasGlobalPrivileges($executor, $user, $host);

        // ไม่สั่งอะไรเมื่อสถานะตรงอยู่แล้ว — REVOKE ที่ไม่มีอะไรให้ถอนจะล้มด้วย error 1141
        // และการกลืน error ทิ้งจะทำให้ความล้มเหลวจริงหายไปด้วย
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

    /** สำรองฐานข้อมูลเป็นไฟล์ SQL คืนขนาดไฟล์ */
    public function dump(Executor $executor, string $database, string $target): int
    {
        self::assertDatabaseName($database);

        $binary = $executor->exists(self::DUMP) ? self::DUMP : self::DUMP_FALLBACK;

        if (!$executor->exists($binary)) {
            throw new ExecutionFailed('ไม่พบคำสั่งสำหรับสำรองฐานข้อมูลบนเครื่องนี้');
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
            throw new ExecutionFailed('สำรองฐานข้อมูลไม่สำเร็จ: '.trim($result->stderr));
        }

        $executor->writeFile($executor->path($target), $result->stdout, 0600);

        return strlen($result->stdout);
    }

    /**
     * รันคำสั่ง client โดยส่ง SQL ทาง stdin
     *
     * @param list<string> $flags
     */
    private function run(Executor $executor, string $sql, array $flags): \Phpcp\Agent\Executor\ExecResult
    {
        $client = $this->client($executor);

        if ($client === null) {
            throw new ExecutionFailed('ไม่พบ MariaDB client บนเครื่องนี้');
        }

        // อ่านบัญชีผู้ดูแลจากไฟล์ของระบบ ไม่ส่งรหัสผ่านผ่าน argv
        // ซึ่งผู้ใช้อื่นบนเครื่องอ่านได้จาก /proc/<pid>/cmdline
        // ต้องแมปเส้นทางไฟล์ credential ผ่าน executor เสมอ
        //
        // ถ้าส่งเส้นทางดิบไป โหมด sandbox จะอ่าน /etc/mysql/debian.cnf ของจริง
        // แล้วไปสร้าง/ลบฐานข้อมูลบน MariaDB จริงของเครื่อง ซึ่งผิดสัญญาหลักของโหมดนั้น
        $argv = [$client, ...$this->defaultsFlag($executor), ...$flags];

        $result = $executor->exec($argv, timeout: 120, stdin: $sql);

        if (!$result->ok()) {
            throw new ExecutionFailed('คำสั่งฐานข้อมูลล้มเหลว: '.trim($result->stderr));
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
            throw new ValidationError('ชุดอักขระไม่ถูกต้อง');
        }

        return $charset;
    }

    /**
     * @param string $host
     * @return mixed
     */
    private static function assertHost(string $host): string
    {
        // จำกัดให้แคบกว่าที่ MariaDB ยอมรับ — โฮสต์ที่ใช้จริงมีไม่กี่แบบ
        if (!in_array($host, ['localhost', '127.0.0.1', '%'], true)) {
            throw new ValidationError('โฮสต์ของผู้ใช้ฐานข้อมูลต้องเป็น localhost, 127.0.0.1 หรือ %');
        }

        return $host;
    }

    /** escape สตริงสำหรับใส่ในเครื่องหมายคำพูดเดี่ยวของ SQL */
    private static function escapeString(string $value): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        );
    }
}
