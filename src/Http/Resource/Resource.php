<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * ฐานของตัวแปลงแถวฐานข้อมูล → JSON ที่ฝั่ง SPA ใช้ได้จริง
 *
 * **กฎเหล็กของชั้นนี้: คืนค่าดิบเสมอ ห้ามจัดรูปแบบ**
 *
 * ขนาดไฟล์เป็นไบต์ (ไม่ใช่ "1.2 GB") · เวลาเป็น unix timestamp (ไม่ใช่ "5 นาทีที่แล้ว")
 * เพราะการจัดรูปแบบเป็นเรื่องของภาษาและเขตเวลาของผู้ดู ไม่ใช่ของเซิร์ฟเวอร์ —
 * ถ้าเซิร์ฟเวอร์ส่ง "5 นาทีที่แล้ว" มาแล้ว หน้าจอจะสลับเป็นภาษาอังกฤษไม่ได้เลย
 * และ SPA จะเรียงลำดับหรือคำนวณต่อไม่ได้ (นี่คือสิ่งที่ view เดิมทำ และเป็นเหตุผลหนึ่ง
 * ที่ต้องรื้อในแผนนี้)
 */
abstract class Resource
{
    /** แปลงค่าที่อาจเป็น null ให้เป็น int หรือ null — ห้ามแปลง null เป็น 0 */
    protected static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** SQLite เก็บ boolean เป็น 0/1 — ฝั่ง JSON ต้องได้ true/false จริง ๆ */
    protected static function bool(mixed $value): bool
    {
        return (int) $value === 1;
    }

    protected static function string(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * แปลงทั้งรายการด้วยตัวแปลงตัวเดียวกัน
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function collection(array $rows): array
    {
        return array_map(static fn (array $row): array => static::one($row), array_values($rows));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    abstract public static function one(array $row): array;
}
