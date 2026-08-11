#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * เครื่องมือของผู้เผยแพร่ — สร้างคู่กุญแจและเซ็นแพ็กเกจ release
 *
 *   php tools/sign-release.php keygen
 *   php tools/sign-release.php sign <ไฟล์.tar.gz> <เวอร์ชัน> <กุญแจส่วนตัว base64>
 *
 * ไฟล์นี้ไม่ได้ถูกติดตั้งไปกับ panel และไม่ควรอยู่บนเซิร์ฟเวอร์ปลายทางเลย —
 * กุญแจส่วนตัวต้องอยู่บนเครื่องของผู้เผยแพร่เท่านั้น ถ้าหลุดไปอยู่บนเซิร์ฟเวอร์
 * ที่ถูกเจาะได้ ผู้โจมตีจะเซ็นแพ็กเกจของตัวเองแล้วส่งให้ทุกเครื่องที่เหลือติดตั้ง
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ต้องมีส่วนขยาย sodium\n");
    exit(1);
}

$command = $argv[1] ?? 'help';

if ($command === 'keygen') {
    $pair = sodium_crypto_sign_keypair();

    echo "กุญแจส่วนตัว (เก็บเป็นความลับ ห้ามขึ้น git ห้ามวางบนเซิร์ฟเวอร์):\n";
    echo '  ' . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n\n";
    echo "กุญแจสาธารณะ (ใส่ใน Updater::PUBLIC_KEY แล้ว build ใหม่):\n";
    echo '  ' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";

    exit(0);
}

if ($command === 'sign') {
    $file = $argv[2] ?? '';
    $version = $argv[3] ?? '';
    $secret = $argv[4] ?? (getenv('PHPCP_SIGNING_KEY') ?: '');

    if ($file === '' || $version === '' || $secret === '') {
        fwrite(STDERR, "ใช้: sign <ไฟล์> <เวอร์ชัน> <กุญแจส่วนตัว base64>\n");
        fwrite(STDERR, "หรือกำหนดกุญแจผ่านตัวแปรสภาพแวดล้อม PHPCP_SIGNING_KEY\n");
        exit(1);
    }

    if (!is_file($file)) {
        fwrite(STDERR, "ไม่พบไฟล์: {$file}\n");
        exit(1);
    }

    $key = base64_decode($secret, true);

    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "กุญแจส่วนตัวผิดรูปแบบ\n");
        exit(1);
    }

    $contents = (string) file_get_contents($file);
    $signature = base64_encode(sodium_crypto_sign_detached($contents, $key));

    echo json_encode([
        'version' => $version,
        'url' => 'https://example.invalid/releases/' . basename($file),
        'signature' => $signature,
        'sha256' => hash('sha256', $contents),
        'size' => strlen($contents),
        'notes' => '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

fwrite(STDERR, "คำสั่ง: keygen | sign\n");
exit(1);
