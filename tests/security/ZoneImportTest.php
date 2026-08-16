<?php

declare(strict_types=1);

/**
 * แก้เรกคอร์ดทั้ง zone เป็นข้อความ — จุดที่พลาดแล้ว DNS ของลูกค้าหายทั้งโดเมน
 *
 * ## สิ่งที่ชุดนี้เฝ้า
 *
 *   1. **ไป-กลับต้องได้ของเดิม** — ไฟล์ที่ระบบสร้างเอง แปลงกลับแล้วต้องได้เรกคอร์ดชุดเดิม
 *      เป๊ะ ๆ · ถ้าข้อนี้พัง ผู้ใช้ที่กดบันทึกโดยไม่แก้อะไรเลยจะเสียข้อมูลบางส่วนไปเงียบ ๆ
 *      ซึ่งเป็นความเสียหายที่ไม่มีใครสงสัยว่าเกิดจากการกดปุ่ม "บันทึก" ที่ไม่ได้แก้อะไร
 *   2. **สิ่งที่แปลงกลับไม่ได้ต้องถูกปฏิเสธพร้อมบอกบรรทัด** ไม่ใช่ข้ามไปเงียบ ๆ ·
 *      การข้ามเรกคอร์ดที่อ่านไม่ออกคือการลบมันทิ้งโดยที่ผู้ใช้คิดว่าบันทึกสำเร็จ
 *   3. **`$INCLUDE` และค่าที่ขึ้นบรรทัดใหม่ต้องไม่ผ่าน** — ทั้งคู่สั่งให้ BIND อ่านไฟล์อื่น
 *      บนเครื่องได้ · ข้อหลังสำคัญกว่าเพราะไม่ต้องพึ่งชนิดเรกคอร์ดแปลก ๆ เลย
 *   4. **ล้มแล้วต้องคืนเรกคอร์ดเดิม** — ไม่ใช่ทิ้งค่าใหม่ที่ BIND ไม่รับไว้ในฐานข้อมูล
 */

use Phpcp\Agent\Capability\DnsZoneImport;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Agent\Capability\DnsZoneRead;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DnsRecord;
use Phpcp\Kernel\Config;

group('ZoneImport — แก้เรกคอร์ดทั้ง zone เป็นข้อความ');

/** @return list<string> ลายนิ้วมือที่ไม่ขึ้นกับลำดับ */
function zoneFingerprint(array $records): array
{
    $keys = array_map(
        static fn (array $r): string => sprintf(
            '%s|%s|%s|%d|%s',
            $r['type'],
            $r['name'],
            $r['value'],
            (int) $r['ttl'],
            $r['priority'] === null ? '-' : $r['priority'],
        ),
        $records,
    );

    sort($keys);

    return $keys;
}

test('ไฟล์ที่ระบบสร้างเอง แปลงกลับต้องได้เรกคอร์ดชุดเดิมเป๊ะ', static function (): void {
    /*
     * **ข้อที่สำคัญที่สุดของทั้งชุด**
     *
     * ผู้ใช้เปิดหน้านี้เพื่อดูภาพรวม แล้วกดบันทึกโดยไม่ได้ตั้งใจแก้อะไร — ถ้าการไป-กลับ
     * ไม่ตรง เขาจะเสียเรกคอร์ดไปโดยไม่มีอะไรบอก และไม่มีใครสงสัยปุ่มที่ "ไม่ได้แก้อะไร"
     *
     * ครอบทุกชนิดที่ระบบรองรับ รวมค่าที่มักทำให้ตัวแปลงพัง: TXT ที่มี `;` อยู่ข้างใน
     * (SPF) · CAA ที่มีเครื่องหมายคำพูด · MX ที่มีลำดับความสำคัญ · wildcard
     */
    $records = [
        ['name' => '@', 'type' => 'A', 'value' => '203.0.113.5', 'ttl' => 3600, 'priority' => null],
        ['name' => '*', 'type' => 'A', 'value' => '203.0.113.6', 'ttl' => 600, 'priority' => null],
        ['name' => 'ipv6', 'type' => 'AAAA', 'value' => '2001:db8::1', 'ttl' => 3600, 'priority' => null],
        ['name' => 'www', 'type' => 'CNAME', 'value' => 'roundtrip.test', 'ttl' => 3600, 'priority' => null],
        ['name' => '@', 'type' => 'MX', 'value' => 'mail.roundtrip.test', 'ttl' => 3600, 'priority' => 10],
        ['name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 include:_spf.google.com; -all', 'ttl' => 3600, 'priority' => null],
        ['name' => '@', 'type' => 'CAA', 'value' => '0 issue "letsencrypt.org"', 'ttl' => 3600, 'priority' => null],
    ];

    $file = DnsRecord::toAuthoritativeZoneFile(
        'roundtrip.test',
        $records,
        2026081301,
        ['ns1.myhostingcompany.net'],
        'admin@roundtrip.test',
    );

    $back = DnsRecord::parseZoneFile('roundtrip.test', $file);

    assertSame(
        zoneFingerprint($records),
        zoneFingerprint($back),
        "ไป-กลับต้องได้ของเดิมทุกรายการ · ไฟล์ที่แปลง:\n" . $file,
    );

    // SOA กับ NS ต้องถูกข้าม ไม่ใช่กลายเป็นเรกคอร์ดปลอมเพิ่มมาในตาราง
    assertSame(count($records), count($back), 'SOA/NS ต้องไม่กลายเป็นเรกคอร์ดในระบบ');
});

