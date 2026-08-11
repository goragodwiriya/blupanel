<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use PDO;
use PDOStatement;

/**
 * SQLite ของ panel — ตัดสินใจ D4: ไม่พึ่ง MariaDB เพราะ panel เป็นคนคุม MariaDB
 *
 * ตั้ง WAL เพื่อให้อ่านขณะเขียนได้ (SSE ที่ poll อยู่ต้องไม่ถูกบล็อกโดยการเขียน audit)
 */
final class Db
{
    private PDO $pdo;

    /** @var array<string,PDOStatement> แคช prepared statement ต่อ process */
    private array $statements = [];

    private bool $walAvailable = true;

    /**
     * true ระหว่างที่ transaction() ของเราเปิดอยู่
     *
     * ต้องจำเอง เพราะ `PDO::inTransaction()` ไม่รู้จัก transaction ที่เปิดด้วย
     * `exec('BEGIN IMMEDIATE')` — PDO เห็นแค่คำสั่งที่มันเปิดให้เองผ่าน beginTransaction()
     */
    private bool $inTransaction = false;

    public function __construct(private readonly string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("สร้างไดเรกทอรีฐานข้อมูลไม่สำเร็จ: {$dir}");
        }

        $isNew = !is_file($file);

        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // ไฟล์ฐานข้อมูลมี hash รหัสผ่านและ session — ห้าม world-readable
        if ($isNew) {
            @chmod($file, 0600);
        }

        // ลำดับสำคัญ: busy_timeout ต้องมาก่อนทุกอย่าง
        //
        // การเปลี่ยน journal_mode ต้องใช้ล็อกระดับ exclusive ถ้ายังไม่ได้ตั้ง busy_timeout
        // การเชื่อมต่อที่เข้ามาพร้อมกัน (เช่น SSE ที่ค้างอยู่ + worker หลายตัว) จะล้มทันที
        // ด้วย "database is locked" แทนที่จะรอคิว
        // 15 วินาที ไม่ใช่ 5 — ระบบมีผู้เขียนพร้อมกันหลายโปรเซส (เว็บ + agent ที่ fork ลูก
        // ต่อคำขอ + CLI) และบน filesystem ที่ล็อกไม่เต็มรูปแบบอย่าง FUSE/NFS
        // การรอนานขึ้นดีกว่าล้มกลางคันแล้วทิ้งงานค้างไว้ครึ่งทาง
        $this->pdo->exec('PRAGMA busy_timeout = 15000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->enableWal();

        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    /**
     * เปิดโหมด WAL ถ้าทำได้ — ให้อ่านขณะมีการเขียนได้โดยไม่บล็อกกัน
     *
     * ตรวจก่อนว่าอยู่ในโหมดนี้แล้วหรือยัง เพราะการสั่งซ้ำทุกการเชื่อมต่อ
     * ต้องขอล็อกที่แรงกว่าเดิมโดยไม่จำเป็น
     *
     * WAL ต้องใช้ shared memory ซึ่ง filesystem บางชนิด (FUSE, NFS, NTFS ที่ mount ผ่าน FUSE)
     * รองรับไม่ครบ กรณีนั้นให้ถอยไปใช้โหมดปกติแทนที่จะทำให้ทั้งระบบใช้งานไม่ได้
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
            // ใช้โหมดเริ่มต้นต่อไป — ช้ากว่าแต่ยังทำงานได้ถูกต้อง
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

        // ต้องปิด cursor เอง — ดูเหตุผลเต็มใน value()
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
     * ค่าเดียวจากคอลัมน์แรกของแถวแรก
     *
     * ต้องปิด cursor ทุกครั้ง ไม่ใช่ปล่อยให้ statement ค้างไว้ — เคยเป็นบั๊กจริง:
     * `fetch()`/`fetchColumn()` ที่ยังไม่ step จนจบทิ้ง read transaction ไว้บนการเชื่อมต่อนี้
     * พอมีอีกโปรเซสถือ write lock อยู่ (agent เขียน audit log ในลูกที่ fork ออกไป)
     * การเขียนครั้งถัดไปของเราจะได้ SQLITE_BUSY กลับมา**ทันที** โดยไม่รอตาม busy_timeout เลย
     * เพราะ SQLite ถือว่าเป็น deadlock ของการยกระดับล็อก ไม่ใช่การรอคิวธรรมดา
     *
     * อาการที่เห็นคือ "database is locked" หรือ "bad parameter or other API misuse"
     * แบบสุ่ม ๆ เฉพาะตอนที่สองโปรเซสทำงานพร้อมกัน ซึ่งตามหาต้นตอยากมาก
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
     * ห่อการทำงานไว้ใน transaction แบบ **IMMEDIATE** เสมอ
     *
     * **ทำไมต้อง IMMEDIATE ไม่ใช่ `beginTransaction()` ธรรมดา:** PDO เริ่มด้วย
     * `BEGIN DEFERRED` ซึ่งยังไม่จองล็อกอะไรเลย พอคำสั่งแรกในบล็อกเป็นการ**อ่าน**
     * การเชื่อมต่อจะได้ล็อกอ่าน แล้วเมื่อจะ**เขียน**ต้องยกระดับล็อก — ถ้าตอนนั้นมี
     * การเชื่อมต่ออื่นถือล็อกเขียนอยู่ SQLite จะคืน `SQLITE_BUSY` **ทันที** โดย
     * **ไม่รอตาม `busy_timeout` เลย** เพราะการรอในสถานะนั้นคือ deadlock
     *
     * `BEGIN IMMEDIATE` จองล็อกเขียนตั้งแต่ต้น การรอจึงเกิดที่จุดเดียวที่รอได้
     * และ `busy_timeout` ทำงานตามที่ตั้งไว้จริง
     *
     * **อาการก่อนแก้:** `RateLimiter::allow()` และ `AuditLog::write()` ใช้ transaction
     * และทำงานทุกคำขอ ทั้งคู่อ่านก่อนเขียน · ยิงพร้อมกัน 8 คำขอแล้วล้ม 7 ด้วย
     * "database is locked" ทั้งบน ext4 และ FUSE · UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า
     * จึงไม่มีใครเจอ แต่ SPA ยิงหลายก้อนพร้อมกันต่อหน้า อาการจึงโผล่ทันที
     *
     * รองรับการซ้อน: บล็อกชั้นในใช้ transaction ของชั้นนอก ไม่เปิดใหม่
     * (SQLite ไม่มี nested transaction จริง และ savepoint ไม่จำเป็นสำหรับที่นี่)
     */
    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction) {
            return $work($this);
        }

