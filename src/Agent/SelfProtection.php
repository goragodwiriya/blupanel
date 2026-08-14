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

    /**
     * เส้นทางที่อยู่**ใต้**ไดเรกทอรีที่กันไว้ แต่เปิดให้เข้าถึงได้โดยเจตนา
     *
     * `/var/lib/phpcp` ถูกกันทั้งก้อนเพราะ `panel.db` อยู่ในนั้น (hash รหัสผ่านและ
     * session) · แต่ `backups/` ที่อยู่ข้างในไม่ใช่ข้อมูลของ panel — มันคือ**ไฟล์ของ
     * ผู้ใช้** ที่ผู้ดูแลต้องเข้าถึงได้จริง: ตรวจว่าไฟล์สำรองมีอยู่ไหม ขนาดเท่าไร
     * คัดลอกออกไปเอง หรือวางไฟล์ที่ได้จากเครื่องอื่นเข้ามาเพื่อนำเข้า
     *
     * ไม่มีรายการนี้ ตัวจัดการไฟล์จะเปิดไปที่นั่นไม่ได้เลย และผู้ดูแลไม่มีทางรู้ว่า
     * ไฟล์สำรองของตัวเองอยู่ที่ไหนหรือมีอยู่จริงหรือเปล่า นอกจากเชื่อหน้าจอ
     *
     * **แคบไว้เสมอ** — เปิดเฉพาะไดเรกทอรีที่ระบุ ไม่ใช่ทั้งชั้นบน · เพิ่มรายการที่นี่
     * เท่ากับประกาศว่า "สิ่งนี้ไม่ใช่ของ panel" ซึ่งต้องจริงเท่านั้น
     *
     * @var list<string>
     */
    private static array $exceptions = [];

    /** @var list<string> path เพิ่มเติมที่ตั้งค่าตอน bootstrap (เช่น layout แบบ portable) */
    private static array $extraPaths = [];

    /**
     * ประกาศว่าเส้นทางนี้ไม่ใช่ของ panel แม้จะอยู่ใต้ไดเรกทอรีที่กันไว้
     *
     * เรียกตอน bootstrap เหมือน `protectAlso()` — เส้นทางของไฟล์สำรองต่างกันตาม
     * layout (system/portable) จึงตรึงเป็นค่าคงที่ไม่ได้
     */
    public static function allowAlso(string ...$paths): void
    {
        foreach ($paths as $path) {
            $normalized = rtrim($path, '/');

            if ($normalized !== '' && !in_array($normalized, self::$exceptions, true)) {
                self::$exceptions[] = $normalized;
            }
        }
    }

    /** @return list<string> */
    public static function allowedPaths(): array
    {
        return self::$exceptions;
    }

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

            /*
             * ข้อยกเว้นชนะการกัน — แต่**ห้ามมี `..` เด็ดขาด**
             *
             * `/var/lib/phpcp/backups/../panel.db` ขึ้นต้นด้วยเส้นทางของข้อยกเว้นทุก
             * ตัวอักษร แต่ชี้ไปที่ฐานข้อมูลของ panel · ถ้าเทียบด้วยคำนำหน้าเฉย ๆ
             * ข้อยกเว้นที่ตั้งใจเปิดแค่โฟลเดอร์เดียวจะกลายเป็นทางเข้าถึงทุกอย่างที่
             * กันไว้ (เทสต์จับได้ตอนเพิ่มข้อยกเว้นครั้งแรก)
             *
             * `realpath()` ช่วยได้เฉพาะไฟล์ที่มีอยู่จริง — เส้นทางที่ยังไม่มีไฟล์
             * (กำลังจะสร้าง) คืน false แล้วเหลือแต่สตริงดิบให้ตรวจ จึงพึ่งมันอย่างเดียวไม่ได้
             *
             * ตรวจข้อยกเว้นกับ candidate ตัวเดียวกับที่กำลังวนอยู่เท่านั้น — symlink ที่
             * ชี้ออกจากโฟลเดอร์ข้อยกเว้นไปหาของที่กันไว้ จึงยังถูกจับที่ candidate ที่ resolve แล้ว
             */
            if (!in_array('..', explode('/', $candidate), true)) {
                foreach (self::$exceptions as $allowed) {
                    if ($candidate === $allowed || str_starts_with($candidate . '/', $allowed . '/')) {
                        continue 2;
                    }
                }
            }

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
