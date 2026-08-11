<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * คะแนนความปลอดภัยและรายการที่ต้องแก้ — PROMPT.md หัวข้อ Security
 *
 * แยกการ "คิดคะแนน" ออกจากการ "เก็บข้อมูล" เพราะสองอย่างนี้เปลี่ยนคนละจังหวะ
 * และการคิดคะแนนต้องทดสอบได้โดยไม่ต้องมีเซิร์ฟเวอร์จริง
 *
 * หลักที่ยึดไว้ตลอดไฟล์นี้: **คะแนนต้องโกงไม่ได้ด้วยการซ่อนปัญหา**
 * ถ้าตรวจข้อไหนไม่ได้ (เช่นอ่านสถานะ firewall ไม่ได้) ข้อนั้นนับเป็น "ไม่ทราบ"
 * และคิดคะแนนเท่ากับยังไม่ผ่าน ไม่ใช่ข้ามไปเฉย ๆ — เพราะถ้าข้ามได้
 * เครื่องที่พังจนตรวจอะไรไม่ได้เลยจะได้ 100 คะแนน ซึ่งตรงข้ามกับความจริง
 */
final class SecurityScore
{
    /** ผ่าน — ไม่ต้องทำอะไร */
    public const PASS = 'pass';

    /** ควรปรับปรุง แต่ยังไม่ถึงขั้นอันตราย */
    public const WARN = 'warn';

    /** ต้องแก้ */
    public const FAIL = 'fail';

    /** ตรวจไม่ได้ — คิดคะแนนเหมือนยังไม่ผ่าน แต่บอกผู้ใช้ตามตรงว่าไม่รู้ */
    public const UNKNOWN = 'unknown';

    /**
     * สัดส่วนคะแนนที่แต่ละสถานะได้รับ
     *
     * WARN ได้ครึ่งหนึ่งเพราะเป็นเรื่องที่ "ควรทำ" ไม่ใช่ "ต้องทำ" —
     * ถ้าให้ 0 ผู้ดูแลที่จัดการเรื่องสำคัญครบแล้วจะเห็นคะแนนต่ำจนเลิกสนใจคะแนนไปเลย
     */
    private const CREDIT = [
        self::PASS => 1.0,
        self::WARN => 0.5,
        self::FAIL => 0.0,
        self::UNKNOWN => 0.0,
    ];

    /**
     * คิดคะแนนรวมแบบถ่วงน้ำหนัก
     *
     * @param list<array{status:string,weight:int}> $checks
     */
    public static function calculate(array $checks): int
    {
        $total = 0;
        $earned = 0.0;

        foreach ($checks as $check) {
            $weight = max(0, $check['weight']);
            $total += $weight;
            $earned += $weight * (self::CREDIT[$check['status']] ?? 0.0);
        }

        if ($total === 0) {
            return 0;
        }

        // ปัดลงเสมอ — 99.6 ต้องไม่กลายเป็น 100 เพราะ 100 สื่อว่า "ไม่เหลืออะไรให้ทำแล้ว"
        return (int) floor($earned / $total * 100);
    }

    /** ระดับที่ใช้เลือกสีและถ้อยคำบนหน้าจอ */
    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'ดี',
            $score >= 70 => 'พอใช้',
            $score >= 50 => 'ต้องปรับปรุง',
            default => 'เสี่ยง',
        };
    }

    public static function tone(int $score): string
    {
        return match (true) {
            $score >= 90 => 'ok',
            $score >= 70 => 'warn',
            default => 'danger',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::PASS => 'ผ่าน',
            self::WARN => 'ควรปรับปรุง',
            self::FAIL => 'ต้องแก้',
            default => 'ตรวจไม่ได้',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            self::PASS => 'ok',
            self::WARN => 'warn',
            self::FAIL => 'danger',
            default => 'muted',
        };
    }

    /**
     * เรียงรายการที่ต้องแก้ตามความเร่งด่วนจริง
     *
     * เรียงตาม "ผลกระทบ" ก่อน ไม่ใช่ตามลำดับที่ตรวจ — คนที่มีเวลาแก้แค่เรื่องเดียว
     * ต้องได้แก้เรื่องที่สำคัญที่สุด ไม่ใช่เรื่องที่บังเอิญถูกตรวจก่อน
     *
     * @param list<array<string,mixed>> $checks
     * @return list<array<string,mixed>>
     */
    public static function recommendations(array $checks): array
    {
        $todo = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] !== self::PASS,
        ));

        usort($todo, static function (array $a, array $b): int {
            $rank = static fn (array $c): int => match ($c['status']) {
                self::FAIL => 0,
                self::UNKNOWN => 1,
                default => 2,
            };

            // สถานะแย่กว่ามาก่อน ถ้าเท่ากันให้ข้อที่น้ำหนักมากกว่ามาก่อน
            return [$rank($a), -$a['weight']] <=> [$rank($b), -$b['weight']];
        });

        return $todo;
    }
}
