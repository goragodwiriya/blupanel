<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;

/**
 * Validate and describe a cron schedule
 *
 * Validated here instead of letting cron reject it later, because a malformed cron
 * line gets silently skipped for the *whole file*, taking every other, correctly
 * formed job in the same file down with it.
 */
final class CronSchedule
{
    /** The accepted range for each field, in crontab order */
    private const RANGES = [
        ['minute', 0, 59],
        ['hour', 0, 23],
        ['day of month', 1, 31],
        ['month', 1, 12],
        ['day of week', 0, 7],
    ];

    /** Shorthand forms cron supports — converted straight into five fields */
    private const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /** Ready-made choices offered on screen */
    public static function presets(): array
    {
        return [
            '*/5 * * * *' => 'Every 5 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '0 * * * *' => 'Every hour',
            '0 1 * * *' => 'Every day at 01:00',
            '0 3 * * 0' => 'Every week (Sunday 03:00)',
            '0 4 1 * *' => 'Every month (the 1st at 04:00)',
        ];
    }

    /**
     * Validate the format and always return the schedule as five fields
     */
    public static function normalize(string $schedule): string
    {
        $schedule = trim(preg_replace('/\s+/', ' ', $schedule) ?? '');

        if ($schedule === '') {
            throw new ValidationError('A schedule must be specified');
        }

        if (isset(self::ALIASES[strtolower($schedule)])) {
            return self::ALIASES[strtolower($schedule)];
        }

        if (str_starts_with($schedule, '@')) {
            throw new ValidationError('Only these shorthand forms are supported: @hourly @daily @weekly @monthly @yearly');
        }

        $fields = explode(' ', $schedule);

        if (count($fields) !== 5) {
            throw new ValidationError('A schedule must have 5 fields, e.g. "0 3 * * *"');
        }

        foreach ($fields as $index => $field) {
            [$label, $min, $max] = self::RANGES[$index];
            self::assertField($field, $label, $min, $max);
        }

        return implode(' ', $fields);
    }

    /** Validate a single field — supports * , - / exactly as real cron does */
    private static function assertField(string $field, string $label, int $min, int $max): void
    {
        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                throw new ValidationError("The {$label} field has an invalid format");
            }

