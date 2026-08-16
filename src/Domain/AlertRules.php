<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * Decide what's abnormal and when it should be notified — PLAN-V2 Phase E6
 *
 * **This class's real job is "when to stay quiet," not "what's abnormal"** —
 * checking whether disk usage passed 85% is easy; what makes an alerting system
 * actually usable is not resending the same message every minute until people turn
 * notifications off entirely (full reasoning in `db/migrations/0015_alert_state.sql`).
 *
 * Sending rules:
 *   - First time entering an abnormal state → send
 *   - Level gets worse (warning → critical) → send immediately, don't wait for the cycle
 *   - Level stays the same or improves but is still abnormal → stay quiet until the repeat cycle
 *   - Back to normal → send "recovered" once, then forget the state
 */
final class AlertRules
{
    /** Repeat every 6 hours while the problem persists — frequent enough not to be forgotten, spaced enough not to annoy */
    public const REPEAT_AFTER = 21600;

    /**
     * Thresholds for resources measured as a percentage → [warning, critical]
     *
     * These numbers come from real hosting-machine behavior: 85% disk is the point
     * where it's time to start looking for something to delete, since the next
     * backup run might not have room · 95% is the point where MariaDB starts failing
     * to write and the whole machine's sites go down · memory uses a higher threshold
     * because Linux normally uses free memory as cache anyway.
     *
     * @var array<string,array{0:float,1:float,2:string}>
     */
    public const THRESHOLDS = [
        'disk' => [85.0, 95.0, 'Disk space'],
        'memory' => [90.0, 97.0, 'Memory'],
    ];

    /** Load average per core — 1.0 = every core exactly saturated · past 2x means a real queue is backing up */
    public const LOAD_WARNING = 1.5;
    public const LOAD_CRITICAL = 3.0;

    /** A certificate with fewer days left than this must warn — certbot renews at 30 days */
    public const CERT_WARNING_DAYS = 20;
    public const CERT_CRITICAL_DAYS = 7;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Decide whether this threshold should send a notification, and record state itself
     *
     * @param string $key the threshold's key, e.g. `disk` or `service:nginx`
     * @param string|null $level `warning`/`critical` · null = back to normal
     * @return array{notify:bool,reason:string} `reason` is used to build the message and for later debugging
     */
    public function evaluate(string $key, ?string $level, float $value = 0, ?int $now = null): array
    {
        $now ??= time();
        $previous = $this->db->first('SELECT * FROM alert_state WHERE alert_key = :k', ['k' => $key]);

        // Back to normal — notify once that it recovered, only if it was previously reported abnormal
        if ($level === null) {
            if ($previous === null) {
                return ['notify' => false, 'reason' => 'already normal'];
            }

            $this->db->run('DELETE FROM alert_state WHERE alert_key = :k', ['k' => $key]);

            return ['notify' => true, 'reason' => 'recovered'];
        }

        // First time abnormal
        if ($previous === null) {
            $this->remember($key, $level, $value, $now, $now);

            return ['notify' => true, 'reason' => 'new'];
        }

        // Got worse than before — send immediately without waiting for the cycle, since the situation changed for the worse
        if ($level === 'critical' && $previous['level'] === 'warning') {
            $this->remember($key, $level, $value, (int) $previous['first_at'], $now);

            return ['notify' => true, 'reason' => 'escalated'];
        }

        // Still abnormal, unchanged — stay quiet until the repeat cycle is up
        if ($now - (int) $previous['notified_at'] >= self::REPEAT_AFTER) {
            $this->remember($key, $level, $value, (int) $previous['first_at'], $now);

            return ['notify' => true, 'reason' => 'reminder'];
        }

        // Update the latest value but don't send — the next cycle's message will report the correct current value
        $this->db->update(
            'alert_state',
            ['value' => $value, 'level' => $level, 'updated_at' => $now],
            ['alert_key' => $key],
        );

        return ['notify' => false, 'reason' => 'already sent and the repeat cycle is not up yet'];
    }

