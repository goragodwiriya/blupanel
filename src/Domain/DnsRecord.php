<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * กฎของ DNS record หนึ่งแถว — ที่เดียวที่ตอบได้ว่าค่าแบบไหนใช้ได้
 *
 * แยกออกมาจาก controller เพราะตอนนี้มีสองทางที่เพิ่มเรกคอร์ดได้ (หน้าเว็บเดิมกับ REST API)
 * ถ้าปล่อยให้แต่ละทางตรวจเอง จะมีวันที่ทางหนึ่งยอมรับค่าที่อีกทางปฏิเสธ แล้วผู้ใช้
 * จะเจอ "เพิ่มผ่านหน้าเว็บได้แต่ผ่าน API ไม่ได้" ซึ่งเป็นบั๊กที่อธิบายยากที่สุดชนิดหนึ่ง
 *
 * ขอบเขตตาม ARCHITECTURE §15 Q1: ตารางนี้เก็บ "ค่าที่ตั้งใจให้เป็น" แล้วส่งออกเป็น zone file
 * — จนกว่าเฟส E3 จะเชื่อม BIND9 จริง การแก้ที่นี่จึงยังไม่ผ่าน agent
 */
final class DnsRecord
{
    /** ชนิดที่รองรับ — ตรงกับ CHECK constraint ของคอลัมน์ dns_records.type */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA'];

    /** เกินนี้ไม่ใช่ zone ของโดเมนเดียวแล้ว — กันการวางข้อมูลผิดที่ลงช่องแก้ไข */
    public const MAX_RECORDS = 500;

    public const TTL_MIN = 60;
    public const TTL_MAX = 86400;

    /**
     * ตรวจค่าที่ผู้ใช้ส่งมาแล้วคืนแถวที่พร้อมเขียนลงฐานข้อมูล
     *
     * @param array<string,mixed> $input
     * @return array{type:string,name:string,value:string,ttl:int,priority:int|null}
     */
    public static function validate(array $input): array
    {
        $type = Validator::requireEnum($input, 'type', self::TYPES);

        $name = Validator::pattern(
            trim((string) ($input['name'] ?? '')) ?: '@',
            // @ = โดเมนตัวเอง · * = wildcard · นอกนั้นเป็นชื่อโฮสต์ย่อยที่คั่นด้วยจุด
            '/^(@|\*|[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*)$/i',
            'ชื่อเรกคอร์ดไม่ถูกต้อง',
        );

        $value = Validator::requireString(['value' => trim((string) ($input['value'] ?? ''))], 'value', 512);

        $ttl = (int) ($input['ttl'] ?? 3600);
        $ttl = max(self::TTL_MIN, min(self::TTL_MAX, $ttl));

        // MX ที่ไม่มีลำดับความสำคัญคือ zone file ที่ใช้ไม่ได้ — เติมค่าปริยายให้เสมอ
        $priority = null;
        if ($type === 'MX') {
            $priority = max(0, min(65535, (int) ($input['priority'] ?? 10)));
        }

        self::assertValueMatchesType($type, $value);

        return ['type' => $type, 'name' => $name, 'value' => $value, 'ttl' => $ttl, 'priority' => $priority];
    }

    /**
     * ค่าต้องเข้ากับชนิดของเรกคอร์ด
     *
     * ใส่ IP ลงช่อง CNAME เป็นความผิดพลาดที่พบบ่อยที่สุด และเป็นแบบที่ DNS server
     * จะรับไว้เงียบ ๆ แล้วทำให้ชื่อนั้นใช้ไม่ได้ทั้งโดเมน — ต้องจับตั้งแต่ตอนกรอก
     *
     * **เคยเป็นบั๊กจริง:** ตัวตรวจเดิมใช้ `/^[a-z0-9.-]+\.?$/i` ซึ่ง "203.0.113.10"
     * ผ่านฉลุยเพราะมีแต่ตัวเลขกับจุด — คำเตือนในคอมเมนต์บอกว่ากันไว้แล้ว แต่โค้ดไม่ได้กัน
     * ตอนนี้ปฏิเสธค่าที่เป็น IP อย่างชัดเจนก่อน แล้วค่อยตรวจรูปแบบชื่อโฮสต์
     */
    public static function assertValueMatchesType(string $type, string $value): void
    {
        match ($type) {
            'A' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? null
                : throw new ValidationError('เรกคอร์ด A ต้องเป็น IPv4'),
            'AAAA' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? null
                : throw new ValidationError('เรกคอร์ด AAAA ต้องเป็น IPv6'),
            'CNAME', 'MX' => self::assertHostname($type, $value),
            default => null,
        };
    }

