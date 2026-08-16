<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;
use Phpcp\Kernel\Logger;

/**
 * Decides which jobs are due, and dispatches them through the agent — PLAN-V2 Phase A1
 *
 * Kept separate from bin/phpcp-scheduler so it can be tested without a real agent:
 * the dispatcher is injected as a closure, so a test can pass in a fake one that
 * records what it was called with.
 *
 * The principle that must never break: nothing here holds any special privilege at
 * all — everything that touches the real system goes through Agent\Client, exactly
 * the same as the web tier does. The scheduler is therefore only ever "the one who
 * presses the button on time," never a shortcut that bypasses the security layer
 * to issue commands itself.
 */
final class Scheduler
{
    /** Can look back at most 1 day — a machine that was down overnight shouldn't run a daily job multiple times in a row once it's back */
    public const CATCH_UP_SECONDS = 86400;

    /** @param \Closure(string,array<string,mixed>):array<string,mixed> $dispatch */
    public function __construct(
        private readonly Db $db,
        private readonly \Closure $dispatch,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Run every job that's due, in a single pass
     *
     * One job failing must never stop the rest from running — this pass might be
     * the only one that happens this minute.
     *
     * @return list<array{name:string,status:string,message:string,duration_ms:int}>
     */
    public function runDue(?int $now = null): array
    {
        $now ??= time();
        $jobs = new ScheduledJobRepository($this->db);
        $results = [];

        foreach ($jobs->enabled() as $job) {
            $schedule = (string) $job['schedule'];
            $name = (string) $job['name'];
            $lastRunAt = $job['last_run_at'] === null ? null : (int) $job['last_run_at'];

            try {
                $due = CronSchedule::isDue($schedule, $now, $lastRunAt, self::CATCH_UP_SECONDS);
            } catch (\Throwable $e) {
                // A malformed schedule must be loud enough to notice, not silently skipped every minute forever
                $jobs->recordRun((int) $job['id'], 'error', 'Invalid schedule: ' . $e->getMessage(), $now);
                $results[] = $this->result($name, 'error', $e->getMessage(), 0);
                $this->log('error', "Job {$name} has an invalid schedule: " . $e->getMessage());

                continue;
            }

            if (!$due) {
                continue;
            }

            $results[] = $this->run($jobs, $job, $now);
        }

        return $results;
    }

    /**
     * Force one job to run immediately, ignoring its schedule — used from the command line for testing
     *
     * @return array{name:string,status:string,message:string,duration_ms:int}
     */
    public function runNow(string $name): array
    {
        $jobs = new ScheduledJobRepository($this->db);
        $job = $jobs->find($name);

        if ($job === null) {
            return $this->result($name, 'error', "No job found named {$name}", 0);
        }

        return $this->run($jobs, $job, time(), force: true);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{name:string,status:string,message:string,duration_ms:int}
     */
    private function run(ScheduledJobRepository $jobs, array $job, int $now, bool $force = false): array
    {
        $id = (int) $job['id'];
        $name = (string) $job['name'];
        $capability = (string) $job['capability'];
        $args = json_decode((string) $job['args_json'], true);
        $args = is_array($args) ? $args : [];

        if (!$force && !$this->hasWork($capability)) {
            // Deliberately skipped, but last_run_at is still recorded — "checked and found nothing to do" still counts as one pass
            $jobs->recordRun($id, 'skipped', '', $now);

            return $this->result($name, 'skipped', 'No work pending', 0);
        }

        $startedAt = hrtime(true);

        try {
            $data = ($this->dispatch)($capability, $args);
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            $message = is_string($data['message'] ?? null) ? $data['message'] : 'Success';

            $jobs->recordRun($id, 'ok', '', $now);
            $this->log('info', sprintf('Job %s succeeded in %d ms — %s', $name, $durationMs, $message));

            return $this->result($name, 'ok', $message, $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $jobs->recordRun($id, 'error', $e->getMessage(), $now);
            $this->log('error', sprintf('Job %s failed: %s', $name, $e->getMessage()));

            return $this->result($name, 'error', $e->getMessage(), $durationMs);
        }
    }

    /**
     * Is there actually anything to do — asked before dispatching a job that runs very often
     *
     * Why this exists: every command that changes the system gets two audit rows
     * per call · `rollback.run`, running every minute, would add roughly 2,880
     * audit rows a day, nearly all of them "checked and found nothing" — an audit
     * log flooded with meaningless rows makes it impossible to find what actually
     * happened later, which defeats the entire reason it exists.
     *
     * The condition used to decide this must come from the exact same source of
     * truth the capability itself uses (the same query as
     * `RollbackGuard::expired()`), and if it can't be checked, the answer must
     * always default to "there is work" — getting this wrong in one direction just
     * wastes an audit row, but getting it wrong the other way means rollback stops
     * working.
     */
    private function hasWork(string $capability): bool
    {
        if ($capability !== 'rollback.run') {
            return true;
        }

        try {
            return (int) $this->db->value(
                'SELECT count(*) FROM pending_rollbacks WHERE expires_at <= :now',
                ['now' => time()],
                0,
            ) > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return array{name:string,status:string,message:string,duration_ms:int} */
    private function result(string $name, string $status, string $message, int $durationMs): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger === null) {
            return;
        }

        match ($level) {
            'error' => $this->logger->error($message),
            default => $this->logger->info($message),
        };
    }
}
