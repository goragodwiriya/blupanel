<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Security\Permissions;

/**
 * ใบรับรองของทุกเว็บไซต์ที่ผู้เรียกมีสิทธิ์เห็น — อ่านอย่างเดียว
 *
 * อ่านจากไฟล์ใบรับรองจริงทีละเว็บ ไม่แคชไว้ในฐานข้อมูลของ panel
 * เพราะใบรับรองถูกต่ออายุโดย timer ของ certbot ซึ่งไม่ผ่าน panel เลย
 * ถ้าเก็บสำเนาไว้ หน้าจอจะแสดงวันหมดอายุเก่าค้างหลังการต่ออายุอัตโนมัติทุกครั้ง
 */
final class SslList extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.list';
    }

    public function permission(): string
    {
        return 'ssl.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ดูใบรับรอง SSL ของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $certbot = $this->certbot();
        $isAdmin = in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);

        $rows = $isAdmin || $context->actor->userId === 0
            ? $context->db->all('SELECT id FROM sites ORDER BY primary_domain')
            : $context->db->all(
                'SELECT id FROM sites WHERE owner_user_id = :u ORDER BY primary_domain',
                ['u' => $context->actor->userId],
            );

        $sites = [];
        $expiring = 0;

        foreach ($rows as $row) {
            $site = $this->repository($context)->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            $certificate = $certbot->inspect($executor, $site);

            if (in_array($certificate['status'], ['expiring', 'expired'], true)) {
                $expiring++;
            }

            $sites[] = [
                'site_id' => $site->id,
                'name' => $site->name,
                'domain' => $site->domain,
                'aliases' => $site->aliases,
                'ssl_mode' => $site->sslMode,
                'certificate' => $certificate,
            ];
        }

        return [
            'sites' => $sites,
            'expiring' => $expiring,
            'certbot_installed' => $certbot->isInstalled($executor),
            'auto_renew_active' => $certbot->autoRenewActive($executor),
            'warn_days' => CertbotManager::WARN_DAYS,
        ];
    }
}
