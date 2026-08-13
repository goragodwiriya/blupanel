<?php

declare(strict_types=1);

/**
 * ใบรับรองของ mail hostname และค่าเมลขาออก — PLAN-MAIL เฟส M3
 *
 * สองความผิดพลาดที่ชุดนี้เฝ้า ทั้งคู่เป็นชนิดที่ **ระบบรายงานว่าสำเร็จทุกขั้นตอน**:
 *
 *   1. กดบันทึกค่าเมลขาออกแล้วส่วนรับเมลใน `main.cf` หายไปทั้งก้อน — Postfix กลับไป
 *      ฟังแค่ loopback แล้วกล่องทุกกล่องบนเครื่องหยุดรับเมลเงียบ ๆ · ผู้ใช้แค่แก้
 *      ที่อยู่ผู้ส่ง ไม่มีทางเดาได้เลยว่าตัวเองเพิ่งปิดเมลทั้งเครื่อง
 *   2. ใบรับรองถูกตั้งให้ Postfix ฝ่ายเดียว · โปรแกรมเมลต่อ IMAP เข้า Dovecot ตรง ๆ
 *      จึงได้เครื่องที่ "ส่งเมลไม่มีคำเตือน แต่เปิดกล่องทีไรก็เตือนทุกที"
 */

use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Mail\MailCertificate;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\Template;

group('เมลโฮสติ้ง — ใบรับรองของ mail hostname (M3)');

/** พื้นที่ทดสอบของตัวเอง ไม่ปนกับชุดอื่นที่ใช้ราก sandbox เดียวกัน */
function mailCertSandbox(string $name): SandboxExecutor
{
    $root = sys_get_temp_dir() . '/phpcp-mailcert-' . getmypid() . '-' . $name;

    if (is_dir($root)) {
        exec('rm -rf ' . escapeshellarg($root));
    }

    return new SandboxExecutor($root);
}

/** ออกใบจริงด้วย openssl — SandboxExecutor ไม่จำลอง openssl จึงได้ไฟล์ PEM ของจริง */
function mailCertIssue(SandboxExecutor $executor, string $dir, string $subject, int $days = 90): void
{
    $path = $executor->path($dir);

    $executor->makeDirectory($path, 0755);

    $executor->exec([
        '/usr/bin/openssl', 'req', '-x509', '-nodes',
        '-newkey', 'rsa:2048',
        '-days', (string) $days,
        '-keyout', $path . '/privkey.pem',
        '-out', $path . '/fullchain.pem',
        '-subj', '/CN=' . $subject,
        '-addext', 'subjectAltName=DNS:' . $subject,
    ], timeout: 60);
}

test('ใบที่ครอบคลุมชื่อโฮสต์ของเมลต้องถูกหาเจอ และใบจริงต้องชนะใบที่เซ็นเอง', static function (): void {
    $executor = mailCertSandbox('locate');

    // ใบที่ panel เซ็นเองสำหรับชื่อเดียวกัน — ใช้ได้แต่โปรแกรมเมลยังเตือน
    mailCertIssue($executor, CertbotManager::SELF_SIGNED_DIR . '/mail.example.com', 'mail.example.com');
    // ใบจริงของ Let's Encrypt ที่ครอบคลุมชื่อเดียวกัน
    mailCertIssue($executor, CertbotManager::LIVE_DIR . '/example.com', 'mail.example.com');
    // ใบของโดเมนอื่นบนเครื่องเดียวกัน ต้องไม่ถูกหยิบมาใช้เด็ดขาด
    mailCertIssue($executor, CertbotManager::LIVE_DIR . '/other.test', 'other.test');

    $found = (new MailCertificate(new CertbotManager()))->locate($executor, 'mail.example.com');

    assertTrue($found !== null, 'ต้องหาใบที่ครอบคลุมชื่อนี้เจอ');
    assertSame('letsencrypt', $found['source'], 'ใบจริงต้องชนะใบที่เซ็นเอง');
    assertSame(CertbotManager::LIVE_DIR . '/example.com/fullchain.pem', $found['cert'], 'ต้องเป็นใบของ example.com');
    assertSame('valid', $found['status'], 'ใบที่เพิ่งออกต้องยังใช้ได้');

    // ชื่อที่ไม่มีใบไหนครอบคลุมต้องคืน null ไม่ใช่หยิบใบที่ใกล้เคียงที่สุดมาใช้ —
    // ใบที่ชื่อไม่ตรงทำให้โปรแกรมเมลเตือนแรงกว่าเดิม ไม่ใช่เตือนน้อยลง
    assertTrue(
        (new MailCertificate(new CertbotManager()))->locate($executor, 'mail.nowhere.test') === null,
        'ไม่มีใบที่ครอบคลุมต้องคืน null',
    );
});

