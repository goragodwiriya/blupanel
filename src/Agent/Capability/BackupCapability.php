<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Security\Permissions;

/**
 * ฐานร่วมของ capability ตระกูล backup.* — **ทุกตัวทำงานบนบ้านของเจ้าของข้อมูล**
 *
 * ตั้งแต่ PLAN-BACKUP-V2 ไฟล์สำรองไม่ได้อยู่ในพื้นที่ของ panel อีกแล้ว แต่อยู่ที่
 * `<บ้าน>/backup` ของลูกค้า · สิ่งที่ตามมาคือคำถามสามข้อที่ capability ทุกตัวต้องตอบ
 * ให้เหมือนกันเป๊ะ และเคยตอบไม่เหมือนกันมาแล้วเมื่อคำตอบกระจายอยู่ในแต่ละไฟล์:
 *
 *   1. **บัญชีนี้เป็นของใคร** — ผู้ดูแลทำแทนได้ทุกบัญชี · ลูกค้าทำได้เฉพาะของตัวเอง
 *   2. **เจ้าของไฟล์ที่สร้างขึ้นคือใคร** — agent เป็น root ไฟล์ที่มันสร้างจึงเป็นของ
 *      root ถ้าไม่สั่ง · ลูกค้าจะดาวน์โหลดสำเนาของตัวเองไม่ได้ ซึ่งล้มเป้าหมายทั้งหมด
 *   3. **โควตายังพอไหม** — ไฟล์สำรองนับในโควตาของลูกค้าแล้ว การเขียนต่อทั้งที่เต็ม
 *      คือการเบียดพื้นที่ที่เว็บของเขาต้องใช้ทำงาน
 */
abstract class BackupCapability
{
    /**
     * บัญชีเจ้าของโฟลเดอร์สำรอง พร้อมตรวจสิทธิ์ของผู้เรียก
     *
     * @throws ValidationError|PermissionDenied
     */
    protected function ownerAccount(Context $context, int $userId): UserAccount
    {
        $actor = $context->actor;

        if (!self::isAdmin($actor->role) && $actor->userId !== 0 && $userId !== $actor->userId) {
            // "ไม่พบ" ไม่ใช่ "ห้ามเข้าถึง" — คำตอบที่ต่างกันบอกผู้เรียกว่ารหัสไหนมีอยู่จริง
            throw new ValidationError("ไม่พบบัญชีโฮสติ้งรหัส {$userId}");
        }

        $user = $context->db->first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null || ($user['system_user'] ?? null) === null) {
            throw new ValidationError("ไม่พบบัญชีโฮสติ้งรหัส {$userId}");
        }

