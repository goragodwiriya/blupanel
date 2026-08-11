<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;

/**
 * งานอัตโนมัติต่อเว็บไซต์ — ตาราง cron_jobs
 *
 * ฐานข้อมูลคือความจริงหนึ่งเดียว ไฟล์ `/etc/cron.d` เป็นผลลัพธ์ที่ generate ตามทีหลัง
 * (แบบเดียวกับ vhost) · ทุกการแก้ไขจึงต้องจบด้วยการเขียนไฟล์ใหม่เสมอ
 *
 * **ส่วนที่สำคัญที่สุดของคลาสนี้คือ `applyThenSync()`** — ถ้าเขียนไฟล์ไม่สำเร็จ
 * ต้องย้อนการแก้ไขในฐานข้อมูลกลับ ไม่งั้นหน้าจอจะบอกว่างานทำงานอยู่ทั้งที่ cron
 * ไม่รู้จักมันเลย ซึ่งเป็นความเข้าใจผิดที่หาสาเหตุยากมาก · ตรรกะนี้เคยอยู่ใน
 * controller ของหน้าเว็บอย่างเดียว การย้ายมาที่นี่ทำให้ REST API ได้การรับประกันเดียวกัน
 * โดยไม่ต้องเขียนซ้ำ (ซึ่งแปลว่าจะมีวันที่ทางหนึ่งลืมย้อนกลับ)
 */
final class CronJobRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM cron_jobs WHERE id = :id', ['id' => $id]);
    }

    /**
     * รายการงานพร้อมข้อมูลเว็บไซต์ — กรองตามเจ้าของที่ระดับ query
     *
     * @return list<array<string,mixed>>
     */
    public function listWithSite(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        return $this->db->all(
            'SELECT c.*, s.primary_domain, u.system_user
             FROM cron_jobs c
             JOIN sites s ON s.id = c.site_id
             JOIN users u ON u.id = s.owner_user_id' . $where . '
             ORDER BY s.primary_domain, c.name',
            $params,
        );
    }

    /**
     * ตรวจค่าที่ผู้ใช้ส่งมา — กฎเดียวกันทั้งหน้าเว็บและ API
     *
     * @param array<string,mixed> $input
     * @return array{name:string,schedule:string,command:string,enabled:int}
     */
    public static function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $command = trim((string) ($input['command'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationError('ชื่องานต้องยาว 1-100 ตัวอักษร');
        }

        if ($command === '') {
            throw new ValidationError('ต้องระบุคำสั่งที่จะให้ทำงาน');
        }

        return [
            'name' => $name,
            // โยน ValidationError เองถ้ารูปแบบผิด — cron ที่รูปแบบพังจะถูกข้ามทั้งไฟล์เงียบ ๆ
            'schedule' => CronSchedule::normalize((string) ($input['schedule'] ?? '')),
            'command' => $command,
            'enabled' => ($input['enabled'] ?? true) ? 1 : 0,
        ];
    }

    /** @param array{name:string,schedule:string,command:string,enabled:int} $data */
    public function create(int $siteId, array $data): int
    {
        return $this->db->insert('cron_jobs', $data + ['site_id' => $siteId, 'created_at' => time()]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('cron_jobs', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM cron_jobs WHERE id = :id', ['id' => $id]);
    }

    /**
     * แก้ไขแล้วเขียนไฟล์ cron ใหม่ — ล้มเมื่อไหร่ย้อนกลับทันที
     *
     * `$undo` ได้รับผลลัพธ์ของ `$change` เป็นอาร์กิวเมนต์ เพราะการย้อน "การสร้าง"
     * ต้องรู้ id ของแถวที่เพิ่งเกิดขึ้น ซึ่งยังไม่มีตอนที่ผู้เรียกประกอบ closure
     *
     * @param callable():mixed      $change ทำการแก้ไขในฐานข้อมูล คืนค่าที่จะส่งกลับให้ผู้เรียก
     * @param callable():mixed      $sync   สั่ง cron.sync ผ่าน agent (โยน exception เมื่อล้ม)
     * @param callable(mixed):mixed $undo   ย้อนสิ่งที่ $change ทำไว้
     */
    public function applyThenSync(callable $change, callable $sync, callable $undo): mixed
    {
        $result = $change();

        try {
            $sync();
        } catch (\Throwable $e) {
            $undo($result);

            throw $e;
        }

        return $result;
    }

    /**
     * สำเนาค่าที่ต้องย้อนกลับได้ของงานหนึ่ง
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function restorable(array $job): array
    {
        return [
            'name' => $job['name'],
            'schedule' => $job['schedule'],
            'command' => $job['command'],
            'enabled' => $job['enabled'],
        ];
    }
}