test('wildcard ครอบคลุมได้ชั้นเดียวตามที่โปรแกรมเมลบังคับใช้จริง', static function (): void {
    // ใบ `*.example.com` ที่ผู้ดูแลขอไว้ให้เว็บครอบคลุม mail.example.com อยู่แล้ว —
    // การไม่รู้จักมันแปลว่าบังคับให้ไปขอใบซ้ำโดยไม่จำเป็น
    assertTrue(MailCertificate::covers(['*.example.com'], 'mail.example.com'), 'wildcard ต้องครอบคลุมชั้นแรก');
    assertTrue(MailCertificate::covers(['example.com', 'mail.example.com'], 'mail.example.com'), 'ชื่อตรงตัวต้องผ่าน');
    assertTrue(MailCertificate::covers(['MAIL.EXAMPLE.COM'], 'mail.example.com'), 'ต้องไม่แคร์ตัวพิมพ์');

    // RFC 6125: `*.example.com` ไม่ครอบคลุมชื่อที่ลึกลงไปอีกชั้น และไม่ครอบคลุม
    // โดเมนเปล่า · ยอมรับผิดตรงนี้แปลว่าเขียนใบที่ไคลเอนต์จะปฏิเสธลงไฟล์ตั้งค่า
    assertTrue(!MailCertificate::covers(['*.example.com'], 'a.mail.example.com'), 'wildcard ต้องไม่ข้ามสองชั้น');
    assertTrue(!MailCertificate::covers(['*.example.com'], 'example.com'), 'wildcard ต้องไม่ครอบคลุมโดเมนเปล่า');
    assertTrue(!MailCertificate::covers(['*.example.com'], 'mail.example.com.evil.test'), 'ต้องเทียบท้ายชื่อ ไม่ใช่แค่มีคำนี้อยู่');
    assertTrue(!MailCertificate::covers([], 'mail.example.com'), 'ใบที่ไม่มีชื่อในนั้นต้องไม่ผ่าน');
});

test('ต่ออายุแล้วต้องรู้ว่าต้องบอกเดมอนใหม่ ทั้งที่เส้นทางเหมือนเดิมทุกตัวอักษร', static function (): void {
    /*
     * **นี่คือกรณีที่เกิดบ่อยที่สุด — ทุก 60 วัน ตลอดอายุของเครื่อง**
     *
     * certbot ต่ออายุเองโดยไม่ผ่าน panel เลย ไฟล์ใหม่ทับที่เดิม · Dovecot ถือใบที่
     * อ่านตอนสตาร์ตไว้จนกว่าจะถูกสั่ง reload · เทียบแค่ "เส้นทางเปลี่ยนไหม" จะตอบว่า
     * ไม่มีอะไรเปลี่ยนตลอดไป แล้วลูกค้าเจอใบหมดอายุตอนเปิดกล่องโดยไม่มีอะไรผิดปกติ
     * บนหน้าจอสักอย่าง
     */
    $executor = mailCertSandbox('renew');
    $certificates = new MailCertificate(new CertbotManager());

    mailCertIssue($executor, CertbotManager::LIVE_DIR . '/example.com', 'mail.example.com');

    $cert = CertbotManager::LIVE_DIR . '/example.com/fullchain.pem';

    // ยังไม่เคยเขียนไฟล์ตั้งค่าเลย = ยังไม่เคยบอกใคร ต้องถือว่าเปลี่ยน
    assertTrue(
        $certificates->changedSince($executor, $cert, MailboxManager::DOVECOT_CONF),
        'ไม่มีไฟล์ตั้งค่า = ยังไม่เคยบอกเดมอน',
    );

    // เขียนไฟล์ตั้งค่าหลังใบ = เพิ่งบอกไปแล้ว
    $executor->makeDirectory($executor->path(dirname(MailboxManager::DOVECOT_CONF)), 0755);
    touch($executor->path(MailboxManager::DOVECOT_CONF), time() + 5);

    assertTrue(
        !$certificates->changedSince($executor, $cert, MailboxManager::DOVECOT_CONF),
        'บอกไปแล้วและใบยังเป็นใบเดิม = ไม่ต้องทำอะไร',
    );

    // certbot ต่ออายุ: ไฟล์ใหม่ทับที่เดิม เส้นทางไม่เปลี่ยนสักตัวอักษร
    touch($executor->path($cert), time() + 60);

    assertTrue(
        $certificates->changedSince($executor, $cert, MailboxManager::DOVECOT_CONF),
        'ใบใหม่กว่าไฟล์ตั้งค่า = ต้องบอกเดมอนใหม่',
    );
});

