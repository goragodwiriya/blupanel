<?php

declare (strict_types = 1);

/**
 * ตัวจัดการไฟล์ — ด่านป้องกันการหลุดออกนอกโฟลเดอร์ของเว็บไซต์
 *
 * ตัวจัดการไฟล์เป็นส่วนที่อันตรายที่สุดของแผงควบคุม เพราะรับ "เส้นทาง" จากผู้ใช้
 * โดยตรงแล้วเอาไปทำงานด้วยสิทธิ์ของเจ้าของเว็บไซต์ ความเสี่ยงหลักมีสี่ข้อ:
 *   1. path traversal — ../../etc/passwd หลุดออกนอก document root
 *   2. Zip Slip — ไฟล์ในอาร์ไคฟ์ตั้งชื่อ entry เป็น ../ เพื่อเขียนทับไฟล์นอกปลายทาง
 *   3. สิทธิ์กว้างเกินจำเป็น — 0777 บนไฟล์เว็บทำให้ผู้ใช้อื่นบนเครื่องแก้สคริปต์ได้
 *   4. ลบโฟลเดอร์โครงสร้างของเว็บไซต์จนเว็บพัง
 *
 * เทสต์ชุดนี้ตรวจว่า "ปฏิเสธจริง" ไม่ใช่แค่พยายามทำความสะอาดค่าให้ดูปลอดภัย
 */

use Phpcp\Agent\Capability\FileChmod;
use Phpcp\Agent\Capability\FileDelete;
use Phpcp\Agent\Executor\RealExecutor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;

group('FileManager — ตัวจัดการไฟล์ต้องออกนอกโฟลเดอร์ของเว็บไซต์ไม่ได้');

/** โฟลเดอร์ชั่วคราวสำหรับเทสต์ที่ต้องแตะไฟล์จริง */
function fileTestDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/phpcp-file-test-'.getmypid().'-'.$suffix;
    fileTestRemove($dir);
    mkdir($dir, 0o700, true);

    return $dir;
}

/**
 * @param string $path
 * @return null
 */
function fileTestRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            fileTestRemove($path.'/'.$entry);
        }
    }

    rmdir($path);
}

test('PathGuard ปฏิเสธเส้นทางที่พยายามออกนอกโฟลเดอร์', static function (): void {
    $payloads = [
        '../etc/passwd',
        'public/../../etc/passwd',
        'public/..',
        '..',
        './../x',
        'a/./../../b'
    ];

    foreach ($payloads as $payload) {
        assertRejects(
            ValidationError::class,
            static fn() => PathGuard::clean($payload),
            'ต้องปฏิเสธเส้นทางที่มี .. : '.$payload,
        );
    }
});

test('PathGuard ปฏิเสธ null byte และอักขระควบคุม', static function (): void {
    // null byte ตัดสตริงในฟังก์ชันไฟล์ของ C ทำให้ "a.php\0.txt" กลายเป็น a.php
    $payloads = ["public/a.php\x00.txt", "public/\x00", "public/a\nb", "public/a\rb", "public/a\x1bb"];

    foreach ($payloads as $payload) {
        assertRejects(
            ValidationError::class,
            static fn() => PathGuard::clean($payload),
            'ต้องปฏิเสธอักขระควบคุมในเส้นทาง',
        );
    }
});

test('PathGuard ปฏิเสธข้อความที่ไม่ใช่ UTF-8 และชื่อยาวเกินไป', static function (): void {
    assertRejects(
        ValidationError::class,
        static fn() => PathGuard::clean("public/\xff\xfe.txt"),
        'ต้องปฏิเสธชื่อที่ไม่ใช่ UTF-8',
    );

    assertRejects(
        ValidationError::class,
        static fn() => PathGuard::clean(str_repeat('a', 300)),
        'ต้องปฏิเสธชื่อไฟล์ที่ยาวเกินขีดจำกัดของระบบไฟล์',
    );

    assertRejects(
        ValidationError::class,
        static fn() => PathGuard::clean(implode('/', array_fill(0, 40, 'a'))),
        'ต้องปฏิเสธโครงสร้างโฟลเดอร์ที่ลึกเกินไป',
    );
});

