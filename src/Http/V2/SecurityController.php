<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

final class SecurityController extends ApiController
{
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

    /** สถานะการกันเดารหัสผ่านหน้าเข้าสู่ระบบ */
    public function panelJail(Request $request): Response
    {
        return $this->ok(
            $this->agent()->data('security.panel_jail', [], $this->ctx->actor($request)),
        );
    }

    /**
     * เปิด/ปิดการกันเดารหัสผ่าน
     *
     * **ไม่ใช้ `saved()`** — ฟอร์มนี้ไม่ได้อยู่ใน Modal และหน้าจอต้องแสดงสถานะจริงจาก
     * fail2ban ต่อทันที (jail ทำงานอยู่ไหม แบนใครอยู่บ้าง) จึงสั่งให้โหลดหน้าใหม่แทน
     */
    public function panelJailSet(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail_set', [
            'enabled' => $request->payload('enabled'),
            'max_retry' => $request->payload('max_retry'),
            'find_seconds' => $request->payload('find_seconds'),
            'ban_seconds' => $request->payload('ban_seconds'),
            'ignore_ips' => $request->payloadString('ignore_ips'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Saved'),
            extra: is_array($data) ? $data : [],
        );
    }

    /**
     * ปลดแบน IP หนึ่งออกจาก jail ของหน้าเข้าสู่ระบบ
     *
     * ต้องมี เพราะการแบนพลาดเกิดขึ้นจริงและตัดขาดทุกพอร์ต — ผู้ดูแลที่แบนตัวเองจาก
     * อีกเครื่องหนึ่งต้องปลดได้จากหน้าจอ ไม่ต้องหา SSH
     */
    public function panelJailUnban(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail_unban', [
            'ip' => $request->payloadString('ip'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Unbanned'),
            extra: is_array($data) ? $data : [],
        );
    }
}