            // Split off the step part first, e.g. */5 or 1-30/2
            $step = null;
            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);

                if (preg_match('/^\d{1,2}$/', $stepText) !== 1 || (int) $stepText < 1) {
                    throw new ValidationError("Invalid step value for the {$label} field");
                }

                $step = (int) $stepText;
            }

            if ($part === '*') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$from, $to] = explode('-', $part, 2);
                self::assertNumber($from, $label, $min, $max);
                self::assertNumber($to, $label, $min, $max);

                if ((int) $from > (int) $to) {
                    throw new ValidationError("The {$label} field's range is backwards");
                }

                continue;
            }

            self::assertNumber($part, $label, $min, $max);

            // A single number combined with a step is not valid in standard cron
            if ($step !== null) {
                throw new ValidationError("The {$label} field cannot combine a step with a single value");
            }
        }
    }

    private static function assertNumber(string $value, string $label, int $min, int $max): void
    {
        if (preg_match('/^\d{1,2}$/', $value) !== 1) {
            throw new ValidationError("The {$label} field must be a number");
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw new ValidationError("The {$label} field must be between {$min} and {$max}");
        }
    }

    /**
     * Whether this schedule matches the given time — decided at minute-level resolution
     *
     * Follows every rule real Unix cron does, including the one people overlook most
     * often: if both "day of month" and "day of week" are specified (neither is *),
     * it matches when **either one** matches, not both — `0 0 1 * 1` means "the 1st
     * of the month, or every Monday."
     */
    public static function matches(string $schedule, int $timestamp): bool
    {
        [$minute, $hour, $day, $month, $weekday] = explode(' ', self::normalize($schedule));

        if (!in_array((int) date('i', $timestamp), self::expand($minute, 0, 59), true)) {
            return false;
        }

        if (!in_array((int) date('G', $timestamp), self::expand($hour, 0, 23), true)) {
            return false;
        }

        if (!in_array((int) date('n', $timestamp), self::expand($month, 1, 12), true)) {
            return false;
        }

        // 7 and 0 both mean Sunday in cron — normalize to one value before comparing
        $weekdays = array_map(static fn (int $d): int => $d === 7 ? 0 : $d, self::expand($weekday, 0, 7));

        $dayMatches = in_array((int) date('j', $timestamp), self::expand($day, 1, 31), true);
        $weekdayMatches = in_array((int) date('w', $timestamp), $weekdays, true);

        if ($day !== '*' && $weekday !== '*') {
            return $dayMatches || $weekdayMatches;
        }

        return $dayMatches && $weekdayMatches;
    }

    /**
     * Is it time to run yet, given when it last ran
     *
     * Doesn't just ask "does this minute match" — it walks every minute from just
     * after the last run up to now, because the timer might not actually run every
     * single minute (the machine was down, the timer was stopped, the previous run
     * took a long time). Comparing only the current minute would mean a job scheduled
     * for 3am gets skipped for the whole day just because the machine happened to be
     * off at 3am.
     *
     * @param int|null $lastRunAt null = never run before, only look at the current
     *                            minute (a job that was just added shouldn't
     *                            immediately explode into running every missed slot
     *                            since install)
     * @param int      $catchUp   how far back to look, in seconds at most — prevents
     *                            a long-neglected job from running dozens of times
     *                            back to back once the machine comes back
     */
    public static function isDue(string $schedule, int $now, ?int $lastRunAt = null, int $catchUp = 86400): bool
    {
        $nowMinute = $now - ($now % 60);

        $from = $lastRunAt === null
            ? $nowMinute
            : max($lastRunAt - ($lastRunAt % 60) + 60, $nowMinute - max(0, $catchUp));

        for ($minute = $from; $minute <= $nowMinute; $minute += 60) {
            if (self::matches($schedule, $minute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand one field into every value it can match
     *
     * Only ever receives a shape that normalize() has already accepted, so nothing
     * needs to be re-validated here.
     *
     * @return list<int>
     */
    private static function expand(string $field, int $min, int $max): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
            }

            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$fromText, $toText] = explode('-', $part, 2);
                $from = (int) $fromText;
                $to = (int) $toText;
            } else {
                $values[] = (int) $part;
                continue;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * A rough human-readable description of a schedule, for display on screen
     *
     * **Known gap:** this returns pre-formatted, locale-specific text, which
     * conflicts with {@see \Phpcp\Http\Resource\Resource}'s own rule that this layer
     * must always return raw values and leave formatting to the viewer · neither of
     * this method's two callers ({@see \Phpcp\Http\Resource\CronJobResource} and
     * {@see \Phpcp\Http\V2\BackupSchedulesController}) currently pass this through
     * the translator, and `CronJobResource` is a static value object with no
     * translator access at all — so today this text is English-only for every
     * locale. The raw `schedule` field is always sent alongside this one specifically
     * so callers are not forced to depend on it; fixing this properly means either
     * giving Resource classes translator access or moving this formatting to the SPA.
     */
    public static function describe(string $schedule): string
    {
        $known = self::presets();

        if (isset($known[$schedule])) {
            return $known[$schedule];
        }

        $fields = explode(' ', $schedule);
        if (count($fields) !== 5) {
            return $schedule;
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;

        if ($minute !== '*' && $hour !== '*' && $day === '*' && $month === '*' && $weekday === '*') {
            return sprintf('Daily at %02d:%02d', (int) $hour, (int) $minute);
        }

        if (str_starts_with($minute, '*/') && $hour === '*') {
            return 'Every ' . substr($minute, 2) . ' minutes';
        }

        if ($minute !== '*' && $hour === '*') {
            return sprintf('Every hour at minute %d', (int) $minute);
        }

        return $schedule;
    }
}