test('แทนที่ MX ทั้งชุดด้วยของ Google ได้ในคำสั่งเดียว', static function (): void {
    /*
     * งานจริงที่ทำให้ต้องมีหน้านี้ · **ต้องลบ MX เดิมออกให้หมดพร้อมกับใส่ของใหม่** ไม่งั้น
     * เมลจะวิ่งไปสองทางพร้อมกัน · การไล่กดลบทีละรายการทำให้ zone อยู่ในสภาพครึ่ง ๆ กลาง ๆ
     * จริง ๆ บนอินเทอร์เน็ตระหว่างนั้น
     *
     * รับรูปแบบที่คนคัดลอกมาจากหน้าช่วยเหลือของ Google จริง ๆ: บางบรรทัดมี `IN` บางบรรทัด
     * ไม่มี · TTL อยู่บ้างไม่อยู่บ้าง
     */
    $text = <<<'ZONE'
    $TTL 3600
    @   IN  MX  1 aspmx.l.google.com.
    @   IN  MX  5 alt1.aspmx.l.google.com.
    @   IN  MX  5 alt2.aspmx.l.google.com.
    @       MX  10 alt3.aspmx.l.google.com.
    @   1h  IN  MX  10 alt4.aspmx.l.google.com.
    ZONE;

    $records = DnsRecord::parseZoneFile('mx.test', $text);

    assertSame(5, count($records), 'ต้องได้ MX ครบห้ารายการ');

    $priorities = array_map(static fn (array $r): int => (int) $r['priority'], $records);
    assertSame([1, 5, 5, 10, 10], $priorities, 'ลำดับความสำคัญต้องตรงตามที่เขียน');

    assertSame('aspmx.l.google.com', $records[0]['value'], 'จุดปิดท้ายต้องถูกถอดออกให้ตรงกับที่ฟอร์มบันทึก');
    assertSame(3600, $records[4]['ttl'], 'TTL แบบมีหน่วย (1h) ต้องถูกแปลงเป็นวินาที');
});

test('ค่าที่คนพิมพ์ต่อกันหลายบรรทัดต้องต่อกลับเป็นค่าเดียว', static function (): void {
    // กุญแจ DKIM ยาวเกิน 255 ไบต์จึงถูกตัดเป็นหลายสตริงเสมอ · ถ้าไม่ต่อกลับ กุญแจจะขาด
    // กลางแล้วลายเซ็นเมลใช้ไม่ได้ทั้งโดเมน โดยที่หน้าจอแสดงว่าบันทึกสำเร็จ
    $records = DnsRecord::parseZoneFile(
        'dkim.test',
        '@ IN TXT "v=DKIM1; k=rsa; p=MIGfMA0GCSq" "GSIb3DQEBAQUAA4GNADCBiQ"',
    );

    assertSame(
        'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ',
        $records[0]['value'],
        'สตริงที่วางต่อกันต้องกลายเป็นค่าเดียว',
    );
});

