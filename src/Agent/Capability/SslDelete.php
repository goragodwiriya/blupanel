<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Support\Validator;

/**
 * ลบใบรับรอง
 *
 * ต้องปิด HTTPS ให้ก่อนเสมอ ไม่ใช่ลบไฟล์แล้วค่อยว่ากัน — vhost อ้างถึงไฟล์ใบรับรอง
 * โดยตรง ถ้าลบไฟล์ทิ้งขณะที่ config ยังชี้อยู่ Apache จะไม่ยอมโหลดค่าตั้งทั้งเครื่อง
 * ผลคือเว็บ "ทุกเว็บ" บนเซิร์ฟเวอร์ล่ม ไม่ใช่แค่เว็บที่ลบใบรับรอง
 */
final class SslDelete extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.delete';
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
        return 'ลบใบรับรอง SSL ของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'confirm_domain' => trim((string) ($args['confirm_domain'] ?? '')),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $certificate = $this->assertCertificateExists($executor, $site);

        if ($args['confirm_domain'] !== $site->domain) {
            throw new \Phpcp\Agent\ValidationError(
                'ชื่อโดเมนที่ยืนยันไม่ตรงกับเว็บไซต์ปลายทาง — ยกเลิกเพื่อความปลอดภัย',
            );
        }

        // ลำดับนี้ห้ามสลับ: ปิด HTTPS และเขียน vhost ใหม่ให้เลิกอ้างไฟล์ใบรับรองก่อน
        // แล้วจึงลบไฟล์ ถ้าสลับกันคือ Apache ค้างอยู่กับ config ที่ชี้ไปยังไฟล์ที่ไม่มีแล้ว
        if ($site->sslMode !== 'off') {
            $repository = $this->repository($context);

            $this->rewriteVhost(
                $context,
                $executor,
                $site->withSslMode('off'),
                static fn () => $repository->setSslMode($site->id, 'off'),
            );
        }

        if ($certificate['source'] === 'letsencrypt') {
            $this->certbot()->delete($executor, $site->domain);
        } else {
            $this->certbot()->deleteSelfSigned($executor, $site->domain);
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'source' => $certificate['source'],
            'message' => sprintf(
                'ลบใบรับรองของ %s แล้ว และปิด HTTPS ให้อัตโนมัติเพื่อไม่ให้ค่าตั้งชี้ไปยังไฟล์ที่ไม่มีอยู่',
                $site->domain,
            ),
        ];
    }
}