    private static function assertHostname(string $type, string $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            throw new ValidationError(
                "เรกคอร์ด {$type} ต้องเป็นชื่อโฮสต์ ไม่ใช่ IP address"
                . ($type === 'CNAME' ? ' — ถ้าต้องการชี้ไปที่ IP ให้ใช้เรกคอร์ด A หรือ AAAA แทน' : ''),
            );
        }

        // ชื่อโฮสต์: แต่ละส่วนขึ้นต้นและลงท้ายด้วยตัวอักษรหรือตัวเลข มีขีดกลางได้
        // ลงท้ายด้วยจุดได้ (fully qualified) และต้องมีอย่างน้อยหนึ่งจุด
        $pattern = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\.?$/i';

        if (preg_match($pattern, $value) !== 1) {
            throw new ValidationError("เรกคอร์ด {$type} ต้องเป็นชื่อโฮสต์ที่ถูกต้อง เช่น mail.example.com");
        }
    }

    /**
     * แปลง zone file กลับเป็นเรกคอร์ด — ทางกลับของ {@see toAuthoritativeZoneFile()}
     *
     * ## ทำไมต้องแปลงกลับ แทนที่จะให้แก้ไฟล์ตรง ๆ
     *
     * zone file ถูกสร้างใหม่ทั้งไฟล์จากฐานข้อมูลทุกครั้งที่มีคนแตะเรกคอร์ดสักรายการ ·
     * การเปิดให้แก้ไฟล์ตรง ๆ จึงเป็นกับดักที่คลาสสิกที่สุดของ panel แบบนี้: แก้แล้วใช้ได้
     * ทันที ทุกอย่างดูถูกต้อง แล้ววันหนึ่งหายไปเงียบ ๆ ตอนที่มีคนกดเพิ่มเรกคอร์ดอื่น
     *
     * การแปลงกลับเข้าฐานข้อมูลทำให้ "แก้ไฟล์" ได้ผลเหมือนกันในสายตาผู้ใช้ แต่ฐานข้อมูล
     * ยังเป็นแหล่งความจริงเดียว — ไม่มีอะไรหายทีหลัง และหน้าตารางกับไฟล์ตรงกันเสมอ
     *
     * ## สิ่งที่ไม่รับ และทำไม
     *
     * `$INCLUDE` สั่งให้ BIND อ่านไฟล์อื่นบนเครื่อง · `$ORIGIN` เปลี่ยนความหมายของทุกชื่อ
     * ที่ตามมา · `$GENERATE` สร้างเรกคอร์ดเป็นชุด — ทั้งสามอย่างแปลงกลับเป็นแถวในฐานข้อมูล
     * ไม่ได้ และการรับไว้แบบครึ่ง ๆ กลาง ๆ อันตรายกว่าการปฏิเสธพร้อมบอกเหตุผล
     *
     * `SOA` กับ `NS` **ข้ามให้เงียบ ๆ** ไม่ใช่ปฏิเสธ — สองอย่างนี้ panel สร้างจากค่าตั้ง
     * ของเครื่องเสมอ และมันอยู่ในไฟล์ที่ผู้ใช้กำลังแก้อยู่แล้ว การบังคับให้ลบทิ้งก่อน
     * บันทึกคือการสร้างงานที่ไม่มีประโยชน์กับใครเลย
     *
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:int|null}>
     * @throws ValidationError พร้อมหมายเลขบรรทัดเสมอ — ข้อความว่า "รูปแบบไม่ถูกต้อง"
     *         เฉย ๆ ทำให้ผู้ใช้ต้องไล่หาเองในข้อความ 50 บรรทัด
     */
    public static function parseZoneFile(string $domain, string $text): array
    {
        $origin = strtolower(rtrim(trim($domain), '.'));
        $lines = preg_split('/\R/', $text) ?: [];

        $records = [];
        $defaultTtl = 3600;
        $owner = '@';

        // เรกคอร์ดเดียวคร่อมหลายบรรทัดได้ด้วยวงเล็บ — SOA ที่ panel สร้างเองก็เป็นแบบนั้น
        $pending = '';
        $pendingLine = 0;
        $depth = 0;

        foreach ($lines as $index => $raw) {
            $lineNo = $index + 1;
            $clean = self::stripZoneComment($raw);

            if ($depth === 0 && trim($clean) === '') {
                continue;
            }

            // บรรทัดที่ขึ้นต้นด้วยช่องว่างใช้ชื่อของเรกคอร์ดก่อนหน้า (กติกาของ BIND)
            if ($depth === 0 && preg_match('/^\s/', $raw) === 1) {
                $clean = $owner . ' ' . ltrim($clean);
            }

            if ($depth === 0) {
                $pendingLine = $lineNo;
            }

            $depth += substr_count($clean, '(') - substr_count($clean, ')');
            $pending = trim($pending . ' ' . str_replace(['(', ')'], ' ', $clean));

            if ($depth > 0) {
                continue;
            }

            $depth = 0;
            $statement = $pending;
            $pending = '';

            if ($statement === '') {
                continue;
            }

            $tokens = self::tokenizeZoneLine($statement);

            if ($tokens === []) {
                continue;
            }

            if (str_starts_with($tokens[0], '$')) {
                $defaultTtl = self::applyZoneDirective($tokens, $defaultTtl, $pendingLine);
                continue;
            }

            $owner = $tokens[0];
            $record = self::zoneStatementToRecord(array_slice($tokens, 1), $owner, $origin, $defaultTtl, $pendingLine);

            if ($record !== null) {
                $records[] = $record;
            }

            if (count($records) > self::MAX_RECORDS) {
                throw new ValidationError(sprintf(
                    'เรกคอร์ดเกิน %d รายการ — มากขนาดนี้มักเป็นสัญญาณว่าวางข้อมูลผิดที่',
                    self::MAX_RECORDS,
                ));
            }
        }

        if ($depth !== 0) {
            throw new ValidationError(sprintf('บรรทัดที่ %d: วงเล็บเปิดไว้แล้วไม่ได้ปิด', $pendingLine));
        }

        return $records;
    }

    /**
     * ตัดคอมเมนต์ `;` ออก โดยไม่แตะอันที่อยู่ในเครื่องหมายคำพูด
     *
     * ค่า TXT ของ SPF/DKIM มี `;` อยู่ข้างในเป็นเรื่องปกติ (`v=spf1 ...; -all`) การตัด
     * ด้วย `explode(';')` จึงทำลายค่าที่ถูกต้องเงียบ ๆ แล้วไปโผล่เป็นเมลส่งไม่ออกทีหลัง
     */
    private static function stripZoneComment(string $line): string
    {
        $out = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $out .= $char . $line[$i + 1];
                $i++;
                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === ';' && !$inQuotes) {
                break;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * แยกโทเคน โดยถือว่าข้อความในเครื่องหมายคำพูดเป็นโทเคนเดียว
     *
     * @return list<string>
     */
    private static function tokenizeZoneLine(string $line): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\S+/', $line, $matches);

        return $matches[0];
    }

    /**
     * คำสั่งที่ขึ้นต้นด้วย `$` — รับแค่ `$TTL` ที่เหลือปฏิเสธพร้อมบอกเหตุผล
     *
     * @param list<string> $tokens
     */
    private static function applyZoneDirective(array $tokens, int $defaultTtl, int $lineNo): int
    {
        $directive = strtoupper($tokens[0]);

        if ($directive === '$TTL') {
            return self::parseTtl($tokens[1] ?? '', $lineNo);
        }

        throw new ValidationError(sprintf(
            'บรรทัดที่ %d: %s แปลงกลับเป็นเรกคอร์ดในระบบไม่ได้ — %s',
            $lineNo,
            $directive,
            match ($directive) {
                '$INCLUDE' => 'มันสั่งให้ BIND อ่านไฟล์อื่นบนเครื่อง ซึ่งอยู่นอกขอบเขตของหน้านี้',
                '$ORIGIN' => 'มันเปลี่ยนความหมายของทุกชื่อที่ตามมา · เขียนชื่อเต็มพร้อมจุดปิดท้ายแทน',
                '$GENERATE' => 'มันสร้างเรกคอร์ดเป็นชุด · เขียนออกมาทีละรายการแทน',
                default => 'ระบบรองรับเฉพาะ $TTL',
            },
        ));
    }

    /**
     * แปลงส่วนที่เหลือของบรรทัดเป็นเรกคอร์ดหนึ่งรายการ — คืน null เมื่อเป็น SOA/NS ที่ข้ามไป
     *
     * @param list<string> $tokens โทเคนหลังชื่อเรกคอร์ด
     * @return array{type:string,name:string,value:string,ttl:int,priority:int|null}|null
     */
    private static function zoneStatementToRecord(
        array $tokens,
        string $owner,
        string $origin,
        int $defaultTtl,
        int $lineNo,
    ): ?array {
        $ttl = $defaultTtl;
        $ttlSeen = false;

        // ลำดับของ TTL กับคลาสสลับกันได้ทั้งสองแบบตามมาตรฐาน ต้องรับทั้งคู่
        while ($tokens !== []) {
            $token = $tokens[0];

            if (strtoupper($token) === 'IN') {
                array_shift($tokens);
                continue;
            }

            if (!$ttlSeen && preg_match('/^\d+[smhdwSMHDW]?$/', $token) === 1) {
                $ttl = self::parseTtl($token, $lineNo);
                $ttlSeen = true;
                array_shift($tokens);
                continue;
            }

            break;
        }

        if ($tokens === []) {
            throw new ValidationError(sprintf('บรรทัดที่ %d: ไม่มีชนิดของเรกคอร์ด', $lineNo));
        }

        $type = strtoupper((string) array_shift($tokens));

        // panel สร้าง SOA/NS จากค่าตั้งของเครื่องเสมอ — ข้ามไปเงียบ ๆ ดูเหตุผลที่ parseZoneFile()
        if (in_array($type, ['SOA', 'NS'], true)) {
            return null;
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationError(sprintf(
                'บรรทัดที่ %d: ระบบยังไม่รองรับเรกคอร์ดชนิด %s (รองรับ %s)',
                $lineNo,
                $type,
                implode(' · ', self::TYPES),
            ));
        }

        if ($tokens === []) {
            throw new ValidationError(sprintf('บรรทัดที่ %d: เรกคอร์ด %s ไม่มีค่า', $lineNo, $type));
        }

        $priority = null;

        if ($type === 'MX') {
            $first = (string) array_shift($tokens);

            if (preg_match('/^\d+$/', $first) !== 1) {
                throw new ValidationError(sprintf(
                    'บรรทัดที่ %d: เรกคอร์ด MX ต้องมีลำดับความสำคัญเป็นตัวเลขก่อนชื่อเซิร์ฟเวอร์ '
                        . 'เช่น `10 mail.example.com.`',
                    $lineNo,
                ));
            }

            $priority = (int) $first;
        }

        try {
            return self::validate([
                'type' => $type,
                'name' => self::relativeZoneName($owner, $origin),
                'value' => self::zoneRdata($type, $tokens),
                'ttl' => $ttl,
                'priority' => $priority,
            ]);
        } catch (ValidationError $e) {
            // ข้อความของ validate() ไม่รู้จักบรรทัด — เติมให้ ไม่งั้นผู้ใช้ต้องไล่หาเอง
            throw new ValidationError(sprintf('บรรทัดที่ %d: %s', $lineNo, $e->getMessage()));
        }
    }

    /**
     * ประกอบค่าของเรกคอร์ดจากโทเคนที่เหลือ
     *
     * TXT ยาว ๆ (DKIM) ถูกตัดเป็นหลายสตริงในเครื่องหมายคำพูดแล้ววางต่อกัน — ต้องต่อกลับ
     * เป็นค่าเดียวเสมอ ไม่งั้นกุญแจ DKIM ที่วางมาจะขาดกลางโดยไม่มีอะไรฟ้อง
     *
     * @param list<string> $tokens
     */
    private static function zoneRdata(string $type, array $tokens): string
    {
        if ($type === 'TXT') {
            $parts = array_map(static fn (string $token): string => self::unquoteZoneToken($token), $tokens);

            return implode('', $parts);
        }

        if (in_array($type, ['CNAME', 'MX'], true)) {
            // จุดปิดท้ายถูกเติมกลับให้ตอนเขียนไฟล์ — เก็บแบบไม่มีจุดให้ตรงกับที่ฟอร์มบันทึก
            return rtrim((string) $tokens[0], '.');
        }

        if ($type === 'CAA') {
            return implode(' ', array_map(
                static fn (string $token): string => str_contains($token, '"') ? $token : $token,
                $tokens,
            ));
        }

        return (string) $tokens[0];
    }

    /** ถอดเครื่องหมายคำพูดและ escape ออกจากโทเคนเดียว */
    private static function unquoteZoneToken(string $token): string
    {
        if (strlen($token) >= 2 && str_starts_with($token, '"') && str_ends_with($token, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($token, 1, -1));
        }

        return $token;
    }

    /**
     * แปลงชื่อในไฟล์ให้เป็นชื่อสัมพัทธ์ที่ระบบเก็บ
     *
     * **ชื่อที่ไม่มีจุดปิดท้ายและเท่ากับชื่อโดเมนพอดีต้องปฏิเสธ** ไม่ใช่เดาให้ — BIND อ่าน
     * `example.com` (ไม่มีจุด) เป็น `example.com.example.com.` ซึ่งเกือบทุกครั้งไม่ใช่สิ่งที่
     * คนพิมพ์ตั้งใจ · การเดาให้เป็น `@` เงียบ ๆ ทำให้ผู้ใช้ไม่มีวันรู้ว่าตัวเองเข้าใจผิด
     * แล้วไปพลาดซ้ำที่อื่นซึ่งไม่มีใครแก้ให้
     *
     * ไม่เติมหมายเลขบรรทัดเองเพราะผู้เรียกเติมให้อยู่แล้ว — เติมทั้งสองที่ได้ข้อความที่
     * ขึ้นต้นว่า "บรรทัดที่ 7: บรรทัดที่ 7:" ซึ่งอ่านแล้วดูเหมือนระบบพัง
     */
    private static function relativeZoneName(string $name, string $origin): string
    {
        if ($name === '@' || $name === '*') {
            return $name;
        }

        $lower = strtolower($name);

        if (str_ends_with($lower, '.')) {
            $absolute = rtrim($lower, '.');

            if ($absolute === $origin) {
                return '@';
            }

            if (str_ends_with($absolute, '.' . $origin)) {
                return substr($absolute, 0, -strlen('.' . $origin));
            }

            throw new ValidationError(sprintf(
                'ชื่อ %s อยู่นอกโดเมน %s — zone นี้ประกาศชื่อนอกโดเมนตัวเองไม่ได้',
                $name,
                $origin,
            ));
        }

        if ($lower === $origin) {
            throw new ValidationError(sprintf(
                'ชื่อ %s ไม่มีจุดปิดท้าย BIND จะอ่านเป็น %s.%s — '
                    . 'ใช้ `@` ถ้าหมายถึงตัวโดเมนเอง หรือเติมจุดปิดท้ายเป็น `%s.`',
                $name,
                $lower,
                $origin,
                $lower,
            ));
        }

        return $name;
    }

    /** TTL รับได้ทั้งวินาทีล้วนและแบบมีหน่วยท้าย (`1h`, `30m`) ที่พบในไฟล์ที่คัดลอกมา */
    private static function parseTtl(string $token, int $lineNo): int
    {
        if (preg_match('/^(\d+)([smhdwSMHDW]?)$/', $token, $m) !== 1) {
            throw new ValidationError(sprintf('บรรทัดที่ %d: TTL ไม่ถูกต้อง (%s)', $lineNo, $token));
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
            default => 1,
        };
    }

    /**
     * ประกอบ zone file จากเรกคอร์ดทั้งหมดของโดเมนหนึ่ง
     *
     * @param list<array<string,mixed>> $records
     */
    public static function toZoneFile(string $domain, array $records): string
    {
        $lines = [
            '; zone file สำหรับ ' . $domain,
            '; ส่งออกจาก PHP Server Control Panel เมื่อ ' . date('Y-m-d H:i:s'),
            '; นำไปใส่ที่ผู้ให้บริการ DNS ของคุณ — panel ไม่ได้ทำหน้าที่เป็น DNS server',
            '',
        ];

        foreach ($records as $record) {
            $lines[] = sprintf(
                '%-20s %-6s IN %-6s %s%s',
                $record['name'],
                $record['ttl'],
                $record['type'],
                $record['priority'] !== null ? $record['priority'] . ' ' : '',
                $record['value'],
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * zone file แบบสมบูรณ์ที่ BIND9 โหลดเป็น master ได้จริง — PLAN-V2 เฟส E3
     *
     * ต่างจาก `toZoneFile()` (ไฟล์ส่งออกให้ผู้ใช้ไปวางที่ DNS provider ภายนอก) ตรงที่ต้องมี
     * `SOA`/`NS` ครบและ `$TTL` — ไม่มีสามอย่างนี้ `named-checkzone` ปฏิเสธทันที
     *
     * @param list<array<string,mixed>> $records
     * @param list<string> $nameservers ต้องมีอย่างน้อยหนึ่งตัว — ผู้เรียกเป็นคนตรวจก่อน
     */
    public static function toAuthoritativeZoneFile(
        string $domain,
        array $records,
        int $serial,
        array $nameservers,
        string $soaEmail,
    ): string {
        $primaryNs = self::fqdn($nameservers[0]);

        $lines = [
            '; จัดการโดย phpcp โดยอัตโนมัติ — แก้ไขตรงนี้แล้วหายไปตอน sync รอบถัดไป',
            '; แก้ผ่านหน้า DNS ของ panel เท่านั้น',
            '$TTL 3600',
            sprintf('@   IN  SOA %s %s (', $primaryNs, self::soaRname($soaEmail, $domain)),
            sprintf('        %d   ; serial', $serial),
            '        3600        ; refresh (1 ชั่วโมง)',
            '        900         ; retry (15 นาที)',
            '        1209600     ; expire (14 วัน)',
            '        3600 )      ; minimum / negative-cache TTL',
        ];

        foreach ($nameservers as $ns) {
            $lines[] = sprintf('@   IN  NS  %s', self::fqdn($ns));
        }

        $lines[] = '';

        foreach ($records as $record) {
            $lines[] = sprintf(
                '%-20s %-6s IN %-6s %s%s',
                $record['name'],
                $record['ttl'],
                $record['type'],
                $record['priority'] !== null ? $record['priority'] . ' ' : '',
                self::zoneValue((string) $record['type'], (string) $record['value']),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * ค่าโฮสต์ต้องลงท้ายด้วยจุดเสมอใน zone file (fully qualified) ไม่งั้น BIND9 จะเอาชื่อโดเมน
     * ต่อท้ายให้เองซ้ำ กลายเป็น `ns1.example.com.example.com` แบบเงียบ ๆ — บั๊กคลาสสิกของ DNS
     */
    private static function fqdn(string $host): string
    {
        return str_ends_with($host, '.') ? $host : $host . '.';
    }

    /**
     * แปลงค่าให้อยู่ในรูปที่ zone file ต้องการตามชนิดของเรกคอร์ด
     *
     * · **CNAME/MX** ต้องเป็น FQDN เหมือน NS ไม่งั้น BIND9 ต่อชื่อโดเมนให้เองซ้ำ
     * · **TXT** ต้องอยู่ในเครื่องหมายคำพูดเสมอ — ค่าที่มีช่องว่าง (SPF, DKIM และ
     *   ACME challenge token) ถ้าไม่ห่อไว้ BIND9 จะอ่านเป็นหลายสตริงแยกกันหรือ
     *   ปฏิเสธทั้ง zone · เดิมปล่อยให้ผู้ใช้ใส่ `"` มาเองซึ่งเป็นกับดัก: คนที่วาง
     *   ค่า SPF ตามที่ผู้ให้บริการเมลบอกมา (ไม่มีคำพูด) จะได้ zone ที่ใช้ไม่ได้
     * · ชนิดอื่น (A/AAAA/CAA) ปล่อยตามเดิม
     */
    private static function zoneValue(string $type, string $value): string
    {
        if (in_array($type, ['CNAME', 'MX'], true)) {
            return self::fqdn($value);
        }

        if ($type !== 'TXT') {
            return $value;
        }

        // ใส่คำพูดมาเองแล้วก็ปล่อยไว้ — ไม่งั้นจะซ้อนกันเป็น ""value""
        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
            return $value;
        }

        // คำพูดที่อยู่กลางค่าต้อง escape ไม่งั้นมันปิดสตริงก่อนเวลา
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * แปลงอีเมลผู้ดูแลเป็นฟอร์แมต RNAME ของ SOA (จุดแทน @ ตาม RFC 1035 §3.3.13)
     *
     * จุดที่มีอยู่แล้วใน local-part (เช่น "first.last@example.com") ต้อง escape ด้วย \.
     * ก่อนแทน @ ไม่งั้น BIND9 จะอ่านผิดว่าเป็นส่วนของโดเมนแทนที่จะเป็นส่วนของชื่อผู้ใช้
     */
    private static function soaRname(string $soaEmail, string $domain): string
    {
        if ($soaEmail === '') {
            return self::fqdn('hostmaster.' . $domain);
        }

        if (!str_contains($soaEmail, '@')) {
            // รับรูปแบบที่เป็น RNAME อยู่แล้ว (จุดแทน @ มาตั้งแต่ต้น) โดยไม่แตะอะไรเพิ่ม
            return self::fqdn($soaEmail);
        }

        [$local, $host] = explode('@', $soaEmail, 2);

        return self::fqdn(str_replace('.', '\\.', $local) . '.' . $host);
    }
}
