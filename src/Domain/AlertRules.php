<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * ตัดสินว่าอะไรผิดปกติและควรแจ้งเตือนเมื่อไร — PLAN-V2 เฟส E6
 *
 * **หัวใจของคลาสนี้คือ "เมื่อไรควรเงียบ" ไม่ใช่ "อะไรผิดปกติ"** — การตรวจว่าดิสก์เกิน 85%
 * เป็นเรื่องง่าย ส่วนที่ทำให้ระบบแจ้งเตือนใช้งานได้จริงคือการไม่ส่งข้อความเดิมซ้ำทุกนาที
 * จนคนปิดการแจ้งเตือนทิ้ง (ดูเหตุผลเต็มใน `db/migrations/0015_alert_state.sql`)
 *
 * กฎการส่ง:
 *   - เข้าสู่สถานะผิดปกติครั้งแรก → ส่ง
 *   - ระดับแย่ลง (warning → critical) → ส่งทันที ไม่ต้องรอรอบ
 *   - ระดับเท่าเดิมหรือดีขึ้นแต่ยังผิดปกติ → เงียบจนครบรอบเตือนซ้ำ
 *   - กลับมาปกติ → ส่ง "หายแล้ว" ครั้งเดียวแล้วลืมสถานะ
 */
final class AlertRules
{
    /** เตือนซ้ำทุก 6 ชั่วโมงถ้าปัญหายังอยู่ — ถี่พอให้ไม่ลืม ห่างพอให้ไม่รำคาญ */
    public const REPEAT_AFTER = 21600;

    /**
     * เกณฑ์ของทรัพยากรที่วัดเป็นเปอร์เซ็นต์ → [เตือน, วิกฤต]
     *
     * ตัวเลขมาจากพฤติกรรมจริงของเครื่องโฮสติ้ง: ดิสก์ 85% คือจุดที่ควรเริ่มหาที่ลบเพราะ
     * ไฟล์สำรองรอบถัดไปอาจไม่พอ · 95% คือจุดที่ MariaDB เริ่มเขียนไม่ได้และเว็บล่มทั้งเครื่อง
     * · หน่วยความจำใช้เกณฑ์สูงกว่าเพราะ Linux ใช้ที่ว่างเป็น cache เป็นปกติอยู่แล้ว
     *
     * @var array<string,array{0:float,1:float,2:string}>
     */
    public const THRESHOLDS = [
        'disk' => [85.0, 95.0, 'พื้นที่ดิสก์'],
        'memory' => [90.0, 97.0, 'หน่วยความจำ'],
    ];

    /** load average ต่อคอร์ — 1.0 = ใช้เต็มทุกคอร์พอดี · เกิน 2 เท่าคือมีคิวรอจริง */
    public const LOAD_WARNING = 1.5;
    public const LOAD_CRITICAL = 3.0;

    /** ใบรับรองที่เหลือน้อยกว่านี้ (วัน) ต้องเตือน — certbot ต่ออายุที่ 30 วัน */
    public const CERT_WARNING_DAYS = 20;
    public const CERT_CRITICAL_DAYS = 7;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * ตัดสินว่าเกณฑ์นี้ควรส่งข้อความหรือไม่ แล้วบันทึกสถานะให้เอง
     *
     * @param string $key คีย์ของเกณฑ์ เช่น `disk` หรือ `service:nginx`
     * @param string|null $level `warning`/`critical` · null = กลับมาปกติแล้ว
     * @return array{notify:bool,reason:string} `reason` ใช้ประกอบข้อความและไว้ดูตอนไล่ปัญหา
     */
    public function evaluate(string $key, ?string $level, float $value = 0, ?int $now = null): array
    {
        $now ??= time();
        $previous = $this->db->first('SELECT * FROM alert_state WHERE alert_key = :k', ['k' => $key]);

        // กลับมาปกติ — แจ้งครั้งเดียวว่าหายแล้ว เฉพาะเมื่อเคยแจ้งว่าผิดปกติไปก่อน
        if ($level === null) {
            if ($previous === null) {
                return ['notify' => false, 'reason' => 'ปกติอยู่แล้ว'];
            }

            $this->db->run('DELETE FROM alert_state WHERE alert_key = :k', ['k' => $key]);

            return ['notify' => true, 'reason' => 'recovered'];
        }

        // ผิดปกติครั้งแรก
        if ($previous === null) {
            $this->remember($key, $level, $value, $now, $now);

            return ['notify' => true, 'reason' => 'new'];
        }

        // แย่ลงกว่าเดิม — ส่งทันทีโดยไม่รอรอบ เพราะสถานการณ์เปลี่ยนไปในทางที่แย่ลง
        if ($level === 'critical' && $previous['level'] === 'warning') {
            $this->remember($key, $level, $value, (int) $previous['first_at'], $now);

            return ['notify' => true, 'reason' => 'escalated'];
        }

        // ยังผิดปกติอยู่เหมือนเดิม — เงียบจนครบรอบเตือนซ้ำ
        if ($now - (int) $previous['notified_at'] >= self::REPEAT_AFTER) {
            $this->remember($key, $level, $value, (int) $previous['first_at'], $now);

            return ['notify' => true, 'reason' => 'reminder'];
        }

        // อัปเดตค่าล่าสุดไว้แต่ไม่ส่ง — ข้อความรอบหน้าจะได้บอกค่าปัจจุบันที่ถูกต้อง
        $this->db->update(
            'alert_state',
            ['value' => $value, 'level' => $level, 'updated_at' => $now],
            ['alert_key' => $key],
        );

        return ['notify' => false, 'reason' => 'ส่งไปแล้วและยังไม่ครบรอบเตือนซ้ำ'];
    }

