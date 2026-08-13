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

// --- 6. ทุกทางที่โหลด Site ต้องเห็นเลย์เอาต์เดียวกัน --------------------------

test('SiteRepository::load ต้องคืนเลย์เอาต์ของเจ้าของ ไม่ใช่ค่าเริ่มต้นเสมอ', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-13):** `load()` เคยประกอบ UserAccount เองด้วย
     * สองอาร์กิวเมนต์ จึงทิ้งเลย์เอาต์ไปเงียบ ๆ · ทุกอย่างอื่นในระบบเห็น cpanel
     * แต่เส้นทางที่ `load()` คืนเป็นของ phpcp — ตัวย้ายเลย์เอาต์จึงไม่ย้ายอะไรเลย
     * แล้ว vhost ถูกเขียนชี้ไปที่ไดเรกทอรีเปล่า เว็บ 404 ทั้งที่ทุกขั้นรายงานว่าสำเร็จ
     *
     * เทสต์ 715 ข้อผ่านหมดตอนนั้น เพราะไม่มีข้อไหนโหลด Site ผ่าน repository จริง
     * แล้วถามเลย์เอาต์กลับมา — ต้องตรวจที่ทางเข้าจริง ไม่ใช่ที่ value object อย่างเดียว
     */
    $root = sys_get_temp_dir() . '/phpcp-layoutrepo-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $userId = $db->insert('users', [
        'username' => 'cpuser', 'display_name' => 'cpuser',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Phpcp\Security\Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'system_user' => 'cpuser', 'site_layout' => 'cpanel', 'main_domain' => 'shop.test',
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $siteId = $db->insert('sites', [
        'name' => 'ร้านค้า', 'primary_domain' => 'shop.test', 'docroot' => '/tmp/x',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);

    $site = (new Phpcp\Domain\SiteRepository($db))->load($siteId);

    assertSame(SiteLayout::Cpanel, $site->owner->layout(), 'เลย์เอาต์ของเจ้าของต้องมาถึง Site');
    assertSame('shop.test', $site->owner->mainDomain, 'โดเมนหลักต้องมาถึง Site');
    assertSame(
        Paths::usersDir() . '/cpuser/public_html',
        $site->docroot(),
        'docroot ต้องเป็นของเลย์เอาต์ cpanel ไม่ใช่ตกกลับไป phpcp',
    );
});

// --- 7. ลำดับการย้ายต้องสลับทิศตามเลย์เอาต์ปลายทาง ---------------------------

test('ลำดับการย้ายต้องถูกทั้งสองทิศ — ทิศเดียวใช้ได้ อีกทิศพังเสมอ', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-13):** phpcp วาง log/backup/docroot ไว้ใต้ stateDir
     * ส่วน cpanel วางแยกกัน · ตอนย้าย cpanel → phpcp ถ้าย้ายลูกก่อน ระบบจะสร้าง
     * โฟลเดอร์แม่ให้เองเพื่อรองรับลูก แล้วการย้ายแม่ตัวจริงถูกปฏิเสธว่า "ปลายทางมีอยู่แล้ว"
     * · ตัวย้ายจึงล้มแล้ว rollback ทุกครั้งในทิศนั้น ทั้งที่ทิศตรงข้ามทำงานได้ปกติ
     */
    $method = new ReflectionMethod(Phpcp\Agent\Capability\CustomerLayoutSet::class, 'plan');
    $capability = new Phpcp\Agent\Capability\CustomerLayoutSet();

    foreach ([
        [SiteLayout::Phpcp, SiteLayout::Cpanel],
        [SiteLayout::Cpanel, SiteLayout::Phpcp],
    ] as [$from, $to]) {
        $owner = siteLayoutUser($from, 'main.test');
        $plan = $method->invoke($capability, $owner, $to, siteLayoutSite($owner, 'main.test'));

        $movedFrom = [];

        foreach ($plan as $src => $dst) {
            // ห้ามย้ายอะไรที่อยู่ใต้สิ่งที่ย้ายไปแล้ว — ต้นทางนั้นถูกยกไปทั้งก้อนแล้ว
            foreach ($movedFrom as $done) {
                assertTrue(
                    !str_starts_with(rtrim($src, '/') . '/', rtrim($done, '/') . '/'),
                    sprintf('%s → %s: ย้าย %s หลังจากยกแม่ %s ไปแล้ว', $from->value, $to->value, $src, $done),
                );
            }

            $movedFrom[] = $src;
        }

        // ปลายทางที่เป็นแม่ของปลายทางอื่นต้องมาก่อน ไม่งั้นถูกสร้างขึ้นเองแล้วชนกัน
        $targets = array_values($plan);
        foreach ($targets as $i => $dst) {
            foreach (array_slice($targets, $i + 1) as $later) {
                assertTrue(
                    !str_starts_with(rtrim($dst, '/') . '/', rtrim($later, '/') . '/'),
                    sprintf('%s → %s: ปลายทาง %s อยู่ใต้ %s ที่ย้ายทีหลัง', $from->value, $to->value, $dst, $later),
                );
            }
        }
    }
});
