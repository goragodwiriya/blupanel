<?php

declare(strict_types=1);

/**
 * จำกัดอัตราคำขอต่อเว็บไซต์ด้วย fail2ban — PLAN-V2 เฟส E5
 *
 * เรียงตามความเสียหายถ้าพลาด:
 *
 *   1. **บอกว่าเปิดป้องกันแล้วทั้งที่ไม่มีอะไรบังคับใช้** — อันตรายกว่าไม่มีฟีเจอร์นี้เลย
 *      เพราะผู้ดูแลเข้าใจว่าเว็บมีการป้องกันอยู่ · ฐานข้อมูลจึงต้องถูกเขียน **หลัง**
 *      ไฟล์และการ reload สำเร็จเสมอ
 *   2. **regex หรือค่าที่ผิดทำให้ fail2ban ทั้งตัวสตาร์ตไม่ขึ้น** — jail ของ SSH
 *      ที่กันคนเดารหัสผ่านจะหายไปด้วย · ต้องตรวจก่อน commit และย้อนไฟล์เมื่อไม่ผ่าน
 *   3. **`restart` แทน `reload`** — ล้างรายการ IP ที่ถูกแบนอยู่ทุก jail ทั้งเครื่อง
 *      คนที่กำลังยิงอยู่ได้รับอภัยโทษฟรีทุกครั้งที่มีใครกดบันทึกค่าของเว็บใดก็ตาม
 *   4. **ลูกค้าตั้งค่าเว็บของคนอื่นได้** — ขอบเขตเดียวกับ capability อื่นของเว็บไซต์
 *   5. **ไม่มีทางปลดแบน** — การแบนสั่งที่ firewall ซึ่งไม่รู้จัก vhost ผู้ดูแลที่
 *      โดนแบนตัวเองจะเข้า panel ไม่ได้เลย
 */

use Phpcp\Agent\Capability\SiteRateLimitSet;
use Phpcp\Agent\Capability\SiteRateLimitStatus;
use Phpcp\Agent\Capability\SiteRateLimitUnban;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Security\Fail2banManager;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Request;

group('Rate limit — จำกัดอัตราคำขอต่อเว็บไซต์');

test('ค่าที่อยู่นอกช่วงต้องถูกปฏิเสธพร้อมบอกช่วงที่ยอมรับ', static function (): void {
    // ขอบเขตต้องตรงกับ CHECK ใน migration 0016 — ถ้าสองที่ไม่ตรงกัน ค่าที่ผ่านด่านนี้
    // จะไปตายที่ฐานข้อมูลด้วยข้อความที่ผู้ใช้อ่านไม่รู้เรื่อง
    $capability = new SiteRateLimitSet();

    $tooSmall = [
        ['max_requests' => 9, 'window_seconds' => 60, 'ban_seconds' => 600],
        ['max_requests' => 300, 'window_seconds' => 9, 'ban_seconds' => 600],
        ['max_requests' => 300, 'window_seconds' => 60, 'ban_seconds' => 59],
    ];

    foreach ($tooSmall as $args) {
        $rejected = false;

        try {
            $capability->validate($args + ['site_id' => 1, 'enabled' => true]);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, 'ค่าต่ำเกินต้องถูกปฏิเสธ: ' . json_encode($args, JSON_UNESCAPED_UNICODE));
    }

    // ค่าที่ขอบพอดีต้องผ่าน — ไม่งั้นผู้ใช้ตั้งค่าต่ำสุดที่ระบบโฆษณาไว้ไม่ได้
    $clean = $capability->validate([
        'site_id' => 1, 'enabled' => true,
        'max_requests' => 10, 'window_seconds' => 10, 'ban_seconds' => 60,
    ]);

    assertSame(10, $clean['max_requests'], 'ค่าต่ำสุดต้องผ่าน');
});

