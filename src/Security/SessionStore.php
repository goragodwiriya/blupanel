<?php

declare(strict_types=1);

namespace Phpcp\Security;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;

/**
 * The panel's sessions — stored by hand in SQLite, not PHP's own session mechanism
 *
 * Why not $_SESSION: this needs control over id rotation, binding to IP/User-
 * Agent, enforcing an idle timeout, and being able to destroy one user's
 * sessions from the user-management page — all of which are awkward and unreliable to do with PHP's own session files
 *
 * Only the session id's hash is stored — whoever gets hold of the panel.db file can't impersonate a session with it
 */
final class SessionStore
{
    private const ID_BYTES = 32;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    /**
     * The grace window after rotating the id, in seconds — the old id still works during this window
     *
     * 30 seconds is enough for a request already in flight from a page (the
     * SPA fires 2–4 requests at once, and each takes under a second on a
     * LAN), but short enough not to meaningfully extend a leaked cookie's
     * lifespan — which is the entire reason for rotating the id in the first place
     */
    private const ROTATE_GRACE = 30;

    /**
     * The reason `load()` rejected the last time — empty = not rejected, or rejected for an ordinary reason
     *
     * Only set for cases that **someone should see** (a cookie used from a
     * different IP) · expiry and idle timeout aren't recorded here, since they
     * happen to every user every day and say nothing meaningful
     *
     * @var array<string,mixed>
     */
    private array $rejection = [];

    /** @return array<string,mixed> */
    public function lastRejection(): array
    {
        return $this->rejection;
    }

    /**
     * The cookie name — uses the __Host- prefix over HTTPS
     * This prefix forces the browser to only accept a cookie that's Secure,
     * Path=/, and has no Domain, which prevents another subdomain from overwriting it (session fixation)
     */
    public function cookieName(): string
    {
        return $this->config->bool('panel.cookie_secure') ? '__Host-phpcp_sid' : 'phpcp_sid';
    }

    public static function hashId(string $rawId): string
    {
        return hash('sha256', $rawId);
    }

    public static function hashUserAgent(string $userAgent): string
    {
        return substr(hash('sha256', $userAgent), 0, 32);
    }

    /**
     * Create a new session, returning the raw id to put in the cookie (only the hash is stored in the DB)
     */
    public function create(int $userId, string $ip, string $uaHash, bool $pending2fa): string
    {
        $rawId = bin2hex(random_bytes(self::ID_BYTES));
        $now = time();

        $this->db->insert('sessions', [
            'id_hash' => self::hashId($rawId),
            'user_id' => $userId,
            'ip' => $ip,
            'ua_hash' => $uaHash,
            'pending_2fa' => $pending2fa ? 1 : 0,
            'created_at' => $now,
            'last_seen_at' => $now,
            'rotated_at' => $now,
            'expires_at' => $now + $this->config->int('panel.session_ttl', 28800),
        ]);

        return $rawId;
    }