test('คอมเมนต์ที่อยู่ในเครื่องหมายคำพูดต้องไม่ถูกตัดทิ้ง', static function (): void {
    // `;` เป็นตัวเปิดคอมเมนต์ของ zone file แต่ก็อยู่กลางค่า SPF เป็นเรื่องปกติ ·
    // การตัดด้วย explode(';') ทำลายค่าที่ถูกต้องเงียบ ๆ แล้วไปโผล่เป็นเมลส่งไม่ออกทีหลัง
    $records = DnsRecord::parseZoneFile(
        'spf.test',
        '@ IN TXT "v=spf1 mx a; -all"   ; คอมเมนต์จริงที่ต้องถูกตัด',
    );

    assertSame('v=spf1 mx a; -all', $records[0]['value'], 'เครื่องหมาย ; ในค่าต้องอยู่ครบ');
});

test('สิ่งที่แปลงกลับไม่ได้ต้องถูกปฏิเสธพร้อมบอกหมายเลขบรรทัด', static function (): void {
    /*
     * **ข้ามเรกคอร์ดที่อ่านไม่ออกไปเงียบ ๆ คือการลบมันทิ้ง** โดยที่ผู้ใช้เห็นข้อความว่า
     * บันทึกสำเร็จ · ทุกกรณีต้องปฏิเสธทั้งไฟล์ และต้องบอกบรรทัดเสมอ ไม่งั้นผู้ใช้ต้อง
     * ไล่หาเองในข้อความ 50 บรรทัด
     */
    $cases = [
        'ชื่อชนิดที่ไม่ใช่ชื่อชนิด' => "@ IN A 203.0.113.5\n@ IN 123 foo",
        'ชื่อนอกโดเมน' => "other.test. IN A 203.0.113.5",
        'MX ที่ไม่มีลำดับความสำคัญ' => "@ IN MX mail.zone.test.",
        'ค่าไม่เข้ากับชนิด' => "@ IN A ไม่ใช่ไอพี",
        'CNAME ที่ชี้ไปไอพี' => "www IN CNAME 203.0.113.5",
        'TTL ที่ไม่ใช่ตัวเลข' => "\$TTL abc\n@ IN A 203.0.113.5",
        'วงเล็บที่เปิดแล้วไม่ปิด' => "@ IN TXT ( \"ค้างไว้\"",
    ];

    foreach ($cases as $why => $text) {
        $message = '';

        try {
            DnsRecord::parseZoneFile('zone.test', $text);
        } catch (ValidationError $e) {
            $message = $e->getMessage();
        }

        assertTrue($message !== '', "ต้องปฏิเสธ: {$why}");
        assertTrue(
            str_contains($message, 'Line '),
            "ต้องบอกหมายเลขบรรทัดด้วย ({$why}): {$message}",
        );
        assertTrue(
            !str_contains($message, 'Line 0'),
            "หมายเลขบรรทัดต้องไม่เป็นศูนย์ ({$why}): {$message}",
        );
    }
});

test('ชื่อที่ลืมจุดปิดท้ายต้องถูกปฏิเสธ ไม่ใช่เดาให้', static function (): void {
    /*
     * BIND อ่าน `example.com` (ไม่มีจุด) เป็น `example.com.example.com.` ซึ่งเกือบทุกครั้ง
     * ไม่ใช่สิ่งที่คนพิมพ์ตั้งใจ · **การเดาให้เป็น `@` เงียบ ๆ อันตรายกว่าการปฏิเสธ**
     * เพราะผู้ใช้จะไม่มีวันรู้ว่าตัวเองเข้าใจกติกาผิด แล้วไปพลาดซ้ำที่อื่นซึ่งไม่มีใครแก้ให้
     */
    $message = '';

    try {
        DnsRecord::parseZoneFile('guess.test', 'guess.test IN A 203.0.113.5');
    } catch (ValidationError $e) {
        $message = $e->getMessage();
    }

    assertTrue($message !== '', 'ต้องปฏิเสธชื่อที่เท่ากับโดเมนแต่ไม่มีจุดปิดท้าย');
    assertTrue(str_contains($message, '@'), 'ต้องบอกว่าให้ใช้ @ แทน: ' . $message);
    assertTrue(str_contains($message, 'guess.test.guess.test'), 'ต้องแสดงว่า BIND จะอ่านเป็นอะไร: ' . $message);
});

