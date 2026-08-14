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

        $wanted = self::forDomain($domain, $ip, $nameservers);

        /*
         * โดเมนที่เครื่องนี้ให้บริการอยู่แล้วและอยู่**ใต้** zone ที่กำลังสร้าง ต้องมีชื่อ
         * อยู่ใน zone นี้ด้วยตั้งแต่วินาทีแรก
         *
         * **เจอบนเซิร์ฟเวอร์จริง 2026-08-14:** เครื่องให้บริการ `srv.example.com` อยู่ก่อน
         * โดยอาศัยเรกคอร์ดที่ผู้รับจดโดเมน · พอผู้ดูแลสร้างเว็บ `example.com` แล้วชี้ NS
         * มาที่เครื่องนี้ zone ใหม่ไม่มีชื่อ `srv` เลย — เว็บที่ใช้งานได้อยู่กลายเป็น
         * NXDOMAIN ทันทีและ certbot ต่ออายุใบรับรองไม่ได้อีก โดยไม่มีอะไรเตือนสักคำ
         *
         * การรับ zone มาดูแลต้องไม่ทำให้สิ่งที่เครื่องนี้ให้บริการอยู่แล้วหายไป
         */
        foreach ($db->all('SELECT domain FROM domains WHERE id <> :id', ['id' => $domainId]) as $row) {
            $label = self::labelInside((string) $row['domain'], $domain);

            if ($label === null || $label === '@') {
                continue;
            }

            foreach (self::forSubdomain($label, $ip) as $record) {
                $wanted[] = $record;
            }
        }

        $created = [];

        foreach ($wanted as $record) {
            if (in_array($record['name'], $existing, true)) {
                continue;
            }

            $db->insert('dns_records', ['domain_id' => $domainId] + $record);
            $created[] = $record['name'];
        }

        return $created;
    }

    /**
     * zone ที่ **ควร** เก็บเรกคอร์ดของโดเมนนี้ — null = โดเมนนี้เป็น zone ของตัวเอง
     *
     * ## ทำไมต้องมี
     *
     * เว็บสองแห่งที่เป็นแม่ลูกกันทางชื่อ (`example.com` กับ `srv.example.com`) ถูกเก็บ
     * เป็นสองแถวใน `domains` เท่ากัน · แต่ในโลกของ DNS มันไม่เท่ากันเลย: เมื่อเครื่องนี้
     * เป็นเจ้าของ zone `example.com` แล้ว **ชื่อ `srv.example.com` ต้องเป็นเรกคอร์ด
     * อยู่ข้างใน zone นั้น** ไม่ใช่ zone แยกอีกไฟล์ (นอกจากจะตั้งใจ delegate ออกไป
     * ซึ่งเป็นงานคนละอย่างที่ต้องมี NS record บอกชัด)
     *
     * **เจอบนเซิร์ฟเวอร์จริง 2026-08-14:** เครื่องมี `srv.example.com` ให้บริการอยู่ก่อน
     * โดยอาศัยเรกคอร์ดที่ผู้รับจดโดเมน · พอผู้ดูแลสร้างเว็บ `example.com` แล้วชี้ NS
     * มาที่เครื่องนี้ zone ใหม่ไม่มีชื่อ `srv` อยู่เลย — `srv.example.com` จึงกลายเป็น
     * NXDOMAIN ทันที เว็บที่ใช้งานได้อยู่ล่มโดยไม่มีอะไรเตือน และ certbot ต่ออายุ
     * ใบรับรองไม่ได้อีกเลยเพราะพิสูจน์สิทธิ์ไม่ผ่าน
     *
     * เลือก zone ที่ยาวที่สุดที่ครอบชื่อนี้ — เครื่องที่โฮสต์ทั้ง `example.com` และ
     * `sub.example.com` เป็น zone แยกกันจริง ๆ ต้องได้ zone ที่ใกล้ที่สุดเป็นเจ้าของ
     *
     * @return array{id:int,domain:string,label:string}|null
     */
    public static function parentZone(Db $db, string $domain, int $exceptDomainId = 0): ?array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $best = null;

        foreach ($db->all('SELECT id, domain FROM domains') as $row) {
            $candidate = strtolower(rtrim((string) $row['domain'], '.'));

            if ((int) $row['id'] === $exceptDomainId || $candidate === $domain) {
                continue;
            }

            if (!str_ends_with($domain, '.' . $candidate)) {
                continue;
            }

            if ($best === null || strlen($candidate) > strlen($best['domain'])) {
                $best = [
                    'id' => (int) $row['id'],
                    'domain' => $candidate,
                    'label' => substr($domain, 0, -strlen('.' . $candidate)),
                ];
            }
        }

        return $best;
    }

    /**
     * เรกคอร์ดของชื่อที่อยู่ **ใต้ zone ของคนอื่น** — ใช้แทน forDomain() ในกรณีนั้น
     *
     * ได้ `srv` กับ `www.srv` เทียบเท่ากับ `@` กับ `www` ของ zone ปกติ เพื่อให้
     * พฤติกรรมที่ผู้ดูแลเห็นเหมือนกันไม่ว่าโดเมนจะอยู่ระดับไหน
     *
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:null}>
     */
    public static function forSubdomain(string $label, string $ip): array
    {
        if (!ServerAddress::isIpv4($ip) || trim($label) === '') {
            return [];
        }

        return array_map(
            static fn (string $name): array => [
                'type' => 'A',
                'name' => $name,
                'value' => $ip,
                'ttl' => self::TTL,
                'priority' => null,
            ],
            [$label, 'www.' . $label],
        );
    }

    /**
     * เขียนเรกคอร์ดของ subdomain ลง zone แม่ — เรียกซ้ำได้
     *
     * @return list<string>
     */
    public static function seedSubdomain(Db $db, int $parentDomainId, string $label, string $ip): array
    {
        $existing = array_column(
            $db->all('SELECT name FROM dns_records WHERE domain_id = :id', ['id' => $parentDomainId]),
            'name',
        );

        $created = [];

        foreach (self::forSubdomain($label, $ip) as $record) {
            if (in_array($record['name'], $existing, true)) {
                continue;
            }

            $db->insert('dns_records', ['domain_id' => $parentDomainId] + $record);
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
