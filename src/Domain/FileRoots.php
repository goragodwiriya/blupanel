<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Agent\Actor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Paths;
use Phpcp\Security\Permissions;

/**
 * ขอบเขตไฟล์ที่แต่ละบทบาทเข้าถึงได้ — นโยบายการเข้าถึงไฟล์ทั้งระบบอยู่ที่ไฟล์นี้ไฟล์เดียว
 *
 * นโยบาย:
 *   ผู้ดูแลระบบ / ผู้ดูแลเซิร์ฟเวอร์ → ทั้งเครื่อง (/) พร้อมทางลัดที่ใช้บ่อย
 *   ผู้ดูแลเว็บไซต์                  → เฉพาะไดเรกทอรีของเว็บไซต์ที่ตัวเองเป็นเจ้าของ
 *
 * ต้องพูดให้ตรงว่าการเปิดให้เข้าถึงทั้งเครื่องผ่านหน้าเว็บ เท่ากับให้สิทธิ์ใกล้เคียง
 * root shell กับผู้ที่ล็อกอินเป็นผู้ดูแลระบบได้ มาตรการที่เหลือจึงเป็นเรื่อง
 * "กันพลาด" และ "ตามรอยได้" ไม่ใช่การจำกัดสิทธิ์:
 *
 *   - ไฟล์ของ Control Panel เองแตะไม่ได้ ทั้งอ่านและเขียน (กันระบบทำลายตัวเองจนกู้ไม่ได้
 *     และกันการอ่าน panel.db ที่มี hash รหัสผ่านกับ session)
 *   - /proc /sys /dev เปิดอ่านได้แต่เขียนไม่ได้ เพราะเป็น filesystem เสมือนของ kernel
 *   - ทุกการกระทำถูกบันทึกลง audit log ที่เป็น hash chain แก้ย้อนหลังแล้วตรวจจับได้
 *
 * ด้วยเหตุนี้ บัญชีผู้ดูแลระบบจึงต้องเปิด 2FA และควรจำกัด IP ตาม SECURITY §5
 */
final class FileRoots
{
    /** เปิดอ่านได้ แต่เขียนไม่ได้แม้เป็นผู้ดูแลระบบ */
    private const READ_ONLY_PREFIXES = ['/proc', '/sys', '/dev'];

    /**
     * ขอบเขตทั้งหมดที่ actor นี้เปิดได้ เรียงตามลำดับที่ควรแสดงในเมนู
     *
     * @return array<string,FileScope>
     */
    public static function forActor(Actor $actor, Db $db): array
    {
        $scopes = [];

        if (self::isServerAdmin($actor)) {
            $scopes['sites'] = FileScope::forServer('sites', 'เว็บไซต์ทั้งหมด', Paths::usersDir());
            $scopes['server'] = FileScope::forServer('server', 'ทั้งเซิร์ฟเวอร์', '/');
            /*
             * ที่เก็บไฟล์สำรอง — ทางเดียวที่ผู้ดูแลจะ**เห็นของจริง** ไม่ใช่เชื่อหน้าจอ
             *
             * อยู่ใต้ `/var/lib/phpcp` ที่ SelfProtection กันไว้ จึงต้องมี
             * `SelfProtection::allowAlso()` ตอน boot คู่กัน (ดู App::boot) ·
             * ขอบเขต `server` ที่เป็น `/` ครอบเส้นทางนี้อยู่แล้วในทางทฤษฎี แต่ผู้ดูแล
             * ต้องรู้เส้นทางเองถึงจะเดินไปถึง — รายการนี้ทำให้มันเป็นคลิกเดียว
             */
            $scopes['backups'] = FileScope::forServer('backups', 'ไฟล์สำรอง', Paths::backupsDir());
            $scopes['etc'] = FileScope::forServer('etc', 'ค่าตั้งระบบ', '/etc');
            $scopes['varlog'] = FileScope::forServer('varlog', 'Log ระบบ', '/var/log');
            $scopes['varwww'] = FileScope::forServer('varwww', 'เว็บรูทของระบบ', '/var/www');
            $scopes['home'] = FileScope::forServer('home', 'โฮมผู้ใช้', '/home');
            $scopes['tmp'] = FileScope::forServer('tmp', 'ไฟล์ชั่วคราว', '/tmp');
        }

        foreach (self::visibleSites($actor, $db) as $site) {
            // ไฟล์เว็บจริงมาก่อนบ้านของเว็บ — เว็บที่ใช้ Domain Pointer เก็บไฟล์ไว้นอกบ้าน
            // ผู้ใช้เข้าหน้านี้เพื่อแก้ไฟล์เว็บ ไม่ใช่เพื่อดู logs/tmp/backups
            $pointer = FileScope::forSiteDocroot($site);
            if ($pointer !== null) {
                $scopes[$pointer->key] = $pointer;
            }

            $scope = FileScope::forSite($site);
            $scopes[$scope->key] = $scope;
        }

        return $scopes;
    }

