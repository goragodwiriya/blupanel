<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Middleware\RateLimit;
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

    /**
     * ภาพรวมการป้องกันทั้งเครื่อง — สวิตช์ใหญ่ ทุก jail และ IP ที่ถูกแบนทั้งหมด
     *
     * คำถามที่หน้านี้ตอบคือ "เครื่องนี้กำลังแบนใครอยู่บ้าง" ซึ่งเป็นคำถามระดับเครื่อง
     * ไม่ใช่ระดับเว็บ · ก่อนหน้านี้ต้องไล่เปิดหน้าของแต่ละเว็บเพื่อหาคำตอบ
     */
    public function protection(Request $request): Response
    {
        return $this->ok(
            $this->agent()->data('security.protection', [], $this->ctx->actor($request)),
        );
    }

    /** รายการ IP ที่ถูกแบนของทุก jail รวมกัน — แยกเส้นทางเพื่อให้ตารางโหลดใหม่เองได้ */
    public function protectionBans(Request $request): Response
    {
        $data = $this->agent()->data('security.protection', [], $this->ctx->actor($request));

        return $this->ok($data['bans'] ?? []);
    }

    /** เปิด/ปิดการใช้ fail2ban ทั้งตัว */
    public function fail2banSet(Request $request): Response
    {
        $data = $this->agent()->data('security.fail2ban_set', [
            'enabled' => $request->payload('enabled'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Saved'),
            extra: is_array($data) ? $data : [],
        );
    }

    /** สถานะการกันเดารหัสผ่านหน้าเข้าสู่ระบบ */
    public function panelJail(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail', [], $this->ctx->actor($request));

        /*
         * เพดานของ `max_retry` ไม่ใช่ค่าที่ตั้งใจเลือก แต่คำนวณจากโควตาของหน้าล็อกอิน
         * — คำขอที่โดน 429 ไม่มีบรรทัดใน audit log ให้ fail2ban นับ · ส่งไปให้หน้าจอ
         * เขียนเป็นคำอธิบายใต้ช่องกรอก ผู้ใช้จะได้รู้ก่อนกดบันทึกแล้วโดนปฏิเสธ
         */
        $data['max_retry_ceiling'] = RateLimit::maxLoginFailuresWithin(
            (int) ($data['find_seconds'] ?? 600),
        );

        return $this->ok($data);
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
            'mode' => $request->payloadString('mode'),
            'enabled' => $request->payload('enabled', false),
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
     * ตั้งรายการที่อยู่ที่ห้ามแบน — ระดับเครื่อง ใช้กับทุก jail
     *
     * แยกจากฟอร์มของ jail หน้าเข้าสู่ระบบโดยตั้งใจ: รายการนี้มีผลกับ jail รายเว็บด้วย
     * จึงต้องบันทึกได้แม้ jail หน้าเข้าสู่ระบบจะปิดอยู่
     */
    public function neverBanSet(Request $request): Response
    {
        $data = $this->agent()->data('security.never_ban_set', [
            'ips' => $request->payloadString('ips'),
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
            // หน้ารวมส่งชื่อ jail มาด้วยเพราะมีหลายตัว · หน้าเดิมไม่ส่ง = jail หน้าล็อกอิน
            'jail' => $request->payloadString('jail'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Unbanned'),
            extra: is_array($data) ? $data : [],
        );
    }
}
