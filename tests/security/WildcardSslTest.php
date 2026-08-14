<?php

declare(strict_types=1);

/**
 * ใบรับรอง wildcard ผ่าน DNS-01 บน BIND9 ในเครื่อง — PLAN-V2 เฟส E7
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **vhost ของ wildcard ถูกอ่านก่อน vhost ที่ระบุชื่อเต็ม** — คำขอของ
 *      `blog.example.com` ที่เป็นเว็บของลูกค้าอีกราย จะตกไปที่เว็บ wildcard แทน
 *      · เป็นการรั่วข้ามเว็บไซต์ ไม่ใช่แค่ตั้งค่าผิด
 *   2. **`*` หลุดเข้าไปในชื่อไฟล์หรือคำสั่ง** — ค่านี้ถูกเอาไปประกอบชื่อไฟล์ vhost
 *      และอาร์กิวเมนต์ของ certbot
 *   3. **TXT ไม่มีเครื่องหมายคำพูดใน zone file** — BIND9 ปฏิเสธทั้ง zone หรืออ่านเป็น
 *      หลายสตริงแยกกัน · กระทบ SPF/DKIM ของทุกโดเมนด้วย ไม่ใช่แค่ ACME
 *   4. **hook คืนค่าก่อน BIND9 เสิร์ฟเรกคอร์ดจริง** — Let's Encrypt ถามแล้วไม่เจอ
 *      แล้วรายงาน NXDOMAIN ซึ่งชี้ไปที่การตั้งค่าที่ผิด ทั้งที่แค่ถามเร็วไปหนึ่งวินาที
 */

use Phpcp\Agent\Capability\SiteAddDomain;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\AcmeDnsChallenge;
use Phpcp\Domain\DnsRecord;
use Phpcp\Domain\Site;
use Phpcp\Support\Validator;

group('Wildcard SSL — ใบรับรอง *.example.com ผ่าน DNS-01');

test('vhost ของเว็บที่รับ wildcard ต้องถูกอ่านท้ายสุด', static function (): void {
    // Apache/nginx อ่านไฟล์ตามลำดับตัวอักษร · ถ้า `*.example.com` มาก่อน vhost ที่ระบุ
    // `blog.example.com` ไว้เต็ม ๆ คำขอของ blog จะตกไปที่เว็บ wildcard
    $templates = new Phpcp\Driver\Template(PHPCP_ROOT . '/templates');

    foreach ([new Phpcp\Driver\WebServer\ApacheDriver($templates), new Phpcp\Driver\WebServer\NginxDriver($templates)] as $driver) {
        $plain = $driver->vhostPath(wildcardSite('example.com', []));
        $wild = $driver->vhostPath(wildcardSite('example.com', ['*.example.com']));

        assertTrue(!str_contains($plain, 'zz-wildcard'), 'เว็บธรรมดาต้องไม่มีคำนำหน้าพิเศษ');
        assertTrue(str_contains($wild, 'zz-wildcard'), 'เว็บ wildcard ต้องมีคำนำหน้าที่ทำให้เรียงท้าย');

        // เรียงท้ายจริงเมื่อเทียบกับชื่อที่เป็นไปได้ทั้งหมด — ไม่ใช่แค่ "มีคำว่า zz"
        $names = [basename($wild), 'phpcp-blog.example.com.conf', 'phpcp-zebra.example.com.conf'];
        sort($names, SORT_STRING);

        assertSame(basename($wild), end($names), 'ไฟล์ของ wildcard ต้องอยู่ท้ายสุดหลังเรียง');
    }
});

test('ชื่อไฟล์ vhost ต้องไม่มี * ปนเข้าไปเด็ดขาด', static function (): void {
    // `*` ในชื่อไฟล์ทำให้ทั้ง glob ของเว็บเซิร์ฟเวอร์และคำสั่งลบไฟล์ทำงานผิด
    $templates = new Phpcp\Driver\Template(PHPCP_ROOT . '/templates');
    $path = (new Phpcp\Driver\WebServer\ApacheDriver($templates))
        ->vhostPath(wildcardSite('example.com', ['*.example.com']));

    assertTrue(!str_contains($path, '*'), 'ชื่อไฟล์ต้องไม่มีดอกจัน');
});

