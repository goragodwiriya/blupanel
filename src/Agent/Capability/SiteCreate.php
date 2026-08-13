<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * สร้างเว็บไซต์ใหม่ — ARCHITECTURE §11
 *
 * ทำ 6 อย่างเป็นชุดเดียว ล้มกลางทางต้องย้อนกลับให้หมด:
 *   1. จองแถวในฐานข้อมูลเพื่อให้ได้ id (ชื่อผู้ใช้ระบบคือ web_<id>)
 *   2. สร้างผู้ใช้ระบบของเว็บไซต์
 *   3. สร้างไดเรกทอรีพร้อมสิทธิ์ 750 และตั้งเจ้าของ
 *   4. เขียน FPM pool และ vhost ลงทรานแซกชัน
 *   5. ตรวจ config ทั้งสองบริการ ผ่านแล้วจึง reload
 *   6. บันทึกผลลงฐานข้อมูล
 *
 * ขั้นที่ 5 คือจุดที่กันไม่ให้ vhost ผิดทำให้เว็บทุกเว็บบนเครื่องดับ
 */
final class SiteCreate extends SiteCapability
{
    public static function name(): string
    {
        return 'site.create';
    }

    public function permission(): string
    {
        return 'site.create';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'สร้างเว็บไซต์ใหม่พร้อมผู้ใช้ระบบ FPM pool และ vhost';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $domain = Validator::domain(Validator::requireString($args, 'domain', 253));
        $name = Validator::optionalString($args, 'name', $domain, 100);
        $phpVersion = self::assertPhpVersion(Validator::requireString($args, 'php_version', 8));

        $aliases = [];
        if (isset($args['aliases'])) {
            foreach (Validator::requireStringList($args, 'aliases', maxItems: 20, maxLength: 253) as $alias) {
                $alias = Validator::domain($alias);

                if ($alias === $domain) {
                    throw new ValidationError('โดเมนสำรองต้องไม่ซ้ำกับโดเมนหลัก');
                }

                $aliases[] = $alias;
            }
        }

        return [
            'domain' => $domain,
            'name' => $name,
            'php_version' => $phpVersion,
            'aliases' => array_values(array_unique($aliases)),
            // ว่าง = ใช้ <บ้าน>/public · ชื่อย่อยหรือ path เต็มถูกประกอบ/ตรวจใน run()
            'docroot' => Validator::optionalString($args, 'docroot', '', 4096),
            'pointer_root' => Validator::optionalString($args, 'pointer_root', '', 4096),
            'owner_user_id' => isset($args['owner_user_id'])
                ? Validator::requireInt($args, 'owner_user_id', 0)
                : 0
        ];
    }

