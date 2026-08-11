<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * งานตามเวลาของ panel — `GET /api/v2/scheduled-jobs` (PLAN-V2 เฟส A1 ข้อ 7)
 *
 * เฟส A ทิ้งข้อนี้ไว้ให้เฟส C เพราะตอนนั้นยังไม่มีหน้าจอให้แสดง · ระหว่างนั้นดูได้
 * ทาง `phpcp status` และ `phpcp-scheduler --list` เท่านั้น ซึ่งต้องเข้าเครื่องได้ก่อน
 *
 * **ทำไมต้องเห็นบนหน้าเว็บ:** scheduler ที่ตายไปแล้วทำให้ทุกอย่างดูปกติทั้งที่กลไก
 * คืนค่าอัตโนมัติของ SSH/firewall หายไปเงียบ ๆ · ผู้ดูแลที่ไม่มี shell ต้องมีทาง
 * เห็นชีพจรของมันเช่นกัน ไม่ใช่รู้ตัวตอนที่ล็อกตัวเองออกจากเครื่องไปแล้ว
 *
 * อ่านอย่างเดียว: การเปิด/ปิดงานตามเวลาไม่มีในแผน และเป็นการลดการป้องกันของระบบเอง
 * ผ่านหน้าเว็บ ซึ่งเป็นสิ่งเดียวกับที่ `SelfProtection` กันไว้ที่ชั้น service อยู่แล้ว
 */
final class ScheduledJobsController extends ApiController
{
    /** ถือว่าตัวจับเวลา "ค้าง" เมื่อไม่ได้เดินมานานกว่านี้ — ค่าเดียวกับที่ `phpcp doctor` ใช้ */
    private const STALE_AFTER = 300;

    public function index(Request $request): Response
    {
        $repository = new ScheduledJobRepository($this->app->db());
        $lastRunAt = $repository->lastRunAt();

        $jobs = array_map(
            static function (array $row): array {
                $status = (string) ($row['last_status'] ?? '');

                return [
                    'name' => (string) $row['name'],
                    'capability' => (string) $row['capability'],
                    'schedule' => (string) $row['schedule'],
                    'enabled' => (bool) $row['enabled'],
                    'last_run_at' => $row['last_run_at'] === null ? null : (int) $row['last_run_at'],
                    'last_status' => $status,
                    // ป้ายสำเร็จรูป — ไม่ใช้ last_status ตรง ๆ เป็นคีย์แปลภาษา เพราะงานที่
                    // ยังไม่เคยรันมี last_status เป็นสตริงว่าง ซึ่งเทมเพลต {LNG_${...}}
                    // จะกลายเป็น "{LNG_}" ที่ไม่มีความหมายให้ผู้ใช้เห็นตรง ๆ — ใช้คีย์เดียว
                    // กับ run_status ของหน้าสำรองข้อมูลอัตโนมัติ (backups.html) เพื่อให้
                    // คำแปล "never" ใช้ร่วมกันได้ ไม่ต้องมีคีย์แปลซ้ำซ้อนหลายชื่อ
                    'status_label' => $status === '' ? 'never' : $status,
                    // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
                    'status_tone' => match ($status) {
                        'ok' => 'ok',
                        'failed' => 'danger',
                        default => 'muted',
                    },
                    'last_error' => (string) ($row['last_error'] ?? ''),
                ];
            },
            $repository->all(),
        );

        return $this->ok($jobs, [
            'last_run_at' => $lastRunAt,
            // ให้ฝั่งหน้าจอตัดสินจากค่าเดียวกับ `phpcp doctor` ไม่ใช่คำนวณเกณฑ์ของตัวเอง
            // — ไม่งั้นสองที่จะบอกไม่ตรงกันว่า scheduler ยังทำงานอยู่ไหม
            'stale' => $lastRunAt === null || (time() - $lastRunAt) > self::STALE_AFTER,
            'stale_after' => self::STALE_AFTER,
        ]);
    }
}
