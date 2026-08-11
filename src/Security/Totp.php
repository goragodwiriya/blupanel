<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * TOTP ตาม RFC 6238 — ใช้กับ Google Authenticator, Authy, 1Password ได้ทั้งหมด
 *
 * บังคับใช้กับ role ผู้ดูแลระบบและผู้ดูแลเซิร์ฟเวอร์ (SECURITY §2.2)
 * เขียนเองเพราะเป็นโค้ดไม่ถึง 100 บรรทัดและช่วยให้ไม่ต้องมี dependency ภายนอก
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** ยอมรับรหัสของช่วงเวลาก่อนหน้าและถัดไป 1 ช่วง กันนาฬิกาคลาดเคลื่อน */
    private const WINDOW = 1;

    /** สร้าง secret ใหม่ (160 บิตตามที่ RFC 4226 แนะนำ) คืนเป็น base32 */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function verify(string $base32Secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::codeAt($base32Secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
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

        $binary = pack('J', $counter);              // 64 บิต big-endian
        $hash = hash_hmac('sha1', $binary, $key, true);

        // dynamic truncation ตาม RFC 4226 §5.4
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** URI สำหรับสร้าง QR code ให้ผู้ใช้สแกน */
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
     * รหัสสำรองสำหรับกรณีทำ authenticator หาย ใช้ได้ครั้งเดียวต่อรหัส
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
