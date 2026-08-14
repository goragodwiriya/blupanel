<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * เรกคอร์ดชุดแรกที่โดเมนต้องมีเพื่อให้ zone ใช้งานได้จริง
 *
 * ## ปัญหาที่แก้
 *
 * เดิมการสร้างเว็บไม่สร้างเรกคอร์ด DNS ให้เลยแม้แต่ตัวเดียว · ผู้ดูแลได้โดเมนที่มี
 * หน้า DNS ว่างเปล่า ต้องพิมพ์เองทุกบรรทัด และ **zone file จะไม่เกิดขึ้นเลย**
 * จนกว่าจะเพิ่มเรกคอร์ดแรก — ต่างจาก cPanel/DirectAdmin/Plesk ที่สร้างโดเมนแล้วได้
 * zone ที่ใช้งานได้ทันที
 *
 * ## glue record คือส่วนที่พลาดแล้ว zone เสียทั้งไฟล์
 *
 * `dns.nameservers` มักตั้งเป็นชื่อที่อยู่**ในโดเมนของตัวเอง** (`ns1.example.com`
 * สำหรับ zone `example.com`) · เมื่อชื่อของเนมเซิร์ฟเวอร์อยู่ใต้ zone ที่มันดูแลเอง
 * ตัว zone ต้องมี A record ของชื่อนั้นอยู่ข้างในด้วย ไม่งั้นไม่มีใครหาที่อยู่ของมันเจอ
 * (ปัญหาไก่กับไข่) · `named-checkzone` ปฏิเสธตรง ๆ ว่า
 * *"NS 'ns1.example.com' has no address records"* แล้ว **ทั้ง zone โหลดไม่ขึ้น**
 *
 * เจอบนเซิร์ฟเวอร์จริง 2026-08-14: zone แรกของเครื่องเขียนไม่ผ่านด้วยเหตุนี้พอดี
 *
 * ## ที่ไม่ใส่ให้
 *
 * ไม่ใส่ MX — โดเมนที่ยังไม่เปิดเมลไม่ควรประกาศว่ารับเมล เพราะเมลที่ส่งมาจะเด้ง
 * แบบถาวรแทนที่จะไปที่เดิมของลูกค้า · `MailDomainSet` เป็นคนใส่ให้ตอนเปิดเมลจริง
 */
final class DnsZoneDefaults
{
    /** TTL สั้นในช่วงแรกเพื่อให้แก้ผิดแล้วกระจายเร็ว — ผู้ดูแลปรับขึ้นเองได้ภายหลัง */
    public const TTL = 3600;

    /**
     * เรกคอร์ดที่ควรมีสำหรับโดเมนหนึ่ง
     *
     * @param  list<string> $nameservers ชื่อเนมเซิร์ฟเวอร์ตามค่าตั้งของระบบ
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:null}>
     */
    public static function forDomain(string $domain, string $ip, array $nameservers): array
    {
        if (!ServerAddress::isIpv4($ip)) {
            return [];
        }

        $names = ['@', 'www'];

        // glue ของเนมเซิร์ฟเวอร์ที่อยู่ใต้ zone นี้ — ดูเหตุผลที่หัวคลาส
        foreach ($nameservers as $ns) {
            $label = self::labelInside($ns, $domain);

            if ($label !== null && !in_array($label, $names, true)) {
                $names[] = $label;
            }
        }

        return array_map(
            static fn (string $name): array => [
                'type' => 'A',
                'name' => $name,
                'value' => $ip,
                'ttl' => self::TTL,
                'priority' => null,
            ],
            $names,
        );
    }

    /**
     * เขียนเรกคอร์ดที่ยังไม่มีลงฐานข้อมูล — เรียกซ้ำได้โดยไม่สร้างของซ้ำ
     *
     * ข้ามชื่อที่มีเรกคอร์ดอยู่แล้ว**ไม่ว่าชนิดใด** ไม่ใช่แค่ชนิดเดียวกัน · ผู้ดูแลที่
     * ตั้ง `www` เป็น CNAME ไว้เองต้องไม่ได้ A record ซ้อนเข้ามาเงียบ ๆ ซึ่งจะทำให้
     * zone ขัดกันเอง (CNAME อยู่ร่วมกับเรกคอร์ดชนิดอื่นที่ชื่อเดียวกันไม่ได้ตามมาตรฐาน)
     *
     * @param  list<string> $nameservers
     * @return list<string> ชื่อที่เพิ่งสร้าง
     */
    public static function seed(Db $db, int $domainId, string $domain, string $ip, array $nameservers): array
    {
        $existing = array_column(
            $db->all('SELECT name FROM dns_records WHERE domain_id = :id', ['id' => $domainId]),
            'name',
        );

        $created = [];

        foreach (self::forDomain($domain, $ip, $nameservers) as $record) {
            if (in_array($record['name'], $existing, true)) {
                continue;
            }

            $db->insert('dns_records', ['domain_id' => $domainId] + $record);
            $created[] = $record['name'];
        }

        return $created;
    }

    /**
     * ส่วนหน้าของ `$ns` เมื่อมันอยู่ใต้ `$domain` — null เมื่ออยู่คนละ zone
     *
     * `ns1.example.com` ใต้ `example.com` → `ns1`
     * `ns1.other.com`   ใต้ `example.com` → null (คนละ zone ไม่ต้องมี glue)
     * `example.com`     ใต้ `example.com` → `@`
     */
    private static function labelInside(string $ns, string $domain): ?string
    {
        $ns = strtolower(rtrim(trim($ns), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($ns === '' || $domain === '') {
            return null;
        }

        if ($ns === $domain) {
            return '@';
        }

        return str_ends_with($ns, '.' . $domain)
            ? substr($ns, 0, -strlen('.' . $domain))
            : null;
    }
}
