<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * เปิดใช้งาน HTTPS / บังคับ HTTPS / ปิด — PROMPT.md ระบุไว้เป็นสามการกระทำ
 *
 * ทั้งสามยุบเป็น capability เดียวเพราะเป็นค่าเดียวกันในฐานข้อมูล (`ssl_mode`)
 * และผลลัพธ์คือการเขียน vhost ใหม่เหมือนกันทุกกรณี แยกเป็นสามตัวจะได้โค้ดซ้ำสามชุด
 * ที่ต้องแก้พร้อมกันทุกครั้ง
 *
 * การเปิดใช้งานโดยไม่มีใบรับรองถูกปฏิเสธที่นี่ ไม่ใช่ปล่อยให้ configtest จับ —
 * ข้อความของ Apache ที่ว่าเปิดไฟล์ .pem ไม่ได้ไม่ได้ช่วยให้ผู้ใช้รู้ว่าต้องทำอะไรต่อ
 */
final class SslSetMode extends SslCapability implements Capability
{
    private const LABELS = [
        'off' => 'ปิด HTTPS',
        'on' => 'เปิดใช้งาน HTTPS',
        'forced' => 'บังคับ HTTPS',
    ];

    public static function name(): string
    {
        return 'ssl.set_mode';
    }

    public function permission(): string
    {
        return 'ssl.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เปิด/ปิด/บังคับ HTTPS ของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'mode' => Validator::requireEnum($args, 'mode', ['off', 'on', 'forced']),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $mode = $args['mode'];

        if ($site->sslMode === $mode) {
            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'ssl_mode' => $mode,
                'changed' => false,
                'message' => sprintf('%s อยู่ในสถานะ "%s" อยู่แล้ว', $site->domain, self::LABELS[$mode]),
            ];
        }

        if ($mode !== 'off') {
            $this->assertCertificateExists($executor, $site);
        }

        $updated = $site->withSslMode($mode);
        $repository = $this->repository($context);

        // บันทึกฐานข้อมูลหลังไฟล์ commit แล้วแต่ก่อน reload — ดูเหตุผลใน rewriteVhost()
        $this->rewriteVhost(
            $context,
            $executor,
            $updated,
            static fn () => $repository->setSslMode($site->id, $mode),
        );

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'ssl_mode' => $mode,
            'previous_mode' => $site->sslMode,
            'changed' => true,
            'message' => match ($mode) {
                'off' => sprintf('ปิด HTTPS ของ %s แล้ว — เว็บให้บริการผ่าน HTTP อย่างเดียว', $site->domain),
                'on' => sprintf('เปิดใช้งาน HTTPS ให้ %s แล้ว — เข้าได้ทั้ง http:// และ https://', $site->domain),
                'forced' => sprintf(
                    'บังคับ HTTPS ให้ %s แล้ว — คำขอทาง HTTP จะถูก redirect ไป HTTPS ทั้งหมด '
                    . 'และเบราว์เซอร์จะจำค่านี้ไว้ 6 เดือน (HSTS)',
                    $site->domain,
                ),
            },
        ];
    }
}