test('Validator ต้องรับ wildcard เฉพาะรูปแบบที่ออกใบได้จริง', static function (): void {
    assertSame('*.example.com', Validator::wildcardDomain('*.EXAMPLE.com'), 'ต้องแปลงเป็นตัวพิมพ์เล็ก');
    assertSame('example.com', Validator::wildcardDomain('example.com'), 'โดเมนธรรมดาต้องยังผ่าน');

    // Let's Encrypt ออกให้เฉพาะ `*.` หนึ่งระดับ — รูปอื่นไม่มีอยู่จริง
    foreach (['*.*.example.com', 'www.*.example.com', '*', '*.', '*example.com'] as $bad) {
        $rejected = false;

        try {
            Validator::wildcardDomain($bad);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธ '{$bad}'");
    }

    // `domain()` ต้องไม่ถูกผ่อนกฎ — จุดที่ประกอบชื่อไฟล์ยังต้องกัน `*` เหมือนเดิม
    $rejected = false;

    try {
        Validator::domain('*.example.com');
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'Validator::domain ต้องยังปฏิเสธ wildcard');
});

test('เพิ่ม wildcard ต้องได้ชนิด wildcard และชี้พาธย่อยไม่ได้', static function (): void {
    $capability = new SiteAddDomain();

    $clean = $capability->validate(['site_id' => 1, 'host' => '*.example.com']);
    assertSame('wildcard', $clean['type'], 'ต้องแยกเป็นชนิดของตัวเอง');
    assertSame('', $clean['path'], 'wildcard ไม่มีพาธย่อย');

    // `*.example.com` ครอบชื่อที่ยังไม่มีใครจด การผูกกับโฟลเดอร์เดียวจึงไม่มีความหมาย
    $rejected = false;

    try {
        $capability->validate(['site_id' => 1, 'host' => '*.example.com', 'path' => '/blog']);
    } catch (ValidationError) {
        $rejected = true;
    }

    assertTrue($rejected, 'wildcard ที่ชี้พาธย่อยต้องถูกปฏิเสธ');

    // โดเมนปกติต้องไม่เปลี่ยนพฤติกรรม
    assertSame('alias', $capability->validate(['site_id' => 1, 'host' => 'shop.example.com'])['type'], 'alias ต้องเหมือนเดิม');
    assertSame('subdomain', $capability->validate(['site_id' => 1, 'host' => 'x.example.com', 'path' => '/x'])['type'], 'subdomain ต้องเหมือนเดิม');
});

test('TXT ใน zone file ต้องอยู่ในเครื่องหมายคำพูดเสมอ', static function (): void {
    // ค่าที่มีช่องว่าง (SPF/DKIM) ถ้าไม่ห่อไว้ BIND9 อ่านเป็นหลายสตริงหรือปฏิเสธทั้ง zone
    // · เดิมปล่อยให้ผู้ใช้ใส่ `"` มาเอง ซึ่งเป็นกับดักสำหรับคนที่วางค่า SPF ตามที่
    // ผู้ให้บริการเมลบอกมา
    $zone = DnsRecord::toAuthoritativeZoneFile('example.com', [
        ['name' => '@', 'ttl' => 3600, 'type' => 'TXT', 'value' => 'v=spf1 include:_spf.example.com ~all', 'priority' => null],
        ['name' => '@', 'ttl' => 3600, 'type' => 'TXT', 'value' => '"already quoted"', 'priority' => null],
        ['name' => AcmeDnsChallenge::RECORD_NAME, 'ttl' => 60, 'type' => 'TXT', 'value' => 'abcDEF-123_xyz', 'priority' => null],
    ], 1, ['ns1.example.com'], 'admin@example.com');

    assertTrue(str_contains($zone, '"v=spf1 include:_spf.example.com ~all"'), 'ค่าที่มีช่องว่างต้องถูกห่อ');
    assertTrue(!str_contains($zone, '""already quoted""'), 'ค่าที่ห่อมาแล้วต้องไม่ถูกห่อซ้อน');
    assertTrue(str_contains($zone, '"abcDEF-123_xyz"'), 'ACME token ต้องถูกห่อ');

    // A record ต้องไม่ถูกห่อ — จะทำให้ zone ใช้ไม่ได้ทันที
    $aZone = DnsRecord::toAuthoritativeZoneFile('example.com', [
        ['name' => '@', 'ttl' => 3600, 'type' => 'A', 'value' => '203.0.113.5', 'priority' => null],
    ], 1, ['ns1.example.com'], 'admin@example.com');

    assertTrue(!str_contains($aZone, '"203.0.113.5"'), 'A record ต้องไม่ถูกห่อ');
});

test('hook ต้องรอจน BIND9 เสิร์ฟเรกคอร์ดจริงก่อนคืนค่า', static function (): void {
    // certbot ถือว่า hook ที่จบแล้วแปลว่าเรกคอร์ดพร้อมใช้ แล้วบอก Let's Encrypt
    // ให้มาถามทันที — คืนเร็วไปหนึ่งวินาทีได้ error ที่ชี้ไปคนละทางกับต้นตอ
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/AcmeDnsChallenge.php');

    assertTrue(str_contains($source, 'waitUntilVisible'), 'ต้องมีขั้นตอนรอ');
    assertTrue(str_contains($source, "'@127.0.0.1'"), 'ต้องถาม nameserver ในเครื่องโดยตรง');
    assertTrue(str_contains($source, 'MAX_WAIT'), 'ต้องมีเพดานเวลารอ ไม่ค้างตลอดไป');

    // ต้องเขียนผ่าน BindZoneManager ไม่ใช่แก้ zone file ตรง ๆ — ไม่งั้นจะข้าม
    // การตรวจ named-checkzone และ ConfigTransaction ที่ย้อนไฟล์ได้
    assertTrue(str_contains($source, 'BindZoneManager'), 'ต้องเขียนผ่านกลไกเดิม');
    assertTrue(!str_contains($source, 'writeFile('), 'ห้ามเขียน zone file ตรง ๆ');
});

test('ค่า validation ที่ผิดรูปแบบต้องไม่มีทางไปถึง zone file', static function (): void {
    // ค่านี้มาจาก environment variable ที่ certbot ตั้ง — ถ้ามีใครเรียก hook เองด้วยค่ามั่ว
    // มันจะถูกเขียนลงไฟล์ที่ BIND9 อ่าน
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/AcmeDnsChallenge.php');

    assertTrue(
        str_contains($source, "preg_match('/^[A-Za-z0-9_-]{20,128}\$/'"),
        'ต้องตรวจรูปแบบ token ก่อนเขียน',
    );
});

test('ใบ wildcard ต้องขอด้วย DNS-01 เท่านั้น ไม่ใช่ webroot', static function (): void {
    // Let's Encrypt บังคับ DNS-01 สำหรับ wildcard — การส่ง --webroot ไปจะล้มเหลว
    // ด้วยข้อความที่ไม่ได้บอกว่าต้องใช้วิธีอื่น
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Ssl/CertbotManager.php');

    assertTrue(str_contains($source, "'--preferred-challenges', 'dns'"), 'ต้องเลือก DNS-01');
    assertTrue(str_contains($source, '--manual-auth-hook'), 'ต้องมี auth hook');
    assertTrue(str_contains($source, '--manual-cleanup-hook'), 'ต้องมี cleanup hook — ไม่งั้น TXT ค้างใน zone ตลอดไป');

    // hook ต้องชี้ไปที่เส้นทางที่ติดตั้งจริง ไม่ใช่เส้นทางในโปรเจกต์
    assertTrue(str_contains($source, '/usr/share/phpcp/bin/phpcp-acme-hook'), 'ต้องเป็นเส้นทางของเครื่องที่ติดตั้ง');

    // และต้องเลือกวิธีตามชนิดของโดเมน ไม่ใช่ใช้ DNS-01 กับทุกใบ
    assertTrue(str_contains($source, "str_starts_with(\$domain, '*.')"), 'ต้องตรวจว่ามี wildcard ไหม');
    assertTrue(str_contains($source, "'--webroot'"), 'ใบธรรมดาต้องยังใช้ webroot');
});

test('hook ต้องไม่ล้มเหลวตอน cleanup ถ้า zone หายไปแล้ว', static function (): void {
    // certbot เรียก cleanup เสมอแม้ตอนที่ใบออกสำเร็จแล้ว — ถ้า cleanup ล้ม
    // มันจะรายงานว่าการขอใบล้มเหลวทั้งที่ได้ใบมาแล้ว
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Domain/AcmeDnsChallenge.php');

    assertTrue(
        str_contains($source, "return ['domain' => \$domain, 'removed' => 0];"),
        'cleanup ที่ไม่เจอ zone ต้องคืนค่าปกติ ไม่โยน error',
    );
});

test('ปุ่มขอใบรับรองในตารางต้องใช้งานได้โดยไม่ต้องกรอกอีเมล', static function (): void {
    // **บั๊กจริงบนเครื่อง 2026-08-11:** ปุ่ม "ขอใบรับรอง" ในหน้า /certificates เป็นปุ่ม
    // ในแถวตาราง ส่งแค่ site_id กับ method — ไม่มีที่ให้กรอกอีเมล · แต่ validate()
    // บังคับให้ส่งอีเมล ทุกครั้งที่กดจึงได้ "อีเมลไม่ถูกต้อง" ซึ่งชี้ไปคนละทางกับต้นตอ
    // (ผู้ใช้ไม่ได้กรอกอะไรผิด — ไม่มีช่องให้กรอกตั้งแต่แรก)
    $clean = (new Phpcp\Agent\Capability\SslIssue())->validate([
        'site_id' => 1,
        'method' => 'letsencrypt',
    ]);

    assertSame('', $clean['email'], 'ไม่ส่งอีเมลมาต้องผ่านด่าน validate ไปได้');

    // ค่าที่ส่งมาแต่ผิดรูปแบบยังต้องถูกปฏิเสธเหมือนเดิม — แค่ย้ายไปตรวจตอน run()
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(Phpcp\Agent\Capability\SslIssue::class))->getFileName(),
    );

    assertTrue(str_contains($source, 'resolveEmail'), 'ต้องมีขั้นตอนหาอีเมลแทนการบังคับ');
    assertTrue(str_contains($source, 'CertbotManager::assertEmail($requested)'), 'ค่าที่ส่งมาต้องยังถูกตรวจ');

    // ต้องหาจากเจ้าของเว็บก่อน ค่าตั้งของระบบทีหลัง — คนที่ควรได้รับคำเตือนว่าใบ
    // ใกล้หมดอายุคือคนที่ดูแลเว็บนั้น ไม่ใช่ผู้ดูแลเครื่องที่บังเอิญกดปุ่ม
    $ownerAt = strpos($source, "SELECT email FROM users");
    $settingsAt = strpos($source, "get('mail.from')");

    assertTrue($ownerAt !== false && $settingsAt !== false, 'ต้องมีทั้งสองแหล่ง');
    assertTrue($ownerAt < $settingsAt, 'ต้องถามอีเมลเจ้าของเว็บก่อนค่าตั้งของระบบ');

    // และต้องไม่เงียบเมื่อหาไม่เจอเลย — ข้อความต้องบอกทางแก้ทั้งสองทาง
    assertTrue(str_contains($source, 'กรอกอีเมลในบัญชีเจ้าของเว็บไซต์'), 'ต้องบอกทางแก้');
});

/** Site ตัวอย่างสำหรับทดสอบชื่อไฟล์ vhost */
function wildcardSite(string $domain, array $aliases): Site
{
    return Site::fromRow([
        'id' => 1,
        'primary_domain' => $domain,
        'php_version' => '8.4',
        'ssl_mode' => 'off',
        'status' => 'active',
        'owner_user_id' => 1,
        'owner_system_user' => 'demo',
        'owner_username' => 'demo',
    ], $aliases);
}
