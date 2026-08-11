<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * ต่ออายุใบรับรองทันที
 *
 * ปกติไม่ต้องกดเอง — timer ของ certbot ต่อให้อัตโนมัติเมื่อเหลือ 30 วัน
 * ปุ่มนี้มีไว้สำหรับตอนที่เพิ่งเพิ่มโดเมนใหม่เข้าเว็บไซต์ หรือตอนที่การต่ออัตโนมัติ
 * เคยล้มแล้วแก้สาเหตุเสร็จ อยากรู้ผลเดี๋ยวนี้โดยไม่ต้องรอรอบถัดไป
 */
final class SslRenew extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.renew';
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
        return 'ต่ออายุใบรับรอง SSL';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'force' => (bool) ($args['force'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $certbot = $this->certbot();
        $before = $this->assertCertificateExists($executor, $site);

        if ($before['source'] !== 'letsencrypt') {
            throw new ValidationError(
                'ใบรับรองที่เซ็นเองต่ออายุไม่ได้ — สร้างใบใหม่แทนด้วยปุ่มติดตั้ง SSL',
            );
        }

        $certbot->renew($executor, $site->domain, $args['force']);
        $after = $certbot->inspect($executor, $site);

        // vhost ชี้ไปที่ path เดิมอยู่แล้ว แต่ Apache แคชใบรับรองไว้ในหน่วยความจำ
        // ถ้าไม่ reload ผู้เข้าเว็บจะยังได้ใบเก่าจนกว่าจะมีการรีสตาร์ตด้วยเหตุอื่น
        if ($site->sslMode !== 'off') {
            $this->rewriteVhost($context, $executor, $site);
        }

        $changed = $after['expires_at'] > $before['expires_at'];

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'changed' => $changed,
            'certificate' => $after,
            'message' => $changed
                ? sprintf('ต่ออายุใบรับรองของ %s แล้ว — หมดอายุอีกครั้งใน %d วัน', $site->domain, $after['days_left'])
                : sprintf(
                    'ใบรับรองของ %s ยังไม่ถึงกำหนดต่ออายุ (เหลือ %d วัน) จึงยังเป็นใบเดิม '
                    . 'ถ้าต้องการเปลี่ยนใบเดี๋ยวนี้จริง ๆ ให้เลือกบังคับต่ออายุ',
                    $site->domain,
                    $after['days_left'],
                ),
        ];
    }
}
