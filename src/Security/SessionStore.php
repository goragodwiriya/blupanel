<?php

declare(strict_types=1);

namespace Phpcp\Security;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;

/**
 * Session ของ panel — เก็บเองใน SQLite ไม่ใช้ session ของ PHP
 *
 * เหตุผลที่ไม่ใช้ $_SESSION: ต้องการควบคุมการหมุน id, การผูกกับ IP/User-Agent,
 * การบังคับ idle timeout และการสั่งตัด session ของผู้ใช้คนหนึ่งจากหน้าจัดการผู้ใช้
 * ซึ่งทำกับไฟล์ session ของ PHP ได้ลำบากและไม่น่าเชื่อถือ
 *
 * เก็บเฉพาะ hash ของ session id — ผู้ที่ได้ไฟล์ panel.db ไปสวมรอย session ไม่ได้
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
     * ชื่อคุกกี้ — ใช้ prefix __Host- เมื่อเป็น HTTPS
     * prefix นี้บังคับให้เบราว์เซอร์ยอมรับเฉพาะคุกกี้ที่ Secure, Path=/ และไม่มี Domain
     * ทำให้ subdomain อื่นเขียนคุกกี้ทับไม่ได้ (session fixation)
     */
    /**
     * ช่วงผ่อนผันหลังหมุน id (วินาที) — id เดิมยังใช้ได้ในช่วงนี้
     *
     * 30 วินาทีพอสำหรับคำขอที่ค้างอยู่ระหว่างทางของหน้าหนึ่ง (SPA ยิงพร้อมกัน 2–4 ก้อน
     * และแต่ละก้อนใช้เวลาไม่ถึงวินาทีบน LAN) แต่สั้นพอที่จะไม่ยืดอายุคุกกี้ที่รั่วไป
     * อย่างมีนัยสำคัญ ซึ่งเป็นเหตุผลทั้งหมดที่ต้องหมุน id ตั้งแต่แรก
     */
    private const ROTATE_GRACE = 30;

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
     * สร้าง session ใหม่ คืน id ดิบสำหรับใส่คุกกี้ (เก็บลง DB เฉพาะ hash)
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
     * โหลด session พร้อมข้อมูลผู้ใช้ คืน null ถ้าไม่ผ่านเงื่อนไขข้อใดข้อหนึ่ง
     *
     * @return array<string,mixed>|null
     */
    public function load(string $rawId, string $ip, string $uaHash): ?array
    {
        if ($rawId === '' || strlen($rawId) !== self::ID_BYTES * 2) {
            return null;
        }

        $row = $this->db->first(
            'SELECT s.*, u.username, u.display_name, u.role, u.status AS user_status,
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
         * ผูก session กับ IP — ขโมยคุกกี้ไปใช้จากที่อื่นแล้วใช้ไม่ได้
         *
         * **ปฏิเสธคำขอเฉย ๆ ไม่ทำลาย session** (เปลี่ยนเมื่อ 2026-08-11) — เดิมตัดทิ้ง
         * ทันทีที่ไม่ตรง ซึ่งมีปัญหาสองข้อ: ผู้ใช้ที่ถูกต้องซึ่งเน็ตสลับจาก WiFi ไป 4G
         * ถูกล็อกเอาต์ถาวรทั้งที่ไม่มีใครทำอะไรผิด · และใครที่ได้คุกกี้ไปยิงจาก IP อื่น
         * เพียงครั้งเดียวก็**เตะเจ้าของออกจากระบบ**ได้ ซึ่งกลายเป็นเครื่องมือก่อกวน
         *
         * ปฏิเสธอย่างเดียวให้ผลกันขโมยเท่าเดิม (คุกกี้ที่ถูกขโมยใช้ไม่ได้อยู่ดี)
         * โดยไม่ทำร้ายเจ้าของ session
         */
        if (!hash_equals((string) $row['ip'], $ip)) {
            return null;
        }

        /*
         * **ไม่ผูกกับ User-Agent แล้ว** (ตัดออกเมื่อ 2026-08-11)
         *
         * เหตุผลที่ตัด: ได้ประโยชน์น้อยกว่าที่เสีย · คุกกี้เป็น `__Host-` + HttpOnly +
         * SameSite=Strict และ CSP ไม่มี `unsafe-eval` การขโมยจึงต้องถึงขั้นมีมัลแวร์
         * บนเครื่องนั้น ซึ่งกรณีนั้นผู้โจมตีได้ UA เดียวกันไปด้วยอยู่แล้ว
         *
         * ส่วนที่เสียคือของจริงและเกิดบ่อย: Chrome อัปเดตเวอร์ชันทีเดียวทุกคนถูก
         * ล็อกเอาต์พร้อมกัน · ผู้ดูแลที่ย่อหน้าจอทดสอบ responsive ใน DevTools ถูกเด้ง
         * ออกกลางคัน (โหมดจำลองอุปกรณ์ปลอม UA ให้ด้วย) · ปุ่ม "ขอเว็บไซต์เดสก์ท็อป"
         * บนมือถือก็เปลี่ยน UA เช่นกัน
         *
         * ค่าที่เก็บไว้ยังมีอยู่และถูกอัปเดตเมื่อเปลี่ยน — `SessionMiddleware` บันทึกลง
         * audit log ให้ผู้ดูแลตรวจย้อนหลังได้ว่าคุกกี้ก้อนนี้ถูกใช้จากเบราว์เซอร์อื่นเมื่อไร
         */
        return $row;
    }

    /**
     * อัปเดต hash ของ User-Agent ที่ผูกกับ session นี้
     *
     * เรียกเมื่อค่าที่ส่งมาต่างจากที่เก็บไว้ เพื่อให้บันทึกลง audit เพียงครั้งเดียว
     * ต่อการเปลี่ยนหนึ่งครั้ง ไม่ใช่ทุกคำขอหลังจากนั้น
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
     * หมุน session id ถ้าถึงเวลา คืน id ใหม่ หรือ null ถ้ายังไม่ต้องหมุน
     *
     * การหมุนเป็นระยะทำให้คุกกี้ที่รั่วไปมีอายุใช้งานสั้นลง
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
     * หมุน session id — คืน id ใหม่ หรือ **null ถ้าคำขออื่นหมุนไปแล้ว**
     *
     * **บั๊กจริงที่เคยเกิด (พบ 2026-08-07):** เดิมคืน id ใหม่เสมอโดยไม่ดูว่า UPDATE
     * โดนแถวไหนหรือเปล่า · SPA ยิงหลายคำขอพร้อมกันต่อหนึ่งหน้า ทุกคำขอเห็นว่าถึงเวลาหมุน
     * พร้อมกัน ตัวแรกหมุน X→Y สำเร็จ ที่เหลือ UPDATE ไม่โดนแถวไหน (id เปลี่ยนไปแล้ว)
     * แต่ยังคืน id ที่สุ่มใหม่ของตัวเองออกไปตั้งเป็นคุกกี้ · คำตอบที่มาถึงเบราว์เซอร์
     * ทีหลังชนะ ผลคือเบราว์เซอร์ถือ id ที่**ไม่มีอยู่ในฐานข้อมูล** แล้วถูกเด้งออกทันที
     *
     * อาการที่ผู้ใช้เห็น: คลิกใช้งานไปเรื่อย ๆ แล้วจู่ ๆ เด้งไปหน้าล็อกอินเอง
     * ทุก ๆ ประมาณ 15 นาที (เท่ากับรอบการหมุน) · UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า
     * จึงไม่เคยชนกันเลย
     *
     * ทั้งการอ่านและการเขียนอยู่ใน transaction เดียว (`BEGIN IMMEDIATE`) เพื่อให้
     * "ตรวจว่ายังเป็น id นี้อยู่ไหม แล้วค่อยเปลี่ยน" เป็นขั้นตอนเดียวที่แทรกไม่ได้
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
     * ลบ session
     *
     * ต้องจับ `prev_id_hash` ด้วย ไม่งั้นผู้ใช้ที่กดออกจากระบบด้วยคุกกี้ที่เพิ่งถูกหมุนไป
     * (ยังอยู่ในช่วงผ่อนผัน) จะได้ 204 กลับไปแต่ session ยังอยู่ในฐานข้อมูล —
     * "ออกจากระบบแล้วแต่ไม่ได้ออกจริง" เป็นข้อบกพร่องด้านความปลอดภัย ไม่ใช่แค่ความไม่สะดวก
     */
    public function destroy(string $rawId): void
    {
        $this->db->run(
            'DELETE FROM sessions WHERE id_hash = :h OR prev_id_hash = :h',
            ['h' => self::hashId($rawId)],
        );
    }

    /** ตัด session ทั้งหมดของผู้ใช้คนหนึ่ง — ใช้ตอนเปลี่ยนรหัสผ่านหรือระงับบัญชี */
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
