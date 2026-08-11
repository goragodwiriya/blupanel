<?php

declare (strict_types = 1);

/**
 * เขียนบล็อก sites ลงในไฟล์ config ของ panel — ใช้โดย tools/migrate-host.sh
 *
 *   php tools/write-sites-config.php /etc/phpcp/config.php /mnt/Server/htdocs true /mnt/Server/htdocs
 *
 * ทำไมต้องแยกเป็นไฟล์ PHP แทนที่จะ sed เอาใน bash
 *
 * ไฟล์ config คือ PHP ที่ถูก require ด้วยสิทธิ์ของ panel การแก้ด้วย regex ใน bash
 * แล้วพลาดไปตัดวงเล็บผิดตัว จะได้ไฟล์ที่ parse ไม่ผ่าน — panel ตายทั้งตัวและ
 * ตัวติดตั้งไม่มีทางรู้จนกว่าจะเปิดหน้าเว็บ ที่นี่จึงเขียนลงไฟล์ชั่วคราวก่อน
 * แล้ว require ทดสอบจริง ถ้า parse ไม่ผ่าน PHP จะตายตรงนั้นโดยที่ไฟล์เดิมยังอยู่ครบ
 *
 * รันซ้ำได้ — ครั้งต่อไปจะแทนที่บล็อกเดิมระหว่างบรรทัดคั่น ไม่เพิ่มซ้อน
 */

const BEGIN = '    // >>> phpcp migrate-host — แก้ค่าได้ แต่อย่าลบสองบรรทัดคั่นนี้ >>>';
const END = '    // <<< phpcp migrate-host <<<';

/** @param list<string> $argv */
function fail(string $message): never
{
    fwrite(STDERR, '  ✘ '.$message.PHP_EOL);
    exit(1);
}

/**
 * @param string $value
 * @return mixed
 */
function cleanPath(string $value): string
{
    // ตรวจอักขระก่อน trim เพราะ trim() ตัด \0 กับ \n ท้ายสตริงทิ้งให้เอง
    // ค่าที่แนบ null byte มาท้ายเส้นทางจึงรอดไปได้ถ้าตรวจทีหลัง
    if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $value) === 1) {
        fail('เส้นทางมีอักขระที่ใช้ในไฟล์ตั้งค่าไม่ได้: '.addcslashes($value, "\0..\37"));
    }

    $path = rtrim(trim($value), '/');

    if ($path === '' || !str_starts_with($path, '/')) {
        fail('ต้องเป็นเส้นทางสัมบูรณ์: '.$value);
    }

    if (in_array('..', explode('/', $path), true)) {
        fail('เส้นทางต้องไม่มี .. : '.$value);
    }

    return $path;
}

/**
 * หาขอบเขตของบล็อก 'sites' => [ ... ], ที่อยู่ในระดับบนสุดของไฟล์ config
 *
 * ใช้ tokenizer ไม่ใช่ regex เพราะบล็อกนี้มีคอมเมนต์อธิบายยาว ๆ ที่มีวงเล็บ
 * ปนอยู่ข้างใน การนับ [ ] ด้วยตัวอักษรดิบ ๆ จะจบบล็อกผิดที่แล้วได้ไฟล์ที่ parse ไม่ผ่าน
 *
 * @return array{0:int,1:int}|null [ตำแหน่งเริ่ม, ตำแหน่งจบ] เป็น byte offset
 */
function locateSitesBlock(string $code): ?array
{
    /** @var list<array{0:int|null,1:string,2:int}> $tokens */
    $tokens = [];
    $offset = 0;

    foreach (token_get_all($code) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $tokens[] = [is_array($token) ? $token[0] : null, $text, $offset];
        $offset += strlen($text);
    }

    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $count = count($tokens);
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] === null) {
            if ($tokens[$i][1] === '[') {
                $depth++;
                continue;
            }

            if ($tokens[$i][1] === ']') {
                $depth--;
                continue;
            }
        }

        // ระดับ 1 = คีย์ของ array ที่ไฟล์ return ออกมา ไม่ใช่คีย์ชื่อเดียวกันที่ซ้อนอยู่ข้างใน
        if ($depth !== 1
            || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING
            || trim($tokens[$i][1], "'\"") !== 'sites') {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && in_array($tokens[$j][0], $skip, true)) {
            $j++;
        }

        if ($j >= $count || $tokens[$j][0] !== T_DOUBLE_ARROW) {
            continue;
        }

        $j++;
        while ($j < $count && in_array($tokens[$j][0], $skip, true)) {
            $j++;
        }

        if ($j >= $count || $tokens[$j][0] !== null || $tokens[$j][1] !== '[') {
            continue;
        }

        $nested = 0;

        for ($k = $j; $k < $count; $k++) {
            if ($tokens[$k][0] !== null) {
                continue;
            }

            if ($tokens[$k][1] === '[') {
                $nested++;
            } elseif ($tokens[$k][1] === ']' && --$nested === 0) {
                $start = $tokens[$i][2];
                $end = $tokens[$k][2] + 1;

                // ดึงย้อนไปถึงต้นบรรทัด เพื่อไม่ให้ย่อหน้าเดิมค้างอยู่หน้าบล็อกใหม่
                while ($start > 0 && ($code[$start - 1] === ' ' || $code[$start - 1] === "\t")) {
                    $start--;
                }

                // กลืนจุลภาคท้ายบล็อกไปด้วย เพราะบล็อกใหม่มีของตัวเองแล้ว
                $after = $end;
                while ($after < strlen($code) && ($code[$after] === ' ' || $code[$after] === "\t")) {
                    $after++;
                }

                if ($after < strlen($code) && $code[$after] === ',') {
                    $end = $after + 1;
                }

                return [$start, $end];
            }
        }
    }

    return null;
}

