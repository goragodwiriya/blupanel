<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use Phpcp\Agent\Actor;
use Phpcp\Security\Csrf;
use Phpcp\Security\Permissions;

/**
 * สถานะของคำขอหนึ่งครั้งที่ middleware ส่งต่อกันไปเรื่อย ๆ
 *
 * เป็น object ที่แก้ค่าได้ (ต่างจาก Request ที่ readonly) เพราะ middleware
 * ต้องเติมข้อมูลให้กันตามลำดับ — Session เติม user, Csrf เติม token, ฯลฯ
 */
final class Ctx
{
    /** @var array<string,mixed>|null แถวจากตาราง sessions พร้อมข้อมูลผู้ใช้ */
    public ?array $session = null;

    /** id ดิบของ session (ไม่ได้เก็บลง DB เก็บแต่ hash) */
    public string $sessionId = '';

    public ?Route $route = null;

    public string $nonce = '';

    public string $csrfToken = '';


    public function __construct(public readonly App $app)
    {
        $this->nonce = base64_encode(random_bytes(16));
    }

    public function isAuthenticated(): bool
    {
        return $this->session !== null && (int) ($this->session['pending_2fa'] ?? 0) === 0;
    }

    public function awaiting2fa(): bool
    {
        return $this->session !== null && (int) ($this->session['pending_2fa'] ?? 0) === 1;
    }

    public function userId(): int
    {
        return (int) ($this->session['user_id'] ?? 0);
    }

    public function username(): string
    {
        return (string) ($this->session['username'] ?? '');
    }

    public function displayName(): string
    {
        $name = (string) ($this->session['username'] ?? '');

        return $name !== '' ? $name : $this->username();
    }

    public function role(): string
    {
        return (string) ($this->session['role'] ?? '');
    }

    public function mustChangePassword(): bool
    {
        return (int) ($this->session['must_change_password'] ?? 0) === 1;
    }

    public function can(string $permission): bool
    {
        return $this->session !== null && Permissions::roleHas($this->role(), $permission);
    }

    /**
     * ออก CSRF token ใหม่ให้ตรงกับ session id ปัจจุบัน
     *
     * ต้องเรียกทุกครั้งที่ controller เปลี่ยน `sessionId` เอง (ล็อกอิน, ยืนยัน 2FA,
     * เปลี่ยนรหัสผ่าน) — token ที่ middleware ออกไว้ตอนต้นคำขอผูกกับ session **เก่า**
     * ถ้าไม่ออกใหม่ คำตอบจะพา token ที่ใช้ไม่ได้กลับไปให้ SPA แล้วคำขอถัดไปโดน 419 ทันที
     */
    public function refreshCsrfToken(): void
    {
        $this->csrfToken = (new Csrf($this->app->config->secretKey()))->token(
            $this->sessionId !== '' ? $this->sessionId : 'guest',
            Csrf::SCOPE,
        );
    }

    /** actor ที่จะส่งให้ agent — ระบบจะคำนวณ permission ใหม่จาก role ฝั่งนั้นอีกที */
    public function actor(Request $request): Actor
    {
        return new Actor(
            userId: $this->userId(),
            username: $this->username(),
            role: $this->role(),
            ip: $request->ip,
            requestId: $request->requestId,
        );
    }
}
