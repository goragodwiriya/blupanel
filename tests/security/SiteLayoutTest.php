<?php

declare(strict_types=1);

/**
 * รูปทรงของไฟล์ใต้บ้านผู้ใช้ — เลือกได้ต่อบัญชี
 *
 * สิ่งที่ห้ามพลาดในไฟล์นี้:
 *   1. เปลี่ยนเลย์เอาต์แล้ว **ทุกเส้นทางต้องขยับพร้อมกัน** — vhost ชี้ที่หนึ่งแต่โควตา
 *      นับอีกที่หนึ่งคือความผิดพลาดที่ไม่มีอะไรฟ้องจนกว่าลูกค้าจะโทรมา
 *   2. บัญชีเดิมต้องไม่ขยับ — การเพิ่มทางเลือกต้องไม่ย้ายไฟล์ของใครโดยไม่ได้สั่ง
 *   3. log ต้องไม่อยู่ใต้ไดเรกทอรีที่เสิร์ฟออกเว็บ ไม่ว่าเลย์เอาต์ไหน
 */

use Phpcp\Domain\Site;
use Phpcp\Domain\SiteLayout;
use Phpcp\Domain\UserAccount;
use Phpcp\Kernel\Paths;

group('SiteLayout — รูปทรงไฟล์ที่เลือกได้ต่อบัญชี');

/** ผู้ใช้ที่ตั้งเลย์เอาต์ไว้ชัดเจน พร้อมโดเมนหลักของบัญชี */
function siteLayoutUser(?SiteLayout $layout, string $mainDomain = 'main.test'): UserAccount
{
    return new UserAccount(7, 'lucy', $layout, $mainDomain);
}

function siteLayoutSite(UserAccount $owner, string $domain): Site
{
    return new Site(1, 'ทดสอบ', $domain, $owner, '8.4', 'off', 'active');
}

// --- 1. เลย์เอาต์เดิมต้องไม่ขยับแม้แต่ตัวอักษรเดียว -------------------------

test('เลย์เอาต์ phpcp ต้องได้เส้นทางเดิมเป๊ะ — บัญชีที่มีอยู่ห้ามขยับ', static function (): void {
    $site = siteLayoutSite(siteLayoutUser(SiteLayout::Phpcp), 'main.test');
    $home = Paths::usersDir() . '/lucy';

    assertSame($home . '/domains/main.test/public', $site->docroot(), 'docroot เดิม');
    assertSame($home . '/domains/main.test/logs', $site->logDir(), 'log เดิม');
    assertSame($home . '/domains/main.test/backups', $site->backupDir(), 'backup เดิม');
});

test('บัญชีที่ยังไม่เคยเลือกต้องตกไปที่ค่าเริ่มต้นของระบบ ไม่ใช่ล้ม', static function (): void {
    // ค่าว่างกับ 'phpcp' เป็นคนละความหมาย: อันแรกขยับตามค่าเริ่มต้นของระบบ อันหลังไม่ขยับ
    $site = siteLayoutSite(siteLayoutUser(null), 'main.test');

    assertSame(
        Paths::usersDir() . '/lucy/domains/main.test/public',
        $site->docroot(),
        'ค่าเริ่มต้นของระบบคือ phpcp',
    );
});

// --- 2. cpanel: public_html ต้องไปอยู่ที่โดเมนหลักเท่านั้น --------------------

test('เลย์เอาต์ cpanel — โดเมนหลักได้ public_html โดเมนที่เพิ่มทีหลังได้โฟลเดอร์ชื่อตัวเอง', static function (): void {
    $owner = siteLayoutUser(SiteLayout::Cpanel, 'main.test');
    $home = Paths::usersDir() . '/lucy';

    assertSame($home . '/public_html', siteLayoutSite($owner, 'main.test')->docroot(), 'โดเมนหลัก');
    assertSame($home . '/shop.test', siteLayoutSite($owner, 'shop.test')->docroot(), 'โดเมนที่เพิ่มทีหลัง');
});

