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