    /**
     * ตรวจสอบโควตาสำหรับลูกค้าก่อนสร้างเว็บไซต์
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $ownerUserId): void
    {
        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, 'domain', 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    /**
     * หาเจ้าของและชื่อบัญชีระบบที่เว็บนี้จะรันด้วย
     *
     * ผู้ใช้ที่ยังไม่เคยมีเว็บจะยังไม่มีบัญชีระบบ — ชื่อบัญชีคือชื่อผู้ใช้ ตรวจซ้ำอีกครั้ง
     * ด้วยกฎเดียวกับตอนสร้างผู้ใช้ เพราะแถวในฐานข้อมูลอาจถูกแก้ด้วยมือมาก่อน
     * และค่านี้กำลังจะกลายเป็นชื่อโฟลเดอร์กับชื่อบัญชี Linux จริง
     *
     * @throws ValidationError
     */
    private function resolveOwner(Context $context, int $ownerUserId): UserAccount
    {
        if ($ownerUserId <= 0) {
            throw new ValidationError('ต้องระบุเจ้าของเว็บไซต์ (owner_user_id)');
        }

        $user = (new UserRepository($context->db))->find($ownerUserId);

        if ($user === null) {
            throw new ValidationError("ไม่พบผู้ใช้รหัส {$ownerUserId} ที่จะเป็นเจ้าของเว็บไซต์");
        }

        try {
            return UserAccount::fromRow($user);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * บันทึกว่าผู้ใช้คนนี้มีบัญชีระบบแล้ว พร้อม uid/gid ที่ระบบปฏิบัติการให้มาจริง
     *
     * เก็บ uid ไว้เพื่อให้ตัวตรวจสภาพและโควตาดิสก์ (เฟส E2) อ้างอิงได้โดยไม่ต้องถาม OS ซ้ำ
     *
     * @param array{uid:int,gid:int} $identity
     */
    private function rememberSystemUser(Context $context, UserAccount $owner, array $identity): void
    {
        $context->db->update('users', [
            'system_user' => $owner->username,
            'uid' => $identity['uid'],
            'gid' => $identity['gid'],
            'updated_at' => time(),
        ], ['id' => $owner->userId]);
    }

    /**
     * ตรึงโดเมนหลักของบัญชีไว้ที่เว็บแรก — ตัวตัดสินว่าใครได้ `public_html`
     *
     * **เขียนเฉพาะตอนที่ยังว่าง** เพราะค่านี้คือเส้นทางไฟล์ของเว็บที่ให้บริการอยู่
     * ในเลย์เอาต์ cpanel · ถ้าเขียนทับทุกครั้งที่สร้างเว็บใหม่ เว็บเดิมจะย้ายจาก
     * `public_html` ไปเป็น `<บ้าน>/<โดเมน>` เงียบ ๆ แล้วเว็บที่เคยใช้งานได้จะ 404
     * ทันทีโดยที่ไม่มีใครสั่งอะไรกับมันเลย
     *
     * มีความหมายเฉพาะเลย์เอาต์ cpanel แต่บันทึกทุกเลย์เอาต์ เพราะบัญชีที่เปลี่ยนมา
     * ใช้ cpanel ภายหลังต้องมีคำตอบพร้อมอยู่แล้ว ไม่ใช่ต้องเดาย้อนหลังตอนนั้น
     */
    private function rememberMainDomain(Context $context, UserAccount $owner, string $domain): void
    {
        if ($owner->mainDomain !== '') {
            return;
        }

        $context->db->run(
            "UPDATE users SET main_domain = :d, updated_at = :t WHERE id = :id AND main_domain = ''",
            ['d' => $domain, 't' => time(), 'id' => $owner->userId],
        );
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        if ($repository->domainExists($args['domain'])) {
            throw new ValidationError("โดเมน {$args['domain']} ถูกใช้งานอยู่แล้วในระบบ");
        }

        foreach ($args['aliases'] as $alias) {
            if ($repository->domainExists($alias)) {
                throw new ValidationError("โดเมนสำรอง {$alias} ถูกใช้งานอยู่แล้วในระบบ");
            }
        }

        if (!$provisioner->fpm()->isVersionInstalled($executor, $args['php_version'])) {
            throw new ValidationError("เครื่องนี้ยังไม่ได้ติดตั้ง PHP {$args['php_version']}");
        }

        // Domain Pointer — รับทั้งชื่อโฟลเดอร์ย่อยและ path เต็ม แล้วบังคับให้อยู่ในขอบเขต
        // จาก config เท่านั้น มิฉะนั้นผู้ดูแลจะชี้ vhost ไปที่ /etc หรือ /root ได้
        $docroot = Validator::resolvePointerDocroot(
            $args['docroot'],
            $context->config->docrootRoots(),
            $context->config->list('sites.pointer_roots'),
            $args['pointer_root'] ?? '',
        );

        // เว็บทุกแห่งต้องมีเจ้าของตั้งแต่ migration 0006 — เส้นทางไฟล์ทั้งหมดของเว็บ
        // อนุมานจากบ้านของเจ้าของ การสร้างเว็บโดยไม่รู้เจ้าของจึงประกอบเส้นทางไม่ได้เลย
        $owner = $this->resolveOwner($context, $args['owner_user_id']);

        $this->assertQuota($context, $owner->userId);

        $siteId = $repository->reserve(
            $args['name'],
            $args['domain'],
            $args['php_version'],
            $owner->userId,
            $docroot,
        );

        $site = new Site(
            id: $siteId,
            name: $args['name'],
            domain: $args['domain'],
            owner: $owner,
            phpVersion: $args['php_version'],
            aliases: $args['aliases'],
            docrootOverride: $docroot,
        );

        $transaction = new ConfigTransaction($executor);

        try {
            // บัญชีระบบถูกสร้างแบบ lazy — เว็บแรกของผู้ใช้เป็นตัวจุดชนวน เว็บถัด ๆ ไป
            // เรียกซ้ำได้โดยไม่มีผลข้างเคียง เพราะ ensure() เป็น idempotent
            $identity = $provisioner->account()->ensure($executor, $owner);
            $this->rememberSystemUser($context, $owner, $identity);
            $this->rememberMainDomain($context, $owner, $site->domain);

            $provisioner->createDirectories($executor, $site);

            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($owner->userId, $site->phpVersion),
            );
            $transaction->commit(static fn(): array=> $provisioner->validate($executor, $site));

            $provisioner->reload($executor, $site);

            $repository->completeProvisioning($site);
            $this->recordDomains($context, $site);

            if ($this->isLocalEnvironment($executor, $context)) {
                if (str_ends_with($site->domain, '.test')) {
                    $this->updateHostsFile($executor, $site->domain, true);
                }
                foreach ($site->aliases as $alias) {
                    if (str_ends_with($alias, '.test')) {
                        $this->updateHostsFile($executor, $alias, true);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ย้อนกลับให้ครบทุกชั้น มิฉะนั้นจะเหลือผู้ใช้ระบบหรือแถวในฐานข้อมูลค้าง
            // ที่ทำให้สร้างโดเมนเดิมซ้ำไม่ได้อีกเลย
            // ย้อนเฉพาะสิ่งที่คำสั่งนี้สร้าง — **ห้ามลบบัญชีระบบทิ้ง** เพราะเจ้าของอาจมีเว็บอื่น
            // ที่ยังใช้บัญชีนั้นอยู่ การลบจะทำให้เว็บที่ยังดีอยู่ล่มไปด้วยทันที
            $transaction->rollback();
            $repository->delete($siteId);

            throw $e instanceof ExecutionFailed || $e instanceof ValidationError
                ? $e
                : new ExecutionFailed('สร้างเว็บไซต์ไม่สำเร็จ: '.$e->getMessage());
        }

        return [
            'site_id' => $siteId,
            'domain' => $site->domain,
            'name' => $site->name,
            'php_version' => $site->phpVersion,
            'system_user' => $site->systemUser(),
            'docroot' => $site->docroot(),
            'fpm_socket' => $site->fpmSocket(),
            'vhost' => $provisioner->webserver()->vhostPath($site),
            'aliases' => $site->aliases,
            'message' => "สร้างเว็บไซต์ {$site->domain} เรียบร้อยแล้ว"
        ];
    }

    /** บันทึกโดเมนหลักและโดเมนสำรองลงตาราง domains */
    private function recordDomains(Context $context, Site $site): void
    {
        $now = time();

        $context->db->insert('domains', [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'type' => 'primary',
            'created_at' => $now
        ]);

        foreach ($site->aliases as $alias) {
            $context->db->insert('domains', [
                'site_id' => $site->id,
                'domain' => $alias,
                'type' => 'alias',
                'created_at' => $now
            ]);
        }
    }
}
