<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Domain\CronSchedule;

/**
 * งานอัตโนมัติหนึ่งงาน
 *
 * ส่ง `schedule_label` มาด้วยเพื่อให้หน้าจอไม่ต้องแปล cron expression เอง — แต่ยังส่ง
 * `schedule` ดิบมาคู่กันเสมอ เพราะฟอร์มแก้ไขต้องใช้ค่าดิบ และการเรียงลำดับ/เทียบค่า
 * ต้องทำจากค่าดิบเท่านั้น (นี่คือทางสายกลางระหว่าง "ส่งแต่ค่าดิบ" กับ "ส่งแต่ข้อความ")
 */
final class CronJobResource extends Resource
{
    /**
     * โครงเปล่าสำหรับฟอร์ม "สร้างใหม่"
     *
     * ฟอร์มเดียวใช้ทั้งสร้างและแก้ไข จึงต้องได้ข้อมูลรูปเดียวกันเสมอ — ถ้าตอนสร้าง
     * ได้ object ว่าง `data-attr="value:name"` จะไม่มีอะไรให้ผูกแล้วเทมเพลตต้องเขียน
     * เผื่อกรณีไม่มีคีย์ · `id = 0` คือสิ่งที่บอกทั้งฟอร์มและเซิร์ฟเวอร์ว่านี่ของใหม่
     *
     * @return array<string,mixed>
     */
    public static function blank(): array
    {
        return self::one([]);
    }

    public static function one(array $row): array
    {
        $schedule = self::string($row['schedule'] ?? '');
        $id = (int) ($row['id'] ?? 0);

        $job = [
            'id' => $id,
            // ซ้ำกับ id โดยตั้งใจ — site.html เองมี ?id= (id ของเว็บไซต์) ใน URL
            // การใส่ {id} ใน data-row-actions ของตาราง siteCronJobs จะถูก
            // RouterManager.render (js/ui.js) แทนค่าด้วย id ของเว็บไซต์ก่อนที่
            // TableManager จะได้เห็นเทมเพลตด้วยซ้ำ — ปุ่ม "จัดการ" ทุกแถวจะพาไปที่
            // งาน cron เดียวกันหมด (ปัญหาเดียวกับ DomainResource::row_id)
            'row_id' => $id,
            'site_id' => (int) ($row['site_id'] ?? 0),
            'name' => self::string($row['name'] ?? ''),
            'schedule' => $schedule,
            'schedule_label' => $schedule === '' ? '' : CronSchedule::describe($schedule),
            'command' => self::string($row['command'] ?? ''),
            'enabled' => self::bool($row['enabled'] ?? 0),
            'last_run_at' => self::intOrNull($row['last_run_at'] ?? null),
            // 0 = สำเร็จ · ค่าอื่น = คำสั่งล้มเหลว · null = ยังไม่เคยรัน
            'last_exit_code' => self::intOrNull($row['last_exit_code'] ?? null),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];

        // มาจาก JOIN ตอนดึงเป็นรายการ
        if (array_key_exists('primary_domain', $row)) {
            $job['site_domain'] = self::string($row['primary_domain']);
        }

        if (array_key_exists('system_user', $row)) {
            $job['system_user'] = self::string($row['system_user']);
        }

        return $job;
    }
}