test('$INCLUDE ต้องไม่ผ่านเด็ดขาด', static function (): void {
    /*
     * `$INCLUDE` สั่งให้ BIND อ่านไฟล์อื่นบนเครื่อง · ที่นี่ข้อความไม่ได้ถูกเขียนลงดิสก์
     * อยู่แล้ว (ถูกแปลงเป็นแถวในฐานข้อมูล) มันจึงไปถึง BIND ไม่ได้ตั้งแต่ต้น — แต่การ
     * ปฏิเสธอย่างชัดเจนพร้อมบอกเหตุผลดีกว่าการข้ามเงียบ ๆ ซึ่งทำให้ผู้ใช้เข้าใจว่าใช้ได้
     */
    foreach (['$INCLUDE /etc/shadow', '$ORIGIN evil.test.', '$GENERATE 1-10 host$ A 10.0.0.$'] as $directive) {
        $message = '';

        try {
            DnsRecord::parseZoneFile('zone.test', $directive);
        } catch (ValidationError $e) {
            $message = $e->getMessage();
        }

        assertTrue($message !== '', "ต้องปฏิเสธ {$directive}");
    }
});

test('เรกคอร์ดที่มากเกินจริงต้องถูกปฏิเสธ', static function (): void {
    // กันการวางข้อมูลผิดที่ลงช่องแก้ไข — ไม่ใช่ zone ของโดเมนเดียวแล้ว
    $text = str_repeat("host IN A 203.0.113.5\n", DnsRecord::MAX_RECORDS + 5);
    $rejected = false;

    try {
        DnsRecord::parseZoneFile('big.test', $text);
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'ต้องปฏิเสธเมื่อเกินจำนวนที่กำหนด');
});

test('ข้อความว่างแปลว่าลบเรกคอร์ดทั้งหมด ไม่ใช่ข้อผิดพลาด', static function (): void {
    // เป็นคำสั่งที่ถูกต้องและมีความหมายชัดเจน · การปฏิเสธจะทำให้ไม่มีทางลบทั้งชุดได้เลย
    assertSame([], DnsRecord::parseZoneFile('empty.test', ''), 'ข้อความว่างต้องได้เรกคอร์ดศูนย์รายการ');
    assertSame(
        [],
        DnsRecord::parseZoneFile('empty.test', "; เหลือแต่คอมเมนต์\n\n"),
        'ไฟล์ที่มีแต่คอมเมนต์ต้องได้เรกคอร์ดศูนย์รายการ',
    );
});

test('สิทธิ์ของสองคำสั่งต้องตรงกับการแก้เรกคอร์ดทีละรายการ', static function (): void {
    /*
     * แก้ทั้งชุดเป็นงานเดียวกับแก้ทีละรายการ ทำทีเดียวหลายรายการ — สิทธิ์จึงต้องเท่ากัน ·
     * ถ้าเข้มกว่า ผู้ดูแลเว็บไซต์จะแก้ DNS ของโดเมนตัวเองด้วยวิธีที่สะดวกกว่าไม่ได้
     * ถ้าหลวมกว่า จะกลายเป็นทางลัดที่ข้ามสิทธิ์ที่ตั้งใจบังคับไว้
     */
    assertSame('domain.manage', (new DnsZoneImport())->permission(), 'แก้ทั้งชุดใช้สิทธิ์เดียวกับแก้ทีละรายการ');
    assertSame('domain.view', (new DnsZoneRead())->permission(), 'การอ่านใช้สิทธิ์ดูอย่างเดียว');
    assertSame(false, (new DnsZoneRead())->isMutating(), 'การอ่านต้องไม่ถูกนับเป็นคำสั่งที่เปลี่ยนระบบ');
});

