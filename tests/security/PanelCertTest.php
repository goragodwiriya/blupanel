<?php

declare(strict_types=1);

/**
 * ใบรับรองของ **หน้าจัดการเอง** — คำสั่งที่ตัดทางเข้าของตัวเองได้
 *
 * ## ทำไมขอบเขตนี้อันตรายเป็นพิเศษ
 *
 * ใบที่ผิดไม่ได้ทำให้หน้าจอเพี้ยน แต่ทำให้ **เบราว์เซอร์ปฏิเสธการเชื่อมต่อทั้งหมด** —
 * แล้วหน้าเว็บซึ่งเป็นที่เดียวที่จะแก้กลับได้ก็เข้าไม่ได้ไปด้วย · อยู่ในกลุ่มเดียวกับ
 * กฎไฟร์วอลล์และค่าตั้ง SSH
 *
 * ที่แย่กว่าคือความผิดพลาดบางแบบ **ไม่แสดงอาการทันที**: คู่กุญแจที่ไม่ตรงกันทำให้ Apache
 * สตาร์ตไม่ขึ้น *ในการรีบูตครั้งถัดไป* ซึ่งอาจเป็นเดือนต่อมา และไม่มีอะไรโยงกลับมาที่
 * การกดปุ่มในวันนั้น
 *
 * ชุดนี้จึงเฝ้าสามข้อ:
 *
 *   1. คู่กุญแจที่ไม่ตรงกันต้องถูกปฏิเสธ **ก่อน**เขียนไฟล์
 *   2. ใบที่หมดอายุแล้วต้องถูกปฏิเสธ
 *   3. ต้องมีทางกลับที่ไม่ต้องพึ่งหน้าเว็บเสมอ
 */

use Phpcp\Agent\Capability\PanelCertSet;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\PanelCertificate;

group('PanelCertificate — ใบรับรองของหน้าจัดการ');

/**
 * สร้างคู่ใบรับรอง/กุญแจจริงด้วย openssl
 *
 * **ต้องเป็นใบจริง ไม่ใช่ข้อความปลอม** — สิ่งที่ตรวจคือการเทียบกุญแจสาธารณะซึ่งต้องรัน
 * openssl จริงถึงจะพิสูจน์อะไรได้ · ไฟล์ปลอมจะทำให้เทสต์ผ่านเพราะ openssl อ่านไม่ออก
 * ทั้งคู่ ซึ่งไม่ได้ตรวจสิ่งที่ตั้งใจตรวจเลย
 *
 * @return array{cert:string,key:string}
 */
function panelCertPair(string $cn, int $days = 30): array
{
    $dir = sys_get_temp_dir() . '/phpcp-panelcert-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

    $cert = $dir . '/cert.pem';
    $key = $dir . '/key.pem';

    exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -days %d -keyout %s -out %s -subj %s 2>/dev/null',
        max(1, $days),
        escapeshellarg($key),
        escapeshellarg($cert),
        escapeshellarg('/CN=' . $cn),
    ));

    return ['cert' => $cert, 'key' => $key];
}

/**
 * ใบที่หมดอายุไปแล้วจริง ๆ
 *
 * `openssl req -x509` กำหนดวันหมดอายุย้อนหลังไม่ได้ (`-days` ต้องเป็นบวก และ 3.0 ยังไม่มี
 * `-not_after`) — ต้องเซ็นผ่าน `openssl ca` ซึ่งระบุ `-enddate` ได้ · ยาวกว่าแต่ได้ใบจริง
 * ที่ openssl อ่านแล้วบอกว่าหมดอายุ ซึ่งเป็นสิ่งเดียวที่พิสูจน์ตัวตรวจได้
 *
 * @return array{cert:string,key:string}
 */
