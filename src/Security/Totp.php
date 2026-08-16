<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * TOTP per RFC 6238 — works with Google Authenticator, Authy, and 1Password alike
 *
 * Enforced for the Administrator and Server admin roles (SECURITY §2.2) ·
 * written by hand since it's under 100 lines of code, and avoids needing an external dependency
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Accepts a code from 1 period before or after, to tolerate clock drift */
    private const WINDOW = 1;

    /** Generates a new secret (160 bits, as RFC 4226 recommends), returned as base32 */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function verify(string $base32Secret, string $code): bool
    {
        return self::verifyAt($base32Secret, $code) !== null;
    }

    /**
     * Verify a code and return the **period number** it matched — null = no match
     *
     * ## Why return a number, not just true/false
     *
     * The ±1 period window (which tolerates clock drift) means a single code
     * stays valid for around 90 seconds · without remembering which code was
     * already used, the same code could be reused for that entire window —
     * which defeats the reason 2FA exists at all · 2FA exists to cover the
     * case where **the password has already leaked** — an attacker who
     * catches the six-digit code just once (shoulder-surfing, malware,
     * phishing) must not get almost another minute and a half to reuse that same code after the real owner already used it
     *
     * The caller must store the returned value and pass it back as
     * `$notBefore` next time ({@see \Phpcp\Domain\UserRepository::recordTotpCounter()})
     *
     * @param int $notBefore the period number already used · only a code newer than this is accepted
     */
    public static function verifyAt(string $base32Secret, string $code, int $notBefore = 0): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $candidate = $counter + $offset;

            // A code from a period already used (or older) must never pass, even if it's still within the window
            if ($candidate <= $notBefore) {
                continue;
            }

            if (hash_equals(self::codeAt($base32Secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function currentCode(string $base32Secret): string
    {
        return self::codeAt($base32Secret, intdiv(time(), self::PERIOD));
    }

    public static function secondsRemaining(): int
    {
        return self::PERIOD - (time() % self::PERIOD);
    }

    private static function codeAt(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            return '';
        }

        $binary = pack('J', $counter);              // 64-bit big-endian
        $hash = hash_hmac('sha1', $binary, $key, true);

        // dynamic truncation per RFC 4226 §5.4
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The URI used to generate a QR code for the user to scan */
    public static function provisioningUri(string $base32Secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Recovery codes for when the authenticator app is lost — each one is single-use
     *
     * @return list<string>
     */
    public static function recoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf('%05d-%05d', random_int(0, 99999), random_int(0, 99999));
        }

        return $codes;
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