test('ปิดการจำกัดอัตราต้องไม่บังคับให้กรอกค่าตัวเลข', static function (): void {
    // คนที่กดปิดไม่ควรถูกบังคับให้กรอกค่าที่กำลังจะไม่ถูกใช้ — ฟอร์มที่ปิดสวิตช์แล้ว
    // มักส่งช่องตัวเลขมาว่างเปล่า ถ้าบังคับตรวจจะปิดไม่ได้เลย
    $clean = (new SiteRateLimitSet())->validate(['site_id' => 7, 'enabled' => false]);

    assertSame(false, $clean['enabled'], 'ต้องเป็นการปิด');
    assertSame(7, $clean['site_id'], 'ต้องรู้ว่าเว็บไหน');
    assertTrue(!isset($clean['max_requests']), 'ต้องไม่ต้องการค่าตัวเลข');
});

test('รายการ IP ที่ยกเว้นต้องตรวจรูปแบบตั้งแต่ตอนกรอก', static function (): void {
    // ค่าที่ผิดรูปแบบทำให้ fail2ban สตาร์ตไม่ขึ้น ซึ่งแปลว่า jail ของ SSH
    // ที่กันคนเดารหัสผ่านหายไปด้วย — ต้องจับตั้งแต่ตอนกรอก ไม่ใช่ตอน reload
    assertSame('', Fail2banManager::normalizeIgnoreList(''), 'ค่าว่างต้องผ่าน');
    assertSame(
        '203.0.113.5 198.51.100.0/24',
        Fail2banManager::normalizeIgnoreList("203.0.113.5,  198.51.100.0/24 "),
        'ต้องรับทั้งจุลภาคและช่องว่าง แล้วคืนรูปแบบที่ fail2ban อ่านได้',
    );
    assertSame('2001:db8::1/64', Fail2banManager::normalizeIgnoreList('2001:db8::1/64'), 'ต้องรองรับ IPv6');

    foreach (['ไม่ใช่ไอพี', '999.1.1.1', '203.0.113.5/33', '2001:db8::1/129', '10.0.0.1/abc'] as $bad) {
        $rejected = false;

        try {
            Fail2banManager::normalizeIgnoreList($bad);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธ '{$bad}'");
    }
});

test('IP ที่จะปลดแบนต้องเป็น IP จริง ไม่ใช่ข้อความที่กลายเป็นอาร์กิวเมนต์อื่น', static function (): void {
    // ค่านี้ถูกส่งต่อไปเป็นอาร์กิวเมนต์ของ fail2ban-client — ข้อความอย่าง `--help`
    // หรือชื่อ jail อื่นต้องไม่หลุดผ่านไปได้
    $capability = new SiteRateLimitUnban();

    assertSame('203.0.113.5', $capability->validate(['site_id' => 1, 'ip' => '203.0.113.5'])['ip'], 'IP ปกติต้องผ่าน');

    foreach (['--help', 'sshd', '203.0.113.5 extra', ''] as $bad) {
        $rejected = false;

        try {
            $capability->validate(['site_id' => 1, 'ip' => $bad]);
        } catch (ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธ '{$bad}'");
    }
});

test('ชื่อ jail ต้องอิงชื่อบัญชีระบบที่ถูกตรวจรูปแบบมาแล้ว', static function (): void {
    // ชื่อโดเมนมีจุดและอาจยาวเกินขีดจำกัดของชื่อไฟล์ · ชื่อบัญชีระบบผ่าน
    // การตรวจรูปแบบมาแล้วให้ปลอดภัยพอเป็นชื่อไฟล์ (ดู Site::assertSystemUser)
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    assertTrue(str_contains($source, 'systemUser()'), 'ต้องใช้ชื่อบัญชีระบบเป็นฐาน');
    assertTrue(!str_contains($source, '$site->domain,') || str_contains($source, 'PREFIX'), 'ต้องมีคำนำหน้าของ panel');

    // คำนำหน้าสำคัญมาก — กันไม่ให้เขียนทับ jail ที่ผู้ดูแลตั้งเอง (เช่น sshd)
    assertTrue(str_contains($source, "PREFIX = 'phpcp-'"), 'ต้องมีคำนำหน้า phpcp-');
});

test('ต้องใช้ reload ไม่ใช่ restart — restart ล้างรายการแบนทั้งเครื่อง', static function (): void {
    // fail2ban restart ล้าง IP ที่ถูกแบนอยู่ทุก jail รวมถึง jail ของ SSH
    // คนที่กำลังเดารหัสผ่านอยู่จะได้รับอภัยโทษฟรีทุกครั้งที่มีใครกดบันทึกค่าเว็บใดก็ตาม
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    // ตัดคอมเมนต์ออกก่อนค้น — คอมเมนต์ที่อธิบายว่า "ห้ามใช้ restart" มีคำว่า restart อยู่
    $code = (string) preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $source);

    assertTrue(str_contains($code, "'reload'"), 'ต้องสั่ง reload');
    assertTrue(!str_contains($code, "'restart'"), 'ห้ามสั่ง restart');
});

test('ต้องตรวจ config ก่อน commit และย้อนไฟล์เมื่อไม่ผ่าน', static function (): void {
    // regex ที่ผิดทำให้ fail2ban ทั้งตัวสตาร์ตไม่ขึ้นครั้งถัดไป — ต้องไม่มีทาง
    // ที่ไฟล์ผิดจะค้างอยู่บนดิสก์
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    assertTrue(str_contains($source, 'ConfigTransaction'), 'ต้องเขียนผ่าน ConfigTransaction');
    assertTrue(str_contains($source, '$tx->commit(function'), 'ต้องตรวจก่อน commit');

    // **ต้องเป็น `-t` ไม่ใช่ `--test get <jail> ...`** — แบบหลังถามเซิร์ฟเวอร์ที่กำลังรันอยู่
    // ว่า jail นั้นมีค่าอะไร ซึ่งยังไม่รู้จัก jail ที่เพิ่งเขียนลงดิสก์ จึงล้มเหลวทุกครั้ง
    // (`NOK: ('phpcp-xxx',)` — เจอบนเครื่องจริง 2026-08-11) · ส่วน `-t` อ่านไฟล์จากดิสก์
    $code = (string) preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $source);

    assertTrue(str_contains($code, "'-t'"), 'ต้องตรวจ config ด้วย fail2ban-client -t');
    assertTrue(
        !str_contains($code, "'--test', 'get'"),
        'ห้ามใช้ --test เป็น modifier ของ get — ไม่ใช่ไวยากรณ์ที่มีอยู่จริง',
    );
});