function panelCertExpired(string $cn): array
{
    $dir = sys_get_temp_dir() . '/phpcp-panelexp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir . '/newcerts', 0700, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

    file_put_contents($dir . '/index.txt', '');
    file_put_contents($dir . '/serial', "1000\n");
    file_put_contents($dir . '/openssl.cnf', implode("\n", [
        '[ca]', 'default_ca = CA_default',
        '[CA_default]',
        'database = ' . $dir . '/index.txt',
        'serial = ' . $dir . '/serial',
        'new_certs_dir = ' . $dir . '/newcerts',
        'default_md = sha256', 'policy = pol', 'email_in_dn = no', 'rand_serial = yes',
        '[pol]', 'commonName = supplied', '',
    ]));

    $q = static fn (string $v): string => escapeshellarg($v);

    exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout %s -out %s -subj %s 2>/dev/null',
        $q($dir . '/ca.key'), $q($dir . '/ca.crt'), $q('/CN=phpcp-test-ca'),
    ));
    exec(sprintf(
        'openssl req -newkey rsa:2048 -nodes -keyout %s -out %s -subj %s 2>/dev/null',
        $q($dir . '/leaf.key'), $q($dir . '/leaf.csr'), $q('/CN=' . $cn),
    ));
    exec(sprintf(
        'openssl ca -batch -config %s -cert %s -keyfile %s -in %s -out %s '
            . '-startdate 20200101000000Z -enddate 20200201000000Z -notext 2>/dev/null',
        $q($dir . '/openssl.cnf'), $q($dir . '/ca.crt'), $q($dir . '/ca.key'),
        $q($dir . '/leaf.csr'), $q($dir . '/leaf.crt'),
    ));

    return ['cert' => $dir . '/leaf.crt', 'key' => $dir . '/leaf.key'];
}

test('คู่กุญแจที่ไม่ตรงกันต้องถูกปฏิเสธก่อนเขียนไฟล์', static function (): void {
    /*
     * **ความผิดพลาดที่ไม่แสดงอาการทันที** — Apache ที่รันอยู่ยังทำงานต่อได้เพราะอ่านค่า
     * ไปแล้ว แต่จะสตาร์ตไม่ขึ้นในการรีบูตครั้งถัดไป ซึ่งอาจเป็นเดือนต่อมา · ไม่มีใคร
     * โยงกลับมาที่การกดปุ่มในวันนั้น
     *
     * เทียบด้วยลายนิ้วมือของกุญแจสาธารณะเป็นวิธีเดียวที่ตอบได้แน่นอน — ชื่อโดเมนที่
     * ตรงกันไม่ได้แปลว่าเป็นคู่กัน และเป็นกับดักที่เจอบ่อยเวลาสลับใบด้วยมือ
     */
    $a = panelCertPair('panel.example.com');
    $b = panelCertPair('panel.example.com');

    $panel = new PanelCertificate();
    $executor = new Phpcp\Agent\Executor\RealExecutor();

    // คู่ที่ถูกต้องต้องผ่าน — ไม่งั้นเทสต์ข้างล่างพิสูจน์อะไรไม่ได้เลย
    $ok = $panel->read($executor, $a['cert'], $a['key']);

    assertTrue(str_contains($ok['cert'], 'BEGIN CERTIFICATE'), 'คู่ที่ถูกต้องต้องอ่านได้');

    $message = '';

    try {
        // ใบของ a กับกุญแจของ b — ชื่อโดเมนตรงกันทุกตัวอักษร แต่ไม่ใช่คู่กัน
        $panel->read($executor, $a['cert'], $b['key']);
    } catch (ValidationError $e) {
        $message = $e->getMessage();
    }

    assertTrue($message !== '', 'คู่กุญแจที่ไม่ตรงกันต้องถูกปฏิเสธ');
    assertTrue(
        str_contains($message, 'ไม่ใช่คู่กัน'),
        'ต้องบอกให้ชัดว่าปัญหาคืออะไร ไม่ใช่แค่ "ผิดพลาด": ' . $message,
    );
});