    /**
     * เลือกขอบเขตตามคีย์ พร้อมตรวจสิทธิ์
     *
     * ตรวจที่นี่คือด่านเดียวที่ตัดสินว่าใครเปิดอะไรได้ — capability ทุกตัวต้องผ่านทางนี้
     */
    public static function require(Actor $actor, Db $db, string $key): FileScope
    {
        $scopes = self::forActor($actor, $db);

        if (!isset($scopes[$key])) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์เข้าถึงขอบเขตไฟล์นี้');
        }

        return $scopes[$key];
    }

    /** ขอบเขตที่ควรเปิดให้เห็นก่อนเมื่อเข้าหน้าตัวจัดการไฟล์ */
    public static function defaultKey(Actor $actor, Db $db): string
    {
        $scopes = self::forActor($actor, $db);

        if ($scopes === []) {
            throw new PermissionDenied('บัญชีของคุณยังไม่มีขอบเขตไฟล์ที่เข้าถึงได้');
        }

        // ผู้ดูแลระบบเริ่มที่รายการเว็บไซต์เพราะเป็นงานที่ทำบ่อยที่สุด
        // ผู้ดูแลเว็บไซต์เริ่มที่เว็บของตัวเองซึ่งเป็นขอบเขตเดียวที่มี
        return isset($scopes['sites']) ? 'sites' : (string) array_key_first($scopes);
    }

    /**
     * ตรวจว่าเส้นทางนี้เปลี่ยนแปลงได้หรือไม่ — ใช้กับทุกคำสั่งที่เขียน ไม่ใช่แค่ตอนแสดงผล
     */
    public static function assertWritable(string $absolutePath): void
    {
        SelfProtection::assertPath($absolutePath);

        foreach (self::READ_ONLY_PREFIXES as $prefix) {
            if ($absolutePath === $prefix || str_starts_with($absolutePath, $prefix.'/')) {
                throw new ValidationError("{$prefix} เป็น filesystem ของ kernel แก้ไขไม่ได้");
            }
        }
    }

    /** เส้นทางของ panel เองปิดทั้งอ่านและเขียน เพราะ panel.db มี hash รหัสผ่านและ session */
    public static function assertReadable(string $absolutePath): void
    {
        SelfProtection::assertPath($absolutePath);
    }

    /**
     * @param Actor $actor
     */
    private static function isServerAdmin(Actor $actor): bool
    {
        return in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);
    }

    /** @return list<array<string,mixed>> */
    private static function visibleSites(Actor $actor, Db $db): array
    {
        // เส้นทางไฟล์ของเว็บอนุมานจากบ้านของเจ้าของ จึงต้อง join ตาราง users มาด้วยเสมอ
        // เว็บที่เจ้าของยังไม่มีบัญชีระบบ (system_user เป็น NULL) แปลว่ายัง provision ไม่เสร็จ
        // — ไม่ต้องแสดงในตัวจัดการไฟล์เพราะโฟลเดอร์ยังไม่มีอยู่จริง
        // เลย์เอาต์กับโดเมนหลักต้องมาด้วย — FileScope ประกอบเส้นทางจากแถวนี้โดยตรง
        // ขาดไปแปลว่าเจ้าของที่ใช้ cpanel จะถูกมองเป็น phpcp แล้วเปิดไปที่โฟลเดอร์ที่ไม่มีอยู่
        $select = "SELECT s.id, s.primary_domain, s.docroot_override, s.owner_user_id,
                          u.system_user, u.site_layout, u.main_domain
                   FROM sites s JOIN users u ON u.id = s.owner_user_id
                   WHERE u.system_user IS NOT NULL";

        if (self::isServerAdmin($actor)) {
            return $db->all($select.' ORDER BY s.primary_domain');
        }

        // actor ระบบ (CLI/cron) ไม่มี userId — ไม่ผูกกับเว็บไซต์ใด
        if ($actor->userId === 0) {
            return [];
        }

        return $db->all(
            $select.' AND s.owner_user_id = :u ORDER BY s.primary_domain',
            ['u' => $actor->userId],
        );
    }
}
