<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\AlertRules;
use Phpcp\Domain\Notifier;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Alert thresholds still outstanding — `GET /api/v2/alerts` (PLAN-V2 phase E6)
 *
 * **Why this screen needs to exist when notifications already exist:** a good
 * notification system must stay quiet while the problem is unchanged (see
 * {@see AlertRules}) — so an admin who just opened their computer has no way
 * to know whether disk is still full if the last message went out yesterday
 * · this page is "the current state," notifications are "what just changed" — different questions entirely
 *
 * Read-only — clearing the state by hand would produce a duplicate "first
 * seen" message even though the same problem is still there · the state only
 * ever clears itself once the value genuinely returns to normal
 */
final class AlertsController extends ApiController
{
    public function index(Request $request): Response
    {
        $now = time();

        $alerts = array_map(
            function (array $row) use ($now): array {
                $level = (string) $row['level'];

                return [
                    'alert_key' => (string) $row['alert_key'],
                    'label' => $this->label((string) $row['alert_key']),
                    'level' => $level,
                    'value' => (float) $row['value'],
                    'first_at' => (int) $row['first_at'],
                    'notified_at' => (int) $row['notified_at'],
                    // How long it's been outstanding — the number that tells whether this has been left unattended
                    'duration' => $now - (int) $row['first_at'],
                    'level_tone' => $level === 'critical' ? 'danger' : 'warn',
                ];
            },
            (new AlertRules($this->app->db()))->active(),
        );

        return $this->ok($alerts, [
            'total' => count($alerts),
            'critical' => count(array_filter($alerts, static fn (array $a): bool => $a['level'] === 'critical')),
            // If no channel is active at all, this page being empty doesn't
            // mean the machine is fine — it means nobody received the
            // message instead — the screen must tell these two cases apart
            'channels' => (new Notifier($this->app->db()))->activeChannels(),
            'repeat_after' => AlertRules::REPEAT_AFTER,
        ]);
    }

    /**
     * A human-readable name from the threshold's key
     *
     * The key is deliberately shaped `kind:target` so it can be decoded back
     * without storing text in the table — text stored at row-creation time
     * would go stale the moment the wording changed
     */
    private function label(string $key): string
    {
        if (str_starts_with($key, 'service:')) {
            return $this->t('Service {name}', ['name' => substr($key, 8)]);
        }

        if (str_starts_with($key, 'cert:')) {
            return $this->t('Certificate for {domain}', ['domain' => substr($key, 5)]);
        }

        return $this->t(match ($key) {
            'disk' => 'Disk space',
            'memory' => 'Memory',
            'load' => 'Load average',
            default => $key,
        });
    }
}
