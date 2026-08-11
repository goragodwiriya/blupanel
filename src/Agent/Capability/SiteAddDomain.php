<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserRepository;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * เพิ่มโดเมนย่อยหรือ alias ทีละรายการ โดยไม่ทับรายการเดิม
 *
 * รับได้ทั้งชื่อย่อยอย่างเดียว (shop → shop.example.com) และ FQDN เต็ม
 * แล้วเขียน vhost ใหม่ให้มี ServerAlias ครบทั้งชุด
 */
final class SiteAddDomain extends SiteCapability
{
    private const MAX_DOMAINS = 50;

    public static function name(): string
    {
        return 'site.add_domain';
    }

    public function permission(): string
    {
        return 'domain.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เพิ่มโดเมนย่อยหรือ alias ให้เว็บไซต์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $path = Validator::optionalString($args, 'path', '', 253);
        $path = trim($path);
        if ($path !== '') {
            $path = '/'.ltrim($path, '/');
            if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $path) === 1 || str_contains($path, '..')) {
                throw new ValidationError('รูปแบบพาธปลายทางไม่ถูกต้อง');
            }
        }
        $host = Validator::requireString($args, 'host', 253);

        // wildcard ต้องแยกชนิดตั้งแต่ตรงนี้ (PLAN-V2 เฟส E7) เพราะมันเปลี่ยนสามอย่าง:
        // วิธีขอใบรับรอง (DNS-01 เท่านั้น) · ลำดับไฟล์ vhost (ต้องท้ายสุด) ·
        // และความหมายด้านความปลอดภัยที่หน้าจอต้องเตือน
        //
        // ชี้ไปพาธย่อยไม่ได้ — `*.example.com` ครอบชื่อที่ยังไม่มีใครจด การผูกกับ
        // โฟลเดอร์เดียวจึงไม่มีความหมาย และ subdomainPaths ก็ค้นด้วยชื่อเต็มเท่านั้น
        if (str_starts_with(trim(strtolower($host)), '*.')) {
            if ($path !== '' && $path !== '/') {
                throw new ValidationError('โดเมน wildcard ชี้ไปยังพาธย่อยไม่ได้ — ใช้ได้เฉพาะรากของเว็บไซต์');
            }

            return [
                'site_id' => Validator::requireInt($args, 'site_id', 1),
                'host' => $host,
                'path' => '',
                'type' => 'wildcard',
            ];
        }

        $type = ($path === '' || $path === '/') ? 'alias' : 'subdomain';

        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'host' => $host,
            'path' => $path,
            'type' => $type
        ];
    }

    /**
     * ตรวจสอบโควตาของลูกค้าเจ้าของ site ก่อนเพิ่มโดเมนย่อย/alias
     *
     * MAX_DOMAINS ข้างบนเป็นเพดานความปลอดภัยระดับระบบต่อ site เดียว ไม่เกี่ยวกับ
     * แพ็กเกจของลูกค้า — อันนี้คือโควตาจริงตามแพ็กเกจ (quota_subdomains/quota_aliases)
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $siteId, string $type): void
    {
        $site = $context->db->first('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);
        if ($site === null) {
            return;
        }

        $ownerUserId = (int) ($site['owner_user_id'] ?? 0);

        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, $type, 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $domain = self::resolveHost((string) $args['host'], $site->domain);

        if ($domain === $site->domain) {
            throw new ValidationError('โดเมนหลักอยู่ในรายการอยู่แล้ว ไม่ต้องเพิ่มซ้ำ');
        }

        if (in_array($domain, $site->aliases, true)) {
            throw new ValidationError("โดเมน {$domain} ถูกผูกกับเว็บไซต์นี้อยู่แล้ว");
        }

        if (count($site->aliases) >= self::MAX_DOMAINS) {
            throw new ValidationError('เว็บไซต์นี้มีโดเมนย่อย/alias ครบจำนวนสูงสุดแล้ว');
        }

        $this->assertQuota($context, $site->id, $args['type']);

        $owner = $context->db->first('SELECT site_id FROM domains WHERE domain = :d', ['d' => $domain]);
        if ($owner !== null && (int) $owner['site_id'] !== $site->id) {
            throw new ValidationError("โดเมน {$domain} ถูกใช้งานโดยเว็บไซต์อื่นอยู่แล้ว");
        }

        $aliases = [ ...$site->aliases, $domain];
        $subdomainPaths = $site->subdomainPaths;
        if ($args['type'] === 'subdomain') {
            $subdomainPaths[$domain] = $args['path'];
        }

        $updated = new Site(
            id: $site->id,
            name: $site->name,
            domain: $site->domain,
            owner: $site->owner,
            phpVersion: $site->phpVersion,
            sslMode: $site->sslMode,
            status: $site->status,
            aliases: $aliases,
            memoryLimitMb: $site->memoryLimitMb,
            uploadLimitMb: $site->uploadLimitMb,
            maxChildren: $site->maxChildren,
            docrootOverride: $site->docrootOverride,
            subdomainPaths: $subdomainPaths,
        );

        $provisioner = $this->provisioner($context);

        // หากเป็นโดเมนย่อยที่มีการกำหนดพาธ ให้สร้างโฟลเดอร์ไว้รอ
        if ($args['type'] === 'subdomain') {
            $subdoc = $site->docroot().'/'.ltrim($args['path'], '/');
            if (!$executor->exists($executor->path($subdoc))) {
                $executor->makeDirectory($executor->path($subdoc), 0750);

                $owner = $site->systemUser().':'.$provisioner->webserver()->runAsGroup();
                $executor->exec([
                    '/usr/bin/chown',
                    '-R',
                    $owner,
                    $executor->path($subdoc)
                ], timeout: 20);
            }
        }

        $transaction = new ConfigTransaction($executor);
        $provisioner->stageVhost($transaction, $updated, $executor);
        $transaction->commit(static fn(): array=> $provisioner->webserver()->testConfig($executor));
        $provisioner->webserver()->reload($executor);

        $context->db->insert('domains', [
            'site_id' => $site->id,
            'domain' => $domain,
            'type' => $args['type'],
            'redirect_target' => $args['type'] === 'subdomain' ? $args['path'] : null,
            'created_at' => time()
        ]);

        if ($this->isLocalEnvironment($executor, $context)) {
            if (str_ends_with($domain, '.test')) {
                $this->updateHostsFile($executor, $domain, true);
            }
        }

        $label = $args['type'] === 'subdomain' ? 'โดเมนย่อย' : 'alias';

        return [
            'site_id' => $site->id,
            'domain' => $domain,
            'type' => $args['type'],
            'message' => "เพิ่ม{$label} {$domain} ให้ {$site->domain} แล้ว"
        ];
    }

    /**
     * ชื่อย่อยอย่างเดียว → ต่อท้ายโดเมนหลัก · มีจุดอยู่แล้ว → ใช้เป็น FQDN
     */
    public static function resolveHost(string $host, string $primary): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new ValidationError('ต้องระบุชื่อโดเมนย่อย');
        }

        // `*.example.com` — ตรวจส่วนที่เหลือด้วยกฎเดียวกับโดเมนปกติ แล้วประกอบกลับ
        // ไม่ผ่อนกฎของ Validator::domain() เพราะค่านั้นถูกเอาไปประกอบชื่อไฟล์ที่อื่นด้วย
        if (str_starts_with($host, '*.')) {
            return '*.' . Validator::domain(substr($host, 2));
        }

        if (str_contains($host, '.')) {
            return Validator::domain($host);
        }

        // label เดี่ยว เช่น www, shop, api
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host) !== 1) {
            throw new ValidationError('ชื่อโดเมนย่อยต้องเป็นตัวอักษร ตัวเลข หรือขีดกลางเท่านั้น');
        }

        return Validator::domain($host.'.'.$primary);
    }
}
