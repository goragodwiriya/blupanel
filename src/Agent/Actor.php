<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Security\Permissions;

/**
 * ผู้สั่งงาน — ส่งมาจากชั้นที่ 1 พร้อมทุก request
 *
 * agent ไม่เชื่อ permission ที่ส่งมา แต่คำนวณใหม่จาก role เองเสมอ
 * เพื่อลดสิ่งที่ต้องเชื่อจากฝั่งเว็บให้เหลือน้อยที่สุด
 */
final readonly class Actor
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $role,
        public string $ip = '',
        public string $requestId = '',
    ) {
    }

    public static function system(string $reason = 'system'): self
    {
        return new self(0, $reason, Permissions::SUPERADMIN, 'local', '');
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $role = (string) ($data['role'] ?? '');
        if (!Permissions::isValidRole($role)) {
            throw new ValidationError('บทบาทผู้ใช้ไม่ถูกต้อง');
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            username: substr((string) ($data['username'] ?? ''), 0, 64),
            role: $role,
            ip: substr((string) ($data['ip'] ?? ''), 0, 45),
            requestId: substr((string) ($data['request_id'] ?? ''), 0, 32),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'role' => $this->role,
            'ip' => $this->ip,
            'request_id' => $this->requestId,
        ];
    }

    public function can(string $permission): bool
    {
        return Permissions::roleHas($this->role, $permission);
    }
}
