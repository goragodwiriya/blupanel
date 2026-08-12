<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

final class SecurityController extends ApiController
{
    /**
     * ชื่อไฟล์รายงานที่ `tools/security-audit.sh --json-out=...` เขียนไว้ให้ panel อ่าน
     *
     * ตกลงเป็นเส้นทางตายตัวใต้ไดเรกทอรีข้อมูล เพื่อให้ผู้ดูแลไม่ต้องตั้งค่าอะไรเพิ่ม —
     * รันตัวสแกนด้วย `--json-out=/var/lib/phpcp/security-audit.json` แล้วหน้าเว็บเห็นเอง
     */
    private const AUDIT_FILE = 'security-audit.json';

    /** ผลตรวจถือว่า "เก่า" เมื่อเกินกี่วินาที — 30 วันตามที่แผนระบุ */
    private const AUDIT_MAX_AGE = 30 * 86400;

    public function scan(Request $request): Response
    {
        $data = $this->agent()->data('security.scan', [], $this->ctx->actor($request));

        $checks = $data['checks'] ?? [];
        unset($data['checks']);

        // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
        $checks = array_map(static function (array $check): array {
            $check['status_tone'] = match ($check['status'] ?? '') {
                'pass' => 'ok',
                'fail' => 'danger',
                'warn' => 'warn',
                default => 'muted',
            };

            return $check;
        }, $checks);

        return $this->ok($checks, $data);
    }

    /**
     * ผลตรวจจากภายนอกที่ `tools/security-audit.sh` ทิ้งไว้ — PLAN-V2 เฟส M
     *
     * **ต่างจาก `/security/scan` ตรงมุมมอง:** `scan` ตรวจจาก**ข้างใน**เครื่องผ่าน agent
     * (ค่าตั้งของ sshd, สถานะ firewall, สิทธิ์ไฟล์) ส่วนตัวนี้คือผลที่มองจาก**ข้างนอก**
     * (TLS, header, พอร์ตที่เปิด, คุกกี้) ซึ่งเป็นสิ่งที่ agent มองไม่เห็นด้วยตัวเอง
     *
     * **ทำไมต้องเป็นไฟล์ ไม่ใช่สั่งรันจากหน้าเว็บ:** ตัวสแกนยิง nmap และทดสอบ rate limit
     * ซึ่งกินเวลาเป็นนาทีและกินโควตาล็อกอินของ IP นั้น · ปุ่มบนหน้าเว็บที่สั่งสแกนได้
     * ยังเป็นช่องให้ใช้ panel เป็นเครื่องมือสแกนเป้าหมายอื่นด้วย — ผู้ดูแลรันเองจาก
     * เชลล์แล้วชี้ `--json-out` มาที่นี่คือทางที่ปลอดภัยกว่าและง่ายกว่า
     *
     * ตอบ 404 เมื่อยังไม่เคยรัน — "ยังไม่มีข้อมูล" ไม่ใช่ข้อผิดพลาดของระบบ
     * แต่ก็ไม่ใช่ผลตรวจที่ว่างเปล่าเช่นกัน หน้าจอต้องแยกสองอย่างนี้ออกจากกันได้
     */
    public function audit(Request $request): Response
    {
        $file = $this->app->config->paths->data.'/'.self::AUDIT_FILE;

        if (!is_file($file)) {
            return $this->problem(
                ApiProblem::NotFound,
                'No external scan results yet — run tools/security-audit.sh with --json-out',
            );
        }

        $raw = @file_get_contents($file);
        $report = $raw === false ? null : json_decode($raw, true);

        if (!is_array($report)) {
            // ไฟล์เสียหรือถูกเขียนค้างกลางทาง — บอกให้ชัดว่าเป็นปัญหาของไฟล์
            // ไม่ใช่ปล่อยให้หน้าจอแสดงผลตรวจที่ไม่สมบูรณ์เหมือนว่าถูกต้อง
            return $this->problem(ApiProblem::InternalError, 'The scan result file could not be read — it may be damaged');
        }

        $scannedAt = (int) (strtotime((string) ($report['scan_time'] ?? '')) ?: filemtime($file));
        $age = max(0, time() - $scannedAt);

        return $this->ok(
            // ส่งเฉพาะรายการผลตรวจเป็น data ส่วนที่เหลือเป็น meta ตามรูปแบบ §4.2
            array_values(is_array($report['results'] ?? null) ? $report['results'] : []),
            [
                'target' => (string) ($report['target'] ?? ''),
                'mode' => (string) ($report['mode'] ?? ''),
                'scanned_at' => $scannedAt,
                'age_seconds' => $age,
                // หน้าจอไม่ต้องรู้เกณฑ์เอง — สองที่ที่ตัดสินคนละแบบจะขัดกันเองในที่สุด
                'stale' => $age > self::AUDIT_MAX_AGE,
                'max_age_seconds' => self::AUDIT_MAX_AGE,
                'summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            ],
        );
    }
}