    /**
     * Load the session along with the user's data, returning null if any condition fails
     *
     * @return array<string,mixed>|null
     */
    public function load(string $rawId, string $ip, string $uaHash): ?array
    {
        if ($rawId === '' || strlen($rawId) !== self::ID_BYTES * 2) {
            return null;
        }

        $row = $this->db->first(
            'SELECT s.*, u.username, u.role, u.status AS user_status,
                    u.totp_enabled, u.must_change_password
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id_hash = :h
                OR (s.prev_id_hash = :h AND s.prev_until > :now)',
            ['h' => self::hashId($rawId), 'now' => time()],
        );

        if ($row === null) {
            return null;
        }

        $now = time();

        if ((int) $row['expires_at'] <= $now) {
            $this->destroy($rawId);

            return null;
        }

        $idle = $this->config->int('panel.session_idle', 1800);
        if ($idle > 0 && $now - (int) $row['last_seen_at'] > $idle) {
            $this->destroy($rawId);

            return null;
        }

        if ($row['user_status'] !== 'active') {
            $this->destroy($rawId);

            return null;
        }

        /*
         * Bind the session to an IP — a stolen cookie used from elsewhere doesn't work
         *
         * **Just reject the request without destroying the session** (changed
         * 2026-08-11) — it used to destroy the session immediately on a
         * mismatch, which had two problems: a legitimate user whose network
         * switched from WiFi to 4G got permanently logged out even though
         * nobody did anything wrong · and anyone who got hold of the cookie
         * could **kick the real owner out** just by firing one request from a
         * different IP, which turned into a harassment tool
         *
         * Rejecting alone gives the same protection against theft (a stolen
         * cookie still doesn't work) without harming the session's owner
         */
        if (!hash_equals((string) $row['ip'], $ip)) {
            /*
             * **Can't reject silently** — this is the clearest signal the
             * system has that a cookie is being used from somewhere else ·
             * the request used to just get answered as "not signed in," with
             * no trace left behind at all, so an admin had no way to see a
             * cookie theft being attempted even though this check had already caught it
             *
             * Left here for the caller to log, rather than logging it itself,
             * since this class has no (and shouldn't have any) path to the
             * audit log · the caller is {@see \Phpcp\Middleware\SessionMiddleware}
             */
            $this->rejection = [
                'reason' => 'ip_mismatch',
                'user_id' => (int) $row['user_id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected_ip' => (string) $row['ip'],
                'seen_ip' => $ip,
            ];

            return null;
        }

        /*
         * **No longer bound to the User-Agent** (removed 2026-08-11)
         *
         * Why it was removed: cost more than it was worth · the cookie is
         * already `__Host-` + HttpOnly + SameSite=Strict, and the CSP has no
         * `unsafe-eval`, so stealing it would already require malware on that
         * machine — and in that case the attacker gets the same UA along with it anyway
         *
         * What was lost was real and frequent: a single Chrome version update
         * logged everyone out at once · an admin resizing the screen to test
         * responsive layout in DevTools got bounced mid-session (device
         * emulation mode fakes the UA too) · the "request desktop site"
         * button on mobile also changes the UA
         *
         * The stored value is still kept and updated when it changes —
         * `SessionMiddleware` writes it to the audit log so an admin can look
         * back and see when this cookie was used from a different browser
         */
        return $row;
    }

    /**
     * Update the User-Agent hash bound to this session
     *
     * Called only when the incoming value differs from what's stored, so it
     * gets written to the audit log exactly once per change, not on every request afterward
     */
    public function noteUserAgent(string $rawId, string $uaHash): void
    {
        $this->db->run(
            'UPDATE sessions SET ua_hash = :ua WHERE id_hash = :h OR prev_id_hash = :h',
            ['ua' => $uaHash, 'h' => self::hashId($rawId)],
        );
    }

    public function touch(string $rawId): void
    {
        $this->db->run(
            'UPDATE sessions SET last_seen_at = :t WHERE id_hash = :h OR prev_id_hash = :h',
            ['t' => time(), 'h' => self::hashId($rawId)],
        );
    }

    /**
     * Rotate the session id if it's due, returning the new id, or null if it isn't time yet
     *
     * Rotating periodically shortens how long a leaked cookie stays useful
     */
    public function rotateIfDue(string $rawId, int $rotatedAt): ?string
    {
        $interval = $this->config->int('panel.session_rotate', 900);
        if ($interval <= 0 || time() - $rotatedAt < $interval) {
            return null;
        }

        return $this->rotate($rawId);
    }

    /**
     * Rotate the session id — returns the new id, or **null if another request already rotated it**
     *
     * **A real bug that happened here (found 2026-08-07):** it used to always
     * return a new id without checking whether the UPDATE actually hit a row
     * · the SPA fires several requests at once per page, and all of them saw
     * that it was time to rotate at the same moment — the first one rotated
     * X→Y successfully, the rest of the UPDATEs hit no row at all (the id had
     * already changed) but still returned their own freshly-random id to be
     * set as the cookie · whichever response reached the browser last won,
     * leaving the browser holding an id **that didn't exist in the
     * database**, and it got bounced immediately
     *
     * What the user saw: clicking around normally, then suddenly getting
     * dropped back to the login page, roughly every 15 minutes (matching the
     * rotation interval) · the old plain-HTML UI fired only one request per page, so this never collided
     *
     * Both the read and the write sit in a single transaction (`BEGIN
     * IMMEDIATE`) so that "check it's still this id, then change it" is one uninterruptible step
     */
    public function rotate(string $rawId): ?string
    {
        $newId = bin2hex(random_bytes(self::ID_BYTES));
        $now = time();

        $applied = $this->db->transaction(function ($db) use ($rawId, $newId, $now): int {
            return $db->run(
                'UPDATE sessions
                    SET id_hash = :new,
                        prev_id_hash = :old,
                        prev_until = :grace,
                        rotated_at = :t,
                        last_seen_at = :t
                  WHERE id_hash = :old',
                [
                    'new' => self::hashId($newId),
                    'old' => self::hashId($rawId),
                    'grace' => $now + self::ROTATE_GRACE,
                    't' => $now,
                ],
            )->rowCount();
        });

        return $applied === 1 ? $newId : null;
    }

    public function markAuthenticated(string $rawId): void
    {
        $this->db->run(
            'UPDATE sessions SET pending_2fa = 0 WHERE id_hash = :h',
            ['h' => self::hashId($rawId)],
        );
    }

    /**
     * Delete a session
     *
     * Must also catch `prev_id_hash`, otherwise a user who signs out with a
     * cookie that was just rotated (still inside the grace window) gets a
     * 204 back while the session is still in the database — "signed out but
     * not actually signed out" is a security defect, not just an inconvenience
     */
    public function destroy(string $rawId): void
    {
        $this->db->run(
            'DELETE FROM sessions WHERE id_hash = :h OR prev_id_hash = :h',
            ['h' => self::hashId($rawId)],
        );
    }

    /** Destroy every session belonging to one user — used when changing a password or suspending an account */
    public function destroyAllFor(int $userId): int
    {
        return $this->db
            ->run('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId])
            ->rowCount();
    }

    public function prune(): int
    {
        return $this->db
            ->run('DELETE FROM sessions WHERE expires_at < :t', ['t' => time()])
            ->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function activeFor(int $userId): array
    {
        return $this->db->all(
            'SELECT id_hash, ip, created_at, last_seen_at FROM sessions
             WHERE user_id = :u AND expires_at > :t ORDER BY last_seen_at DESC',
            ['u' => $userId, 't' => time()],
        );
    }
}
