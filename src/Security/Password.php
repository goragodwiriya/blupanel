<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * The panel's user passwords — Argon2id per SECURITY §2.2
 *
 * The parameters are chosen so one hash takes around 100-200 milliseconds on
 * a typical server — slow enough to make offline guessing expensive, but
 * still fast enough for the panel's login page, which has only a handful of users
 */
final class Password
{
    private const OPTIONS = [
        'memory_cost' => 65536,   // 64 MB
        'time_cost' => 4,
        'threads' => 2,
    ];

    /** The most common passwords — guards the worst case without needing a wordlist file */
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

    /** true = should be re-hashed with the current parameters (done when the user successfully logs in) */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /**
     * Check strength, returning a list of problems — empty = passes
     *
     * @return list<string>
     */
    public static function problems(string $plain, int $minLength = 12, string $username = ''): array
    {
        $problems = [];
        $lower = mb_strtolower($plain);

        if (mb_strlen($plain) < $minLength) {
            $problems[] = "The password must be at least {$minLength} characters long";
        }

        if (mb_strlen($plain) > 4096) {
            // Guards against a DoS from sending an extremely long password for Argon2id to compute
            $problems[] = 'The password is too long';
        }

        if (in_array($lower, self::COMMON, true)) {
            $problems[] = 'This password is used too often — please choose a different one';
        }

        if (preg_match('/^\d+$/', $plain) === 1) {
            $problems[] = 'The password must not be entirely numbers';
        }

        if (preg_match('/^(.)\1+$/u', $plain) === 1) {
            $problems[] = 'The password must not be a single character repeated';
        }

        if ($username !== '' && str_contains($lower, mb_strtolower($username))) {
            $problems[] = 'The password must not contain the username';
        }

        if (preg_match('/[a-zA-Z]/', $plain) !== 1 || preg_match('/\d/', $plain) !== 1) {
            $problems[] = 'The password must contain both letters and numbers';
        }

        return $problems;
    }

    /** A random password that's hard to read aloud but easy to type, used during initial setup */
    public static function random(int $length = 20): string
    {
        // Drops 0/O/l/1/I because the admin has to type it back exactly as shown on screen during setup
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        // Guarantees both a letter and a number are always present, so it never fails the rule problems() enforces
        if (preg_match('/\d/', $out) !== 1) {
            $out[random_int(0, $length - 1)] = (string) random_int(2, 9);
        }

        return $out;
    }
}