test('ไฟล์ตั้งค่าที่สร้างไว้ก่อนอัปเกรดต้องถูกจับได้ว่าล้าสมัย', static function (): void {
    /*
     * **เจอจริงบนเครื่องจริง (2026-08-12)** — `doveconf -n` ตอบว่า `ssl_cert` เป็นใบของ
     * ดิสโทร ทั้งที่เทมเพลตตั้งให้เรียบร้อยแล้ว
     *
     * เพราะ `99-phpcp.conf` ถูกเขียนใหม่**เฉพาะตอนมีคนแตะกล่องจดหมาย**เท่านั้น ·
     * เครื่องที่ตั้งเมลเสร็จไปแล้วจึงถือไฟล์รุ่นก่อนอัปเกรดไว้ต่อไปเรื่อย ๆ จนกว่าจะมีใคร
     * สร้างหรือลบกล่องสักกล่อง ซึ่งอาจไม่เกิดขึ้นอีกเลยเป็นปี — คุณสมบัติที่ "ทำเสร็จแล้ว"
     * ไม่เคยไปถึงเครื่องที่ใช้งานอยู่จริง และไม่มีอะไรฟ้องเพราะเมลยังทำงานปกติทุกอย่าง
     *
     * เทียบกับ**เนื้อไฟล์ที่ใช้อยู่จริง** ไม่ใช่กับค่าในฐานข้อมูล ซึ่งบอกได้แค่ว่า
     * "เคยตั้งใจให้เป็นแบบนั้น"
     */
    $drift = new ReflectionMethod(Phpcp\Agent\Capability\MailCert::class, 'drift');
    $capability = new Phpcp\Agent\Capability\MailCert();
    $executor = mailCertSandbox('drift');
    $want = '/etc/letsencrypt/live/example.com/fullchain.pem';

    // ยังไม่มีไฟล์เลย = ยังไม่ได้บอก Dovecot
    assertTrue((bool) $drift->invoke($capability, $executor, $want), 'ไม่มีไฟล์ต้องถือว่าล้าสมัย');

    // ไฟล์รุ่นก่อนอัปเกรด: ตั้งค่าครบทุกอย่าง **ยกเว้น** บรรทัดใบรับรอง
    $executor->makeDirectory($executor->path(dirname(MailboxManager::DOVECOT_CONF)), 0755);
    $executor->writeFile($executor->path(MailboxManager::DOVECOT_CONF), "ssl = required\nprotocols = imap pop3 lmtp\n");

    assertTrue((bool) $drift->invoke($capability, $executor, $want), 'ไฟล์ที่ไม่มีบรรทัดใบรับรองต้องถือว่าล้าสมัย');

    // ไฟล์ที่ชี้ไปใบอื่น — เกิดตอนย้ายจากใบที่เซ็นเองไปใบจริง
    $executor->writeFile(
        $executor->path(MailboxManager::DOVECOT_CONF),
        "ssl = required\nssl_cert = </etc/ssl/certs/ssl-cert-snakeoil.pem\n",
    );

    assertTrue((bool) $drift->invoke($capability, $executor, $want), 'ไฟล์ที่ชี้ใบอื่นต้องถือว่าล้าสมัย');

    // ไฟล์ที่ตรงแล้วต้องไม่ถูกเขียนซ้ำทุกวันโดยไม่มีเหตุผล
    $executor->writeFile(
        $executor->path(MailboxManager::DOVECOT_CONF),
        "ssl = required\nssl_cert = <" . $want . "\nssl_key = </etc/letsencrypt/live/example.com/privkey.pem\n",
    );

    assertTrue(!(bool) $drift->invoke($capability, $executor, $want), 'ไฟล์ที่ตรงแล้วต้องไม่ถูกเขียนซ้ำ');
});

