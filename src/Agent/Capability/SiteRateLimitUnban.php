<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * ปลดแบน IP ที่ถูกจำกัดอัตราของเว็บไซต์หนึ่ง — PLAN-V2 เฟส E5
 *
 * **ต้องมี ไม่ใช่ของแถม** — การแบนของ fail2ban สั่งที่ firewall ซึ่งไม่รู้จัก vhost
 * IP ที่ถูกแบนจึงเข้า**ทุกเว็บบนเครื่อง**ไม่ได้ รวมถึงหน้า panel เอง · ผู้ดูแลที่
 * ทดสอบเว็บตัวเองแรงไปหน่อยแล้วโดนแบน จะเข้ามาแก้จากหน้าเว็บไม่ได้เลยถ้าไม่มีปุ่มนี้
 * (ต้องหาทางเข้าเครื่องทางอื่นซึ่งอาจไม่มี)
 */
final class SiteRateLimitUnban extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_unban';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ปลดแบน IP ที่ถูกจำกัดอัตรา';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // ตรวจรูปแบบตั้งแต่ที่นี่ ไม่ปล่อยให้ไปถึงอาร์กิวเมนต์ของ fail2ban-client
            'ip' => Validator::ipAddress(Validator::requireString($args, 'ip', 45)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $manager = new Fail2banManager($executor);

        $manager->unban($site, $args['ip']);

        return [
            'site_id' => $args['site_id'],
            'ip' => $args['ip'],
            'status' => $manager->status($site),
            'message' => sprintf('ปลดแบน %s แล้ว', $args['ip']),
        ];
    }
}
