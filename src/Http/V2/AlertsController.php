<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\AlertRules;
use Phpcp\Domain\Notifier;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * เกณฑ์เตือนที่ยังค้างอยู่ — `GET /api/v2/alerts` (PLAN-V2 เฟส E6)
 *
 * **ทำไมต้องมีหน้าจอ ทั้งที่มีการแจ้งเตือนอยู่แล้ว:** ระบบแจ้งเตือนที่ดีต้องเงียบเมื่อ
 * ปัญหายังเหมือนเดิม (ดู {@see AlertRules}) — ผู้ดูแลที่เพิ่งเปิดคอมพิวเตอร์จึงไม่มีทาง
 * รู้เลยว่าตอนนี้ดิสก์ยังเต็มอยู่ไหม ถ้าข้อความรอบล่าสุดถูกส่งไปตั้งแต่เมื่อวาน
 * · ที่นี่คือ "สถานะปัจจุบัน" ส่วนการแจ้งเตือนคือ "สิ่งที่เพิ่งเปลี่ยน" คนละคำถามกัน
 *
 * อ่านอย่างเดียว — การล้างสถานะด้วยมือจะทำให้ได้ข้อความ "ผิดปกติครั้งแรก" ซ้ำอีกครั้ง
 * ทั้งที่ปัญหาเดิมยังอยู่ · สถานะหายเองเมื่อค่ากลับมาปกติจริงเท่านั้น
 */
final class AlertsController extends ApiController
{
    public function index(Request $request): Response
    {
        $now = time();

        $alerts = array_map(
            static function (array $row) use ($now): array {
                $level = (string) $row['level'];

                return [
                    'alert_key' => (string) $row['alert_key'],
                    'label' => self::label((string) $row['alert_key']),
                    'level' => $level,
                    'value' => (float) $row['value'],
                    'first_at' => (int) $row['first_at'],
                    'notified_at' => (int) $row['notified_at'],
                    // นานแค่ไหนแล้วที่ยังไม่หาย — ตัวเลขที่บอกว่าเรื่องนี้ถูกปล่อยทิ้งไว้หรือเปล่า
                    'duration' => $now - (int) $row['first_at'],
                    'level_tone' => $level === 'critical' ? 'danger' : 'warn',
                ];
            },
            (new AlertRules($this->app->db()))->active(),
        );

        return $this->ok($alerts, [
            'total' => count($alerts),
            'critical' => count(array_filter($alerts, static fn (array $a): bool => $a['level'] === 'critical')),
            // ถ้าไม่มีช่องทางไหนใช้งานได้ การที่หน้านี้ว่างไม่ได้แปลว่าเครื่องปกติ
            // แปลว่าไม่มีใครได้รับข้อความต่างหาก — หน้าจอต้องแยกสองกรณีนี้ให้ออก
            'channels' => (new Notifier($this->app->db()))->activeChannels(),
            'repeat_after' => AlertRules::REPEAT_AFTER,
        ]);
    }

    /**
     * ชื่อที่คนอ่านได้จากคีย์ของเกณฑ์
     *
     * คีย์ถูกออกแบบให้มีรูปแบบ `ชนิด:เป้าหมาย` เพื่อให้แปลงกลับได้โดยไม่ต้องเก็บ
     * ข้อความไว้ในตาราง — ข้อความที่เก็บไว้ตอนสร้างแถวจะกลายเป็นของเก่าทันทีที่แก้คำ
     */
    private static function label(string $key): string
    {
        if (str_starts_with($key, 'service:')) {
            return 'บริการ ' . substr($key, 8);
        }

        if (str_starts_with($key, 'cert:')) {
            return 'ใบรับรองของ ' . substr($key, 5);
        }

        return match ($key) {
            'disk' => 'พื้นที่ดิสก์',
            'memory' => 'หน่วยความจำ',
            'load' => 'โหลดเฉลี่ย',
            default => $key,
        };
    }
}