test('ใบที่เซ็นเองต้องครอบคลุมทุกโดเมนของเว็บ ไม่ใช่แค่โดเมนหลัก', static function (): void {
    /*
     * ไคลเอนต์สมัยใหม่ดูแต่ subjectAltName ไม่สนใจ CN แล้ว · ใบที่มีแต่โดเมนหลักจึงถูก
     * ปฏิเสธทันทีเมื่อเข้าผ่านชื่ออื่นของเว็บเดียวกัน ทั้งที่หน้าจอบอกว่าติดตั้งเรียบร้อย
     *
     * เจอตอนทำ M3 บนเครื่องจริง: `mail.bbl.test` เป็นโดเมนหนึ่งของเว็บ แต่ใบที่เซ็นเอง
     * ครอบคลุมแค่ `bbl.test` · `mail.cert` จึงมองไม่เห็นใบนั้น (ถูกต้องแล้ว) แล้วทางที่
     * ควรใช้ได้บนเครื่องที่ขอใบจริงไม่ได้ กลับตันโดยไม่มีอะไรอธิบาย
     *
     * ออกใบด้วย openssl จริงแล้วอ่าน SAN กลับมา — ไม่ได้ตรวจแค่ว่าโค้ดประกอบ argv ถูก
     */
    $executor = mailCertSandbox('selfsign');
    $site = new Phpcp\Domain\Site(
        id: 1,
        name: 'เว็บทดสอบ',
        domain: 'bbl.test',
        owner: new Phpcp\Domain\UserAccount(1, 'tester'),
        phpVersion: '8.4',
        status: 'active',
        aliases: ['www.bbl.test', 'mail.bbl.test'],
    );

    (new CertbotManager())->selfSign($executor, $site, ['bbl.test', 'www.bbl.test', 'mail.bbl.test']);

    $cert = CertbotManager::SELF_SIGNED_DIR . '/bbl.test/fullchain.pem';
    $info = (new CertbotManager())->inspectFile($executor, $cert);
    $domains = (array) ($info['domains'] ?? []);

    foreach (['bbl.test', 'www.bbl.test', 'mail.bbl.test'] as $name) {
        assertTrue(in_array($name, $domains, true), "ใบต้องครอบคลุม {$name} · ได้: " . implode(', ', $domains));
    }

    // และต้องเป็นใบที่ `mail.cert` หยิบไปใช้ได้จริง ไม่ใช่แค่มีชื่ออยู่ในไฟล์
    $found = (new MailCertificate(new CertbotManager()))->locate($executor, 'mail.bbl.test');

    assertTrue($found !== null, 'mail.cert ต้องหาใบที่เซ็นเองใบนี้เจอ');
    assertSame('self-signed', $found['source'], 'ต้องรายงานว่าเป็นใบที่เซ็นเอง');
    assertSame($cert, $found['cert'], 'ต้องชี้ไปที่ใบของเว็บนั้น');
});