test('PathGuard ตัด / นำหน้าออกเพื่อไม่ให้เส้นทางกลายเป็น absolute', static function (): void {
    assertSame('etc/passwd', PathGuard::clean('/etc/passwd'), 'เส้นทางต้องเป็นแบบ relative เสมอ');
    assertSame('', PathGuard::clean('/'), 'เส้นทางรากต้องกลายเป็นค่าว่าง');
    assertSame('', PathGuard::clean(''), 'ค่าว่างคือรากของเว็บไซต์');
    assertSame('public/index.php', PathGuard::clean('public/index.php'), 'เส้นทางปกติต้องผ่าน');
});

test('PathGuard::name ยอมรับเฉพาะชื่อชั้นเดียว', static function (): void {
    assertSame('index.php', PathGuard::name('index.php'), 'ชื่อไฟล์ปกติต้องผ่าน');

    foreach (['a/b', '../b', '/etc', "a\x00b", '.', '..'] as $payload) {
        assertRejects(
            ValidationError::class,
            static fn() => PathGuard::name($payload),
            'ชื่อไฟล์ต้องมีชั้นเดียวเท่านั้น: '.$payload,
        );
    }
});

test('PathGuard::resolve จับ symlink ที่ชี้ออกนอกโฟลเดอร์ของเว็บไซต์', static function (): void {
    $base = fileTestDir('resolve');
    $root = $base.'/site';
    $outside = $base.'/outside';

    mkdir($root, 0o700, true);
    mkdir($outside, 0o700, true);
    file_put_contents($outside.'/secret.txt', 'ห้ามอ่าน');
    file_put_contents($root.'/ok.txt', 'อ่านได้');

    // symlink ผ่าน PathGuard::clean ได้เพราะชื่อดูปกติ — ต้องถูกจับตอน realpath
    symlink($outside, $root.'/escape');
    symlink($outside.'/secret.txt', $root.'/leak.txt');

    $executor = new RealExecutor();

    try {
        assertSame(
            $root.'/ok.txt',
            PathGuard::resolve($executor, $root, PathGuard::join($root, 'ok.txt')),
            'ไฟล์ที่อยู่ในโฟลเดอร์ของเว็บไซต์ต้องเข้าถึงได้',
        );

        foreach (['escape/secret.txt', 'leak.txt', 'escape'] as $payload) {
            assertRejects(
                ValidationError::class,
                static fn() => PathGuard::resolve($executor, $root, PathGuard::join($root, $payload)),
                'ต้องปฏิเสธ symlink ที่ชี้ออกนอกโฟลเดอร์: '.$payload,
            );
        }
    } finally {
        fileTestRemove($base);
    }
});

test('PathGuard ไม่หลุดไปยังโฟลเดอร์ข้างเคียงที่ขึ้นต้นเหมือนกัน', static function (): void {
    // /srv/sites/example.com กับ /srv/sites/example.com-evil ขึ้นต้นเหมือนกัน
    // การเทียบด้วย str_starts_with ที่ไม่มี / ปิดท้ายจะปล่อยให้โฟลเดอร์หลังผ่าน
    $base = fileTestDir('sibling');
    $root = $base.'/example.com';

    mkdir($root, 0o700, true);
    mkdir($base.'/example.com-evil', 0o700, true);
    file_put_contents($base.'/example.com-evil/x.txt', 'x');
    symlink($base.'/example.com-evil', $root.'/near');

    try {
        assertRejects(
            ValidationError::class,
            static fn() => PathGuard::resolve(new RealExecutor(), $root, PathGuard::join($root, 'near/x.txt')),
            'ชื่อโฟลเดอร์ที่ขึ้นต้นเหมือนกันต้องไม่ถือว่าอยู่ข้างใน',
        );
    } finally {
        fileTestRemove($base);
    }
});

test('unzip ข้าม entry แบบ Zip Slip แทนที่จะเขียนทับไฟล์นอกปลายทาง', static function (): void {
    if (!class_exists(ZipArchive::class)) {
        return;
    }

    $base = fileTestDir('zipslip');
    $destination = $base.'/dest';
    $archive = $base.'/evil.zip';

    mkdir($destination, 0o700, true);
    file_put_contents($base.'/victim.txt', 'ของเดิม');

    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('../victim.txt', 'ถูกเขียนทับแล้ว');
    $zip->addFromString('../../victim.txt', 'ถูกเขียนทับแล้ว');
    $zip->addFromString('/etc/phpcp-zipslip', 'ถูกเขียนทับแล้ว');
    $zip->addFromString('safe/note.txt', 'ปลอดภัย');
    $zip->close();

    try {
        $result = (new RealExecutor())->unzip($archive, $destination);

        assertSame('ของเดิม', file_get_contents($base.'/victim.txt'), 'ไฟล์นอกปลายทางต้องไม่ถูกแตะ');
        assertTrue(!file_exists('/etc/phpcp-zipslip'), 'entry แบบ absolute ต้องไม่ถูกเขียนลงระบบ');
        assertSame('ปลอดภัย', file_get_contents($destination.'/safe/note.txt'), 'entry ปกติต้องถูกแตกตามปกติ');
        assertSame(3, $result['skipped'], 'ต้องรายงานจำนวน entry ที่ถูกข้าม');
    } finally {
        fileTestRemove($base);
        @unlink('/etc/phpcp-zipslip');
    }
});

