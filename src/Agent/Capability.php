<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Agent\Executor\Executor;

/**
 * หน่วยงานเดียวที่ agent ยอมทำ — ARCHITECTURE §4.2
 *
 * กฎที่ห้ามละเมิดเมื่อเขียน capability ใหม่:
 *   1. ห้ามรับ "คำสั่ง" จากผู้ใช้ รับได้เฉพาะ "ข้อมูล" ที่ validate ได้ทีละฟิลด์
 *   2. validate() ต้องคืน array ที่สะอาดแล้ว — run() ห้ามแตะ $args ดิบ
 *   3. ทุก path/unit/user ที่รับเข้ามาต้องผ่าน SelfProtection
 *   4. ค่าที่เอาไปประกอบ argv ต้องมาจาก allowlist หรือผ่าน regex ที่เข้มกว่าที่คิดว่าจำเป็น
 *   5. ห้ามเรียก exec/proc_open/file_put_contents เอง ต้องผ่าน Executor เท่านั้น
 */
interface Capability
{
    /** ชื่อที่ใช้เรียกผ่าน protocol เช่น "service.restart" */
    public static function name(): string;

    /** permission ที่ actor ต้องมี ตรวจก่อน validate() */
    public function permission(): string;

    /**
     * เปลี่ยนแปลงสถานะระบบหรือไม่
     * false = โหมด dryrun จะรันของจริงให้ เพื่อให้ผู้ใช้เห็นสถานะจริงของเครื่อง
     */
    public function isMutating(): bool;

    /** ข้อความไทยสั้น ๆ สำหรับ audit log เช่น "รีสตาร์ตบริการ" */
    public function summary(): string;

    /**
     * ตรวจและทำความสะอาด argument
     *
     * @param array<string,mixed> $args ค่าดิบจากฝั่งเว็บ ไม่เชื่อถือได้
     * @return array<string,mixed> ค่าที่ผ่านการตรวจแล้ว
     * @throws ValidationError|ProtectedResource
     */
    public function validate(array $args): array;

    /**
     * ทำงานจริง — $args คือผลลัพธ์จาก validate() เท่านั้น
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed> ข้อมูลที่จะส่งกลับให้ฝั่งเว็บ
     */
    public function run(array $args, Executor $executor, Context $context): array;
}