test('ต้องยืนยันว่า fail2ban ทำงานอยู่ก่อนบอกว่าเปิดป้องกันแล้ว', static function (): void {
    // เขียนไฟล์สำเร็จแล้วรายงานว่า "เปิดการจำกัดอัตราแล้ว" ทั้งที่ fail2ban ไม่ทำงาน
    // = ความปลอดภัยหลอก ซึ่งอันตรายกว่าไม่มีฟีเจอร์นี้
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    assertTrue(str_contains($source, 'assertRunning'), 'ต้องมีด่านตรวจว่าบริการทำงานอยู่');
    assertTrue(str_contains($source, "'ping'"), 'ต้องถาม fail2ban ว่ายังตอบไหม');

    /*
     * **นับ "เมธอดไหนตรวจบ้าง" ไม่ใช่ "ตรวจกี่ครั้ง"**
     *
     * เดิมยืนยันว่าเจอ `$this->assertRunning();` พอดี 2 ครั้ง ซึ่งพังทันทีที่มี jail
     * ชนิดที่สองเข้ามา (panel-login) ทั้งที่ของใหม่ก็ตรวจครบเหมือนกัน · เทสต์ที่ผูก
     * กับจำนวนบรรทัดแบบนั้นบังคับให้คนแก้ตัวเลขตามโดยไม่ได้อ่านว่ามันควรตรวจอะไร
     *
     * ทุกเมธอดสาธารณะที่**เขียนไฟล์ jail** ต้องตรวจก่อน ไม่งั้นจะเขียนไฟล์สำเร็จแล้ว
     * รายงานว่าเปิดป้องกันแล้วทั้งที่ fail2ban ไม่ได้ทำงานอยู่
     */
    $mustGuard = ['apply', 'remove', 'applyPanelLogin', 'removePanelLogin'];

    foreach ($mustGuard as $method) {
        $start = strpos($source, 'public function ' . $method . '(');
        assertTrue($start !== false, "ต้องมีเมธอด {$method}()");

        // ตัดเอาเฉพาะตัวเมธอดนั้น — เมธอดถัดไปเริ่มที่ `    public function` หรือ `    /**`
        $next = strpos($source, "\n    public function ", $start + 1);
        $body = substr($source, $start, $next === false ? null : $next - $start);

        assertTrue(
            str_contains($body, '$this->assertRunning();'),
            "{$method}() ต้องตรวจว่า fail2ban ทำงานอยู่ก่อนแตะไฟล์",
        );
    }
});

