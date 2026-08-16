<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The panel's own scheduled jobs — `GET /api/v2/scheduled-jobs` (PLAN-V2 phase A1, item 7)
 *
 * Phase A left this for phase C since there was no screen to show it on yet —
 * until now it could only be seen via `phpcp status` and `phpcp-scheduler
 * --list`, both of which require shell access first
 *
 * **Why it needs to be on the web page:** a dead scheduler makes everything
 * look normal while SSH/firewall's automatic rollbacks quietly stop happening
 * — an admin with no shell access needs a way to see its pulse too, not find
 * out only after locking themselves out of the machine
 *
 * Read-only: there's no plan to enable/disable jobs here, since that would be
 * weakening the system's own protection through the web page — exactly what
 * `SelfProtection` already guards against at the service layer
 */
final class ScheduledJobsController extends ApiController
{
    /** A timer counts as "stuck" once it hasn't run for longer than this — the same value `phpcp doctor` uses */
    private const STALE_AFTER = 300;

    public function index(Request $request): Response
    {
        $repository = new ScheduledJobRepository($this->app->db());
        $lastRunAt = $repository->lastRunAt();

        $jobs = array_map(
            static function (array $row): array {
                $status = (string) ($row['last_status'] ?? '');

                return [
                    'name' => (string) $row['name'],
                    'capability' => (string) $row['capability'],
                    'schedule' => (string) $row['schedule'],
                    'enabled' => (bool) $row['enabled'],
                    'last_run_at' => $row['last_run_at'] === null ? null : (int) $row['last_run_at'],
                    'last_status' => $status,
                    // A ready-composed label — never the raw `last_status` as
                    // the translation key directly, since a job that hasn't
                    // run yet has `last_status` as an empty string, and the
                    // template's `{LNG_${...}}` would become the meaningless
                    // "{LNG_}" — mapped to the same capitalized words used
                    // elsewhere on the dashboard (`OK`, `Error`) so the same
                    // th.json entry serves both screens
                    'status_label' => match ($status) {
                        '' => 'Never',
                        'ok' => 'OK',
                        'error' => 'Error',
                        'skipped' => 'Skipped',
                        default => $status,
                    },
                    // The pill's color comes from the server, so the template can write `pill-${status_tone}` directly
                    'status_tone' => match ($status) {
                        'ok' => 'ok',
                        'error' => 'danger',
                        default => 'muted',
                    },
                    'last_error' => (string) ($row['last_error'] ?? ''),
                ];
            },
            $repository->all(),
        );

        return $this->ok($jobs, [
            'last_run_at' => $lastRunAt,
            // Let the screen decide from the same threshold `phpcp doctor`
            // uses instead of computing its own — otherwise the two could
            // disagree about whether the scheduler is still alive
            'stale' => $lastRunAt === null || (time() - $lastRunAt) > self::STALE_AFTER,
            'stale_after' => self::STALE_AFTER,
        ]);
    }
}