test('เว็บแรกของบัญชีที่ยังไม่มีโดเมนหลักต้องได้ public_html', static function (): void {
    /*
     * ถ้าไม่มีกฎนี้ เว็บแรกของบัญชี cpanel จะไปลงที่ <บ้าน>/<โดเมน> แล้ว `public_html`
     * จะไม่มีวันถูกสร้างเลย — ลูกค้าที่ย้ายมาจาก cPanel เปิด SFTP เข้ามาแล้วไม่เจอ
     * โฟลเดอร์ที่คุ้นเคย ซึ่งเป็นเหตุผลทั้งหมดที่เลย์เอาต์นี้มีอยู่
     */
    $owner = siteLayoutUser(SiteLayout::Cpanel, '');

    assertSame(
        Paths::usersDir() . '/lucy/public_html',
        siteLayoutSite($owner, 'first.test')->docroot(),
        'เว็บแรกต้องได้ public_html',
    );
});

// --- 3. log ต้องไม่หลุดออกเว็บ ------------------------------------------------

test('log ต้องไม่อยู่ใต้ไดเรกทอรีที่เสิร์ฟออกเว็บ ไม่ว่าเลย์เอาต์ไหน', static function (): void {
    /*
     * ในเลย์เอาต์ cpanel ไฟล์เว็บอยู่ที่ `public_html` ซึ่งลูกค้าอัปโหลดเข้าไปทั้งก้อน
     * · วาง log ไว้ในนั้นแปลว่า log ของทั้งเว็บดาวน์โหลดออกไปได้ทันทีถ้ากฎกันไฟล์
     * พลาดไปข้อเดียว และลูกค้าลบทิ้งเองได้ด้วยความไม่ตั้งใจ
     */
    foreach ([SiteLayout::Phpcp, SiteLayout::Cpanel] as $layout) {
        foreach (['main.test', 'shop.test'] as $domain) {
            $site = siteLayoutSite(siteLayoutUser($layout), $domain);

            assertTrue(
                !str_starts_with($site->logDir() . '/', $site->docroot() . '/'),
                sprintf('%s/%s: log (%s) อยู่ใต้ docroot (%s)', $layout->value, $domain, $site->logDir(), $site->docroot()),
            );
            assertTrue(
                !str_starts_with($site->backupDir() . '/', $site->docroot() . '/'),
                sprintf('%s/%s: backup อยู่ใต้ docroot', $layout->value, $domain),
            );
        }
    }
});

// --- 4. ค่าที่ไม่รู้จักต้องไม่กลายเป็นเส้นทาง --------------------------------

test('ค่าเลย์เอาต์ที่ไม่รู้จักต้องตกไปที่ค่าเริ่มต้น ไม่ใช่ถูกใช้ประกอบเส้นทาง', static function (): void {
    // นี่คือเหตุผลเดียวที่ค่านี้ยอมให้แก้จากหน้าเว็บได้ ต่างจาก sites.users_dir
    foreach (['../../etc/passwd', 'cpanel extra', 'CPANEL', ''] as $bad) {
        assertSame(
            SiteLayout::DEFAULT,
            SiteLayout::parse($bad),
            "ค่า '{$bad}' ต้องตกไปที่ค่าเริ่มต้น",
        );
    }

    // ค่าที่ถูกต้องต้องยังผ่านได้จริง ไม่ใช่ปฏิเสธทุกอย่างแล้วเทสต์ผ่าน
    assertSame(SiteLayout::Cpanel, SiteLayout::parse('cpanel'), 'ค่าที่ถูกต้องต้องผ่าน');
});

// --- 5. Domain Pointer ต้องมาก่อนเลย์เอาต์เสมอ -------------------------------

test('docroot_override ต้องชนะเลย์เอาต์ — เป็นคำสั่งที่ชัดเจนกว่า', static function (): void {
    $site = new Site(
        1, 'ทดสอบ', 'main.test', siteLayoutUser(SiteLayout::Cpanel), '8.4', 'off', 'active',
        docrootOverride: '/mnt/legacy/site',
    );

    assertSame('/mnt/legacy/site', $site->docroot(), 'override ต้องมาก่อน');
});
