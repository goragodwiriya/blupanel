<?php

declare(strict_types=1);

namespace Phpcp\Security;

use Phpcp\Agent\Actor;
use Phpcp\Kernel\Db;

/**
 * A hash-chained audit log — SECURITY §2.8
 *
 * Each row is bound to the previous row's hash, so deleting or editing a
 * row after the fact breaks the chain, and `phpcp doctor` catches it — no
 * role has a capability that can delete an audit entry
 *
 * Also written to a file in parallel, so a trace still exists even if the
 * SQLite file is destroyed (that file is set append-only with chattr +a during setup)
 */
final class AuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly Db $db,
        private readonly ?string $mirrorFile = null,
    ) {
    }

    /**
     * Record an event, returning the id of the row written
     *
     * @param array<string,mixed> $detail
     */
    public function write(
        Actor $actor,
        string $action,
        string $target,
        string $result,
        array $detail = [],
    ): int {
        $ts = time();
        $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return $this->db->transaction(function (Db $db) use ($actor, $action, $target, $result, $detail, $ts, $detailJson): int {
            $prev = (string) $db->value(
                'SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1',
                [],
                self::GENESIS,
            );

            $hash = self::chainHash($prev, $ts, $actor, $action, $target, $result, $detailJson);

            $id = $db->insert('audit_log', [
                'ts' => $ts,
                'actor_user_id' => $actor->userId > 0 ? $actor->userId : null,
                'actor_name' => $actor->username,
                'actor_ip' => $actor->ip,
                'request_id' => $actor->requestId,
                'action' => $action,
                'target' => $target,
                'result' => $result,
                'detail_json' => $detailJson,
                'prev_hash' => $prev,
                'hash' => $hash,
            ]);

            $this->mirror($ts, $actor, $action, $target, $result, $detail, $hash);

            return $id;
        });
    }

    private static function chainHash(
        string $prev,
        int $ts,
        Actor $actor,
        string $action,
        string $target,
        string $result,
        string $detailJson,
    ): string {
        return hash('sha256', implode('|', [
            $prev,
            (string) $ts,
            (string) $actor->userId,
            $actor->username,
            $actor->ip,
            $actor->requestId,
            $action,
            $target,
            $result,
            $detailJson,
        ]));
    }

    /** @param array<string,mixed> $detail */
    private function mirror(
        int $ts,
        Actor $actor,
        string $action,
        string $target,
        string $result,
        array $detail,
        string $hash,
    ): void {
        if ($this->mirrorFile === null) {
            return;
        }

        $line = json_encode([
            'ts' => date('c', $ts),
            'actor' => $actor->username,
            'user_id' => $actor->userId,
            'ip' => $actor->ip,
            'request_id' => $actor->requestId,
            'action' => $action,
            'target' => $target,
            'result' => $result,
            'detail' => $detail,
            'hash' => $hash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            // FILE_APPEND + LOCK_EX still works even when the file is set append-only
            @file_put_contents($this->mirrorFile, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Verify the chain's continuity across the whole table
     *
     * @return array{ok:bool,count:int,broken_at:int|null}
     */
    public function verifyChain(): array
    {
        $prev = self::GENESIS;
        $count = 0;

        foreach ($this->db->all('SELECT * FROM audit_log ORDER BY id ASC') as $row) {
            $count++;

            if ((string) $row['prev_hash'] !== $prev) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) $row['id']];
            }

            $actor = new Actor(
                userId: (int) ($row['actor_user_id'] ?? 0),
                username: (string) $row['actor_name'],
                role: Permissions::SUPERADMIN, // the role isn't part of the hash, so it has no effect on verification
                ip: (string) $row['actor_ip'],
                requestId: (string) $row['request_id'],
            );

            $expected = self::chainHash(
                $prev,
                (int) $row['ts'],
                $actor,
                (string) $row['action'],
                (string) $row['target'],
                (string) $row['result'],
                (string) $row['detail_json'],
            );

            if (!hash_equals($expected, (string) $row['hash'])) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) $row['id']];
            }

            $prev = (string) $row['hash'];
        }

        return ['ok' => true, 'count' => $count, 'broken_at' => null];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 20, ?int $userId = null): array
    {
        $limit = max(1, min($limit, 500));

        if ($userId !== null) {
            return $this->db->all(
                'SELECT * FROM audit_log WHERE actor_user_id = :uid ORDER BY id DESC LIMIT ' . $limit,
                ['uid' => $userId],
            );
        }

        return $this->db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);
    }
}
