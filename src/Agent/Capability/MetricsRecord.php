<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MetricsHistoryRepository;

/**
 * Records one metrics sample to history, and rolls up older buckets itself — PLAN-V2 Phase E6
 *
 * **Why this needs a separate capability, instead of the scheduler calling
 * `system.metrics` and writing the result itself:** the scheduler can only call
 * one capability per job (the same limitation that made `backup.create` need to
 * accept `destination_id` back in Phase E1) · so reading the values and recording
 * them has to live inside the same command.
 *
 * Marked **read-only** for the same reason as `disk.usage`: it changes nothing on
 * the machine at all — what it writes is the measured value into the panel's own
 * table · the useful side effect is that it never adds an audit log entry every
 * single minute forever, and it gets a real `Executor` even in dryrun mode, so it
 * still measures correctly.
 */
final class MetricsRecord implements Capability
{
    public static function name(): string
    {
        return 'metrics.record';
    }

    public function permission(): string
    {
        return 'dashboard.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Record resource usage into history';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        // Calls the exact same collector `GET /metrics` and the SSE stream use —
        // so the numbers in the history graph come from the same source as the
        // live display, and can never drift apart
        $metrics = (new SystemMetrics())->run([], $executor, $context);

        $repository = new MetricsHistoryRepository($context->db);
        $result = $repository->record($metrics);

        return [
            'bucket_at' => $result['bucket_at'],
            'pruned' => $result['rolled_up'],
            'cpu_percent' => $metrics['cpu']['percent'] ?? 0,
            'memory_percent' => $metrics['memory']['percent'] ?? 0,
            'disk_percent' => $metrics['disk']['percent'] ?? 0,
            'message' => sprintf(
                'Recorded resource usage (CPU %.1f%% · RAM %.1f%% · disk %.1f%%)',
                $metrics['cpu']['percent'] ?? 0,
                $metrics['memory']['percent'] ?? 0,
                $metrics['disk']['percent'] ?? 0,
            ),
        ];
    }
}