test('ใบที่หมดอายุแล้วต้องถูกปฏิเสธ', static function (): void {
    // ใบหมดอายุทำให้เบราว์เซอร์ปฏิเสธเหมือนกับใบที่ผิด — ต่างกันแค่ข้อความบนหน้าจอ
    $expired = panelCertExpired('old.example.com');

    assertTrue(is_file($expired['cert']), 'ต้องสร้างใบที่หมดอายุได้จริงก่อน ไม่งั้นเทสต์นี้ไม่ได้ตรวจอะไร');

    $message = '';

    try {
        (new PanelCertificate())->read(
            new Phpcp\Agent\Executor\RealExecutor(),
            $expired['cert'],
            $expired['key'],
        );
    } catch (ValidationError $e) {
        $message = $e->getMessage();
    }

    assertTrue($message !== '', 'ใบที่หมดอายุต้องถูกปฏิเสธ');
    assertTrue(str_contains($message, 'หมดอายุ'), 'ต้องบอกว่าหมดอายุ: ' . $message);
});

test('ไฟล์ที่ไม่มีอยู่ต้องบอกว่าให้ไปขอใบก่อน ไม่ใช่ล้มแบบไม่มีคำอธิบาย', static function (): void {
    $message = '';

    try {
        (new PanelCertificate())->read(
            new Phpcp\Agent\Executor\RealExecutor(),
            '/etc/letsencrypt/live/never-issued.example.com/fullchain.pem',
            '/etc/letsencrypt/live/never-issued.example.com/privkey.pem',
        );
    } catch (ValidationError $e) {
        $message = $e->getMessage();
    }

    assertTrue($message !== '', 'ไฟล์ที่ไม่มีต้องถูกปฏิเสธ');
    assertTrue(
        str_contains($message, 'ขอใบรับรองให้โดเมนนี้ก่อน'),
        'ต้องบอกทางแก้ ไม่ใช่แค่บอกว่าไม่พบไฟล์: ' . $message,
    );
});

test('เส้นทางของใบต้องประกอบจากโดเมนที่ผ่านการตรวจเท่านั้น', static function (): void {
    /*
     * ชื่อโดเมนถูกเอาไปประกอบเป็นเส้นทางไฟล์ที่จะถูกอ่านแล้วคัดลอกไปเป็นใบของหน้าจัดการ ·
     * ถ้าหลุดออกนอกกติกาได้ ผู้เรียกจะอ่านไฟล์ไหนบนเครื่องก็ได้ผ่านทางนี้
     */
    foreach (['../../etc/shadow', 'a/../../..', 'x y.com', "a\nb.com", '', '.', '/etc/passwd'] as $bad) {
        $rejected = false;

        try {
            PanelCertificate::sourcePaths($bad);
        } catch (\Throwable) {
            $rejected = true;
        }

        assertTrue($rejected, "ต้องปฏิเสธโดเมน: {$bad}");
    }

    $paths = PanelCertificate::sourcePaths('panel.example.com');

    assertSame('/etc/letsencrypt/live/panel.example.com/fullchain.pem', $paths['cert'], 'เส้นทางใบต้องคงที่');
    assertSame('/etc/letsencrypt/live/panel.example.com/privkey.pem', $paths['key'], 'เส้นทางกุญแจต้องคงที่');
});

