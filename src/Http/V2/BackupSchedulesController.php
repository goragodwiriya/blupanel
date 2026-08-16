<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\CronSchedule;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The automatic backup round — `/api/v2/backup-schedule` · **one single one for the whole machine** (item B10)
 *
 * ## Why there's only one
 *
 * The old version was CRUD over many schedules, one row per website per type ·
 * a machine with fifty customers needed hundreds of schedules an admin created
 * and maintained by hand, and **a newly created website got no backups at
 * all** until someone remembered to add one for it — a silent failure until
 * the day the backup file is actually needed
 *
 * The question "what gets backed up" is now answered by the per-account
 * switches ({@see BackupTargetsController}) · what's left here is one single
 * question: **when does that round run**
 *
 * Stored as a row in the existing `scheduled_jobs` table, no new table · the
 * fixed name `backup.auto`, and the capability is pinned to `backup.run` — the
 * caller can't choose it, since choosing it would mean scheduling the system
 * to run any command at all in the name of "the system," the highest
 * privilege there is
 */
final class BackupSchedulesController extends ApiController
{
    /** The fixed name of that one job — a UNIQUE key in the table */
    public const JOB = 'backup.auto';

    /** The default when never configured — 1 AM every night, when websites are quietest */
    private const DEFAULT_SCHEDULE = '0 1 * * *';

    public function show(Request $request): Response
    {
        return $this->ok($this->present($this->row()), ['presets' => CronSchedule::presets()]);
    }

    /**
     * The schedule form's shell, with the command to open its modal
     *
     * Always the current values, never an empty form — there's only one of
     * this job and it always exists; what a user can do is edit its values, not create a new one
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            $this->present($this->row()),
            ['presets' => CronSchedule::presets()],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'backup-schedule-form.html',
                'title' => '{LNG_Backup schedule}',
                'titleClass' => 'icon-clock',
            ]],
        );
    }

    public function update(Request $request): Response
    {
        $row = $this->row();
        $fields = ['updated_at' => time()];

        if ($request->payloadString('schedule') !== '') {
            try {
                $fields['schedule'] = CronSchedule::normalize($request->payloadString('schedule'));
            } catch (\Throwable $e) {
                return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['schedule' => 'Invalid format']);
            }
        }

        if ($request->payload('enabled') !== null) {
            $fields['enabled'] = in_array((string) $request->payload('enabled'), ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
        }

        $args = json_decode((string) ($row['args_json'] ?? '{}'), true);
        $args = is_array($args) ? $args : [];

        foreach (['days', 'keep'] as $key) {
            if ($request->payload($key) !== null) {
                $args[$key] = max(0, (int) $request->payload($key));
            }
        }

        $fields['args_json'] = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';

        if (count($fields) === 2 && $fields['args_json'] === (string) ($row['args_json'] ?? '{}')) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        $this->app->db()->update('scheduled_jobs', $fields, ['id' => (int) $row['id']]);
        // This resource doesn't go through the Dispatcher, so nothing writes the audit entry automatically
        $this->app->audit()->write($this->ctx->actor($request), 'backup.schedule_update', self::JOB, 'ok', $fields);

        return $this->done(
            'Schedule saved',
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success', 'message' => 'Schedule saved'],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'backups'],
            ],
            $this->present($this->row()),
        );
    }

    /**
     * Run the round right now
     *
     * An admin needs to be able to prove what was configured genuinely works
     * **before** the first night — not find out when a backup file should
     * exist but doesn't · this is the exact same command cron itself calls
     */
    public function runNow(Request $request): Response
    {
        $result = $this->agent()->data('backup.run', [], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup run finished'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * That one job's row — created if it doesn't exist yet
     *
     * **Deliberately created on read** · this job is part of the system, not
     * something a user adds themselves, so a machine installed before this
     * migration must get its row without anyone having to click create · the
     * default is `enabled = 0`, because turning on a machine-wide backup round
     * before an admin has chosen which accounts to include means the first
     * round would do nothing at all anyway (every account's switch starts off)
     *
     * @return array<string,mixed>
     */
    private function row(): array
    {
        $row = $this->app->db()->first('SELECT * FROM scheduled_jobs WHERE name = :n', ['n' => self::JOB]);

        if ($row !== null) {
            return $row;
        }

        $this->app->db()->insert('scheduled_jobs', [
            'name' => self::JOB,
            'capability' => 'backup.run',
            'args_json' => json_encode(['days' => 30, 'keep' => 7], JSON_UNESCAPED_UNICODE) ?: '{}',
            'schedule' => self::DEFAULT_SCHEDULE,
            'enabled' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $this->app->db()->first('SELECT * FROM scheduled_jobs WHERE name = :n', ['n' => self::JOB]) ?? [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $args = json_decode((string) ($row['args_json'] ?? '{}'), true);
        $args = is_array($args) ? $args : [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'schedule' => (string) ($row['schedule'] ?? self::DEFAULT_SCHEDULE),
            // A human-readable description of the schedule, so the screen never
            // needs to parse cron syntax itself — always in English for now, see
            // the translator-access note at CronSchedule::describe()
            'schedule_label' => CronSchedule::describe((string) ($row['schedule'] ?? self::DEFAULT_SCHEDULE)),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'days' => (int) ($args['days'] ?? 30),
            'keep' => (int) ($args['keep'] ?? 7),
            // null = never run yet — the table's standard formatter
            // (data-format="datetime" + data-empty-text) shows "—" only for a
            // null value · a 0 would be interpreted as a real date (Jan 1,
            // 1970), not "never run"
            'last_run_at' => empty($row['last_run_at']) ? null : (int) $row['last_run_at'],
            'last_status' => (string) ($row['last_status'] ?? ''),
            'last_error' => (string) ($row['last_error'] ?? ''),
            // The pill's color comes from the server, so the template can write `pill-${run_tone}` directly
            'run_status' => match (true) {
                empty($row['last_run_at']) => 'never',
                (string) ($row['last_status'] ?? '') !== 'ok' => 'failed',
                default => 'ok',
            },
            'run_tone' => match (true) {
                empty($row['last_run_at']) => 'muted',
                (string) ($row['last_status'] ?? '') !== 'ok' => 'danger',
                default => 'ok',
            },
            'can_manage' => $this->ctx->can('backup.manage'),
        ];
    }
}