test('ฐานข้อมูลต้องถูกเขียนหลังไฟล์สำเร็จเสมอ', static function (): void {
    // ถ้าบันทึกก่อนแล้ว fail2ban ล้มเหลว หน้าจอจะบอกว่าเปิดป้องกันไว้แล้ว
    // ทั้งที่ไม่มีอะไรบังคับใช้เลย — เป็นบั๊กประเภทเดียวกับที่เจอตอน E6
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/SiteRateLimitSet.php');

    $applyAt = strpos($source, '$manager->apply($site, $settings);');
    $insertAt = strpos($source, 'INSERT INTO site_rate_limits');

    assertTrue($applyAt !== false && $insertAt !== false, 'ต้องมีทั้งสองอย่าง');
    assertTrue($applyAt < $insertAt, 'ต้องเขียนไฟล์ก่อนบันทึกฐานข้อมูล');
});

test('capability ทั้งสามต้องตรวจสิทธิ์เจ้าของเว็บไซต์', static function (): void {
    // ลูกค้าต้องแตะได้เฉพาะเว็บของตัวเอง — ขอบเขตเดียวกับ capability อื่นของเว็บไซต์
    foreach ([SiteRateLimitSet::class, SiteRateLimitStatus::class, SiteRateLimitUnban::class] as $class) {
        // หาไฟล์จริงด้วย reflection ไม่ใช่เดาจากชื่อ namespace — การเดาพังทันที
        // ที่โครงสร้างไดเรกทอรีไม่ตรงกับ namespace เป๊ะ ๆ แล้วเทสต์จะฟ้องว่าโค้ดผิด
        // ทั้งที่ตัวเทสต์เองหาไฟล์ไม่เจอ
        $file = (new ReflectionClass($class))->getFileName();
        $source = (string) file_get_contents((string) $file);

        assertTrue(
            str_contains($source, 'assertSiteAccess'),
            $class . ' ต้องตรวจสิทธิ์เจ้าของ',
        );
    }

    // การอ่านสถานะต้องไม่เติม audit log ทุกครั้งที่มีคนเปิดหน้าเว็บ
    assertTrue(!(new SiteRateLimitStatus())->isMutating(), 'อ่านสถานะต้องเป็นอ่านอย่างเดียว');
    assertTrue((new SiteRateLimitSet())->isMutating(), 'การตั้งค่าต้องอยู่ใน audit log');
    assertTrue((new SiteRateLimitUnban())->isMutating(), 'การปลดแบนต้องอยู่ใน audit log');
});