        // ใช้ exec ตรง ๆ เพราะ PDO ไม่มีทางระบุชนิดของ transaction ให้ SQLite
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
                // transaction ถูกยกเลิกไปแล้วโดย SQLite เอง — ไม่มีอะไรต้องทำต่อ
                // และห้ามให้ข้อผิดพลาดตรงนี้บังข้อผิดพลาดจริงที่กำลังจะโยนต่อ
            }

            throw $e;
        }
    }

    /**
     * รัน migration ที่ยังไม่เคยรัน เรียงตามชื่อไฟล์
     *
     * @return list<string> รายชื่อไฟล์ที่เพิ่งรันไป
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
                throw new \RuntimeException("อ่านไฟล์ migration ไม่ได้: {$file}");
            }

            $this->runMigration($version, $sql);

            $ran[] = $version;
        }

        return $ran;
    }

    /**
     * ทำเครื่องหมายว่า migration นี้ต้องสร้างตารางใหม่ทั้งตาราง
     *
     * ใส่เป็นบรรทัดใดก็ได้ในไฟล์ .sql
     */
    private const REBUILD_DIRECTIVE = '-- phpcp:rebuild-tables';

    /**
     * รัน migration หนึ่งไฟล์
     *
     * ปกติห่อด้วย transaction เฉย ๆ · แต่ migration ที่ต้อง**สร้างตารางใหม่ทั้งตาราง**
     * (เช่นเอาข้อจำกัด UNIQUE ที่ประกาศไว้ตอน CREATE ออก ซึ่ง `ALTER TABLE DROP COLUMN`
     * ทำไม่ได้) ต้องปิด foreign_keys ก่อน ไม่งั้น `DROP TABLE` ของตารางแม่จะไป
     * ยิง `ON DELETE CASCADE`/`SET NULL` ของตารางลูกจนข้อมูลหายจริง — และ SQLite
     * ห้ามสั่ง `PRAGMA foreign_keys` กลาง transaction จึงต้องสั่งจากตรงนี้แทน
     *
     * ขั้นตอนนี้เป็นวิธีมาตรฐานของ SQLite เอง (คู่มือหัวข้อ "Making Other Kinds Of
     * Table Schema Changes") · ความเป็นอะตอมยังอยู่ครบเพราะ transaction ยังห่ออยู่
     * ข้างใน และ `foreign_key_check` ที่รันก่อน commit จะจับความสัมพันธ์ที่ขาด
     * ระหว่างทางได้ทั้งหมด
     */
    private function runMigration(string $version, string $sql): void
    {
        $rebuild = str_contains($sql, self::REBUILD_DIRECTIVE);

        if ($rebuild) {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }

        // SQLite รันหลายคำสั่งใน exec เดียวได้ แต่ห่อ transaction เองเพื่อให้ล้มแล้วไม่ค้างครึ่งทาง
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec($sql);

            if ($rebuild) {
                $broken = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);

                if ($broken !== []) {
                    throw new \RuntimeException(
                        'สร้างตารางใหม่แล้วเหลือความสัมพันธ์ที่ชี้ไปยังแถวที่ไม่มีอยู่ '
                        .count($broken).' จุด',
                    );
                }
            }

            $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, time()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw new \RuntimeException("migration ล้มเหลวที่ {$version}: ".$e->getMessage(), 0, $e);
        } finally {
            // ต้องเปิดคืนเสมอ แม้ migration จะล้ม ไม่งั้นทั้ง process จะทำงานต่อโดยไม่มี
            // foreign key คุ้มกันเลย ซึ่งอันตรายกว่าตัว migration ที่ล้มเสียอีก
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
