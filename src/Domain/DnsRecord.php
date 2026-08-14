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
    /**
     * ชนิดที่ระบบ "รู้จัก" — มีการตรวจค่าเฉพาะทางและมีช่องกรอกในฟอร์ม
     *
     * **ไม่ใช่รายการที่จำกัดว่าเก็บอะไรได้** (ดู {@see assertType()}) — เป็นรายการของชนิดที่
     * ระบบช่วยตรวจให้ได้มากกว่าปกติ เช่น A ต้องเป็น IPv4 หรือ CNAME ห้ามชี้ไป IP ·
     * ชนิดนอกรายการนี้เก็บได้ตามปกติ แต่ตัวตัดสินความถูกต้องคือ `named-checkzone`
     *
     * @var list<string>
     */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS', 'SRV'];

    /**
     * ชนิดที่ค่าเป็น "ชื่อโฮสต์เดี่ยว" — ต้องเติมจุดปิดท้ายให้ตอนเขียนไฟล์
     *
     * ไม่รวม SRV ทั้งที่ลงท้ายด้วยชื่อโฮสต์ เพราะค่าของมันมีสี่ส่วน
     * (`priority weight port target`) การเติมจุดท้ายทั้งก้อนจะได้ `5060 sip.example.com.`
     * ที่ผิดตำแหน่ง — SRV จึงเก็บค่าตามที่เขียนมาทั้งบรรทัดเหมือน CAA
     *
     * @var list<string>
     */
    public const HOSTNAME_TYPES = ['CNAME', 'MX', 'NS'];

    /** เกินนี้ไม่ใช่ zone ของโดเมนเดียวแล้ว — กันการวางข้อมูลผิดที่ลงช่องแก้ไข */
    public const MAX_RECORDS = 500;

    /**
     * ขอบเขต TTL — กว้างพอครอบการใช้งานจริงทุกแบบ
     *
     * `0` ใช้จริงกับเรกคอร์ดที่เปลี่ยนบ่อย (dynamic DNS, failover) และหนึ่งสัปดาห์คือ
     * ค่าที่ผู้ให้บริการรายใหญ่ตั้งให้เรกคอร์ดที่ไม่เคยเปลี่ยน · ขอบเดิม (60–86400)
     * ตัดทั้งสองกรณีทิ้งเงียบ ๆ เพราะค่าถูก clamp ไม่ใช่ถูกปฏิเสธ
     */
    public const TTL_MIN = 0;
    public const TTL_MAX = 604800;

    /**
     * ความยาวค่าสูงสุด — DKIM 2048 บิตยาวเกิน 512 ตัวอักษรเมื่อรวมเครื่องหมายคำพูด
     * และการตัดทิ้งทำให้กุญแจใช้ไม่ได้โดยไม่มีอะไรฟ้อง
     */
    public const VALUE_MAX = 4096;

    /**
     * ความยาวสูงสุดของ character-string หนึ่งก้อน (RFC 1035 §3.3.14) — ยาวกว่านี้ BIND
     * ปฏิเสธทั้งไฟล์ ค่า TXT ที่ยาวกว่านี้จึงต้องถูกตัดเป็นหลายก้อนตอนเขียน
     * ({@see txtCharacterStrings()}) ไม่ใช่ตอนรับค่า — ค่าที่ผู้ใช้กรอกยังเป็นค่าเดียว
     */
    public const TXT_STRING_MAX = 255;

    /**
     * ตรวจค่าที่ผู้ใช้ส่งมาแล้วคืนแถวที่พร้อมเขียนลงฐานข้อมูล
     *
     * @param array<string,mixed> $input
     * @return array{type:string,name:string,value:string,ttl:int,priority:int|null}
     */
    public static function validate(array $input): array
    {
        $type = self::assertType((string) ($input['type'] ?? ''));

        $name = Validator::pattern(
            trim((string) ($input['name'] ?? '')) ?: '@',
            // @ = โดเมนตัวเอง · * = wildcard (เดี่ยว ๆ หรือนำหน้าชื่อย่อย เช่น *.dev)
            // ขีดล่างนำหน้าได้เพราะ SRV/DMARC/DKIM ใช้ (_sip._tcp, _dmarc, _domainkey)
            '/^(@|(\*|[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*)$/i',
            'ชื่อเรกคอร์ดไม่ถูกต้อง',
        );

        $value = self::assertRdata(
            Validator::requireString(['value' => trim((string) ($input['value'] ?? ''))], 'value', self::VALUE_MAX),
        );

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
     * ชื่อชนิดเรกคอร์ดที่ยอมรับได้ — **รูปแบบ ไม่ใช่รายชื่อ**
     *
     * ## ทำไมไม่ใช้รายการปิด
     *
     * รายการปิดตกหล่นเสมอและตกหล่นเงียบ ๆ · สองชนิดที่เจอทุกวันในงานโฮสติ้งอย่าง SRV
     * (Microsoft 365, Teams, SIP) กับ NS (มอบ subdomain ให้ DNS เครื่องอื่น) ไม่ได้อยู่
     * ในรายการเดิม และรายการจะตกหล่นต่อไปเรื่อย ๆ — TLSA, SSHFP, DS, HTTPS/SVCB,
     * NAPTR, PTR · ทุกครั้งที่มีคนเจอชนิดที่ขาด เขาต้องรอโค้ดใหม่ ซึ่งแพงเกินไปสำหรับ
     * "พิมพ์ข้อความสามคำลงไฟล์ที่ BIND อ่านอยู่แล้ว"
     *
     * ตัวตัดสินความถูกต้องจริงคือ **`named-checkzone` ตัวจริง** ซึ่งแม่นกว่ารายชื่อที่เรา
     * เขียนเองได้เสมอ และเป็นตัวเดียวกับที่ BIND ใช้ตอนโหลดจริง — หลักการเดียวกับที่
     * โปรเจกต์นี้ใช้กับไฟล์ตั้งค่าของเว็บเซิร์ฟเวอร์อยู่แล้ว
     *
     * ที่นี่จึงกันแค่สิ่งที่ตัวตรวจของ BIND กันไม่ได้: ข้อความที่ไม่ใช่ชื่อชนิดเลย ·
     * `TYPE65535` เป็นรูปแบบตาม RFC 3597 สำหรับชนิดที่ยังไม่มีชื่อ
     */
    public static function assertType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (preg_match('/^[A-Z][A-Z0-9]{0,14}$/', $type) !== 1) {
            throw new ValidationError(
                'ชนิดเรกคอร์ดไม่ถูกต้อง — ต้องเป็นตัวอักษรกับตัวเลข เช่น A, MX, SRV, TLSA',
            );
        }

        return $type;
    }

    /**
     * ค่าของเรกคอร์ดต้องไม่มีอักขระควบคุม — **ด่านที่สำคัญที่สุดของการเปิดรับทุกชนิด**
     *
     * ค่าถูกเขียนลงไฟล์ที่ BIND อ่าน · การขึ้นบรรทัดใหม่ได้แปลว่าแทรกเรกคอร์ดเพิ่มเองได้
     * หรือแทรก `$INCLUDE` ให้ BIND ไปอ่านไฟล์อื่นบนเครื่อง — ช่องโหว่ที่ไม่ต้องพึ่ง
     * ชนิดเรกคอร์ดแปลก ๆ เลย แค่ค่า TXT ที่มี `\n` ก็พอ
     *
     * `named-checkzone` จับกรณีนี้ได้เกือบทั้งหมดอยู่แล้ว (แล้วระบบก็คืนไฟล์เดิมให้) แต่
     * การพึ่งตัวตรวจปลายทางอย่างเดียวแปลว่าค่าที่อันตรายถูกเขียนลงดิสก์ไปแล้วหนึ่งครั้ง
     * ทุกครั้งที่มีคนลอง · กันตั้งแต่ต้นทางถูกกว่ามาก
     */
    public static function assertRdata(string $value): string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new ValidationError(
                'ค่าของเรกคอร์ดมีอักขระควบคุมหรือการขึ้นบรรทัดใหม่ปนอยู่ — '
                . 'เรกคอร์ดหนึ่งรายการต้องอยู่ในบรรทัดเดียว',
            );
        }

        return $value;
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
            'CNAME', 'MX', 'NS' => self::assertHostname($type, $value),
            'SRV' => self::assertSrv($value),
            // ชนิดที่ระบบไม่ได้รู้จักเป็นพิเศษ — `named-checkzone` เป็นตัวตัดสิน
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
     * SRV มีสี่ส่วนในค่าเดียว: `priority weight port target`
     *
     * เก็บทั้งก้อนไว้ในคอลัมน์ `value` แทนที่จะแตกเป็นคอลัมน์ใหม่สี่คอลัมน์ — เพราะ
     * ชนิดอื่นที่ระบบเปิดรับก็มีโครงสร้างของตัวเองคนละแบบ (TLSA มีสาม, NAPTR มีหก)
     * การไล่เพิ่มคอลัมน์ตามแต่ละชนิดคือการกลับไปสู่รายการปิดในรูปแบบอื่น
     *
     * ตรวจแค่รูปตัวเลขสามตัวแรกกับพอร์ตที่อยู่ในช่วง เพราะนั่นคือความผิดพลาดที่คนพิมพ์เอง
     * ทำบ่อย (สลับตำแหน่ง weight กับ port) และเป็นแบบที่ BIND รับไว้เงียบ ๆ ได้
     */
    private static function assertSrv(string $value): void
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        if (count($parts) !== 4) {
            throw new ValidationError(
                'เรกคอร์ด SRV ต้องมีสี่ส่วน: ลำดับความสำคัญ น้ำหนัก พอร์ต และชื่อเซิร์ฟเวอร์ '
                . 'เช่น `0 5 5060 sip.example.com.`',
            );
        }

        foreach (array_slice($parts, 0, 3) as $index => $number) {
            if (preg_match('/^\d+$/', $number) !== 1 || (int) $number > 65535) {
                throw new ValidationError(sprintf(
                    'เรกคอร์ด SRV: ส่วนที่ %d (%s) ต้องเป็นตัวเลข 0–65535',
                    $index + 1,
                    $number,
                ));
            }
        }

        self::assertHostname('SRV', rtrim($parts[3], '.'));
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

        try {
            $name = self::relativeZoneName($owner, $origin);
        } catch (ValidationError $e) {
            throw new ValidationError(sprintf('บรรทัดที่ %d: %s', $lineNo, $e->getMessage()));
        }

        /*
         * SOA ข้ามเสมอ · **NS ข้ามเฉพาะที่ยอดโดเมน** — ตรงนั้น panel สร้างจาก
         * `dns.nameservers` ของเครื่องเสมอ การเก็บของผู้ใช้ไว้ด้วยจะได้ NS ซ้ำ
         *
         * แต่ **NS ของ subdomain คือของผู้ใช้ล้วน ๆ** — มันคือการมอบโซนย่อยให้ DNS
         * เครื่องอื่นดูแล (delegation) ซึ่งเป็นงานจริงที่พบบ่อย · การข้ามมันไปด้วยแปลว่า
         * ผู้ใช้บันทึกแล้วเรกคอร์ดหายไปเงียบ ๆ โดยหน้าจอบอกว่าสำเร็จ
         */
        if ($type === 'SOA' || ($type === 'NS' && $name === '@')) {
            return null;
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
                'name' => $name,
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

        if (in_array($type, self::HOSTNAME_TYPES, true)) {
            // จุดปิดท้ายถูกเติมกลับให้ตอนเขียนไฟล์ — เก็บแบบไม่มีจุดให้ตรงกับที่ฟอร์มบันทึก
            return rtrim((string) $tokens[0], '.');
        }

        /*
         * ชนิดที่เหลือเก็บ**ทั้งบรรทัดตามที่เขียนมา** — CAA มีสามส่วน SRV มีสี่ TLSA มีสี่
         * NAPTR มีหก · การไล่แตกโครงสร้างตามแต่ละชนิดคือการกลับไปสู่รายการปิดในรูปแบบอื่น
         * และจะตกหล่นชนิดถัดไปเสมอ · `named-checkzone` เป็นตัวตัดสินว่าเขียนถูกไหม
         */
        return implode(' ', $tokens);
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
            $lines[] = self::recordLine($record);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * บรรทัดเดียวของเรกคอร์ด — **ที่เดียวที่ตอบว่าเรกคอร์ดหนึ่งรายการหน้าตาอย่างไร**
     *
     * เคยมีสามที่เขียน `sprintf` ก้อนเดียวกันนี้เอง (ไฟล์จริงบนดิสก์, ไฟล์ส่งออกให้ผู้ใช้
     * ไปวางที่ DNS provider ภายนอก, ช่องแก้ไขทั้งชุด) แล้วมันแยกทางกันจริง ๆ: ไฟล์ส่งออก
     * เป็นที่เดียวที่ไม่เคยเรียก {@see zoneValue()} เลย จึงไม่ห่อคำพูดให้ TXT และไม่เติม
     * จุดปิดท้ายให้ CNAME/MX — ค่าที่ผู้ใช้คัดลอกไปวางที่ผู้ให้บริการภายนอกจึงเป็นคนละค่า
     * กับที่ DNS ของเครื่องนี้ตอบ โดยไม่มีอะไรฟ้อง
     *
     * รวมไว้ที่เดียวแล้วการแก้กฎการเขียนค่าครั้งเดียวมีผลกับทุกที่ที่แสดงเรกคอร์ด —
     * เหมือนที่ zone file ทั้งไฟล์ถูก derive จากฐานข้อมูลเสมอ ไม่ใช่ patch ทีละจุด
     *
     * @param array<string,mixed> $record
     */
    private static function recordLine(array $record): string
    {
        return sprintf(
            '%-20s %-6s IN %-6s %s%s',
            (string) $record['name'],
            (string) $record['ttl'],
            (string) $record['type'],
            ($record['priority'] ?? null) !== null ? $record['priority'] . ' ' : '',
            self::zoneValue((string) $record['type'], (string) $record['value']),
        );
    }

    /**
     * เรกคอร์ดในรูปข้อความสำหรับช่องแก้ไข — **ของผู้ใช้ล้วน ไม่มี SOA/NS ที่ระบบสร้าง**
     *
     * ต่างจาก {@see toAuthoritativeZoneFile()} ตรงที่ไม่มีส่วนหัวที่ระบบเป็นเจ้าของ ·
     * การเอา SOA กับ serial มาแสดงในช่องแก้ไขคือการชวนให้แก้สิ่งที่แก้ไม่ได้ แล้วผู้ใช้
     * จะเสียเวลาปรับ serial ที่ระบบเขียนทับให้ทุกครั้งอยู่ดี
     *
     * ค่าถูกจัดรูปแบบเหมือนที่จะถูกเขียนลงไฟล์จริงทุกประการ (จุดปิดท้าย, เครื่องหมายคำพูด
     * ของ TXT) — **สิ่งที่เห็นในช่องแก้ไขต้องเป็นสิ่งที่จะได้จริง** และการวางกลับเข้าไป
     * โดยไม่แก้อะไรต้องได้เรกคอร์ดชุดเดิมเป๊ะ ๆ
     *
     * @param list<array<string,mixed>> $records
     */
    public static function toEditableRecords(string $domain, array $records): string
    {
        $lines = [
            '; เรกคอร์ดของ ' . $domain . ' — แก้ได้ทั้งหมด บันทึกแล้วแทนที่ของเดิมทั้งชุด',
            '; รายการที่ลบออกจากข้อความนี้คือลบจริง',
            ';',
            '; SOA และ NS ของตัวโดเมนเองไม่ได้อยู่ที่นี่ เพราะระบบสร้างจากค่าตั้งของเครื่องเสมอ',
            '; ชื่อที่ลงท้ายด้วยจุดคือชื่อเต็ม · `@` คือตัวโดเมนเอง',
            '',
        ];

        foreach ($records as $record) {
            $lines[] = self::recordLine($record);
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
            $lines[] = self::recordLine($record);
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
        if (in_array($type, self::HOSTNAME_TYPES, true)) {
            return self::fqdn($value);
        }

        if ($type !== 'TXT') {
            // ชนิดที่เก็บค่าทั้งบรรทัด (SRV, CAA, TLSA, ...) เขียนออกไปตามที่เก็บไว้เป๊ะ ๆ
            return $value;
        }

        return self::txtCharacterStrings($value);
    }

    /**
     * ห่อค่า TXT เป็น character-string ตามที่ RFC 1035 §3.3.14 กำหนด — **ก้อนละไม่เกิน 255 ไบต์**
     *
     * ## บั๊กจริงที่ข้อนี้แก้ (เจอบนเซิร์ฟเวอร์จริง 2026-08-14)
     *
     * กุญแจ DKIM ขนาด 2048 บิตยาวราว 400 ตัวอักษร · ระบบตั้งใจรองรับอยู่แล้ว
     * ({@see VALUE_MAX} = 4096 และ {@see zoneRdata()} ต่อสตริงที่ถูกตัดมาแล้วกลับเป็น
     * ค่าเดียวเพื่อไม่ให้กุญแจขาดกลาง) แต่ตอน**เขียนกลับ**ออกไปห่อเป็นคำพูดก้อนเดียว
     * ซึ่ง BIND ปฏิเสธทั้งไฟล์ด้วยข้อความที่ไม่บอกใบ้อะไรเลย:
     *
     * ```
     * dns_rdata_fromtext: /etc/bind/zones/<domain>.zone:22: syntax error
     * ```
     *
     * ผลคือทั้งโซนคืนค่าเดิม — เรกคอร์ดที่ไม่เกี่ยวข้องทุกตัวของโดเมนนั้นค้างอยู่กับ
     * ค่าเก่า ทั้งที่หน้าจอบอกว่าบันทึกแล้ว · การตัดเป็นก้อนตอนเขียนคือการแก้ที่ถูกที่
     * เพราะ resolver ต่อทุกก้อนกลับเป็นค่าเดียวให้เองอยู่แล้ว ค่าที่ปลายทางได้รับ
     * จึงเท่าเดิมเป๊ะ ๆ ไม่ว่าจะถูกตัดกี่ก้อน
     *
     * ตัดจาก**ไบต์ดิบก่อน escape** ไม่ใช่หลัง — `\"` ยาวสองไบต์ในไฟล์แต่นับเป็นไบต์เดียว
     * บนสาย การตัดหลัง escape จึงทั้งได้ก้อนที่ยาวเกินจริงและมีโอกาสผ่ากลาง escape
     * sequence จนคำพูดปิดผิดที่
     */
    private static function txtCharacterStrings(string $value): string
    {
        $raw = self::txtRawBytes($value);

        if ($raw === '') {
            return '""';
        }

        $quoted = array_map(
            // คำพูดที่อยู่กลางค่าต้อง escape ไม่งั้นมันปิดสตริงก่อนเวลา
            static fn (string $chunk): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $chunk) . '"',
            str_split($raw, self::TXT_STRING_MAX),
        );

        return implode(' ', $quoted);
    }

    /**
     * ค่า TXT ในรูปไบต์จริงบนสาย — ถอดคำพูดออกถ้าค่าที่เก็บไว้ใส่มาแล้ว
     *
     * ค่าที่เก็บในฐานข้อมูลมาได้สองแบบ: ไม่มีคำพูดเลย (ฟอร์มเพิ่มเรกคอร์ด และ
     * {@see zoneRdata()} ที่ถอดให้แล้ว) หรือมีคำพูดติดมา (ผู้ใช้วางค่าตามที่ผู้ให้บริการ
     * เมลให้มาทั้งก้อน) · ทั้งสองแบบต้องได้ไบต์ชุดเดียวกันก่อนตัด ไม่งั้นค่าที่มีคำพูด
     * ติดมาจะถูกนับความยาวเกินจริงแล้วตัดผิดตำแหน่ง
     *
     * คำพูดต้องครบทุกโทเคนถึงจะถือว่าเป็นการห่อ — ค่าอย่าง `"quoted" ไม่ห่อ` แปลว่า
     * คำพูดเป็นส่วนหนึ่งของข้อความเอง ต้อง escape ไม่ใช่ถอดทิ้ง
     */
    private static function txtRawBytes(string $value): string
    {
        if (!str_starts_with($value, '"')) {
            return $value;
        }

        $tokens = self::tokenizeZoneLine($value);

        foreach ($tokens as $token) {
            if (strlen($token) < 2 || !str_starts_with($token, '"') || !str_ends_with($token, '"')) {
                return $value;
            }
        }

        return implode('', array_map(static fn (string $t): string => self::unquoteZoneToken($t), $tokens));
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
