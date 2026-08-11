<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Driver\RollbackGuard;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * รายการที่รอยืนยัน — `/api/v2/rollbacks`
 *
 * กลไกนี้คือสิ่งที่ทำให้แก้ firewall/SSH จากระยะไกลแล้วไม่ล็อกตัวเองออกจากเครื่อง:
 * เปลี่ยนค่า → ถ้ายังเข้าถึงได้ให้กดยืนยันภายในเวลา → ไม่กดทันระบบคืนค่าเดิมให้เอง
 *
 * **ตัวจับเวลาอยู่ฝั่งเซิร์ฟเวอร์เสมอ ไม่ใช่ในเบราว์เซอร์** — กรณีที่ต้องกู้คือกรณีที่
 * ผู้ใช้หลุดไปแล้ว เบราว์เซอร์จึงไม่มีโอกาสทำงาน · ตัวที่กระตุ้นจริงคือ
 * `phpcp-scheduler` ที่เรียก `rollback.run` ทุกนาที (เฟส A1)
 *
 * สอง sub-resource เป็นคำนามตาม §4.1:
 *   `confirmation` — ยืนยันว่ายังเข้าถึงได้ · การที่คำขอนี้มาถึงได้คือหลักฐานในตัวมันเอง
 *   `execution`    — สั่งคืนค่าทันทีโดยไม่รอหมดเวลา ใช้เมื่อรู้ตัวว่าตั้งผิด
 */
final class RollbacksController extends ApiController
{
    public function index(Request $request): Response
    {
        $guard = new RollbackGuard($this->app->db());
        $pending = $guard->pending();

        return $this->ok($pending === null ? [] : [$this->describe($pending)], [
            'default_window' => RollbackGuard::DEFAULT_WINDOW,
            // รายการที่หมดเวลาแล้วแต่ scheduler ยังไม่ทันเก็บ — ผู้ดูแลควรเห็นว่ามีค้างอยู่
            'expired_waiting' => count($guard->expired()),
        ]);
    }

    /** ยืนยันว่ายังเข้าถึงเครื่องได้ — ยกเลิกการคืนค่าที่ตั้งเวลาไว้ */
    public function confirm(Request $request): Response
    {
        $result = $this->agent()->data(
            'rollback.confirm',
            ['rollback_id' => $request->paramInt('id')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'ยืนยันการเปลี่ยนแปลงแล้ว'), 'rollbacks', is_array($result) ? $result : []);
    }

    /**
     * สั่งคืนค่าทันที
     *
     * บีบให้รายการหมดอายุก่อน แล้วเดินเส้นทางคืนค่าเดียวกับที่ scheduler ใช้ —
     * ไม่เขียนตรรกะคืนค่าซ้ำอีกชุด เพื่อให้มีเส้นทางเดียวที่ต้องดูแลและทดสอบ
     */
    public function execute(Request $request): Response
    {
        $id = $request->paramInt('id');
        $db = $this->app->db();

        $exists = (int) $db->value('SELECT count(*) FROM pending_rollbacks WHERE id = :id', ['id' => $id], 0);

        if ($exists === 0) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบรายการที่รอยืนยัน — อาจถูกคืนค่าไปแล้ว');
        }

        $db->run('UPDATE pending_rollbacks SET expires_at = :t WHERE id = :id', ['t' => time() - 1, 'id' => $id]);

        $result = $this->agent()->data('rollback.run', [], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'คืนค่าการเปลี่ยนแปลงแล้ว'), 'rollbacks', is_array($result) ? $result : []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'description' => (string) $row['description'],
            'created_at' => (int) $row['created_at'],
            'expires_at' => (int) $row['expires_at'],
            'remaining_seconds' => RollbackGuard::remaining($row),
        ];
    }
}
