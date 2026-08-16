<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Domain\CronSchedule;

/**
 * One cron job
 *
 * `schedule_label` is sent along so the screen never has to parse the cron
 * expression itself — but the raw `schedule` is always sent alongside it too,
 * since the edit form needs the raw value, and sorting/comparing must only
 * ever work from the raw value (this is the middle path between "send only the raw value" and "send only text")
 */
final class CronJobResource extends Resource
{
    /**
     * An empty shape for the "create new" form
     *
     * One form handles both create and edit, so it must always receive data
     * in the same shape — if create got back an empty object, `data-attr="value:name"`
     * would have nothing to bind to, and the template would have to special-case
     * a missing key · `id = 0` is what tells both the form and the server this is a new one
     *
     * @return array<string,mixed>
     */
    public static function blank(): array
    {
        return self::one([]);
    }

    public static function one(array $row): array
    {
        $schedule = self::string($row['schedule'] ?? '');
        $id = (int) ($row['id'] ?? 0);

        $job = [
            'id' => $id,
            // Deliberately a duplicate of id — site.html itself has ?id= (the
            // website's id) in the URL. Putting {id} in the siteCronJobs
            // table's data-row-actions would get replaced by
            // RouterManager.render (js/ui.js) with the website's id before
            // TableManager even sees the template — every row's "manage"
            // button would go to the same one cron job (same issue as DomainResource::row_id)
            'row_id' => $id,
            'site_id' => (int) ($row['site_id'] ?? 0),
            'name' => self::string($row['name'] ?? ''),
            'schedule' => $schedule,
            // Always in English for now — see the translator-access note at CronSchedule::describe()
            'schedule_label' => $schedule === '' ? '' : CronSchedule::describe($schedule),
            'command' => self::string($row['command'] ?? ''),
            'enabled' => self::bool($row['enabled'] ?? 0),
            'last_run_at' => self::intOrNull($row['last_run_at'] ?? null),
            // 0 = succeeded · any other value = the command failed · null = never run yet
            'last_exit_code' => self::intOrNull($row['last_exit_code'] ?? null),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];

        // Comes from a JOIN when fetched as a list
        if (array_key_exists('primary_domain', $row)) {
            $job['site_domain'] = self::string($row['primary_domain']);
        }

        if (array_key_exists('system_user', $row)) {
            $job['system_user'] = self::string($row['system_user']);
        }

        return $job;
    }
}