test('chmod อนุญาตเฉพาะสิทธิ์ในรายการที่ปลอดภัย', static function (): void {
    $capability = new FileChmod();

    foreach (['0777', '0666', '4755', '2755', '1777', '0007', '777777', 'abc', ''] as $mode) {
        assertRejects(
            ValidationError::class,
            static fn() => $capability->validate(['root' => 'sites', 'path' => 'public/index.php', 'mode' => $mode]),
            'ต้องปฏิเสธสิทธิ์ที่กว้างเกินไปหรือมี special bit: '.$mode,
        );
    }

    $valid = $capability->validate(['root' => 'sites', 'path' => 'public/index.php', 'mode' => '0640']);
    assertSame(0o640, $valid['mode'], 'สิทธิ์ที่อยู่ในรายการต้องผ่านและถูกแปลงเป็นเลขฐานแปด');
    assertSame(false, $valid['recursive'], 'ต้องไม่ทำงานแบบ recursive เว้นแต่ผู้ใช้สั่ง');
});

test('delete ปฏิเสธรายการที่ว่างและเส้นทางที่เป็นรากของขอบเขต', static function (): void {
    $capability = (new \Phpcp\Agent\CapabilityRegistry())->resolve('file.delete');

    assertRejects(
        \Phpcp\Agent\ValidationError::class,
        static fn() => $capability->validate(['root' => 'sites', 'items' => []]),
        'ต้องเลือกอย่างน้อยหนึ่งรายการก่อนจึงจะลบได้',
    );

    assertRejects(
        \Phpcp\Agent\ValidationError::class,
        static fn() => $capability->validate(['root' => 'sites', 'items' => ['']]),
        'ลบรากของขอบเขตไม่ได้',
    );

    // โฟลเดอร์โครงสร้างของเว็บไซต์ (public/logs/tmp/backups) ถูกกันใน run()
    // ตามชนิดของขอบเขต ไม่ใช่ใน validate() อีกต่อไป เพราะขอบเขตระดับเซิร์ฟเวอร์
    // ไม่มีโครงสร้างแบบนั้นและไม่ควรถูกจำกัดด้วยชื่อเดียวกัน
    $valid = $capability->validate(['root' => 'sites', 'items' => ['public/old.txt']]);
    assertSame(['public/old.txt'], $valid['items'], 'เส้นทางปกติต้องผ่าน');
});

test('FileCatalog เปิดให้แก้ไขเฉพาะไฟล์ข้อความที่รู้จักและไม่ใหญ่เกินไป', static function (): void {
    $editable = ['index.php', 'app.js', 'style.css', 'data.json', '.env', '.htaccess', 'Dockerfile', 'README.md'];

    foreach ($editable as $name) {
        assertTrue(FileCatalog::isEditable($name, 1024), 'ต้องแก้ไขได้: '.$name);
    }

    // ไฟล์ไบนารีที่เปิดให้แก้ไขจะเสียหายทันทีที่บันทึก และไฟล์ใหญ่ทำให้หน่วยความจำหมด
    foreach (['photo.jpg', 'archive.zip', 'video.mp4', 'binary.so', 'font.woff2'] as $name) {
        assertTrue(!FileCatalog::isEditable($name, 1024), 'ต้องแก้ไขไม่ได้: '.$name);
    }

    assertTrue(
        !FileCatalog::isEditable('huge.php', FileCatalog::MAX_EDIT_BYTES + 1),
        'ไฟล์ที่ใหญ่เกินขีดจำกัดต้องแก้ไขไม่ได้',
    );
});

test('ไฟล์ที่ดาวน์โหลดต้องไม่ถูกเบราว์เซอร์เรนเดอร์เป็นหน้าเว็บ', static function (): void {
    // ถ้าส่ง text/html ไฟล์ .html ของผู้ใช้จะรันในโดเมนของแผงควบคุม = stored XSS
    assertSame(
        'application/octet-stream',
        FileCatalog::downloadType(),
        'ต้องส่งเป็น octet-stream เสมอไม่ว่านามสกุลจะเป็นอะไร',
    );
});

