<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Security\Permissions;

/**
 * The one giving the order — sent by tier 1 with every request
 *
 * The agent never trusts a permission sent to it; it always recomputes permission
 * from the role itself, to keep what has to be trusted from the web side to the
 * bare minimum.
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
            throw new ValidationError('Invalid user role');
        }

        $userId = (int) ($data['user_id'] ?? 0);

        /*
         * **User id 0 means "the system itself", not "unspecified"**
         *
         * Many capabilities use `userId === 0` as the marker that the actor is the
         * system, and skip every ownership check as a result · that value comes
         * from {@see self::system()}, which is always SUPERADMIN · accepting "id 0
         * + a customer role" from a payload would therefore let a hand-built actor
         * walk through every ownership gate while holding no admin permission at all.
         *
         * A negative value has no meaning anywhere in this system — better to
         * reject it here than let it surface as an SQL condition that matches
         * nothing and gets read as "not found".
         */
        if ($userId < 0 || ($userId === 0 && $role !== Permissions::SUPERADMIN)) {
            throw new ValidationError('Invalid actor user id');
        }

        return new self(
            userId: $userId,
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