test('ตัวเขียนต้องคืนเรกคอร์ดเดิมเมื่อ BIND ไม่รับค่าใหม่', static function (): void {
    /*
     * ฐานข้อมูลถูกสลับก่อนแล้วค่อยเขียนไฟล์ · ถ้า `named-checkzone` ไม่ผ่าน ไฟล์ถูกคืน
     * ให้เองโดย ConfigTransaction แต่**แถวในฐานข้อมูลไม่ได้ถูกคืนด้วย** ถ้าไม่เขียนไว้ —
     * ระบบจะเหลือค่าใหม่ที่ BIND ไม่ยอมรับค้างอยู่ แล้วการ sync ครั้งถัดไปของใครก็ตาม
     * จะล้มตามไปโดยไม่มีใครรู้ว่าเพราะอะไร
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/DnsZoneImport.php'),
    );

    assertTrue(
        substr_count($code, '$this->replace($context, $domainId') >= 2,
        'ต้องมีเส้นทางคืนเรกคอร์ดเดิมเมื่อเขียนไฟล์ล้ม',
    );
    assertTrue(str_contains($code, '$previous'), 'ต้องเก็บเรกคอร์ดเดิมไว้ก่อนสลับ');

    // แปลงข้อความให้ผ่านครบก่อนแตะฐานข้อมูล — บรรทัดที่ 40 ผิดต้องไม่ทิ้ง 39 รายการแรกไว้
    $parsePos = strpos($code, 'parseZoneFile');
    $writePos = strpos($code, 'transaction(');

    assertTrue(
        $parsePos !== false && $writePos !== false && $parsePos < $writePos,
        'ต้องแปลงข้อความทั้งหมดให้ผ่านก่อนเริ่มแก้ฐานข้อมูล',
    );
});

test('ข้อความที่ผู้ใช้แก้ ต้องกลายเป็นไฟล์ที่ named-checkzone ยอมรับจริง', static function (): void {
    /*
     * **ตัวตรวจจริงเป็นคำตอบสุดท้าย ไม่ใช่เทสต์ของเราเอง**
     *
     * ตัวแปลงอาจอ่านข้อความได้ครบและตัววาลิเดเตอร์อาจผ่านหมด แต่ไฟล์ที่ออกมายังใช้ไม่ได้
     * เพราะรายละเอียดที่มีแต่ BIND เท่านั้นที่รู้ — จุดปิดท้ายที่หายไป, ค่า TXT ที่ไม่ได้
     * ห่อคำพูด, wildcard ที่วางผิดที่ · เส้นทางนี้จึงต้องถูกตรวจด้วย `named-checkzone`
     * ตัวจริงแบบเดียวกับที่ `DnsZoneTest` ทำกับเรกคอร์ดที่กรอกผ่านฟอร์ม
     */
    $text = <<<'ZONE'
    $TTL 3600
    @    IN  MX    1 aspmx.l.google.com.
    @    IN  MX    5 alt1.aspmx.l.google.com.
    @    IN  A     203.0.113.5
    www  IN  CNAME oracle.test.
    mail IN  AAAA  2001:db8::1
    @    IN  TXT   "v=spf1 include:_spf.google.com; -all"
    @    IN  CAA   0 issue "letsencrypt.org"
    *    IN  A     203.0.113.9
    ZONE;

    $records = DnsRecord::parseZoneFile('oracle.test', $text);
    $file = DnsRecord::toAuthoritativeZoneFile(
        'oracle.test',
        $records,
        2026081301,
        ['ns1.myhostingcompany.net'],
        'admin@oracle.test',
    );

    [$ok, $output] = checkZoneReal('oracle.test', $file);

    assertTrue($ok, "named-checkzone ต้องยอมรับไฟล์ที่แปลงกลับมา:\n{$output}\n\n{$file}");
});

test('ชนิดที่งานจริงต้องใช้ต้องเก็บได้ — SRV กับ NS ของ subdomain', static function (): void {
    /*
     * สองชนิดนี้เจอทุกวันในงานโฮสติ้งและไม่ได้อยู่ในรายการเดิม:
     *
     *   · **SRV** — Microsoft 365, Teams, SIP, Minecraft
     *   · **NS ของ subdomain** — มอบโซนย่อยให้ DNS เครื่องอื่นดูแล (delegation)
     *
     * **NS ที่ยอดโดเมนต้องถูกข้าม** เพราะระบบสร้างจาก `dns.nameservers` เสมอ · แต่
     * NS ของ subdomain เป็นของผู้ใช้ล้วน ๆ การข้ามมันไปด้วยแปลว่าผู้ใช้บันทึกแล้ว
     * เรกคอร์ดหายไปเงียบ ๆ โดยหน้าจอบอกว่าสำเร็จ
     */
    $records = DnsRecord::parseZoneFile('m365.test', <<<'ZONE'
    _sip._tcp          IN SRV 0 5 5061 sipdir.online.lync.com.
    _autodiscover._tcp IN SRV 0 0 443 autodiscover.outlook.com.
    sub                IN NS  ns1.other-dns.net.
    sub                IN NS  ns2.other-dns.net.
    @                  IN NS  ns1.myhostingcompany.net.
    ZONE);

    $types = array_count_values(array_map(static fn (array $r): string => $r['type'], $records));

    assertSame(2, $types['SRV'] ?? 0, 'SRV ต้องเก็บได้');
    assertSame(2, $types['NS'] ?? 0, 'NS ของ subdomain ต้องเก็บได้');
    assertSame(4, count($records), 'NS ที่ยอดโดเมนต้องถูกข้าม เพราะระบบสร้างเองเสมอ');

    // ค่าของ SRV เก็บทั้งสี่ส่วนไว้ด้วยกัน — การแตกเป็นคอลัมน์รายชนิดคือรายการปิดในรูปแบบอื่น
    $srv = array_values(array_filter($records, static fn (array $r): bool => $r['type'] === 'SRV'));
    assertSame('0 5 5061 sipdir.online.lync.com.', $srv[0]['value'], 'SRV ต้องเก็บค่าครบทั้งสี่ส่วน');
});

