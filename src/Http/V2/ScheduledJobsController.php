<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\Scheduler;
use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
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
 * `POST /api/v2/scheduled-jobs/{name}/run` runs one job immediately, ignoring
 * its schedule — the same `Scheduler::runNow()` the CLI has always used, with
 * the same shared lock a scheduled round holds, so a click can never execute a
 * job on top of a round in flight · everything still travels through the
 * agent, is audited under the admin who clicked (as opposed to `scheduler`),
 * and can only make a job happen **sooner** — the one thing still refused here
 * is weakening the system's own protection, i.e. enabling/disabling jobs
 * through the web page, exactly what `SelfProtection` already guards against
 * at the service layer
 */
final class ScheduledJobsController extends ApiController
{
    /** A timer counts as "stuck" once it hasn't run for longer than this — the same value `phpcp doctor` uses */
    private const STALE_AFTER = 300;

    public function index(Request $request): Response
    {
        $repository = new ScheduledJobRepository($this->app->db());
        $lastRunAt = $repository->lastRunAt();
        $canRun = $this->ctx->can('settings.manage');

        $jobs = array_map(
            static function (array $row) use ($canRun): array {
                $status = (string) ($row['last_status'] ?? '');

                return [
                    'name' => (string) $row['name'],
                    'capability' => (string) $row['capability'],
                    'schedule' => (string) $row['schedule'],
                    'enabled' => (bool) $row['enabled'],
                    // Hides the Run-now button on screens the viewer can't use anyway —
                    // the route itself checks the permission again, like every route does
                    'can_run' => $canRun,
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

    /**
     * Run one job right now, ignoring its schedule — the web-page twin of
     * `phpcp-scheduler --run`, through the very same code path
     */
    public function run(Request $request): Response
    {
        $name = $request->param('name');
        $repository = new ScheduledJobRepository($this->app->db());
        $job = $repository->find($name);

        if ($job === null) {
            return $this->problem(
                ApiProblem::NotFound,
                $this->t('No scheduled job named {name}', ['name' => $name]),
            );
        }

        // A disabled job is a deliberate decision made outside the web page —
        // a run button that works on it would be a backdoor around that decision
        if ((int) $job['enabled'] !== 1) {
            return $this->problem(ApiProblem::Conflict, 'This job is disabled, so it cannot be run manually');
        }

        /*
         * The same shared lock a scheduled round holds for its whole run · a
         * manual click while a round (or another manual run) is mid-flight
         * would execute the same capability twice at once — for
         * `webserver.rescan` that means two config transactions interleaving,
         * which is exactly the kind of mess the round lock already prevents
         * between rounds
         */
        $this->app->config->paths->ensureDirectories();
        $lock = Scheduler::acquireRunsLock($this->app->config->paths->run);

        if ($lock === null) {
            return $this->problem(
                ApiProblem::Conflict,
                'A scheduled round or another manual run is still in progress — try again in a moment',
            );
        }

        try {
            $actor = $this->ctx->actor($request);

            $scheduler = new Scheduler(
                $this->app->db(),
                fn (string $capability, array $args): array => $this->agent()->data($capability, $args, $actor),
                $this->app->logger('panel'),
            );

            $result = $scheduler->runNow($name);
        } finally {
            Scheduler::releaseRunsLock($lock);
        }

        if (($result['status'] ?? 'error') === 'error') {
            // 500, not 503 — the request itself succeeded; the job ran and the job failed,
            // and its message is the thing the admin needs to read
            return $this->problem(
                ApiProblem::ExecutionFailed,
                $this->t('{name} failed: {message}', [
                    'name' => $name,
                    'message' => (string) ($result['message'] ?? ''),
                ]),
            );
        }

        return $this->completed(
            $this->t('{name} finished: {status} ({ms} ms)', [
                'name' => $name,
                'status' => (string) ($result['status'] ?? 'ok'),
                'ms' => (int) ($result['duration_ms'] ?? 0),
            ]),
            'scheduledJobs',
            ['result' => $result],
        );
    }
}
