<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * กันไม่ให้ Control Panel จัดการตัวเอง — ARCHITECTURE §5.3
 *
 * เหตุผล: panel รันบน stack ของตัวเองที่แยกจากระบบที่มันบริหาร (§5.2)
 * ถ้าปล่อยให้สั่งหยุดบริการของตัวเองได้ ผู้ใช้จะล็อกตัวเองออกโดยไม่มีทางกลับ
 *
 * ตรวจที่ชั้น 2 ไม่ใช่ที่ UI — ต่อให้ยิง API ตรงหรือ UI มีบั๊กจนเรนเดอร์ปุ่มออกมา
 * คำสั่งก็ถูกปฏิเสธที่นี่
 */
final class SelfProtection
{
    /** systemd unit ของ panel เอง (ไม่ต้องใส่ .service) */
    private const UNITS = [
        'phpcp-agentd',
        'phpcp-web',
        'phpcp-fpm',
        // ตัวจับเวลาและงานของมัน — ถ้าหยุดได้จากหน้าเว็บ กลไกคืนค่าอัตโนมัติจะเงียบไป
        // โดยที่ผู้ดูแลยังเห็นหน้าจอปกติทุกอย่าง ซึ่งเป็นสภาพที่อันตรายที่สุด
        'phpcp-scheduler',
        'phpcp-scheduler.timer',
    ];

    /** ไดเรกทอรีของ panel เอง */
    private const PATHS = [
        '/etc/phpcp',
        '/var/lib/phpcp',
        '/usr/share/phpcp',
        '/var/log/phpcp',
        '/run/phpcp',
    ];

    /** system user ที่ห้ามแตะ */
    private const USERS = [
        'phpcp-web',
        'root',
        'daemon',
        'bin',
        'sys',
        'www-data',
    ];

    /*
     * **ไม่มีข้อยกเว้น** — เคยมี `allowAlso()` ที่เจาะรูให้ `/var/lib/phpcp/backups`
     * เปิดผ่านตัวจัดการไฟล์ได้ (commit dc4425e) เพราะไฟล์สำรองไปกองอยู่ในพื้นที่ของ
     * panel · ข้อยกเว้นนั้นเกือบกลายเป็นทางเข้าถึง `panel.db` ผ่าน `..` และต้องมี
     * เทสต์เฝ้าไว้ตลอดว่ามันแคบพอ
     *
     * ตั้งแต่ไฟล์สำรองย้ายไปอยู่ `<บ้าน>/backup` ของลูกค้า (PLAN-BACKUP-V2 §4.1)
     * ไม่มีอะไรของผู้ใช้เหลืออยู่ใต้ไดเรกทอรีของ panel อีกแล้ว · รูนั้นจึงถูกถมกลับ
     * **การกันจึงกลับไปเป็นกฎที่ไม่มีเงื่อนไข** ซึ่งเป็นรูปแบบเดียวที่ตรวจสอบได้ง่ายจริง
     * · ถ้าวันหนึ่งมีของที่ต้องเปิด ให้ย้ายของนั้นออกจากพื้นที่ของ panel ไม่ใช่เจาะรูใหม่
     */

    /** @var list<string> path เพิ่มเติมที่ตั้งค่าตอน bootstrap (เช่น layout แบบ portable) */
    private static array $extraPaths = [];

    /** ลงทะเบียน path ของ panel เพิ่ม ใช้ตอน layout เป็น portable ซึ่ง path ไม่ได้อยู่ที่ /etc */
    public static function protectAlso(string ...$paths): void
    {
        foreach ($paths as $path) {
            $normalized = rtrim($path, '/');
            if ($normalized !== '' && !in_array($normalized, self::$extraPaths, true)) {
                self::$extraPaths[] = $normalized;
            }
        }
    }

    /** @return list<string> */
    public static function protectedPaths(): array
    {
        return array_values(array_unique([...self::PATHS, ...self::$extraPaths]));
    }

    /** @return list<string> */
    public static function protectedUnits(): array
    {
        return self::UNITS;
    }

    public static function isProtectedUnit(string $unit): bool
    {
        $name = str_ends_with($unit, '.service') ? substr($unit, 0, -8) : $unit;

        return in_array($name, self::UNITS, true);
    }

    public static function assertUnit(string $unit): void
    {
        if (self::isProtectedUnit($unit)) {
            throw new ProtectedResource(
                'ไม่สามารถจัดการบริการของ Control Panel เองได้ — ใช้คำสั่ง `phpcp self:restart` ที่หน้าเครื่องแทน'
            );
        }
    }

    public static function isProtectedPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // ต้องเทียบหลัง resolve symlink เพราะ /tmp/x -> /etc/phpcp ต้องถูกจับได้ด้วย
        $resolved = realpath($path);
        $candidates = $resolved === false ? [$path] : [$path, $resolved];

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/');

            foreach (self::protectedPaths() as $protected) {
                if ($candidate === $protected || str_starts_with($candidate . '/', $protected . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function assertPath(string $path): void
    {
        if (self::isProtectedPath($path)) {
            throw new ProtectedResource('เส้นทางนี้เป็นของ Control Panel ไม่อนุญาตให้แก้ไข');
        }
    }

    public static function isProtectedUser(string $user): bool
    {
        return in_array($user, self::USERS, true);
    }

    public static function assertUser(string $user): void
    {
        if (self::isProtectedUser($user)) {
            throw new ProtectedResource("ไม่อนุญาตให้จัดการผู้ใช้ระบบ: {$user}");
        }
    }

    /**
     * กรอง service ของ panel ออกจากรายการก่อนส่งไปแสดงผล
     * บริการของ panel จึงไม่ปรากฏในหน้า Services เลย ไม่ใช่แค่ซ่อนปุ่ม
     *
     * @param list<string> $units
     * @return list<string>
     */
    public static function filterUnits(array $units): array
    {
        return array_values(array_filter(
            $units,
            static fn (string $unit): bool => !self::isProtectedUnit($unit),
        ));
    }
}
