<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * โหมดการทำงานตาม ARCHITECTURE §6
 *
 * โหมดถูกอ่านที่ bootstrap แล้วส่งต่อเป็น Executor ที่เหมาะสม — โค้ดส่วนอื่น
 * โดยเฉพาะ Capability ต้องไม่มี if ตรวจโหมดเองเด็ดขาด (ARCHITECTURE §6.2)
 */
enum Mode: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';
    case DryRun = 'dryrun';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /** ต้องขึ้นแถบเตือนค้างบนหัวทุกหน้าหรือไม่ */
    public function needsBanner(): bool
    {
        return $this !== self::Production;
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'ใช้งานจริง',
            self::Sandbox => 'ทดสอบ (Sandbox)',
            self::DryRun => 'จำลอง (Dry Run)',
        };
    }

    public function bannerText(): string
    {
        return match ($this) {
            self::Production => '',
            self::Sandbox => 'โหมดทดสอบ (Sandbox) — คำสั่งทั้งหมดจะไม่มีผลกับเซิร์ฟเวอร์จริง',
            self::DryRun => 'โหมดจำลอง (Dry Run) — ระบบจะแสดงคำสั่งที่จะทำงานเท่านั้น ไม่มีการเปลี่ยนแปลงใด ๆ',
        };
    }

    /** ใช้เลือกสีแถบเตือน */
    public function bannerTone(): string
    {
        return $this === self::Sandbox ? 'warn' : 'muted';
    }
}