    /** The level for a percentage value against its configured thresholds — null = normal */
    public static function levelForPercent(string $type, float $percent): ?string
    {
        if (!isset(self::THRESHOLDS[$type])) {
            return null;
        }

        [$warning, $critical] = self::THRESHOLDS[$type];

        return match (true) {
            $percent >= $critical => 'critical',
            $percent >= $warning => 'warning',
            default => null,
        };
    }

    /**
     * The level for a load average against the core count
     *
     * Always compared per core, never the raw value — a load of 4.0 on an 8-core
     * machine is comfortable, but on a single-core machine it's a queue long enough
     * that sites are already responding slowly.
     */
    public static function levelForLoad(float $load1, int $cores): ?string
    {
        $perCore = $load1 / max(1, $cores);

        return match (true) {
            $perCore >= self::LOAD_CRITICAL => 'critical',
            $perCore >= self::LOAD_WARNING => 'warning',
            default => null,
        };
    }

    /** The level for a certificate nearing expiry — null = still plenty of time left */
    public static function levelForCertDays(int $daysLeft): ?string
    {
        return match (true) {
            $daysLeft <= self::CERT_CRITICAL_DAYS => 'critical',
            $daysLeft <= self::CERT_WARNING_DAYS => 'warning',
            default => null,
        };
    }

    /**
     * Every state still outstanding — the screen uses this to show what's currently abnormal
     *
     * **Sorted with a CASE, not `ORDER BY level DESC`** — alphabetical order would put
     * `warning` before `critical` (w > c), exactly backwards from what's wanted · an
     * admin opening the page while a machine has several problems at once should see
     * something merely worth watching sitting above something that's taking the
     * entire machine's sites down · within the same level, the oldest comes first,
     * since it's the issue that's been left unaddressed the longest.
     */
    public function active(): array
    {
        return $this->db->all(
            "SELECT * FROM alert_state
             ORDER BY CASE level WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, first_at",
        );
    }

    /**
     * Delete the state of any threshold not checked this round — prevents a stale
     * entry that would otherwise never go away on its own
     *
     * **An entry in this table only disappears once that key is evaluated and found
     * to be back to normal** · a key that stops being evaluated at all therefore
     * stays stuck forever — this actually happened with `service:<name>` for a
     * service that had stopped and was later removed from the machine entirely:
     * `AlertCheck` simply skips a service that's `not_installed`, so nothing ever
     * tells the system it recovered.
     *
     * The result was a screen permanently showing a problem the admin can never
     * clear no matter what they click, and eventually stops trusting this part of
     * the system entirely — which is more dangerous than not having it at all,
     * because the day there's a real problem, it gets ignored too.
     *
     * @param list<string> $keys every key that was checked this round
     */
    public function forgetOthers(array $keys): int
    {
        if ($keys === []) {
            // Nothing was checked at all = a bug in the checker itself, not a signal
            // that everything recovered. Clearing the whole table here would paper
            // over a real, still-outstanding problem.
            return 0;
        }

        $stale = array_column(
            array_filter(
                $this->db->all('SELECT alert_key FROM alert_state'),
                static fn (array $row): bool => !in_array((string) $row['alert_key'], $keys, true),
            ),
            'alert_key',
        );

        foreach ($stale as $key) {
            $this->db->run('DELETE FROM alert_state WHERE alert_key = :k', ['k' => $key]);
        }

        return count($stale);
    }

    private function remember(string $key, string $level, float $value, int $firstAt, int $now): void
    {
        $this->db->run(
            'INSERT INTO alert_state (alert_key, level, value, first_at, notified_at, updated_at)
             VALUES (:k, :l, :v, :f, :n, :n)
             ON CONFLICT(alert_key) DO UPDATE SET
                level = :l, value = :v, first_at = :f, notified_at = :n, updated_at = :n',
            ['k' => $key, 'l' => $level, 'v' => $value, 'f' => $firstAt, 'n' => $now],
        );
    }
}
