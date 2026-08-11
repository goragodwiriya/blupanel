<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ใบรับรอง SSL — `/api/v2/certificates`
 *
 * ทรัพยากรนี้ใช้ **site id เป็นตัวระบุ** ไม่ใช่ id ของใบรับรอง เพราะหนึ่งเว็บไซต์
 * มีใบรับรองได้ใบเดียวเสมอ (ครอบทุกโดเมนของเว็บนั้นในใบเดียว) การมี id แยกต่างหาก
 * จะทำให้ต้องถามสองรอบทุกครั้งว่า "เว็บนี้ใบไหน" โดยไม่ได้อะไรเพิ่ม
 *
 * ข้อมูลอ่านจากไฟล์ใบรับรองจริงทุกครั้ง ไม่ใช่จากตารางในฐานข้อมูล — certbot ต่ออายุเอง
 * ผ่าน timer ของมันโดยไม่ผ่าน panel เลย ตารางจึงล้าหลังได้เสมอ (`cert.sync` ในเฟส A1
 * เป็นตัวไล่ให้ตารางตามทัน แต่แหล่งความจริงยังเป็นไฟล์)
 */
final class CertificatesController extends HostingController
{
    /** ใบรับรองของทุกเว็บไซต์ที่ผู้เรียกมีสิทธิ์เห็น — กรองด้วย `site_id` ได้ (§4.5) */
    public function index(Request $request): Response
    {
        $data = $this->agent()->data('ssl.list', [], $this->ctx->actor($request));
        $sites = $data['sites'] ?? [];

        $siteId = $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $sites = array_values(array_filter(
                $sites,
                static fn (array $row): bool => (int) ($row['site_id'] ?? 0) === $siteId,
            ));
        }

        // เงื่อนไขของปุ่มในตารางอ่านได้เฉพาะค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        // ไม่ใช่ให้หน้าจอไปหาเอาจากที่อื่น · ตัวจริงที่กันคือ permission ของเส้นทาง API
        $manage = $this->ctx->can('ssl.manage');

