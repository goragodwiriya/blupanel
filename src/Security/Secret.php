<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * เข้ารหัสข้อมูลอ่อนไหวที่ต้องถอดกลับได้ — ปัจจุบันใช้กับ TOTP secret เท่านั้น
 *
 * ใช้ XSalsa20-Poly1305 ผ่าน libsodium ที่มากับ PHP อยู่แล้ว
 * คีย์อยู่ใน /etc/phpcp/config.php (0640) ซึ่งแยกจากไฟล์ฐานข้อมูล
 * ผู้ที่ได้ไฟล์ panel.db ไปอย่างเดียวจึงถอด TOTP secret ไม่ได้
 */
final class Secret
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('คีย์ต้องมีขนาด 32 ไบต์');
        }
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('ข้อมูลที่เข้ารหัสไว้เสียหาย');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('ถอดรหัสไม่สำเร็จ — คีย์ไม่ตรงหรือข้อมูลถูกแก้ไข');
        }

        return $plain;
    }
}