    /** ระดับของค่าเปอร์เซ็นต์ตามเกณฑ์ที่ตั้งไว้ — null = ปกติ */
    public static function levelForPercent(string $type, float $percent): ?string
    {
        if (!isset(self::THRESHOLDS[$type])) {
            return null;
        }

        [$warning, $critical] = self::THRESHOLDS[$type];

        return match (true) {
            $percent >= $critical => 'critical',
            $percent >= $warning => 'warning',
            default => null,
        };
    }

    /**
     * ระดับของ load average เทียบกับจำนวนคอร์
     *
     * เทียบต่อคอร์เสมอ ไม่ใช่ค่าดิบ — load 4.0 บนเครื่อง 8 คอร์คือสบาย ๆ
     * แต่บนเครื่องคอร์เดียวคือคิวยาวจนเว็บตอบช้าแล้ว
     */
    public static function levelForLoad(float $load1, int $cores): ?string
    {
        $perCore = $load1 / max(1, $cores);

        return match (true) {
            $perCore >= self::LOAD_CRITICAL => 'critical',
            $perCore >= self::LOAD_WARNING => 'warning',
            default => null,
        };
    }

    /** ระดับของใบรับรองที่ใกล้หมดอายุ — null = ยังเหลือเวลาพอ */
    public static function levelForCertDays(int $daysLeft): ?string
    {
        return match (true) {
            $daysLeft <= self::CERT_CRITICAL_DAYS => 'critical',
            $daysLeft <= self::CERT_WARNING_DAYS => 'warning',
            default => null,
        };
    }

    /**
     * สถานะที่ยังค้างอยู่ทั้งหมด — หน้าจอใช้แสดงว่าตอนนี้มีอะไรผิดปกติบ้าง
     *
     * **เรียงด้วย CASE ไม่ใช่ `ORDER BY level DESC`** — การเรียงตามตัวอักษรให้ `warning`
     * มาก่อน `critical` (w > c) ซึ่งกลับหัวกับสิ่งที่ต้องการพอดี · ผู้ดูแลที่เปิดหน้าเว็บ
     * ตอนเครื่องมีปัญหาหลายเรื่องพร้อมกัน จะเห็นเรื่องที่แค่ควรจับตาอยู่เหนือเรื่องที่
     * ทำให้เว็บล่มทั้งเครื่อง · ที่เก่าสุดขึ้นก่อนในระดับเดียวกัน เพราะเป็นเรื่องที่ถูก
     * ปล่อยทิ้งไว้นานที่สุด
     */
    public function active(): array
    {
        return $this->db->all(
            "SELECT * FROM alert_state
             ORDER BY CASE level WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, first_at",
        );
    }

    /**
     * ลบสถานะของเกณฑ์ที่รอบนี้ไม่ได้ตรวจแล้ว — กันรายการค้างที่ไม่มีวันหายไปเอง
     *
     * **รายการในตารางนี้จะหายก็ต่อเมื่อมีการประเมินคีย์นั้นแล้วพบว่ากลับมาปกติ** ·
     * คีย์ที่เลิกถูกประเมินจึงค้างอยู่ตลอดไป — เจอจริงกับ `service:<ชื่อ>` ของบริการที่
     * เคยหยุดทำงานแล้วถูกถอนออกจากเครื่องภายหลัง: `AlertCheck` ข้ามบริการที่
     * `not_installed` ไปเลย จึงไม่มีอะไรมาบอกว่ามันหายดีแล้ว
     *
     * ผลคือหน้าจอแสดงว่ามีปัญหาค้างอยู่ตลอดกาล ผู้ดูแลกดอะไรก็ไม่หาย และเลิกเชื่อ
     * ส่วนนี้ไปทั้งส่วน — ซึ่งอันตรายกว่าไม่มีมันเลย เพราะวันที่มีปัญหาจริงก็จะถูกมองข้าม
     *
     * @param list<string> $keys คีย์ทั้งหมดที่รอบนี้ตรวจแล้ว
     */
    public function forgetOthers(array $keys): int
    {
        if ($keys === []) {
            // ไม่ได้ตรวจอะไรเลย = ผิดปกติของตัวตรวจเอง ไม่ใช่สัญญาณว่าทุกอย่างหายดี
            // การลบทั้งตารางตรงนี้จะกลบปัญหาจริงที่ค้างอยู่
            return 0;
        }

        $stale = array_column(
            array_filter(
                $this->db->all('SELECT alert_key FROM alert_state'),
                static fn (array $row): bool => !in_array((string) $row['alert_key'], $keys, true),
            ),
            'alert_key',
        );

        foreach ($stale as $key) {
            $this->db->run('DELETE FROM alert_state WHERE alert_key = :k', ['k' => $key]);
        }

        return count($stale);
    }

    private function remember(string $key, string $level, float $value, int $firstAt, int $now): void
    {
        $this->db->run(
            'INSERT INTO alert_state (alert_key, level, value, first_at, notified_at, updated_at)
             VALUES (:k, :l, :v, :f, :n, :n)
             ON CONFLICT(alert_key) DO UPDATE SET
                level = :l, value = :v, first_at = :f, notified_at = :n, updated_at = :n',
            ['k' => $key, 'l' => $level, 'v' => $value, 'f' => $firstAt, 'n' => $now],
        );
    }
}
