<?php

declare (strict_types = 1);

namespace Phpcp\Support;

use Phpcp\Agent\ValidationError;

/**
 * ตัวตรวจ argument ที่ capability ทุกตัวใช้ร่วมกัน
 *
 * ทุกเมธอดโยน ValidationError พร้อมข้อความไทยที่แสดงให้ผู้ใช้เห็นได้ทันที
 * และตัดค่าที่ผิดรูปแบบทิ้งตั้งแต่ต้นทาง ไม่มีการ "พยายามแก้ให้ถูก" ซึ่งเป็นบ่อเกิดของช่องโหว่
 */
final class Validator
{
    /** @param array<string,mixed> $args */
    public static function requireString(array $args, string $key, int $max = 255): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ValidationError("ต้องระบุ {$key}");
        }
        if (str_contains($value, "\0")) {
            throw new ValidationError("{$key} มีอักขระที่ไม่อนุญาต");
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationError("{$key} ยาวเกิน {$max} ตัวอักษร");
        }

        return $value;
    }

    /** @param array<string,mixed> $args */
    public static function optionalString(array $args, string $key, string $default = '', int $max = 255): string
    {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return $default;
        }

        return self::requireString($args, $key, $max);
    }

    /** @param array<string,mixed> $args */
    public static function requireInt(array $args, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $args[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new ValidationError("{$key} ต้องเป็นตัวเลข");
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new ValidationError("{$key} ต้องอยู่ระหว่าง {$min} ถึง {$max}");
        }

        return $int;
    }

    /**
     * ค่าตัวเลขที่ "ไม่ได้ส่งมา" ต่างจาก "ส่งมาเป็น 0" — ใช้กับคำสั่งที่แก้ค่าบางส่วน
     *
     * ต้องมีแยกจาก optionalInt() เพราะ optionalInt() บังคับให้ค่าเริ่มต้นเป็น int
     * การส่ง null เข้าไปเป็นค่าเริ่มต้นตายด้วย TypeError ทันทีที่ถูกเรียก — เป็นบั๊กจริง
     * ใน customer.quota_update ที่ไม่มีใครเจอมาก่อนเพราะไม่มีใครเรียก capability ตัวนั้นเลย
     *
     * @param array<string,mixed> $args
     */
    public static function nullableInt(
        array $args,
        string $key,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
    ): ?int {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return null;
        }

        return self::requireInt($args, $key, $min, $max);
    }

    /** @param array<string,mixed> $args */
    public static function optionalInt(
        array $args,
        string $key,
        int $default,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
    ): int {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return $default;
        }

        return self::requireInt($args, $key, $min, $max);
    }

    /**
     * @param array<string,mixed> $args
     * @param list<string> $allowed
     */
    public static function requireEnum(array $args, string $key, array $allowed): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationError("{$key} ต้องเป็นค่าใดค่าหนึ่งใน: ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * ค่าบูลีนจากฟอร์มหรือ JSON
     *
     * ต้องรับหลายรูปแบบเพราะผู้เรียกมาจากคนละทาง: JSON ส่ง `true` จริง ๆ ส่วนฟอร์ม HTML
     * ส่ง `"1"` หรือ `"on"` และ query string ส่ง `"true"` เป็นข้อความ
     *
     * **ค่าที่ไม่รู้จักถือเป็น false ไม่ใช่โยน error** — ต่างจาก enum โดยเจตนา เพราะ
     * checkbox ที่ไม่ถูกติ๊กจะ**ไม่ถูกส่งมาเลย** การบังคับให้ส่งค่าจึงทำให้ฟอร์มปกติพัง
     *
     * @param array<string,mixed> $args
     */
    public static function requireBool(array $args, string $key): bool
    {
        return in_array($args[$key] ?? null, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * เหมือน {@see requireEnum} แต่ยอมให้ไม่ส่งมา แล้วใช้ค่าเริ่มต้นแทน
     *
     * ค่าที่ส่งมาแต่ไม่อยู่ในรายการยังถูกปฏิเสธเหมือนเดิม — "ไม่ส่ง" กับ "ส่งค่าผิด"
     * เป็นคนละเรื่อง อย่างหลังคือความผิดพลาดของผู้เรียกที่ต้องบอกให้รู้
     *
     * @param array<string,mixed> $args
     * @param list<string> $allowed
     */
    public static function optionalEnum(array $args, string $key, array $allowed, string $default): string
    {
        $value = $args[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return self::requireEnum($args, $key, $allowed);
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    public static function requireStringList(array $args, string $key, int $maxItems = 64, int $maxLength = 255): array
    {
        $value = $args[$key] ?? null;
        if (!is_array($value)) {
            throw new ValidationError("{$key} ต้องเป็นรายการ");
        }
        if (count($value) > $maxItems) {
            throw new ValidationError("{$key} มีรายการเกิน {$maxItems} รายการ");
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || str_contains($item, "\0")) {
                throw new ValidationError("{$key} มีรายการที่ไม่ถูกต้อง");
            }
            if (mb_strlen($item) > $maxLength) {
                throw new ValidationError("{$key} มีรายการที่ยาวเกินกำหนด");
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param string $value
     * @param string $regex
     * @param string $message
     * @return mixed
     */
    public static function pattern(string $value, string $regex, string $message): string
    {
        if (preg_match($regex, $value) !== 1) {
            throw new ValidationError($message);
        }

        return $value;
    }

    /** ชื่อโดเมนตาม RFC 1123 — ใช้ก่อนเอาไปประกอบชื่อไฟล์ vhost เสมอ */
    public static function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if (mb_strlen($value) > 253) {
            throw new ValidationError('ชื่อโดเมนยาวเกินกำหนด');
        }

        return self::pattern(
            $value,
            '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
            'รูปแบบชื่อโดเมนไม่ถูกต้อง'
        );
    }

    /**
     * ชื่อโดเมนที่อาจเป็น wildcard (`*.example.com`) — PLAN-V2 เฟส E7
     *
     * `*` ไม่ใช่อักขระของชื่อโฮสต์ตาม RFC 1123 จึงผ่าน {@see domain()} ไม่ได้ ·
     * แยกเป็นเมธอดของตัวเองแทนการผ่อนกฎของ `domain()` เพราะจุดที่รับ wildcard ได้
     * มีน้อยมาก (ใบรับรองกับ ServerAlias) ส่วนที่เหลือ — โดยเฉพาะที่เอาไปประกอบ
     * **ชื่อไฟล์ vhost** — ต้องไม่มีทางได้ `*` เข้าไป
     *
     * รองรับเฉพาะ `*.` นำหน้าหนึ่งระดับตามที่ Let's Encrypt ออกให้ · `*.*.example.com`
     * หรือ `www.*.example.com` ไม่มีอยู่จริงในระบบใบรับรอง
     */
    public static function wildcardDomain(string $value): string
    {
        $value = strtolower(trim($value));

        return str_starts_with($value, '*.')
            ? '*.' . self::domain(substr($value, 2))
            : self::domain($value);
    }

    /**
     * @param string $value
     * @return mixed
     */
    public static function ipAddress(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('รูปแบบ IP ไม่ถูกต้อง');
        }

        return $value;
    }

    /**
     * @param int $value
     * @return mixed
     */
    public static function port(int $value): int
    {
        if ($value < 1 || $value > 65535) {
            throw new ValidationError('หมายเลขพอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
        }

        return $value;
    }

    /** ชื่อ identifier ของฐานข้อมูล/ผู้ใช้ — เข้มกว่าที่ MariaDB ยอมรับโดยตั้งใจ */
    public static function identifier(string $value): string
    {
        return self::pattern(
            $value,
            '/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/',
            'ชื่อต้องขึ้นต้นด้วยตัวอักษร ตามด้วยตัวอักษร ตัวเลข หรือ _ ยาวไม่เกิน 32 ตัว'
        );
    }

    /**
     * @param string $value
     */
    public static function phpVersion(string $value): string
    {
        return self::pattern($value, '/^\d\.\d{1,2}$/', 'รูปแบบเวอร์ชัน PHP ไม่ถูกต้อง');
    }

    /**
     * เส้นทางสัมบูรณ์ที่เอาไปใส่ในไฟล์ config ของเว็บเซิร์ฟเวอร์ได้อย่างปลอดภัย
     *
     * ตรวจแค่รูปแบบเท่านั้น — การจำกัดว่าเส้นทางนี้อยู่ในขอบเขตที่ยอมให้ใช้หรือไม่
     * ต้องทำแยกด้วย absolutePathWithin() เพราะขอบเขตมาจากไฟล์ config
     *
     * ห้ามมี `..` เพราะเส้นทางถูกนำไปประกอบเป็น DocumentRoot และ open_basedir
     * ห้ามมีอักขระที่ทำให้ตีความ directive ผิด (ช่องว่างท้าย, บรรทัดใหม่, quote)
     */
    public static function absolutePath(string $value): string
    {
        // ต้องตรวจก่อน trim — trim() ตัด \0 กับ \n ท้ายสตริงทิ้งให้เอง
        // ค่าที่แนบ null byte มาท้ายเส้นทางจึงจะรอดไปได้ถ้าตรวจทีหลัง
        if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $value) === 1) {
            throw new ValidationError('เส้นทางมีอักขระที่ใช้ในไฟล์ตั้งค่าไม่ได้');
        }

        $value = rtrim(trim($value), '/');

        if ($value === '' || !str_starts_with($value, '/')) {
            throw new ValidationError('เส้นทางต้องเป็นเส้นทางสัมบูรณ์ที่ขึ้นต้นด้วย /');
        }

        if (mb_strlen($value) > 4096) {
            throw new ValidationError('เส้นทางยาวเกินกำหนด');
        }

        if (in_array('..', explode('/', $value), true)) {
            throw new ValidationError('เส้นทางต้องไม่มี ..');
        }

        return $value;
    }

    /**
     * เส้นทางสัมบูรณ์ที่ต้องอยู่ภายในขอบเขตใดขอบเขตหนึ่งที่กำหนด
     *
     * เทียบแบบมีเครื่องหมาย / ปิดท้ายเสมอ มิฉะนั้น /srv/phpcp-evil จะผ่าน
     * ในขณะที่ขอบเขตคือ /srv/phpcp
     *
     * @param list<string> $allowedRoots
     */
    public static function absolutePathWithin(string $value, array $allowedRoots): string
    {
        $path = self::absolutePath($value);

        foreach ($allowedRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && ($path === $root || str_starts_with($path, $root.'/'))) {
                return $path;
            }
        }

        throw new ValidationError(
            'เส้นทาง '.$path.' อยู่นอกขอบเขตที่ยอมให้ใช้ — '.
            'เพิ่มโฟลเดอร์แม่เข้าไปใน sites.pointer_roots ของไฟล์ config ก่อน'
        );
    }

    /**
     * ชื่อโฟลเดอร์ย่อยใต้ pointer root — ไม่ขึ้นต้นด้วย / และไม่มี ..
     *
     * ใช้กับฟอร์ม Domain Pointer ที่ให้กรอกแค่ปลายทาง เช่น my-project หรือ shop/public
     */
    public static function relativePath(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $value) === 1) {
            throw new ValidationError('เส้นทางมีอักขระที่ใช้ในไฟล์ตั้งค่าไม่ได้');
        }

        $value = trim($value);
        $value = trim($value, '/');

        if ($value === '') {
            throw new ValidationError('ต้องระบุชื่อโฟลเดอร์ปลายทาง');
        }

        if (mb_strlen($value) > 4096) {
            throw new ValidationError('เส้นทางยาวเกินกำหนด');
        }

        if (in_array('..', explode('/', $value), true) || in_array('.', explode('/', $value), true)) {
            throw new ValidationError('เส้นทางต้องไม่มี . หรือ ..');
        }

        return $value;
    }

    /**
     * แปลงค่าจากฟอร์ม Domain Pointer เป็นเส้นทางสัมบูรณ์ที่ปลอดภัย
     *
     * รับได้ทั้ง path เต็ม (/mnt/.../shop) และชื่อโฟลเดอร์ย่อย (shop)
     * เมื่อเป็นชื่อย่อยจะต่อกับ pointer root ที่เลือก หรือตัวเดียวที่มีใน config
     *
     * @param list<string> $allowedRoots จาก Config::docrootRoots()
     * @param list<string> $pointerRoots จาก sites.pointer_roots
     */
    public static function resolvePointerDocroot(
        string $value,
        array $allowedRoots,
        array $pointerRoots,
        string $pointerRoot = '',
    ): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return self::absolutePathWithin($value, $allowedRoots);
        }

        $relative = self::relativePath($value);
        $roots = [];
        foreach ($pointerRoots as $root) {
            $root = rtrim(trim($root), '/');
            if ($root !== '' && str_starts_with($root, '/')) {
                $roots[] = $root;
            }
        }
        $roots = array_values(array_unique($roots));

        if ($roots === []) {
            throw new ValidationError(
                'ยังไม่ได้ตั้ง sites.pointer_roots — กรอกชื่อโฟลเดอร์อย่างเดียวไม่ได้'
            );
        }

        $pointerRoot = rtrim(trim($pointerRoot), '/');
        if ($pointerRoot !== '') {
            if (!in_array($pointerRoot, $roots, true)) {
                throw new ValidationError('โฟลเดอร์แม่ที่เลือกไม่อยู่ใน sites.pointer_roots');
            }
            $base = $pointerRoot;
        } elseif (count($roots) === 1) {
            $base = $roots[0];
        } else {
            throw new ValidationError('มีหลายโฟลเดอร์แม่ — ต้องระบุว่าชี้จากโฟลเดอร์ไหน');
        }

        return self::absolutePathWithin($base.'/'.$relative, $allowedRoots);
    }
}