test('search ปฏิเสธคำค้นที่สั้นเกินไปและอักขระควบคุม', static function (): void {
    // คำค้นสั้น ๆ บนขอบเขต `server` (ราก /) แปลว่าไล่ทั้งดิสก์เพื่อให้ผลลัพธ์ที่
    // แทบไม่มีประโยชน์ · อักขระควบคุมไม่มีทางตรงกับชื่อไฟล์ที่ระบบยอมให้สร้าง
    // (PathGuard ห้ามไว้) มีแต่จะไปโผล่ใน log แล้วปลอมตัวเป็นบรรทัดอื่น
    $capability = (new \Phpcp\Agent\CapabilityRegistry())->resolve('file.search');

    foreach (['', ' ', 'a', "ab\ncd", "ab\0cd", str_repeat('a', 200)] as $query) {
        assertRejects(
            ValidationError::class,
            static fn() => $capability->validate(['root' => 'sites', 'path' => '', 'q' => $query]),
            'ต้องปฏิเสธคำค้น: ' . var_export($query, true),
        );
    }

    $valid = $capability->validate(['root' => 'sites', 'path' => 'public', 'q' => '  index  ']);

    assertSame('index', $valid['q'], 'ต้องตัดช่องว่างหัวท้ายของคำค้นทิ้ง');
    assertSame('public', $valid['path'], 'เส้นทางต้องผ่าน PathGuard ตามปกติ');
});

test('tree จำกัดความลึกที่ขอได้ และ info ต้องระบุเป้าหมายเสมอ', static function (): void {
    $registry = new \Phpcp\Agent\CapabilityRegistry();

    // ความลึกที่ขอเกินเพดานต้องถูกปฏิเสธ ไม่ใช่ถูกลดค่าให้เงียบ ๆ — การไล่ต้นไม้
    // ลึก ๆ บนเครื่องจริง (node_modules, /proc) ค้างเป็นนาทีและส่งข้อมูลเกิน MAX_FRAME
    $tree = $registry->resolve('file.tree');

    assertRejects(
        ValidationError::class,
        static fn() => $tree->validate(['root' => 'sites', 'path' => '', 'depth' => 9]),
        'ความลึกเกินเพดานต้องถูกปฏิเสธ',
    );

    assertSame(1, $tree->validate(['root' => 'sites', 'path' => ''])['depth'], 'ไม่ระบุความลึก = หนึ่งชั้น');

    // `file.info` ที่ไม่ระบุเส้นทางแปลว่าถามถึงรากของขอบเขต ซึ่งไม่ใช่สิ่งที่กล่อง
    // คุณสมบัติเคยต้องการ — ปฏิเสธชัด ๆ ดีกว่าตอบข้อมูลของสิ่งที่ผู้ใช้ไม่ได้เลือก
    $info = $registry->resolve('file.info');

    assertRejects(
        ValidationError::class,
        static fn() => $info->validate(['root' => 'sites', 'path' => '']),
        'ต้องระบุไฟล์หรือโฟลเดอร์ที่จะดูข้อมูล',
    );

    assertSame(
        'public/index.php',
        $info->validate(['root' => 'sites', 'path' => 'public/index.php'])['path'],
        'เส้นทางปกติต้องผ่าน',
    );
});

test('capability ชุดอ่านของตัวจัดการไฟล์ใช้สิทธิ์ file.view ไม่ใช่ file.manage', static function (): void {
    // สิทธิ์ผิดข้างหมายถึงลูกค้าที่เปิดดูไฟล์ได้อย่างเดียวจะเปิดหน้าไม่ได้เลย
    // (ถ้าเข้มไป) หรือคนที่ควรดูได้อย่างเดียวกลับสั่งงานที่เปลี่ยนไฟล์ได้ (ถ้าหลวมไป)
    $registry = new \Phpcp\Agent\CapabilityRegistry();

    foreach (['file.tree', 'file.search', 'file.info', 'file.list', 'file.read'] as $name) {
        $capability = $registry->resolve($name);

        assertSame('file.view', $capability->permission(), "{$name} ต้องใช้สิทธิ์อ่าน");
        assertSame(false, $capability->isMutating(), "{$name} ต้องไม่ใช่คำสั่งที่เปลี่ยนแปลงระบบ");
    }
});