$file = $argv[1] ?? fail('ต้องระบุไฟล์ config');
$sitesDir = cleanPath($argv[2] ?? fail('ต้องระบุที่เก็บไฟล์เว็บไซต์'));
$sharedOwner = ($argv[3] ?? 'false') === 'true';
$pointerRoots = [];

foreach (explode(',', $argv[4] ?? '') as $root) {
    if (trim($root) !== '') {
        $pointerRoots[] = cleanPath($root);
    }
}

$pointerRoots = array_values(array_unique($pointerRoots));

// ห้ามชี้ไปยังรากของระบบ — DocumentRoot ที่ครอบ /etc หรือ /root เท่ากับเปิดทั้งเครื่องออกเว็บ
foreach ($pointerRoots as $root) {
    foreach (['/', '/etc', '/root', '/home', '/var', '/usr', '/boot', '/proc', '/sys', '/dev'] as $forbidden) {
        if ($root === $forbidden) {
            fail('sites.pointer_roots ต้องไม่มี '.$forbidden.' — เว็บจะเสิร์ฟไฟล์ระบบได้');
        }
    }
}

is_file($file) || fail('ไม่พบไฟล์ '.$file);
$original = file_get_contents($file);
$original === false && fail('อ่าน '.$file.' ไม่ได้');

$lines = [
    BEGIN,
    "    'sites' => [",
    "        'dir' => '".$sitesDir."',",
    '        // true = ไม่แยกเจ้าของไฟล์ระหว่างเว็บ ใช้ได้เฉพาะ filesystem ที่เก็บ uid/gid ไม่ได้',
    '        // agent จะทดสอบ chown จริงก่อนใช้ ถ้า filesystem รองรับ ownership มันจะปฏิเสธทันที',
    "        'shared_owner' => ".($sharedOwner ? 'true' : 'false').',',
    '        // โฟลเดอร์แม่ที่ยอมให้ Domain Pointer ชี้เข้าไปได้ ว่าง = ชี้ได้เฉพาะใน dir',
    "        'pointer_roots' => [".implode('', array_map(
        static fn(string $root): string => "'".$root."', ",
        $pointerRoots,
    )).'],',
    '    ],',
    END
];

$block = implode("\n", $lines);

$beginPos = strpos($original, BEGIN);
$endPos = strpos($original, END);

if ($beginPos !== false && $endPos !== false && $endPos > $beginPos) {
    $updated = substr($original, 0, $beginPos).$block.substr($original, $endPos + strlen(END));
} elseif (($bounds = locateSitesBlock($original)) !== null) {
    // บล็อกที่มากับ config.example.php — แทนที่ทั้งก้อนแล้วคราวหน้าจะเจอบรรทัดคั่นแทน
    $updated = substr($original, 0, $bounds[0]).$block.substr($original, $bounds[1]);
} else {
    if (preg_match("/'sites'\s*=>/", $original) === 1) {
        fail('ไฟล์นี้มีคีย์ sites ในตำแหน่งที่สคริปต์อ่านไม่ออก — กรุณาแก้ '.$file.' เอง');
    }

    $close = strrpos($original, '];');
    $close === false && fail('รูปแบบไฟล์ config ไม่ตรงกับที่คาดไว้ — กรุณาแก้ '.$file.' เอง');

    $updated = substr($original, 0, $close).$block."\n".substr($original, $close);
}

$tmp = $file.'.migrate-'.getmypid();
file_put_contents($tmp, $updated, LOCK_EX) === false && fail('เขียนไฟล์ชั่วคราวไม่ได้');
chmod($tmp, 0640);

// ถ้า parse ไม่ผ่าน PHP จะ fatal ตรงบรรทัดนี้ ไฟล์จริงยังไม่ถูกแตะเลย
/** @var mixed $parsed */
$parsed = require $tmp;

if (!is_array($parsed) || !isset($parsed['sites']['dir'])) {
    unlink($tmp);
    fail('ไฟล์ที่ได้ไม่มีคีย์ sites.dir — ยกเลิกการเขียน');
}

if ($parsed['sites']['dir'] !== $sitesDir || $parsed['sites']['shared_owner'] !== $sharedOwner) {
    unlink($tmp);
    fail('ค่าที่อ่านกลับมาไม่ตรงกับที่ตั้งไว้ — ยกเลิกการเขียน');
}

rename($tmp, $file) || fail('เขียนทับ '.$file.' ไม่สำเร็จ');

fwrite(STDOUT, sprintf(
    "    sites.dir = %s\n    sites.shared_owner = %s\n    sites.pointer_roots = %s\n",
    $sitesDir,
    $sharedOwner ? 'true' : 'false',
    $pointerRoots === [] ? '(ว่าง)' : implode(', ', $pointerRoots),
));
