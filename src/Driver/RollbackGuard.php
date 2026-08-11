<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Kernel\Db;

/**
 * ยืนยันภายในเวลา ไม่อย่างนั้นคืนค่าเดิม — ARCHITECTURE §5.4
 *
 * ใช้กับการเปลี่ยนค่าที่อาจตัดการเชื่อมต่อของตัวเอง: พอร์ต SSH, ปิด password auth,
 * กฎ firewall, การเปิด firewall ครั้งแรก
 *
 * ลำดับ:
 *   1. สำรอง config เดิม + บันทึกกำหนดเวลาคืนค่า
 *   2. เขียนค่าใหม่ + apply
 *   3. ผู้ใช้กดยืนยันภายในเวลา → ลบรายการรอไว้ ถือว่าถาวร
 *   4. ไม่กดทัน → รายการหมดอายุ ระบบคืนค่าเดิมให้อัตโนมัติ
 *
 * ตัวจับเวลาต้องอยู่ฝั่งเซิร์ฟเวอร์ ไม่ใช่ในเบราว์เซอร์ เพราะกรณีที่ต้องกู้
 * คือกรณีที่ผู้ใช้ "หลุดการเชื่อมต่อไปแล้ว" — เบราว์เซอร์จึงไม่มีโอกาสทำงาน
 *
 * การคืนค่าจริงถูกกระตุ้นสองทาง: เมื่อมีคำขอใด ๆ เข้ามาแล้วพบว่ามีรายการหมดอายุ
 * และเมื่อ `phpcp rollback:run` ถูกเรียกจาก cron ของ panel — ทางที่สองจำเป็น
 * เพราะถ้าผู้ใช้หลุดจริง จะไม่มีคำขอเข้ามาให้กระตุ้นเลย
 */
final class RollbackGuard
{
    /** เวลาเริ่มต้นที่ให้ยืนยัน — พอสำหรับเปิดหน้าต่างใหม่แล้วลองเชื่อมต่อ */
    public const DEFAULT_WINDOW = 120;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * บันทึกรายการรอยืนยัน คืน id ที่ใช้ยืนยันหรือยกเลิก
     *
     * @param array<string,string>        $files เส้นทางไฟล์ => เนื้อหาเดิม (null-safe ผ่าน '')
     * @param list<string>                $reloadUnits บริการที่ต้อง reload ตอนคืนค่า
     * @param list<array<string,mixed>>   $undo คำสั่งย้อนกลับแบบมีชนิด (ดู applyUndo)
     */
    public function arm(
        string $action,
        string $description,
        array $files,
        array $reloadUnits,
        int $window = self::DEFAULT_WINDOW,
        int $actorId = 0,
        array $undo = [],
    ): int {
        $window = max(30, min($window, 900));

        return $this->db->insert('pending_rollbacks', [
            'action' => $action,
            'description' => $description,
            'payload_json' => json_encode(
                ['files' => $files, 'units' => $reloadUnits, 'undo' => $undo],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'actor_user_id' => $actorId > 0 ? $actorId : null,
            'created_at' => time(),
            'expires_at' => time() + $window,
        ]);
    }

    /** ผู้ใช้ยืนยันว่ายังเชื่อมต่อได้ — ยกเลิกการคืนค่า */
    public function confirm(int $id): array
    {
        $row = $this->db->first('SELECT * FROM pending_rollbacks WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            throw new ValidationError('ไม่พบรายการที่รอยืนยัน — อาจถูกคืนค่าไปแล้วเพราะหมดเวลา');
        }

        if ((int) $row['expires_at'] <= time()) {
            throw new ValidationError('หมดเวลายืนยันแล้ว ระบบกำลังคืนค่าเดิมให้');
        }

        $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => $id]);

