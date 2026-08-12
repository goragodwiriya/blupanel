<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * ที่อยู่อีเมลของกล่องบนเครื่องนี้ — PLAN-MAIL เฟส M1
 *
 * **ค่าที่ผ่านที่นี่กลายเป็นทั้งชื่อไดเรกทอรีและบรรทัดในตารางของ Postfix** จึงต้อง
 * แคบกว่ากติกาของ RFC มาก · RFC 5321 ยอมให้ local part มีอักขระอย่าง ! # $ % & ' * = ? ^
 * และแม้แต่เว้นวรรคถ้าใส่เครื่องหมายคำพูด ซึ่งใช้ตั้งชื่อโฟลเดอร์ไม่ได้และทำให้
 * ไฟล์ map ของ Postfix อ่านผิดบรรทัด
 *
 * ที่รับ: ตัวอักษร ตัวเลข จุด ขีดกลาง ขีดล่าง บวก — พอสำหรับที่อยู่ที่คนใช้กันจริง
 * และไม่มีอักขระไหนที่มีความหมายพิเศษกับ shell, path หรือรูปแบบไฟล์ map
 */
final class MailAddress
{
    /** ยาวสุดของ local part ตาม RFC 5321 */
    private const MAX_LOCAL = 64;

    /**
     * ชื่อที่ระบบสงวนไว้ — สร้างเป็นกล่องของลูกค้าไม่ได้
     *
     * `postmaster` กับ `abuse` ต้องไปถึงผู้ดูแลเครื่องเสมอตาม RFC 2142 · การให้ลูกค้า
     * ยึดสองชื่อนี้ของโดเมนตัวเองก็ยังยอมได้ แต่ชื่อที่ชนกับบัญชีของระบบไม่ได้
     */
    private const RESERVED = ['root', 'daemon', 'mail', 'vmail', 'nobody'];

    public function __construct(
        public readonly string $localPart,
        public readonly string $domain,
    ) {
    }

    /**
     * แยกและตรวจที่อยู่เต็ม `ชื่อ@โดเมน`
     */
    public static function parse(string $value): self
    {
        $value = strtolower(trim($value));
        $at = strrpos($value, '@');

        if ($at === false) {
            throw new ValidationError('ที่อยู่อีเมลต้องมี @ คั่นระหว่างชื่อกับโดเมน');
        }

        return new self(
            self::assertLocalPart(substr($value, 0, $at)),
            Validator::domain(substr($value, $at + 1)),
        );
    }

    /**
     * ตรวจเฉพาะส่วนหน้า @ — ใช้ตอนที่โดเมนมาจากที่อื่นแล้ว (เช่นเลือกจากรายการ)
     */
    public static function assertLocalPart(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw new ValidationError('ต้องระบุชื่อกล่องจดหมาย');
        }

        if (strlen($value) > self::MAX_LOCAL) {
            throw new ValidationError('ชื่อกล่องจดหมายยาวเกิน ' . self::MAX_LOCAL . ' ตัวอักษร');
        }

        // จุดนำหน้า/ต่อท้าย และจุดติดกันสองตัว ทำให้เกิดชื่อไดเรกทอรีอย่าง `.` หรือ `..`
        if (str_starts_with($value, '.') || str_ends_with($value, '.') || str_contains($value, '..')) {
            throw new ValidationError('ชื่อกล่องจดหมายห้ามขึ้นต้น ลงท้าย หรือมีจุดติดกันสองตัว');
        }

        if (preg_match('/^[a-z0-9._+-]+$/', $value) !== 1) {
            throw new ValidationError('ชื่อกล่องจดหมายใช้ได้เฉพาะ a-z 0-9 . _ + และ -');
        }

        if (in_array($value, self::RESERVED, true)) {
            throw new ValidationError('ชื่อ ' . $value . ' เป็นชื่อที่ระบบสงวนไว้');
        }

        return $value;
    }

    public function full(): string
    {
        return $this->localPart . '@' . $this->domain;
    }

    /**
     * เส้นทาง Maildir ของกล่องนี้ — ท้ายด้วย `/` เสมอ
     *
     * เครื่องหมายทับท้ายคือสิ่งที่บอก Postfix ว่าเป็น **Maildir** (โฟลเดอร์ที่มีไฟล์
     * ละหนึ่งฉบับ) ไม่ใช่ mbox (ไฟล์เดียวที่ทุกฉบับต่อกัน) · ลืมทับท้ายเมื่อไหร่
     * เมลทุกฉบับจะถูกเขียนต่อท้ายไฟล์เดียวกันจนอ่านผ่าน IMAP ไม่ได้
     */
    public function maildir(string $root): string
    {
        return rtrim($root, '/') . '/' . $this->domain . '/' . $this->localPart . '/';
    }
}
