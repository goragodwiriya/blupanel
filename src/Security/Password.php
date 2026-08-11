<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * รหัสผ่านของผู้ใช้ panel — Argon2id ตาม SECURITY §2.2
 *
 * ค่าพารามิเตอร์เลือกให้แฮชหนึ่งครั้งใช้เวลาราว 100-200 มิลลิวินาทีบนเซิร์ฟเวอร์ทั่วไป
 * ช้าพอที่จะทำให้การเดารหัสแบบออฟไลน์แพงมาก แต่ยังเร็วพอสำหรับหน้าล็อกอินของ panel
 * ที่มีผู้ใช้ไม่กี่คน
 */
final class Password
{
    private const OPTIONS = [
        'memory_cost' => 65536,   // 64 MB
        'time_cost' => 4,
        'threads' => 2,
    ];

    /** รหัสผ่านที่พบบ่อยที่สุด กันเคสที่แย่ที่สุดโดยไม่ต้องมีไฟล์ wordlist */
    private const COMMON = [
        'password', 'password1', 'password123', '123456', '12345678', '123456789',
        '1234567890', 'qwerty', 'qwertyuiop', 'abc123', 'letmein', 'welcome',
        'admin', 'admin123', 'administrator', 'root', 'toor', 'passw0rd',
        'p@ssw0rd', 'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess',
        'football', 'baseball', 'master', 'shadow', 'superman', 'trustno1',
        'changeme', 'default', 'server', 'linux', 'ubuntu', 'mysql',
    ];

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** true = ควรแฮชใหม่ด้วยพารามิเตอร์ปัจจุบัน (ทำตอนผู้ใช้ล็อกอินสำเร็จ) */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /**
     * ตรวจความแข็งแรง คืนรายการปัญหาเป็นข้อความไทย ว่าง = ผ่าน
     *
     * @return list<string>
     */
    public static function problems(string $plain, int $minLength = 12, string $username = ''): array
    {
        $problems = [];
        $lower = mb_strtolower($plain);

        if (mb_strlen($plain) < $minLength) {
            $problems[] = "รหัสผ่านต้องยาวอย่างน้อย {$minLength} ตัวอักษร";
        }

        if (mb_strlen($plain) > 4096) {
            // กัน DoS จากการส่งรหัสผ่านยาวมากให้ Argon2id คำนวณ
            $problems[] = 'รหัสผ่านยาวเกินกำหนด';
        }

        if (in_array($lower, self::COMMON, true)) {
            $problems[] = 'รหัสผ่านนี้ถูกใช้บ่อยเกินไป กรุณาเลือกรหัสผ่านอื่น';
        }

        if (preg_match('/^\d+$/', $plain) === 1) {
            $problems[] = 'รหัสผ่านต้องไม่ใช่ตัวเลขล้วน';
        }

        if (preg_match('/^(.)\1+$/u', $plain) === 1) {
            $problems[] = 'รหัสผ่านต้องไม่ใช่อักขระเดียวซ้ำกัน';
        }

        if ($username !== '' && str_contains($lower, mb_strtolower($username))) {
            $problems[] = 'รหัสผ่านต้องไม่มีชื่อผู้ใช้อยู่ในนั้น';
        }

        if (preg_match('/[a-zA-Z]/', $plain) !== 1 || preg_match('/\d/', $plain) !== 1) {
            $problems[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
        }

        return $problems;
    }

    /** สุ่มรหัสผ่านที่อ่านออกเสียงยากแต่พิมพ์ได้ ใช้ตอนติดตั้งครั้งแรก */
    public static function random(int $length = 20): string
    {
        // ตัด 0/O/l/1/I ออกเพราะผู้ดูแลต้องพิมพ์ตามที่เห็นบนหน้าจอตอนติดตั้ง
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        // การันตีว่ามีทั้งตัวอักษรและตัวเลขเสมอ ไม่ให้หลุดกฎที่ problems() บังคับ
        if (preg_match('/\d/', $out) !== 1) {
            $out[random_int(0, $length - 1)] = (string) random_int(2, 9);
        }

        return $out;
    }
}