test('อัปเกรดเทมเพลตแล้วต้องรู้ว่าไฟล์บนเครื่องเก่ากว่า', static function (): void {
    /*
     * **ปัญหาที่กัดซ้ำสามครั้งในเฟสนี้** — ติดตั้ง panel รุ่นใหม่ไม่ได้แปลว่าไฟล์ใน `/etc`
     * ถูกเขียนใหม่ · ครั้งแรกคือ `ssl_cert` ที่หายไปจาก Dovecot ครั้งที่สองคือ
     * `mydestination` ที่ยังเป็นค่าเก่าจนเมลค้างในคิว
     *
     * เทียบเวลาแก้ไขของเทมเพลตกับไฟล์ที่มันสร้าง — ไม่ต้องรู้ว่าเทมเพลตเปลี่ยนอะไร
     */
    $outdated = new ReflectionMethod(Phpcp\Agent\Capability\MailCert::class, 'outdated');
    $capability = new Phpcp\Agent\Capability\MailCert();
    $executor = mailCertSandbox('outdated');

    // รากของ "การติดตั้ง" จำลอง — `Paths::templates()` คือ <ราก>/templates
    $root = sys_get_temp_dir() . '/phpcp-tpl-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($root . '/etc', 0700, true);
    mkdir($root . '/templates/postfix', 0755, true);
    mkdir($root . '/templates/dovecot', 0755, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    foreach (['postfix/main.cf.tpl', 'postfix/hosting.cf.tpl', 'postfix/master.cf.tpl', 'dovecot/99-phpcp.conf.tpl'] as $name) {
        file_put_contents($root . '/templates/' . $name, "# tpl\n");
    }

    file_put_contents($root . '/etc/config.php', "<?php return ['mode' => 'sandbox', 'layout' => 'portable'];\n");

    $previous = getenv('PHPCP_CONFIG');
    putenv('PHPCP_CONFIG=' . $root . '/etc/config.php');
    $config = Phpcp\Kernel\Config::load($root);
    putenv($previous === false ? 'PHPCP_CONFIG' : 'PHPCP_CONFIG=' . $previous);

    $context = new Phpcp\Agent\Context(
        new Phpcp\Agent\Actor(0, 'tester', Phpcp\Security\Permissions::SUPERADMIN, '127.0.0.1', 'test'),
        $config,
        new Phpcp\Kernel\Db($root . '/panel.db'),
    );

    assertSame($root . '/templates', $config->paths->templates(), 'เทมเพลตต้องชี้ไปที่รากจำลอง ไม่ใช่ของจริง');

    // ยังไม่เคยเขียนไฟล์ลง /etc เลย = ต้องถือว่าเก่า
    assertTrue((bool) $outdated->invoke($capability, $executor, $context), 'ไม่มีไฟล์ที่สร้างไว้ = ต้องเขียนใหม่');

    // เขียนไฟล์ทั้งหมด "หลัง" เทมเพลต = เป็นปัจจุบันแล้ว
    foreach (['/etc/postfix/main.cf', '/etc/postfix/master.cf', MailboxManager::DOVECOT_CONF] as $path) {
        // เขียนตรง ๆ ด้วย path ที่แปลงแล้ว — `makeDirectory()` แปลงเส้นทางให้อีกรอบ
        // จึงได้ราก sandbox ซ้อนสองชั้นถ้าส่งเส้นทางที่แปลงมาแล้วเข้าไป
        $resolved = $executor->path($path);
        @mkdir(dirname($resolved), 0755, true);
        file_put_contents($resolved, "generated\n");
        touch($resolved, time() + 30);
    }

    assertTrue(!(bool) $outdated->invoke($capability, $executor, $context), 'ไฟล์ใหม่กว่าเทมเพลต = ไม่ต้องทำอะไร');

    // อัปเกรด panel: เทมเพลตถูกแทนที่ด้วยรุ่นใหม่ ไฟล์ใน /etc ยังเป็นของเดิม
    touch($root . '/templates/postfix/main.cf.tpl', time() + 120);

    assertTrue((bool) $outdated->invoke($capability, $executor, $context), 'เทมเพลตใหม่กว่าไฟล์ = ต้องเขียนใหม่');
});

test('ชื่อโฮสต์ของเมลต้องมาจากที่เดียวทั้งระบบ', static function (): void {
    /*
     * ค่านี้ถูกใช้สามที่ที่ต้องตอบตรงกันเสมอ: `myhostname` ของ Postfix · การหาว่าใบไหน
     * ครอบคลุมชื่อนี้ · การรายงานความพร้อม
     *
     * ตอนที่แต่ละที่อ่านเอง `sync()` อนุมานจาก `mail.from` ต่อได้ แต่ `mail.cert` กับ
     * หน้าความพร้อมอ่านแค่ `mail.hostname` ตรง ๆ · เครื่องที่ไม่เคยกรอกช่องชื่อโฮสต์จึงมี
     * Postfix ที่ประกาศชื่อถูกต้องอยู่ แต่ปุ่มผูกใบรับรองตอบว่า "ยังไม่ได้ตั้งชื่อโฮสต์"
     * แล้วไม่ทำอะไรเลย — และไม่มีอะไรบอกว่าทำไม
     */
    $resolve = new ReflectionMethod(Phpcp\Agent\Capability\MailCert::class, 'mailHostname');

    // ทั้งสามตัวต้องเรียกตัวเดียวกัน ไม่ใช่ต่างคนต่างอ่านค่าตั้งเอง
    foreach ([
        'src/Agent/Capability/MailCapability.php',
        'src/Agent/Capability/MailCert.php',
        'src/Agent/Capability/MailReadiness.php',
    ] as $file) {
        $code = (string) preg_replace(
            '~/\*.*?\*/|//[^\n]*~s',
            '',
            (string) file_get_contents(PHPCP_ROOT . '/' . $file),
        );

        assertTrue(
            str_contains($code, 'mailHostname('),
            "{$file} ต้องถามชื่อโฮสต์จากที่เดียวกัน",
        );
        assertTrue(
            !str_contains($code, "get('mail.hostname')") || str_contains($code, 'protected static function mailHostname'),
            "{$file} ต้องไม่อ่าน mail.hostname เอง",
        );
    }

    // ลำดับการถอย: ช่องที่กรอกไว้ → ส่วนโดเมนของที่อยู่ผู้ส่ง → hostname ของเครื่อง
    $root = sys_get_temp_dir() . '/phpcp-mailhost-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $settings = new Phpcp\Domain\SettingsRepository($db);

    $settings->save(['mail.hostname' => 'mail.example.com', 'mail.from' => 'x@other.test']);
    assertSame('mail.example.com', (string) $resolve->invoke(null, $settings), 'ช่องที่กรอกไว้ต้องชนะ');

    // ช่องว่าง ๆ ต้องนับเป็นว่าง ไม่ใช่ชื่อโฮสต์ที่ชื่อว่า "  "
    $settings->save(['mail.hostname' => '  ', 'mail.from' => 'postmaster@example.com']);
    assertSame('example.com', (string) $resolve->invoke(null, $settings), 'ว่างแล้วต้องอนุมานจากที่อยู่ผู้ส่ง');

    $settings->save(['mail.hostname' => '', 'mail.from' => '']);
    assertSame(gethostname() ?: '', (string) $resolve->invoke(null, $settings), 'ไม่มีอะไรเลยต้องถอยไปชื่อเครื่อง');
});

test('ใบที่ไม่มีกุญแจคู่กันต้องไม่ถูกหยิบมาใช้ — เดมอนจะสตาร์ตไม่ขึ้นทั้งตัว', static function (): void {
    $executor = mailCertSandbox('halfcert');

    mailCertIssue($executor, CertbotManager::LIVE_DIR . '/example.com', 'mail.example.com');
    unlink($executor->path(CertbotManager::LIVE_DIR . '/example.com/privkey.pem'));

    assertTrue(
        (new MailCertificate(new CertbotManager()))->locate($executor, 'mail.example.com') === null,
        'ใบที่ขาดกุญแจต้องถือว่าใช้ไม่ได้',
    );
});

test('ยังไม่มีใบต้องถอยไปใช้ใบของดิสโทร ไม่ใช่เขียนค่าว่างลงไฟล์ตั้งค่า', static function (): void {
    // ค่าว่างใน `smtpd_tls_cert_file` ทำให้ Postfix สตาร์ตไม่ขึ้น — เครื่องที่ยังไม่ได้
    // ขอใบต้องยังรับส่งเมลได้ แค่โปรแกรมเมลขึ้นคำเตือน ซึ่งหน้าความพร้อมบอกไว้แล้ว
    $fallback = MailCertificate::pathsOrDefault('', '');

    assertSame(MailCertificate::DEFAULT_CERT, $fallback['cert'], 'ต้องถอยไปใบของดิสโทร');
    assertSame(MailCertificate::DEFAULT_KEY, $fallback['key'], 'ต้องถอยไปกุญแจของดิสโทร');

    // มีใบแต่ไม่มีกุญแจ (หรือกลับกัน) ต้องถอยทั้งคู่ ไม่ใช่ผสมของสองใบเข้าด้วยกัน
    assertSame(MailCertificate::DEFAULT_CERT, MailCertificate::pathsOrDefault('/tmp/a.pem', '')['cert'], 'ขาดกุญแจต้องถอยทั้งคู่');
    assertSame(MailCertificate::DEFAULT_KEY, MailCertificate::pathsOrDefault('', '/tmp/a.key')['key'], 'ขาดใบต้องถอยทั้งคู่');

    $real = MailCertificate::pathsOrDefault('/etc/letsencrypt/live/x/fullchain.pem', '/etc/letsencrypt/live/x/privkey.pem');

    assertSame('/etc/letsencrypt/live/x/fullchain.pem', $real['cert'], 'มีครบต้องใช้ใบจริง');
});

test('บันทึกค่าเมลขาออกต้องไม่ลบส่วนรับเมลออกจาก main.cf', static function (): void {
    /*
     * **บั๊กที่ชุดนี้มีไว้กันตั้งแต่แรก**
     *
     * `mail.apply` เคยเรียก `MailManager::apply()` เอง โดยส่งแค่ค่าเมลขาออก · มันไม่รู้
     * ว่าเครื่องนี้เปิดเมลโฮสติ้งให้โดเมนไหนอยู่บ้าง `hosting` จึงเป็น false เสมอ แล้ว
     * `main.cf` ถูกเขียนทับด้วยไฟล์ที่ **ไม่มีส่วนรับเมลเลยสักบรรทัด** และ
     * `inet_interfaces = loopback-only`
     *
     * ผลคือกล่องทุกกล่องบนเครื่องหยุดรับเมล เพราะมีคนกดบันทึกที่อยู่ผู้ส่ง · ทุกขั้นตอน
     * รายงานว่าสำเร็จ ไฟล์ที่เขียนไปถูกต้องตามที่สั่งทุกตัวอักษร ไม่มีอะไรให้ผู้ดูแล
     * สงสัยเลยว่าตัวเองเพิ่งทำอะไรลงไป — ตระกูลเดียวกับบั๊ก `ports.conf` ของ Apache
     */
    $templates = new Template(PHPCP_ROOT . '/templates');
    $section = new ReflectionMethod(MailManager::class, 'hostingSection');
    $manager = new MailManager($templates);

    $hosting = (string) $section->invoke($manager, []);

    $main = $templates->render('postfix/main.cf.tpl', [
        'HOSTNAME' => 'mail.example.com',
        'ORIGIN' => 'mail.example.com',
        'RELAY_HOST' => '',
        'SASL_ENABLED' => 'no',
        'TLS_SECURITY' => 'may',
        'INET_INTERFACES' => 'all',
        'MYDESTINATION' => 'localhost, $myhostname',
        'HOSTING_SECTION' => new Phpcp\Driver\SafeBlock($hosting),
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);

    foreach ([
        'inet_interfaces = all' => 'ต้องฟังทุกหน้าตัดเน็ต ไม่ใช่แค่ loopback',
        'virtual_mailbox_domains' => 'ต้องรู้ว่ารับเมลให้โดเมนไหน',
        'virtual_transport = lmtp:' => 'ต้องส่งต่อให้ Dovecot เขียนลงกล่อง',
        'smtpd_sasl_auth_enable = yes' => 'ต้องให้คนที่ล็อกอินแล้วส่งออกได้',
        'reject_unauth_destination' => 'บรรทัดที่กันไม่ให้เป็น open relay ต้องอยู่ครบ',
    ] as $needle => $why) {
        assertTrue(str_contains($main, $needle), $why);
    }

    /*
     * ทางเดียวที่ `mail.apply` เขียน `main.cf` ได้คือผ่าน `MailCapability::sync()`
     * ซึ่งอ่านโดเมนที่เปิดเมลจากฐานข้อมูลมาประกอบเองทุกครั้ง · ตัดคอมเมนต์ก่อนตรวจ
     * เพราะคำอธิบายที่หัวคลาสพูดถึงบั๊กนี้ตรง ๆ และจะทำให้เทสต์ผ่านโดยไม่ได้ตรวจอะไร
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/MailApply.php'),
    );

    assertTrue(
        str_contains($code, 'extends MailCapability'),
        'mail.apply ต้องเดินผ่านทางเดียวกับคำสั่งเมลอื่น',
    );
    assertTrue(
        str_contains($code, '$this->sync('),
        'ต้องเขียนไฟล์ตั้งค่าผ่าน sync() ซึ่งรู้ว่ามีโดเมนไหนเปิดเมลอยู่',
    );
    assertTrue(
        !str_contains($code, 'new MailManager('),
        'ห้ามประกอบค่าตั้ง Postfix เองที่นี่ — ที่นี่ไม่รู้ว่ามีเมลโฮสติ้งเปิดอยู่ไหม',
    );
});

test('เลือก relay แล้วไม่กรอกโฮสต์ต้องถูกปฏิเสธ ไม่ใช่รับไว้เงียบ ๆ', static function (): void {
    /*
     * โหมด relay ที่ไม่มีโฮสต์ปลายทาง = `relayhost` ว่าง = Postfix ส่งเองแบบ local
     * ต่อไปตามเดิม · หน้าจอบอกว่า "ส่งผ่าน relay" แต่เมลออกทางเดิมทุกฉบับ ซึ่งบนเครื่อง
     * ที่ผู้ให้บริการบล็อกพอร์ต 25 แปลว่าเมลค้างในคิวจนหมดอายุแล้วหายไป
     */
    $capability = new Phpcp\Agent\Capability\MailApply();
    $clean = $capability->validate(['mail.mode' => 'relay', 'mail.relay_host' => '']);

    assertSame('relay', $clean['values']['mail.mode'], 'ค่าที่ตรวจแล้วต้องยังเป็น relay');

    foreach (['direct', 'smtp', '', 'LOCAL'] as $bad) {
        $rejected = false;

        try {
            $capability->validate(['mail.mode' => $bad]);
        } catch (Phpcp\Agent\ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "โหมดที่ไม่รู้จักต้องถูกปฏิเสธ: {$bad}");
    }

    // รหัสผ่านที่ยังเป็นดอกจัน = ผู้ใช้ไม่ได้แตะช่องนั้น ต้องไม่ถูกบันทึกทับของจริง
    $masked = $capability->validate(['mail.relay_password' => '********']);

    assertSame('********', $masked['values']['mail.relay_password'], 'validate ต้องส่งต่อตามที่ได้รับ');
    assertTrue(
        str_contains(
            (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/MailApply.php')),
            "=== '********'",
        ),
        'run() ต้องทิ้งค่าดอกจันก่อนบันทึก ไม่งั้นการกดบันทึกครั้งเดียวลบรหัส relay ทิ้ง',
    );
});

test('Postfix กับ Dovecot ต้องได้ใบใบเดียวกันเสมอ', static function (): void {
    /*
     * ตั้งใบจริงให้ Postfix ฝ่ายเดียวได้เครื่องที่ "ส่งเมลไม่มีคำเตือน แต่เปิดกล่อง
     * ทีไรก็เตือนใบรับรองทุกที" — หาสาเหตุยากเป็นพิเศษเพราะครึ่งหนึ่งของระบบถูกต้อง
     */
    $templates = new Template(PHPCP_ROOT . '/templates');
    $cert = '/etc/letsencrypt/live/example.com/fullchain.pem';
    $key = '/etc/letsencrypt/live/example.com/privkey.pem';

    $postfix = new ReflectionMethod(MailManager::class, 'hostingSection');
    $hosting = (string) $postfix->invoke(new MailManager($templates), ['tls_cert' => $cert, 'tls_key' => $key]);

    $dovecot = $templates->render('dovecot/99-phpcp.conf.tpl', [
        'MAIL_ROOT' => MailboxManager::MAIL_ROOT,
        'VMAIL_USER' => MailboxManager::VMAIL_USER,
        'USERS_FILE' => '/etc/dovecot/phpcp-users',
        'TLS_CERT' => $cert,
        'TLS_KEY' => $key,
        'GENERATED_AT' => '2026-01-01 00:00:00',
    ]);

    assertTrue(str_contains($hosting, 'smtpd_tls_cert_file = ' . $cert), 'Postfix ต้องได้ใบที่ผูกไว้');
    assertTrue(str_contains($dovecot, 'ssl_cert = <' . $cert), 'Dovecot ต้องได้ใบใบเดียวกัน');
    assertTrue(str_contains($dovecot, 'ssl_key = <' . $key), 'Dovecot ต้องได้กุญแจใบเดียวกัน');
});
