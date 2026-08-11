<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Security\Secret;

/**
 * ปลายทางของไฟล์สำรอง — ตาราง `backup_destinations` (PLAN-V2 เฟส E1)
 *
 * **ความลับไม่เคยออกจากคลาสนี้ในรูปที่อ่านได้** ยกเว้นทาง `secretFor()` ซึ่งมีไว้ให้
 * ตัวสร้าง driver เรียกจุดเดียว · `all()` และ `find()` คืนแถวที่ตัดคอลัมน์ `secret_enc`
 * ออกแล้วเสมอ เพื่อให้การเผลอส่งทั้งแถวออก API กลายเป็นเรื่องที่ทำไม่ได้ ไม่ใช่เรื่องที่
 * ต้องระวังทุกครั้ง — รูปแบบเดียวกับที่ `DbAccountRepository` ใช้กับรหัสผ่าน MariaDB
 */
final class BackupDestinationRepository
{
    public const DRIVERS = ['local', 'sftp', 'rsync', 's3'];

    public function __construct(
        private readonly Db $db,
        private readonly Secret $secret,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(
            $this->present(...),
            $this->db->all('SELECT * FROM backup_destinations ORDER BY name'),
        );
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return array_map(
            $this->present(...),
            $this->db->all('SELECT * FROM backup_destinations WHERE enabled = 1 ORDER BY name'),
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM backup_destinations WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /**
     * ความลับที่ถอดรหัสแล้ว — เรียกได้จากตัวสร้าง driver เท่านั้น
     *
     * แยกเป็นเมธอดของตัวเองแทนที่จะให้มากับแถว เพราะทำให้ **ค้น repo ได้ว่าใครแตะ
     * ความลับบ้าง** ด้วยการค้นชื่อเมธอดเดียว ซึ่งเป็นสิ่งที่ต้องตรวจได้ตอนรีวิว
     */
    public function secretFor(int $id): string
    {
        $encrypted = $this->db->value(
            'SELECT secret_enc FROM backup_destinations WHERE id = :id',
            ['id' => $id],
        );

        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        return $this->secret->decrypt((string) $encrypted);
    }

    /**
     * @param array<string,mixed> $config
     */
    public function create(string $name, string $driver, array $config, string $secret, int $retentionDays, int $retentionCount): int
    {
        $this->assertDriver($driver);

        if (trim($name) === '') {
            throw new ValidationError('ต้องตั้งชื่อปลายทาง');
        }

        if ($this->db->value('SELECT id FROM backup_destinations WHERE name = :n', ['n' => $name]) !== null) {
            throw new ValidationError('มีปลายทางชื่อนี้อยู่แล้ว');
        }

        $now = time();

        return $this->db->insert('backup_destinations', [
            'name' => trim($name),
            'driver' => $driver,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_enc' => $secret === '' ? null : $this->secret->encrypt($secret),
            'retention_days' => max(0, $retentionDays),
            'retention_count' => max(0, $retentionCount),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $changes  ยอมเฉพาะคีย์ที่รู้จัก
     */
    public function update(int $id, array $changes): void
    {
        $fields = ['updated_at' => time()];

        foreach (['name', 'retention_days', 'retention_count', 'enabled'] as $key) {
            if (array_key_exists($key, $changes)) {
                $fields[$key] = $changes[$key];
            }
        }

        if (array_key_exists('config', $changes) && is_array($changes['config'])) {
            $fields['config_json'] = json_encode($changes['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // ส่งความลับว่างมา = ไม่เปลี่ยน ไม่ใช่ล้างทิ้ง · หน้าจอแก้ไขจะส่งฟอร์มทั้งชุด
        // กลับมาโดยที่ช่องความลับว่างเสมอ (เพราะเราไม่เคยส่งค่าเดิมออกไป) ถ้าตีความว่า
        // "ล้าง" ผู้ดูแลจะทำปลายทางพังทุกครั้งที่แก้แค่ชื่อ
        if (isset($changes['secret']) && is_string($changes['secret']) && $changes['secret'] !== '') {
            $fields['secret_enc'] = $this->secret->encrypt($changes['secret']);
        }

        $this->db->update('backup_destinations', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM backup_destinations WHERE id = :id', ['id' => $id]);
    }

    /** บันทึกผลการติดต่อครั้งล่าสุด — ปลายทางที่ล้มเงียบอันตรายพอ ๆ กับไม่มีปลายทาง */
    public function recordResult(int $id, bool $ok, string $error = ''): void
    {
        $this->db->update('backup_destinations', [
            'last_ok_at' => $ok ? time() : null,
            'last_error' => $ok ? null : mb_substr($error, 0, 500),
            'updated_at' => time(),
        ], ['id' => $id]);
    }

    public function assertDriver(string $driver): string
    {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new ValidationError('ชนิดปลายทางไม่ถูกต้อง — ใช้ได้: ' . implode(', ', self::DRIVERS));
        }

        return $driver;
    }

    /**
     * แถวที่ปลอดภัยต่อการส่งออก — ไม่มีความลับติดไปด้วยไม่ว่าจะเผลอแค่ไหน
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        // บอกว่ามีความลับเก็บไว้หรือยัง โดยไม่บอกว่าคืออะไร — หน้าจอต้องแยก
        // "ยังไม่ได้ใส่กุญแจ" ออกจาก "ใส่แล้วแต่ไม่แสดง" ให้ผู้ดูแลเห็น
        $row['has_secret'] = ($row['secret_enc'] ?? null) !== null && $row['secret_enc'] !== '';
        unset($row['secret_enc']);

        $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $row['config'] = is_array($config) ? $config : [];
        unset($row['config_json']);

        return $row;
    }
}
