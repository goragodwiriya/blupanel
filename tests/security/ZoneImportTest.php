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
 *   3. **`$INCLUDE` ต้องไม่ผ่าน** — มันสั่งให้ BIND อ่านไฟล์อื่นบนเครื่อง
 *   4. **ล้มแล้วต้องคืนเรกคอร์ดเดิม** — ไม่ใช่ทิ้งค่าใหม่ที่ BIND ไม่รับไว้ในฐานข้อมูล
 */

use Phpcp\Agent\Capability\DnsZoneImport;
use Phpcp\Agent\Capability\DnsZoneRead;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DnsRecord;

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
        'ชนิดที่ยังไม่รองรับ' => "@ IN A 203.0.113.5\n@ IN SRV 0 5 5060 sip.example.com.",
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
            str_contains($message, 'บรรทัดที่'),
            "ต้องบอกหมายเลขบรรทัดด้วย ({$why}): {$message}",
        );
        assertTrue(
            !str_contains($message, 'บรรทัดที่ 0'),
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
