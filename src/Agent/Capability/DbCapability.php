<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Kernel\Db;
use Phpcp\Security\Permissions;

/**
 * ฐานร่วมของ capability ที่จัดการฐานข้อมูล MariaDB
 *
 * แยกความจริงสองชุดออกจากกันให้ชัด:
 *   - ตาราง databases_ ใน panel.db คือ "ฐานข้อมูลที่ panel รู้จักและดูแล"
 *   - SHOW DATABASES ของ MariaDB คือ "ฐานข้อมูลที่มีอยู่จริงบนเครื่อง"
 * สองอย่างนี้อาจไม่ตรงกันได้ (มีคนสร้างเองผ่าน CLI) หน้าจอจึงต้องแสดงทั้งคู่
 * ไม่ใช่เชื่อฝั่งใดฝั่งหนึ่งแล้วซ่อนความจริงอีกด้าน
 */
abstract class DbCapability implements Capability
{
    /** อายุ cache ขนาดฐานข้อมูล — information_schema แพง ไม่ต้องสดทุกคลิก */
    private const SIZES_CACHE_TTL = 60;

    protected function manager(): MariaDbManager
    {
        return new MariaDbManager();
    }

    /** ไฟล์ cache ผล sizes() ใต้ data ของ panel */
    protected function sizesCachePath(Context $context): string
    {
        return $context->config->paths->data.'/cache/db-sizes.json';
    }

    /**
     * ขนาดฐานข้อมูลพร้อม cache สั้น ๆ — ลดการสแกน information_schema ซ้ำ
     *
     * @return array<string,int>
     */
    protected function cachedSizes(Executor $executor, Context $context): array
    {
        $path = $executor->path($this->sizesCachePath($context));

        if ($executor->exists($path)) {
            try {
                $payload = json_decode($executor->readFile($path), true);
                if (is_array($payload)
                    && isset($payload['at'], $payload['sizes'])
                    && is_array($payload['sizes'])
                    && (time() - (int) $payload['at']) < self::SIZES_CACHE_TTL
                ) {
                    $sizes = [];
                    foreach ($payload['sizes'] as $name => $bytes) {
                        $sizes[(string) $name] = (int) $bytes;
                    }

                    return $sizes;
                }
            } catch (\Throwable) {
                // อ่าน cache ไม่ได้ — ดึงใหม่จาก MariaDB
            }
        }

        $sizes = $this->manager()->sizes($executor);
        $json = json_encode(['at' => time(), 'sizes' => $sizes], JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            try {
                $executor->writeFile($path, $json, 0640);
            } catch (\Throwable) {
                // เขียน cache ไม่ได้ไม่ทำให้รายการล้ม
            }
        }

        return $sizes;
    }

    /** ล้าง cache ขนาดหลังสร้าง/ลบฐานข้อมูลให้ตัวเลขหน้าจอตรงกับเครื่อง */
    protected function invalidateSizesCache(Executor $executor, Context $context): void
    {
        $path = $executor->path($this->sizesCachePath($context));
        if ($executor->exists($path)) {
            try {
                $executor->removePath($path);
            } catch (\Throwable) {
                // ลบไม่ได้ก็ปล่อยให้หมดอายุตาม TTL
            }
        }
    }

    /**
     * ตรวจว่าผู้สั่งงานมีสิทธิ์กับฐานข้อมูลนี้จริง
     *
     * ผู้ดูแลเว็บไซต์แตะได้เฉพาะฐานข้อมูลที่ผูกกับเว็บไซต์ของตัวเอง — กัน IDOR
     * ตรวจที่ agent ด้วยเสมอ ไม่พึ่งการตรวจของชั้นเว็บอย่างเดียว
     */
    protected function assertOwnership(Context $context, string $database): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM databases_ d
             JOIN sites s ON s.id = d.site_id
             WHERE d.db_name = :name AND s.owner_user_id = :user',
            ['name' => $database, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์จัดการฐานข้อมูลนี้');
        }
    }

    /** เว็บไซต์ที่ผูกกับฐานข้อมูลได้ — ผู้ดูแลเว็บไซต์ผูกได้เฉพาะเว็บของตัวเอง */
    protected function assertSiteAccess(Context $context, int $siteId): void
    {
        if ($siteId === 0) {
            return;
        }

        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :user',
            ['id' => $siteId, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์กับเว็บไซต์ที่ระบุ');
        }
    }

    /** @return array<string,mixed> */
    protected function findDatabase(Db $db, string $name): array
    {
        $row = $db->first('SELECT * FROM databases_ WHERE db_name = :n', ['n' => $name]);

        if ($row === null) {
            throw new ValidationError("ไม่พบฐานข้อมูล {$name} ในระบบ");
        }

        return $row;
    }

    /** สุ่มรหัสผ่านฐานข้อมูล — ไม่ใช้อักขระที่ต้อง escape ใน SQL หรือใน connection string */
    protected static function randomPassword(int $length = 24): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