test('jail ต้องระบุ backend เอง ไม่งั้นไม่นับอะไรเลยบน Debian/Ubuntu', static function (): void {
    // **บั๊กที่เกือบหลุด:** /etc/fail2ban/jail.d/defaults-debian.conf ตั้ง `backend = systemd`
    // ไว้ใน [DEFAULT] ซึ่งทำให้ fail2ban อ่านจาก systemd journal แล้ว**เมิน logpath**
    // · jail จะขึ้นสถานะ active ทุกอย่างดูปกติ แต่ไม่นับคำขอสักรายการเดียว
    // และไม่มีอะไรฟ้อง — เป็นความล้มเหลวแบบเงียบที่สุดของทั้งเฟสนี้
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    // ตัดคอมเมนต์ก่อนค้น — คอมเมนต์ที่อธิบายบั๊กนี้มีคำว่า backend อยู่ด้วย
    $code = (string) preg_replace('/^\s*#.*$/m', '', $source);

    assertTrue(str_contains($code, 'backend  = auto'), 'jail ต้องบังคับ backend เป็น auto');

    // logpath ต้องยังอยู่ — backend=auto อ่านไฟล์ตาม logpath
    assertTrue(str_contains($code, 'logpath  = %s'), 'ต้องระบุไฟล์ log ที่จะอ่าน');
});

test('localhost ต้องไม่ถูกแบนเด็ดขาด ไม่ว่าผู้ใช้จะกรอกอะไร', static function (): void {
    // jail.conf ของ Debian **comment `ignoreip` ไว้** จึงไม่มีการยกเว้นค่าเริ่มต้นเลย
    // · การแบน 127.0.0.1 ตัดขาดหน้า panel ที่ผู้ดูแลใช้เข้ามาแก้ปัญหาพอดี
    // และไม่มีประโยชน์ด้วย เพราะคนที่ยิงจาก localhost ได้ แปลว่าเข้าเครื่องได้แล้ว
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');
    $code = (string) preg_replace('/^\s*(#|\/\/|\*).*$/m', '', $source);

    assertTrue(str_contains($code, "LOCAL_IPS = '127.0.0.1/8 ::1'"), 'ต้องมีรายการ IP ในเครื่อง');
    assertTrue(
        str_contains($code, 'self::LOCAL_IPS'),
        'ต้องเติมรายการนั้นเข้าไปในทุก jail ที่สร้าง ไม่ใช่แค่ประกาศไว้เฉย ๆ',
    );

    // ต้องเติม**เสมอ** ไม่ใช่เฉพาะตอนที่ผู้ใช้เว้นช่องว่างไว้
    assertTrue(
        !str_contains($code, "\$ignore === '' ? self::LOCAL_IPS"),
        'ต้องไม่ใช่ค่าสำรองที่หายไปเมื่อผู้ใช้กรอกอะไรก็ตาม',
    );
});

test('ลบเว็บไซต์ต้องถอน jail ทิ้งด้วย ไม่ใช่ปล่อยกำพร้าไว้', static function (): void {
    // jail ที่ค้างอยู่จะเฝ้า access log ที่ถูกย้ายไปถังพักแล้ว — fail2ban เตือนทุกครั้ง
    // ที่ reload ว่าหาไฟล์ไม่เจอ · และถ้าโดเมนเดิมถูกสร้างใหม่ jail เก่าที่ชี้ไฟล์ผิด
    // จะทำให้การเปิดใช้ครั้งใหม่ล้มเหลวโดยไม่มีใครรู้สาเหตุ
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(Phpcp\Agent\Capability\SiteDelete::class))->getFileName(),
    );

    assertTrue(str_contains($source, 'Fail2banManager'), 'SiteDelete ต้องถอน jail');

    // ต้องอยู่**ก่อน**การย้ายไฟล์ ไม่งั้น fail2ban ยังเฝ้าไฟล์ที่หายไปแล้วอยู่ช่วงหนึ่ง
    $removeAt = strpos($source, '->remove($site);');
    $trashAt = strpos($source, '$this->moveToTrash(');

    assertTrue($removeAt !== false && $trashAt !== false, 'ต้องมีทั้งสองขั้น');
    assertTrue($removeAt < $trashAt, 'ต้องถอน jail ก่อนย้ายไฟล์');

    // ความล้มเหลวของ fail2ban ต้องไม่หยุดการลบเว็บ — ผู้ใช้ยืนยันชื่อโดเมนมาแล้ว
    // การค้างครึ่งทางแย่กว่าการเหลือไฟล์ jail กำพร้าที่เก็บกวาดด้วยมือได้
    $block = substr($source, (int) $removeAt - 200, 400);
    assertTrue(str_contains($block, 'catch'), 'ต้องไม่ให้ความล้มเหลวของ fail2ban หยุดการลบ');
});

