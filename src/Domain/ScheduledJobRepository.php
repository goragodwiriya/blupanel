<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * System-level scheduled jobs — the `scheduled_jobs` table
 *
 * Differs from cron_jobs in that cron_jobs belongs to "the customer's own site" and
 * ends up in the system crontab; this table holds the panel's own jobs, which
 * `bin/phpcp-scheduler` reads and dispatches through the agent.
 *
 * The default list lives in code, not a migration file, because it has to be
 * backfilled into machines that are already installed, on every update
 * (install.sh runs `phpcp db:migrate` every time) — if it lived in a migration,
 * a machine that already passed that migration would never get a job added
 * afterward.
 */
final class ScheduledJobRepository
{
    /**
     * Jobs the system must always have — a duplicate name is never overwritten, so
     * the admin can adjust the schedule themselves without it being reverted
     *
     * @var list<array{name:string,capability:string,schedule:string,args:array<string,mixed>}>
     */
    public const DEFAULTS = [
        // Every minute — the auto-rollback mechanism must work even when nobody has
        // the web page open. This is exactly the scenario it was designed to
        // handle (the admin has already lost their connection).
        ['name' => 'rollback.run', 'capability' => 'rollback.run', 'schedule' => '* * * * *', 'args' => []],

        // Daily at 3am — sends advance warnings and changes the status of expired customers
        ['name' => 'expiry.check', 'capability' => 'expiry.check', 'schedule' => '0 3 * * *', 'args' => []],

        // Every 15 minutes per ARCHITECTURE §14
        ['name' => 'disk.usage', 'capability' => 'disk.usage', 'schedule' => '*/15 * * * *', 'args' => []],

        // Every minute — the finest tier's resolution is 1 minute; recording more
        // often would just get averaged back into the same row anyway · less often
        // would leave gaps with no data at all in the 24-hour graph (PLAN-V2 Phase
        // E6) · this job is very cheap: reads /proc and writes one row.
        ['name' => 'metrics.record', 'capability' => 'metrics.record', 'schedule' => '* * * * *', 'args' => []],

        // Hourly — measures disk usage at the account level (the whole home, not
        // per site) and notifies at 80/90/100% · cheaper per run than disk.usage,
        // but still walks the account's entire file tree, so it isn't run as
        // often (PLAN-V2 Phase E2).
        ['name' => 'quota.disk_check', 'capability' => 'quota.disk_check', 'schedule' => '0 * * * *', 'args' => []],

        // Every 5 minutes — checks the machine's alert thresholds (disk, RAM,
        // load, services, certificates). Frequent enough to notice quickly when a
        // critical service goes down, but not so frequent it wastes resources
        // querying systemctl for each service one at a time · deduplicating
        // repeated notifications lives in `AlertRules`, not in this job's
        // frequency (PLAN-V2 Phase E6).
        ['name' => 'alert.check', 'capability' => 'alert.check', 'schedule' => '*/5 * * * *', 'args' => []],

        // Hourly — a customer can edit their own .htaccess at any time over SFTP,
        // which changes whether nginx can serve that site's static files itself ·
        // without this job, a protection rule a customer just added would have no
        // effect on the site's root files until someone happens to edit that site
        // again. Only rewrites the file when the content actually differs, so
        // it's completely silent when nothing changed.
        ['name' => 'webserver.rescan', 'capability' => 'webserver.rescan', 'schedule' => '20 * * * *', 'args' => []],

        // Daily at 4:30am — after certbot's own timer, which usually runs in the early morning
        ['name' => 'cert.sync', 'capability' => 'cert.sync', 'schedule' => '30 4 * * *', 'args' => []],

        // Daily at 4:45am — right after cert.sync · certbot renews its own
        // certificates without going through the panel, and **Dovecot holds onto
        // the certificate it read at startup until it's told to reload** ·
        // without this job, a customer would see an expired-certificate warning
        // when opening their mailbox even though the file on disk is already the
        // new certificate, with nothing on screen looking wrong at all
        // (PLAN-MAIL Phase M3).
        ['name' => 'mail.cert', 'capability' => 'mail.cert', 'schedule' => '45 4 * * *', 'args' => []],

        /*
         * **There is no longer a `backup.prune` default job** (PLAN-BACKUP-V2)
         *
         * Back when backup files lived in the panel's own space, the pruner could
         * only delete what the panel itself had created · once files moved into
         * the customer's own `<home>/backup`, a job left enabled from
         * installation would turn into **deleting files in the customer's own
         * home every day at 5am with nobody having asked for it** — including
         * copies they created themselves and deliberately intended to keep
         * longer than the default policy.
         *
         * Pruning is now one step **inside the automated backup run**
         * (`backup.run`), which the admin turns on and configures the policy for
         * at the same time · no run enabled = nothing gets deleted.
         */
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM scheduled_jobs ORDER BY name');
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return $this->db->all('SELECT * FROM scheduled_jobs WHERE enabled = 1 ORDER BY name');
    }

    /** @return array<string,mixed>|null */
    public function find(string $name): ?array
    {
        return $this->db->first('SELECT * FROM scheduled_jobs WHERE name = :name', ['name' => $name]);
    }

    /**
     * Insert any default job that doesn't already exist — safe to call as many times as needed
     *
     * @return list<string> names of jobs that were just added
     */
    public function installDefaults(): array
    {
        $added = [];
        $now = time();

        foreach (self::DEFAULTS as $job) {
            if ($this->find($job['name']) !== null) {
                continue;
            }

            $this->db->insert('scheduled_jobs', [
                'name' => $job['name'],
                'capability' => $job['capability'],
                'args_json' => json_encode($job['args'], JSON_UNESCAPED_UNICODE) ?: '{}',
                'schedule' => CronSchedule::normalize($job['schedule']),
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $added[] = $job['name'];
        }

        return $added;
    }

    /** Record the result of one run — last_error is always cleared on success, never left stale */
    public function recordRun(int $id, string $status, string $error = '', ?int $ranAt = null): void
    {
        $this->db->update('scheduled_jobs', [
            'last_run_at' => $ranAt ?? time(),
            'last_status' => $status,
            'last_error' => $error === '' ? null : mb_substr($error, 0, 500),
            'updated_at' => time(),
        ], ['id' => $id]);
    }

    public function setEnabled(string $name, bool $enabled): bool
    {
        return $this->db->update(
            'scheduled_jobs',
            ['enabled' => $enabled ? 1 : 0, 'updated_at' => time()],
            ['name' => $name],
        ) > 0;
    }

    /**
     * The most recent time the scheduler touched this table, regardless of which job
     *
     * Used as the scheduler's own heartbeat: if this value hasn't moved in more
     * than a few minutes, the timer isn't running, which is more dangerous than
     * any single job failing, since nothing will ever trigger auto-rollback.
     */
    public function lastRunAt(): ?int
    {
        $value = $this->db->value('SELECT max(last_run_at) FROM scheduled_jobs');

        return $value === null ? null : (int) $value;
    }

    /** @return list<array<string,mixed>> jobs whose most recent run failed */
    public function failing(): array
    {
        return $this->db->all("SELECT * FROM scheduled_jobs WHERE last_status = 'error' ORDER BY name");
    }
}