        return $row;
    }

    /** @return array<string,mixed>|null รายการที่ยังรอยืนยันอยู่ */
    public function pending(): ?array
    {
        return $this->db->first(
            'SELECT * FROM pending_rollbacks WHERE expires_at > :now ORDER BY id DESC LIMIT 1',
            ['now' => time()],
        );
    }

    /** @return list<array<string,mixed>> รายการที่หมดเวลาแล้วและต้องคืนค่า */
    public function expired(): array
    {
        return $this->db->all(
            'SELECT * FROM pending_rollbacks WHERE expires_at <= :now ORDER BY id',
            ['now' => time()],
        );
    }

    /**
     * คืนค่าทุกรายการที่หมดเวลา
     *
     * @return list<array{id:int,action:string,files:int,undo:int}>
     */
    public function rollbackExpired(Executor $executor): array
    {
        $done = [];

        foreach ($this->expired() as $row) {
            $payload = json_decode((string) $row['payload_json'], true);

            if (!is_array($payload)) {
                $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => (int) $row['id']]);
                continue;
            }

            $restored = 0;
            $undone = 0;

            foreach (($payload['files'] ?? []) as $path => $original) {
                $resolved = $executor->path((string) $path);

                if ($original === null || $original === false) {
                    // เดิมไม่มีไฟล์นี้ — ลบทิ้งให้กลับไปเหมือนเดิม
                    if ($executor->exists($resolved)) {
                        $executor->removePath($resolved);
                    }
                } else {
                    $executor->writeFile($resolved, (string) $original, 0644);
                }

                $restored++;
            }

            foreach (($payload['undo'] ?? []) as $operation) {
                if (!is_array($operation)) {
                    continue;
                }

                // ล้มก็ต้องไปต่อ — งานคืนค่าที่เหลือสำคัญกว่าการหยุดที่ข้อผิดพลาดแรก
                try {
                    $this->applyUndo($executor, $operation);
                    $undone++;
                } catch (\Throwable) {
                }
            }

            foreach (($payload['units'] ?? []) as $unit) {
                // ล้มก็ต้องไปต่อ — คืนไฟล์ได้แล้วสำคัญกว่าการ reload สำเร็จ
                $executor->exec(
                    [$executor->path('/usr/bin/systemctl'), 'reload-or-restart', (string) $unit],
                    timeout: 30,
                );
            }

            $this->db->run('DELETE FROM pending_rollbacks WHERE id = :id', ['id' => (int) $row['id']]);

            $done[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'files' => $restored,
                'undo' => $undone,
            ];
        }

        return $done;
    }

    /**
     * ทำคำสั่งย้อนกลับที่ไม่ใช่ไฟล์ — ปัจจุบันมีแค่ firewall
     *
     * บางการเปลี่ยนแปลงไม่ได้อยู่ในไฟล์ที่คัดลอกกลับได้ง่าย ๆ กฎ firewall เป็นตัวอย่างชัดเจน:
     * ufw เก็บสถานะไว้หลายไฟล์และมีกฎ iptables ที่โหลดอยู่ในเคอร์เนลด้วย
     * การย้อนกลับจึงต้องสั่งผ่าน ufw เอง ไม่ใช่เขียนไฟล์ทับ
     *
     * ค่าที่อ่านจากฐานข้อมูลถูกตรวจซ้ำด้วย validator ชุดเดียวกับตอนสร้าง —
     * ถึงมีใครแก้แถวในฐานข้อมูลได้ ก็ยังฉีดคำสั่งผ่านทางนี้ไม่ได้
     *
     * @param array<string,mixed> $operation
     */
    private function applyUndo(Executor $executor, array $operation): void
    {
        $ufw = new UfwDriver();
        $type = (string) ($operation['type'] ?? '');

        switch ($type) {
            case 'ufw.enable':
                $ufw->enable($executor);
                break;

            case 'ufw.disable':
                $ufw->disable($executor);
                break;

            case 'ufw.rule_add':
                $ufw->rule(
                    $executor,
                    (string) ($operation['action'] ?? 'allow'),
                    (string) ($operation['port'] ?? ''),
                    (string) ($operation['protocol'] ?? 'tcp'),
                    (string) ($operation['source'] ?? ''),
                    (string) ($operation['comment'] ?? ''),
                );
                break;

            case 'ufw.rule_remove':
                $ufw->removeRule(
                    $executor,
                    (string) ($operation['action'] ?? 'allow'),
                    (string) ($operation['port'] ?? ''),
                    (string) ($operation['protocol'] ?? 'tcp'),
                    (string) ($operation['source'] ?? ''),
                );
                break;

            default:
                throw new ValidationError("ไม่รู้จักคำสั่งย้อนกลับชนิด {$type}");
        }
    }

    /** เหลือเวลายืนยันอีกกี่วินาที */
    public static function remaining(array $row): int
    {
        return max(0, (int) $row['expires_at'] - time());
    }
}