test('filter ต้องจับทุกคำขอ ไม่ใช่เฉพาะที่ล้มเหลว', static function (): void {
    // นี่คือความต่างระหว่าง "rate limit" กับ jail ทั่วไปของ fail2ban ที่นับเฉพาะ
    // การยืนยันตัวตนที่ล้มเหลว — ถ้าลอก filter ของ auth มาจะไม่จำกัดอัตราอะไรเลย
    $source = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/Security/Fail2banManager.php');

    assertTrue(str_contains($source, '<HOST>'), 'ต้องใช้ token <HOST> ของ fail2ban');
    assertTrue(
        str_contains($source, 'GET|POST|HEAD'),
        'ต้องจับทุกเมธอด ไม่ใช่เฉพาะหน้าล็อกอิน',
    );

    // ต้องมี datepattern — ไม่งั้น fail2ban อ่านเวลาจาก access log ไม่ออกแล้วนับผิดหมด
    assertTrue(str_contains($source, 'datepattern'), 'ต้องระบุรูปแบบวันเวลาของ log');
});

test('X-Forwarded-For ต้องถูกเมินเมื่อไม่ได้ตั้ง proxy ที่เชื่อถือไว้', static function (): void {
    /*
     * **จุดเดียวที่พังแล้วปิดการจำกัดอัตราทั้งระบบเงียบ ๆ**
     *
     * โควตาของหน้าล็อกอินผูกกับ IP (`login:<ip>`) ไม่ใช่เซสชัน — หมุนคุกกี้จึงเลี่ยงไม่ได้ ·
     * แต่ถ้า `Request` เชื่อ `X-Forwarded-For` ที่ผู้เรียกส่งมาเอง ผู้โจมตีเปลี่ยนค่าใน
     * header ทุกคำขอก็ได้ IP ใหม่ทุกครั้ง แล้วเดารหัสผ่านได้ไม่จำกัดโดยที่ตัวจำกัดยัง
     * "ทำงานอยู่" ทุกประการ · ไม่มีอะไรในระบบฟ้องเลยว่าการป้องกันหายไปแล้ว
     *
     * กติกาที่ต้องเป็นจริงเสมอ: เชื่อ `X-Forwarded-For` **ก็ต่อเมื่อ** คำขอมาจากที่อยู่ที่
     * อยู่ใน `panel.trusted_proxies` ซึ่งค่าเริ่มต้นคือ *ว่าง* (ไม่เชื่อใครเลย)
     */
    $config = Config::load(PHPCP_ROOT);
    $reflection = new ReflectionMethod(Request::class, 'resolveIp');
    $reflection->setAccessible(true);

    $spoofed = [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.0.0.1',
    ];

    // ไม่มี proxy ที่เชื่อถือ = ต้องใช้ที่อยู่จริงของการเชื่อมต่อเท่านั้น
    assertSame(
        '203.0.113.9',
        $reflection->invoke(null, $spoofed, []),
        'ไม่ได้ตั้ง trusted_proxies ไว้ ต้องเมิน X-Forwarded-For ทั้งหมด',
    );

    // ตั้งไว้แต่คำขอไม่ได้มาจาก proxy ตัวนั้น = ยังต้องเมิน
    assertSame(
        '203.0.113.9',
        $reflection->invoke(null, $spoofed, ['127.0.0.1']),
        'คำขอที่ไม่ได้มาจาก proxy ที่ตั้งไว้ ต้องเมิน X-Forwarded-For',
    );

    /*
     * มาจาก proxy ที่เชื่อถือจริง = ไล่จาก**ขวา**เข้ามา ไม่ใช่หยิบตัวซ้ายสุด
     *
     * ตัวซ้ายสุด (`198.51.100.1` ในที่นี้) คือค่าที่ proxy ชั้นนอกได้รับมา ซึ่งก็คือ
     * ค่าที่ **ผู้ใช้แนบมาเอง** ถ้าเขาส่ง header นี้มาด้วย · ตัวขวาสุดที่ไม่ใช่ proxy
     * ของเราคือค่าที่ proxy ชั้นในสุดเห็นกับตา — อันนั้นเท่านั้นที่ปลอมไม่ได้
     *
     * เดิมที่นี่คาดหวัง `198.51.100.1` ซึ่งแปลว่าผู้โจมตีที่ยิงผ่าน proxy ของเรา
     * ตั้ง IP ของตัวเองเป็นอะไรก็ได้ทุกคำขอ แล้วเดารหัสผ่านไม่จำกัดโดยที่ตัวจำกัด
     * อัตรายัง "ทำงานอยู่" ครบทุกประการ
     */
    assertSame(
        '10.0.0.1',
        $reflection->invoke(null, $spoofed, ['203.0.113.9']),
        'ต้องอ่านตัวขวาสุดที่ไม่ใช่ proxy ของเรา ไม่ใช่ตัวซ้ายสุดที่ผู้ใช้เขียนเองได้',
    );

    // proxy ซ้อนหลายชั้น — ข้ามชั้นที่เป็นของเราเองจนเจอตัวแรกที่ไม่ใช่
    assertSame(
        '198.51.100.7',
        $reflection->invoke(
            null,
            [
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.7, 10.0.0.1, 10.0.0.2',
            ],
            ['203.0.113.9', '10.0.0.1', '10.0.0.2'],
        ),
        'ต้องข้าม proxy ของเราทุกชั้นแล้วหยุดที่ตัวแรกที่ไม่ใช่',
    );

    // ทั้งสายเป็น proxy ของเราเอง = ไม่มีที่อยู่ของใครนอกระบบอยู่ในนั้นเลย
    assertSame(
        '203.0.113.9',
        $reflection->invoke(
            null,
            ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1'],
            ['203.0.113.9', '10.0.0.1'],
        ),
        'ทั้งสายเป็น proxy ของเรา ต้องตกกลับไปใช้ REMOTE_ADDR',
    );

    // รูปแบบเพี้ยนต้องเชื่อไม่ได้ทั้งหัว ไม่ใช่ข้ามรายการนั้นแล้วอ่านตัวถัดไป —
    // ไม่งั้นผู้โจมตีเลือกได้ว่าจะให้เราอ่านตัวไหนด้วยการใส่ขยะคั่น
    assertSame(
        '203.0.113.9',
        $reflection->invoke(
            null,
            ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, ไม่ใช่ไอพี'],
            ['203.0.113.9'],
        ),
        'รายการที่อ่านไม่ออกต้องทำให้ทั้งหัวเชื่อไม่ได้',
    );

    // ค่าเริ่มต้นของระบบต้องเป็น "ไม่เชื่อใครเลย" — ตั้งเองได้ แต่ต้องเป็นการตัดสินใจ
    assertSame(
        [],
        $config->list('panel.trusted_proxies'),
        'ค่าเริ่มต้นของ panel.trusted_proxies ต้องว่าง',
    );
});
