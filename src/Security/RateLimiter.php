<?php

declare(strict_types=1);

namespace Phpcp\Security;

use Phpcp\Kernel\Db;

/**
 * Token bucket เก็บใน SQLite — SECURITY §2.1
 *
 * เก็บใน DB ไม่ใช่ในหน่วยความจำ เพราะ PHP-FPM มีหลาย worker
 * ถ้าเก็บในหน่วยความจำ ผู้โจมตีจะได้โควตาคูณจำนวน worker
 *
 * ใช้ token bucket แทนการนับแบบหน้าต่างเวลา เพราะยอมให้กดถี่ได้ช่วงสั้น ๆ
 * (ผู้ใช้จริงที่พิมพ์รหัสผิดสองสามครั้ง) แต่กันการยิงต่อเนื่องได้
 */
final class RateLimiter
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * พยายามใช้ 1 token คืน true ถ้ายังมีโควตาเหลือ
     *
     * @param float $capacity     จำนวนครั้งสูงสุดที่กดรัวได้
     * @param float $refillPerSec เติมกลับกี่ token ต่อวินาที
     */
    public function allow(string $bucket, float $capacity, float $refillPerSec): bool
    {
        $now = time();
        $key = substr(hash('sha256', $bucket), 0, 40);

        return $this->db->transaction(function (Db $db) use ($key, $capacity, $refillPerSec, $now): bool {
            $row = $db->first('SELECT tokens, updated_at FROM rate_limits WHERE bucket = :b', ['b' => $key]);

            if ($row === null) {
                $db->run(
                    'INSERT INTO rate_limits (bucket, tokens, updated_at) VALUES (:b, :t, :u)',
                    ['b' => $key, 't' => $capacity - 1, 'u' => $now],
                );

                return true;
            }

            $elapsed = max(0, $now - (int) $row['updated_at']);
            $tokens = min($capacity, (float) $row['tokens'] + $elapsed * $refillPerSec);

            if ($tokens < 1.0) {
                // อัปเดตเวลาไว้ด้วยเพื่อให้การเติมกลับคำนวณต่อเนื่อง
                $db->run('UPDATE rate_limits SET tokens = :t, updated_at = :u WHERE bucket = :b', [
                    't' => $tokens, 'u' => $now, 'b' => $key,
                ]);

                return false;
            }

            $db->run('UPDATE rate_limits SET tokens = :t, updated_at = :u WHERE bucket = :b', [
                't' => $tokens - 1, 'u' => $now, 'b' => $key,
            ]);

            return true;
        });
    }

    /** เหลืออีกกี่วินาทีถึงจะกดได้อีกครั้ง ใช้บอกผู้ใช้ให้ชัดเจน */
    public function retryAfter(string $bucket, float $refillPerSec): int
    {
        $key = substr(hash('sha256', $bucket), 0, 40);
        $row = $this->db->first('SELECT tokens, updated_at FROM rate_limits WHERE bucket = :b', ['b' => $key]);

        if ($row === null || $refillPerSec <= 0) {
            return 0;
        }

        $elapsed = max(0, time() - (int) $row['updated_at']);
        $tokens = (float) $row['tokens'] + $elapsed * $refillPerSec;

        return $tokens >= 1.0 ? 0 : (int) ceil((1.0 - $tokens) / $refillPerSec);
    }

    public function reset(string $bucket): void
    {
        $this->db->run(
            'DELETE FROM rate_limits WHERE bucket = :b',
            ['b' => substr(hash('sha256', $bucket), 0, 40)],
        );
    }

    /** ลบ bucket ที่เต็มแล้วออก เรียกจากงานทำความสะอาดเป็นระยะ */
    public function prune(int $olderThanSeconds = 86400): int
    {
        return $this->db
            ->run('DELETE FROM rate_limits WHERE updated_at < :t', ['t' => time() - $olderThanSeconds])
            ->rowCount();
    }
}