test('ชนิดที่ระบบไม่รู้จักต้องเก็บได้ ให้ named-checkzone เป็นคนตัดสิน', static function (): void {
    /*
     * **รายการปิดตกหล่นเสมอ และตกหล่นเงียบ ๆ** — TLSA (DANE), SSHFP, DS (DNSSEC),
     * HTTPS/SVCB (มาตรฐานใหม่ที่เบราว์เซอร์เริ่มใช้แล้ว) · ทุกครั้งที่มีคนเจอชนิดที่ขาด
     * เขาต้องรอโค้ดใหม่ ซึ่งแพงเกินไปสำหรับ "พิมพ์ข้อความสามคำลงไฟล์ที่ BIND อ่านอยู่แล้ว"
     *
     * ตัวตัดสินความถูกต้องคือ `named-checkzone` ตัวจริง ซึ่งแม่นกว่ารายชื่อที่เราเขียนเอง
     * ได้เสมอ — หลักการเดียวกับที่โปรเจกต์นี้ใช้กับไฟล์ตั้งค่าของเว็บเซิร์ฟเวอร์
     */
    $records = DnsRecord::parseZoneFile('modern.test', <<<'ZONE'
    _25._tcp.mail IN TLSA  3 1 1 abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789
    host          IN SSHFP 2 1 123456789abcdef67890123456789abcdef67890
    @             IN HTTPS 1 . alpn=h2
    ZONE);

    assertSame(3, count($records), 'ชนิดที่ระบบไม่รู้จักต้องเก็บได้ตามปกติ');
    assertSame('TLSA', $records[0]['type'], 'ชื่อชนิดต้องถูกเก็บตามที่เขียน');
    assertSame('1 . alpn=h2', $records[2]['value'], 'ค่าต้องถูกเก็บทั้งบรรทัดตามที่เขียน');

    $file = DnsRecord::toAuthoritativeZoneFile(
        'modern.test',
        $records,
        2026081301,
        ['ns1.myhostingcompany.net'],
        'admin@modern.test',
    );

    [$ok, $output] = checkZoneReal('modern.test', $file);

    assertTrue($ok, "named-checkzone ต้องยอมรับชนิดเหล่านี้:\n{$output}\n\n{$file}");
});

