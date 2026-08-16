<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * Encrypts sensitive data that must be decryptable again — currently only used for TOTP secrets
 *
 * Uses XSalsa20-Poly1305 via libsodium, which already ships with PHP · the
 * key lives in /etc/phpcp/config.php (0640), separate from the database
 * file, so whoever gets only the panel.db file can't decrypt a TOTP secret
 */
final class Secret
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('The key must be 32 bytes');
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
            throw new \RuntimeException('The encrypted data is corrupted');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed — the key does not match, or the data was tampered with');
        }

        return $plain;
    }
}