        return UserAccount::fromRow($user);
    }

    /** โดเมนทุกชื่อของบัญชีนี้ — ใช้จับว่าไฟล์สำรองเป็นของเว็บไหน */
    protected function domainsOf(Context $context, int $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['primary_domain'],
            $context->db->all(
                'SELECT primary_domain FROM sites WHERE owner_user_id = :u ORDER BY primary_domain',
                ['u' => $userId],
            ),
        );
    }

    /**
     * เว็บไซต์ที่ผู้เรียกมีสิทธิ์แตะ
     *
     * @throws ValidationError|PermissionDenied
     */
    protected function siteFor(Context $context, int $siteId): Site
    {
        $repository = new SiteRepository($context->db);
        $site = $repository->load($siteId);

        if ($site === null) {
            throw new ValidationError('ไม่พบเว็บไซต์ที่ระบุ');
        }

        $actor = $context->actor;

        if (!self::isAdmin($actor->role)
            && $actor->userId !== 0
            && !$repository->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์จัดการข้อมูลสำรองของเว็บไซต์นี้');
        }

        return $site;
    }

    /**
     * เจ้าของที่ไฟล์สำรองต้องเป็น (`ผู้ใช้:กลุ่ม`) · ค่าว่าง = ข้าม chown
     *
     * ชุดเดียวกับที่ `site.reset_owner` และการกู้คืนใช้ — กลุ่มเป็นกลุ่มของเว็บเซิร์ฟเวอร์
     * ไม่ใช่กลุ่มของผู้ใช้ (เหตุผลเต็มอยู่ที่ `SiteProvisioner::ownershipTargets()`)
     *
     * shared_owner แปลว่า filesystem เก็บเจ้าของไฟล์ไม่ได้อยู่แล้ว จึงข้ามไปเลย
     * แทนที่จะปล่อยให้ chown ล้มแล้วทำทั้งคำสั่งพัง
     */
    public static function ownerString(Context $context, UserAccount $owner): string
    {
        if ($context->config->sharedOwner()) {
            return '';
        }

        return $owner->username . ':' . SiteCapability::provisionerFor($context)->webserver()->runAsGroup();
    }

    /**
     * โควตาดิสก์ของบัญชีนี้ยังเหลือพอให้เขียนไฟล์สำรองอีกไฟล์หรือไม่
     *
     * **ตรวจก่อนสร้าง ไม่ใช่ตอนดิสก์เต็ม** — ไฟล์สำรองของเว็บมีขนาดเท่าเว็บทั้งเว็บ
     * การปล่อยให้เขียนไปเรื่อย ๆ จนเต็มแปลว่าเว็บที่ยังให้บริการอยู่เขียน session,
     * แคช หรือไฟล์อัปโหลดไม่ได้กลางคัน ซึ่งเสียหายกว่าการไม่มีสำเนาของคืนนี้มาก
     *
     * ข้อความต้องบอก "เหลือเท่าไร" ไม่ใช่แค่ "เต็ม" — ลูกค้าต้องตัดสินใจได้ว่าจะลบ
     * ไฟล์เก่ากี่ไฟล์หรือขอขยายโควตาเท่าไร โดยไม่ต้องเดา
     *
     * @throws ValidationError
     */
    protected function assertQuotaAllows(Context $context, UserAccount $owner): void
    {
        $row = $context->db->first(
            'SELECT role, disk_quota_mb, disk_used_mb FROM users WHERE id = :id',
            ['id' => $owner->userId],
        );

        if ($row === null || ($row['role'] ?? '') !== Permissions::WEBADMIN) {
            // บัญชีผู้ดูแลไม่ถูกจำกัดโควตา — กฎเดียวกับ QuotaChecker::checkOwnerCanCreate()
            return;
        }

        $limit = (int) ($row['disk_quota_mb'] ?? Quota::UNLIMITED);

        if (Quota::isUnlimited($limit)) {
            return;
        }

        $used = (int) ($row['disk_used_mb'] ?? 0);
        $free = $limit - $used;

        if ($free > 0) {
            return;
        }

        throw new ValidationError(sprintf(
            'พื้นที่ของบัญชี %s เต็มแล้ว (ใช้ %s MB จาก %s MB) — ไฟล์สำรองนับในโควตาด้วย'
            . ' จึงต้องลบไฟล์สำรองเก่าหรือขยายโควตาก่อน',
            $owner->username,
            number_format($used),
            number_format($limit),
        ));
    }

    protected static function isAdmin(string $role): bool
    {
        return in_array($role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);
    }

    /**
     * บัญชีโฮสติ้งทุกบัญชีที่ผู้เรียกมีสิทธิ์เห็น
     *
     * @return list<UserAccount>
     */
    protected function visibleAccounts(Context $context): array
    {
        $actor = $context->actor;
        $sql = 'SELECT * FROM users WHERE system_user IS NOT NULL';
        $params = [];

        if (!self::isAdmin($actor->role) && $actor->userId !== 0) {
            $sql .= ' AND id = :id';
            $params['id'] = $actor->userId;
        }

        return array_map(
            static fn (array $row): UserAccount => UserAccount::fromRow($row),
            $context->db->all($sql . ' ORDER BY username', $params),
        );
    }

    /** ไฟล์นี้มีอยู่จริงไหม — โฟลเดอร์เป็นของลูกค้า เขาลบทิ้งเองได้ทุกเมื่อ */
    protected function assertFileExists(Executor $executor, string $path): void
    {
        if (!$executor->exists($executor->path($path))) {
            throw new ValidationError(
                'ไม่พบไฟล์ ' . basename($path) . ' ในโฟลเดอร์สำรองแล้ว — อาจถูกลบไปหลังจากหน้าจอนี้ถูกเปิด',
            );
        }
    }
}