test('ค่าที่ขึ้นบรรทัดใหม่ได้คือช่องแทรกเรกคอร์ด — ต้องปิดตั้งแต่ต้นทาง', static function (): void {
    /*
     * **ด่านที่สำคัญที่สุดของการเปิดรับทุกชนิด**
     *
     * ค่าถูกเขียนลงไฟล์ที่ BIND อ่าน · การขึ้นบรรทัดใหม่ได้แปลว่าแทรกเรกคอร์ดเพิ่มเองได้
     * หรือแทรก `$INCLUDE` ให้ BIND ไปอ่านไฟล์อื่นบนเครื่อง — ช่องโหว่ที่ไม่ต้องพึ่งชนิด
     * เรกคอร์ดแปลก ๆ เลย แค่ค่า TXT ที่มี `\n` ก็พอ
     *
     * `named-checkzone` จับได้เกือบทั้งหมดอยู่แล้วแล้วระบบก็คืนไฟล์เดิมให้ — แต่นั่นแปลว่า
     * ค่าที่อันตรายถูกเขียนลงดิสก์ไปแล้วหนึ่งครั้งทุกครั้งที่มีคนลอง · กันที่ต้นทางถูกกว่า
     *
     * เส้นทางนี้เข้ามาทาง **ฟอร์มเพิ่มเรกคอร์ดกับ REST API** ไม่ใช่ทางตัวแปลงข้อความ
     * (ตัวแปลงแยกทีละบรรทัดอยู่แล้ว) — ด่านจึงต้องอยู่ที่ `validate()` ซึ่งทุกทางผ่าน
     */
    foreach ([
        "ok\n@ IN A 203.0.113.5" => 'แทรกเรกคอร์ดเพิ่ม',
        "ok\n\$INCLUDE /etc/shadow" => 'สั่งอ่านไฟล์อื่นบนเครื่อง',
        "ok\rกลับต้นบรรทัด" => 'อักขระควบคุมอื่น',
        "ok\x00ตัดกลาง" => 'ไบต์ศูนย์',
    ] as $value => $why) {
        $rejected = false;

        try {
            DnsRecord::validate(['type' => 'TXT', 'name' => '@', 'value' => $value]);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธค่าที่ {$why}");
    }

    // ชื่อชนิดต้องเป็นชื่อชนิดจริง ๆ ไม่ใช่ช่องให้ยัดข้อความอื่น
    foreach (['', '123', 'A B', 'A' . str_repeat('X', 20)] as $bad) {
        $rejected = false;

        try {
            DnsRecord::assertType($bad);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธชื่อชนิด: {$bad}");
    }
});

test('ตัวเขียนต้องบันทึกลงฐานข้อมูลจริงได้ ไม่ใช่แค่แปลงข้อความผ่าน', static function (): void {
    /*
     * **เทสต์ที่ควรมีตั้งแต่แรก และการไม่มีทำให้ปล่อยของเสียออกไปแล้วหนึ่งรอบ**
     *
     * รอบก่อนตัวเขียนใส่คอลัมน์ `created_at` ที่ตาราง `dns_records` ไม่มี — การแทรกล้ม
     * ทุกครั้งที่บันทึกจริง แต่เทสต์ทั้งหมดผ่านเพราะไม่มีข้อไหนแตะฐานข้อมูลจริงเลย
     * (ตัวแปลงถูกทดสอบแยก · เทสต์ระดับ HTTP หยุดที่ 503 เพราะไม่มี agent ในแท่นทดสอบ)
     *
     * ชั้นที่ไม่มีใครทดสอบคือชั้นที่พัง — ที่นี่จึงรัน capability กับฐานข้อมูลที่ migrate
     * จริงแล้วอ่านแถวกลับมาดู
     */
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'store.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', 'old', '203.0.113.1');

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $context = contextWith($fixture, $config);

    $result = (new DnsZoneImport())->run(
        [
            'domain_id' => (int) $domain['id'],
            'content' => "@ IN A 203.0.113.5\n"
                . "_sip._tcp IN SRV 0 5 5061 sipdir.online.lync.com.\n"
                . "sub IN NS ns1.other-dns.net.\n"
                . "@ IN MX 10 mail.store.test.\n",
        ],
        new DryRunExecutor(),
        $context,
    );

    assertSame(4, $result['record_count'], 'ต้องรายงานจำนวนที่บันทึกจริง');
    assertSame(1, $result['previous_count'], 'ต้องรายงานจำนวนเดิมให้ผู้ใช้เทียบได้');

    $rows = $fixture['db']->all(
        'SELECT type, name, value, ttl, priority FROM dns_records WHERE domain_id = :id ORDER BY type',
        ['id' => $domain['id']],
    );

    assertSame(4, count($rows), 'ต้องมีสี่แถวในฐานข้อมูลจริง');

    $byType = [];
    foreach ($rows as $row) {
        $byType[(string) $row['type']] = $row;
    }

    assertSame('203.0.113.5', $byType['A']['value'] ?? '', 'ค่าของ A ต้องถูกบันทึก');
    assertSame('0 5 5061 sipdir.online.lync.com.', $byType['SRV']['value'] ?? '', 'SRV ต้องเก็บครบทั้งสี่ส่วน');
    assertSame('ns1.other-dns.net', $byType['NS']['value'] ?? '', 'NS ของ subdomain ต้องถูกบันทึก');
    assertSame(10, (int) ($byType['MX']['priority'] ?? 0), 'MX ต้องเก็บลำดับความสำคัญแยกคอลัมน์');

    // เรกคอร์ดเดิมต้องหายไปจริง — เป็นการ "แทนที่ทั้งชุด" ไม่ใช่ "เพิ่มเข้าไป"
    assertSame(
        0,
        (int) $fixture['db']->value(
            "SELECT COUNT(*) FROM dns_records WHERE domain_id = :id AND name = 'old'",
            ['id' => $domain['id']],
        ),
        'รายการที่ไม่อยู่ในข้อความต้องถูกลบจริง',
    );
});

test('เขียนไฟล์ล้มแล้วเรกคอร์ดในฐานข้อมูลต้องกลับเป็นของเดิม', static function (): void {
    /*
     * ฐานข้อมูลถูกสลับก่อนแล้วค่อยเขียนไฟล์ · ถ้าขั้นเขียนไฟล์ล้ม ไฟล์ถูกคืนให้เองโดย
     * ConfigTransaction แต่**แถวในฐานข้อมูลไม่ได้ถูกคืนด้วย**ถ้าไม่เขียนไว้ — ระบบจะเหลือ
     * ค่าใหม่ที่ BIND ไม่ยอมรับค้างอยู่ แล้วการ sync ครั้งถัดไปของใครก็ตามจะล้มตามไป
     * โดยไม่มีใครรู้ว่าเพราะอะไร
     *
     * จำลองความล้มที่ระดับการเขียนไฟล์ ไม่ใช่ที่ค่าตั้ง — `dnsEnabled()`/`dnsNameservers()`
     * อ่านจาก static ที่ `Config::useStoredSettings()` เติมไว้ก่อนค่าจากไฟล์เสมอ เทสต์ที่
     * พึ่งค่าตั้งจึงผ่านตอนรันเดี่ยวแต่ล้มตอนรันทั้งชุด (เจอจริงตอนเขียนข้อนี้)
     */
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'revert.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', 'keep', '203.0.113.1');

    /*
     * **ต้องตั้งค่าที่ static นี้ ไม่ใช่ที่ไฟล์ config** — `dnsEnabled()` อ่านจากค่าที่
     * `Config::useStoredSettings()` เติมไว้**ก่อน**ค่าจากไฟล์เสมอ · เทสต์ตัวใดก็ตามที่
     * บูต App ทิ้งค่าของฐานข้อมูลตัวเองไว้ให้ทั้งกระบวนการ ทำให้เทสต์ที่พึ่งไฟล์ config
     * ผ่านตอนรันเดี่ยวแต่ล้มตอนรันทั้งชุด (เจอจริงตอนเขียนข้อนี้)
     *
     * ล้างทิ้งตอนจบเพื่อไม่ส่งต่อปัญหาเดิมให้เทสต์ถัดไป — App ที่บูตทีหลังเติมค่าของ
     * ตัวเองใหม่ทุกครั้งอยู่แล้ว
     */
    Config::useStoredSettings(['dns.enabled' => '1', 'dns.nameservers' => 'ns1.myhostingcompany.net']);

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $executor = new BindFakeExecutor();
    $executor->failWritesMatching = '.zone';
    $failed = false;

    try {
        (new DnsZoneImport())->run(
            ['domain_id' => (int) $domain['id'], 'content' => "@ IN A 203.0.113.99\n"],
            $executor,
            contextWith($fixture, $config),
        );
    } catch (\Throwable) {
        $failed = true;
    } finally {
        Config::useStoredSettings([]);
    }

    assertTrue($failed, 'ต้องล้มเมื่อ BIND ไม่รับค่าใหม่');

    $rows = $fixture['db']->all(
        'SELECT name, value FROM dns_records WHERE domain_id = :id',
        ['id' => $domain['id']],
    );

    assertSame(1, count($rows), 'ต้องเหลือเรกคอร์ดเดิมรายการเดียว');
    assertSame('keep', (string) $rows[0]['name'], 'ต้องเป็นเรกคอร์ดเดิม ไม่ใช่ของรอบที่ล้ม');
    assertSame('203.0.113.1', (string) $rows[0]['value'], 'ค่าต้องเป็นของเดิมทุกตัวอักษร');
});