test('ต้องตั้งเวลาถอนคืนเมื่อสั่งจากหน้าเว็บ และไม่ตั้งเมื่อสั่งจากบรรทัดคำสั่ง', static function (): void {
    /*
     * **`window = 0` แปลว่าไม่ตั้งเลย ไม่ใช่ตั้งเป็นศูนย์วินาที**
     *
     * `RollbackGuard::arm()` บีบค่าให้อยู่ในช่วง 30–900 วินาทีเสมอ — ส่ง 0 เข้าไปตรง ๆ
     * จะได้ 30 วินาที แล้วคนที่สั่งจากบรรทัดคำสั่ง (ซึ่งมักกำลังกู้ระบบอยู่) จะเจอค่า
     * คืนกลับเองภายในครึ่งนาทีโดยไม่รู้ว่าต้องไปกดยืนยันที่ไหน
     */
    $code = (string) preg_replace(
        '~/\*.*?\*/|//[^\n]*~s',
        '',
        (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/PanelCertSet.php'),
    );

    assertTrue(
        str_contains($code, "if (\$args['window'] > 0) {"),
        'ต้องข้ามการตั้งเวลาถอนคืนเมื่อ window เป็น 0',
    );
    assertTrue(str_contains($code, 'RollbackGuard'), 'ต้องตั้งเวลาถอนคืนเมื่อสั่งจากหน้าเว็บ');

    // ทางกลับที่ไม่ต้องพึ่งหน้าเว็บ — ใช้ตอนที่หน้าเว็บเข้าไม่ได้แล้ว ซึ่งเป็นเหตุผลที่มันมีอยู่
    $cli = (string) file_get_contents(PHPCP_ROOT . '/src/Cli/Application.php');

    assertTrue(str_contains($cli, "'panel:cert'"), 'ต้องมีคำสั่ง panel:cert');
    assertTrue(str_contains($cli, '--self-signed'), 'ต้องมีทางกลับไปใช้ใบที่เซ็นเองจากบรรทัดคำสั่ง');
    assertTrue(str_contains($cli, "'panel:cert-sync'"), 'ต้องมีคำสั่งที่ certbot เรียกหลังต่ออายุ');
});

test('hook ต่ออายุต้องถูกติดตั้ง ไม่งั้นใบหมดอายุใน 90 วันแล้วกลับไปเจอคำเตือนอีก', static function (): void {
    /*
     * **จุดที่ลืมแล้วไม่มีใครรู้จนกว่าจะผ่านไปสามเดือน** — certbot ต่ออายุใบให้เรียบร้อย
     * ทุกอย่าง แต่ไฟล์ที่หน้าจัดการใช้เป็นสำเนาที่คัดลอกไว้ตอนกดปุ่ม มันจึงค้างอยู่ที่
     * ใบเดิมที่หมดอายุแล้ว · อาการที่ไม่มีใครโยงกลับมาที่การกดปุ่มเมื่อสามเดือนก่อน
     */
    $script = PanelCertificate::hookScript('/usr/bin/php', '/usr/share/phpcp/bin/phpcp');

    assertTrue(str_starts_with($script, "#!/bin/sh\n"), 'ต้องเป็นสคริปต์ที่รันได้');
    assertTrue(str_contains($script, 'panel:cert-sync'), 'ต้องเรียกคำสั่งที่รู้ว่าผูกกับโดเมนไหนอยู่');

    /*
     * `|| true` จำเป็น — hook ที่คืนค่าไม่เป็นศูนย์ทำให้ certbot รายงานว่าการต่ออายุ
     * ล้มเหลวทั้งที่ใบใหม่ออกมาแล้วเรียบร้อย ซึ่งทำให้คนไล่หาปัญหาผิดที่ทั้งวัน
     */
    assertTrue(str_contains($script, '|| true'), 'hook ต้องไม่ทำให้ certbot รายงานว่าต่ออายุล้มเหลว');

    assertTrue(
        str_starts_with(PanelCertificate::HOOK, '/etc/letsencrypt/renewal-hooks/deploy/'),
        'ต้องอยู่ในไดเรกทอรีที่ certbot เรียกหลังต่ออายุสำเร็จเท่านั้น',
    );
});

test('ค่าตั้งที่จำโดเมนไว้ต้องแก้ผ่านฟอร์มตั้งค่าทั่วไปไม่ได้', static function (): void {
    /*
     * ถ้าเขียนตรง ๆ ได้ ค่าจะเปลี่ยนโดยที่ไฟล์ใบรับรองไม่เปลี่ยนตาม แล้วหน้าจอจะรายงาน
     * สิ่งที่ไม่ตรงกับความจริง — และ hook ต่ออายุจะไปคัดลอกใบของโดเมนที่ไม่เกี่ยวข้องมาทับ
     */
    assertTrue(
        !isset(Phpcp\Domain\SettingsRepository::webEditableKeys()[PanelCertSet::SETTING]),
        'ต้องเขียนผ่าน panel.cert_set เท่านั้น',
    );
    assertSame('settings.manage', (new PanelCertSet())->permission(), 'ต้องใช้สิทธิ์ระดับเครื่อง');
});