        return $this->ok(
            array_map(static fn (array $row): array => self::flatten($row) + ['can_manage' => $manage], $sites),
            [
                'expiring' => (int) ($data['expiring'] ?? 0),
                'certbot_installed' => (bool) ($data['certbot_installed'] ?? false),
                'auto_renew_active' => (bool) ($data['auto_renew_active'] ?? false),
                'warn_days' => (int) ($data['warn_days'] ?? 0),
                'can' => $this->can(['manage' => 'ssl.manage']),
            ],
        );
    }

    /**
     * แผ่ `certificate` ขึ้นมาไว้ระดับบนสุดของแถว
     *
     * `ssl.list` ของ agent ซ้อนรายละเอียดใบรับรองไว้ในคีย์ `certificate` ซึ่งอ่านง่าย
     * ตอนดูด้วยตา แต่**ตารางของหน้าจออ่านได้แค่คีย์ระดับบนสุด** — คอลัมน์สถานะ
     * ผู้ออกใบรับรอง และวันหมดอายุจึงว่างเปล่ามาตลอดโดยที่ตารางยังขึ้นครบทุกแถว
     * และ console ไม่มี error สักตัว
     *
     * แผ่ที่นี่แทนที่จะให้หน้าจอเดินเข้าไปเอง เพราะนี่คือ **endpoint แบบรายการ** —
     * รูปที่เหมาะกับมันคือแถวแบน ๆ หนึ่งแถวต่อหนึ่งเว็บ ส่วนรายละเอียดที่ซ้อนกัน
     * เป็นรูปของทรัพยากรเดี่ยว
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function flatten(array $row): array
    {
        $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
        unset($row['certificate']);

        $status = (string) ($certificate['status'] ?? 'none');

        return $row + [
            'status' => $status,
            // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
            'status_tone' => match ($status) {
                'valid' => 'ok',
                'expiring' => 'warn',
                'expired', 'invalid' => 'danger',
                default => 'muted',
            },
            // null เมื่อไม่มีใบรับรอง (status=none) — ไม่ใช่ '' หรือ 0 · ตัวจัดรูปแบบมาตรฐาน
            // ของตาราง (`data-format`, `data-empty-text`) แสดง "—" ให้เองเฉพาะค่า null/undefined
            // เท่านั้น ค่า 0 จะถูกตีความเป็นวันที่จริง (1 ม.ค. 1970) ไม่ใช่ "ไม่มี"
            'source' => isset($certificate['source']) && $certificate['source'] !== '' ? (string) $certificate['source'] : null,
            'issuer' => isset($certificate['issuer']) && $certificate['issuer'] !== '' ? (string) $certificate['issuer'] : null,
            'subject' => isset($certificate['subject']) && $certificate['subject'] !== '' ? (string) $certificate['subject'] : null,
            'covers' => is_array($certificate['domains'] ?? null) ? $certificate['domains'] : [],
            'expires_at' => !empty($certificate['expires_at']) ? (int) $certificate['expires_at'] : null,
            'days_left' => isset($certificate['days_left']) && $status !== 'none' ? (int) $certificate['days_left'] : null,
            'auto_renew' => (bool) ($certificate['auto_renew'] ?? false),
        ];
    }

    /**
     * ขอใบรับรองใหม่ให้เว็บไซต์
     *
     * `method` เลือกได้ระหว่าง letsencrypt กับ self-signed · โหมด staging ของ
     * Let's Encrypt มีไว้ทดสอบโดยไม่กิน rate limit ของ production ซึ่งพอโดนแล้ว
     * ต้องรอเป็นสัปดาห์ — ค่านี้จึงต้องส่งผ่านได้จาก API ไม่ใช่มีแต่ในหน้าเว็บ
     */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.issue', [
            'site_id' => $siteId,
            'method' => $request->payloadString('method'),
            'email' => $request->payloadString('email'),
            'staging' => (bool) $request->payload('staging', false),
        ], $this->ctx->actor($request));

        return $this->done(
            (string) ($result['message'] ?? 'ออกใบรับรองแล้ว'),
            [
                ['type' => 'notification', 'level' => 'success',
                 'message' => (string) ($result['message'] ?? 'ออกใบรับรองแล้ว')],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'certificates'],
            ],
            $result,
            201,
        )->withHeader('Location', '/api/v2/certificates');
    }

    /** ต่ออายุใบรับรอง — `force` ใช้เมื่อยังไม่ถึงกำหนดแต่ต้องการต่อเลย */
    public function renew(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.renew', [
            'site_id' => $siteId,
            'force' => (bool) $request->payload('force', false),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'ต่ออายุใบรับรองแล้ว'),
            'certificates',
            $result,
        );
    }

    /**
     * เปลี่ยนโหมด HTTPS ของเว็บไซต์ (off / on / forced)
     *
     * เป็น PUT เพราะเป็นการแทนที่ค่าทั้งค่า และสั่งซ้ำแล้วผลไม่เปลี่ยน
     */
    public function setMode(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.set_mode', [
            'site_id' => $siteId,
            'mode' => $request->payloadString('mode'),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'เปลี่ยนโหมด HTTPS แล้ว'),
            'certificates',
            $result,
        );
    }

    /**
     * ลบใบรับรอง — ต้องยืนยันด้วยชื่อโดเมน
     *
     * capability ปิด HTTPS ให้ก่อนลบไฟล์เสมอ ไม่งั้น vhost จะชี้ไปยังไฟล์ที่ไม่มีอยู่
     * แล้วเว็บทั้งเครื่องจะไม่ขึ้น (Apache ปฏิเสธทั้ง config ไม่ใช่แค่ vhost นั้น)
     */
    public function destroy(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $confirm = trim($request->payloadString('confirm_domain')) ?: trim($request->get('confirm_domain'));

        $this->agent()->data('ssl.delete', [
            'site_id' => $siteId,
            'confirm_domain' => $confirm,
        ], $this->ctx->actor($request));

        return $this->completed('ลบใบรับรองแล้ว', 'certificates', ['site_id' => $siteId]);
    }
}
