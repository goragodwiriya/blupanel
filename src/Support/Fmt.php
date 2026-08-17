<?php

declare (strict_types = 1);

namespace Phpcp\Support;

/**
 * Formats numbers and time for the CLI's own output — the only caller left
 * is `src/Cli/Application.php`; the SPA formats everything itself client-side per Resource.php's raw-values rule
 *
 * Kept in one place so units and wording stay consistent across every command
 * (e.g. always "12d 3h," never one command writing it out differently)
 */
final class Fmt
{
    /** A data size in base 1024 */
    public static function bytes(int | float $bytes, int $decimals = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = min((int) floor(log((float) $bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : $decimals).' '.$units[$power];
    }

    /**
     * A duration as text, e.g. "12d 3h"
     *
     * English, because the only caller is the CLI, which outputs to the
     * destination machine's own terminal — one that can't render Thai vowel
     * and tone marks (same reason install.sh is entirely in English)
     */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }

    /** A relative time, e.g. "3 minutes ago" — English for the same reason as duration() */
    public static function ago(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '-';
        }

        $diff = time() - $timestamp;

        if ($diff < 0) {
            return 'in a moment';
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60).' minutes ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600).' hours ago';
        }
        if ($diff < 2592000) {
            return intdiv($diff, 86400).' days ago';
        }

        // Doesn't call self::date() here — it returns a Thai month name in
        // the Buddhist Era, which would make ago()'s output flip back to
        // Thai only for items older than 30 days — exactly the case most
        // likely to slip past casual testing, since a freshly installed
        // machine has no data that old yet
        return date('Y-m-d', $timestamp);
    }

    /**
     * A date in Thai style, Buddhist Era — currently unused (the only
     * caller, src/Cli/Application.php, never calls this) · left as-is
     * because this isn't translatable UI text, it's a Thai-calendar
     * formatter by design (the +543 year offset, Thai month abbreviations);
     * "converting" it to English would mean turning it into a different,
     * plain Gregorian formatter, not translating this one
     */
    public static function date(?int $timestamp, bool $withTime = false): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }

        $months = [
            1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
        ];

        $day = (int) date('j', $timestamp);
        $month = $months[(int) date('n', $timestamp)];
        $year = (int) date('Y', $timestamp) + 543;

        $out = "{$day} {$month} {$year}";

        return $withTime ? $out.' '.date('H:i', $timestamp).' น.' : $out;
    }

    /**
     * @param int $timestamp
     * @return mixed
     */
    public static function time(?int $timestamp): string
    {
        return $timestamp === null || $timestamp <= 0 ? '—' : date('H:i:s', $timestamp);
    }

    /**
     * @param float $value
     * @param int $decimals
     */
    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals).'%';
    }

    /** Picks the meter bar's color level from the percentage used */
    public static function levelOf(float $percent): string
    {
        return match (true) {
            $percent >= 90 => 'is-danger',
            $percent >= 75 => 'is-warn',
            default => 'is-ok',
        };
    }

    /**
     * The .meter bar's length class — the CSP forbids style="width:NN%", so
     * the length must come from a class that already exists in app.css (.pct-0 through .pct-100)
     */
    public static function meterClass(float $percent): string
    {
        return 'pct-'.(int) round(max(0.0, min(100.0, $percent)));
    }

    /** Service status → a human-readable label — currently unused (the only caller never calls this) */
    public static function serviceStatus(string $status): string
    {
        return match ($status) {
            'running' => 'Running',
            'stopped' => 'Stopped',
            'failed' => 'Failed',
            'transitioning' => 'Changing status',
            'not_installed' => 'Not installed',
            default => 'Unknown status',
        };
    }

    /** Service status → badge class */
    public static function serviceTone(string $status): string
    {
        return match ($status) {
            'running' => 'ok',
            'failed' => 'danger',
            'stopped' => 'muted',
            'transitioning' => 'info',
            'not_installed' => 'warn',
            default => 'muted',
        };
    }

    /** An audit log action name → a human-readable label — currently unused (the only caller never calls this) */
    public static function action(string $action): string
    {
        return match ($action) {
            'auth.login' => 'Signed in',
            'auth.logout' => 'Signed out',
            'auth.2fa' => 'Two-factor verification',
            'auth.password_changed' => 'Changed password',
            'http.forbidden' => 'Tried to access a forbidden section',
            'service.restart' => 'Restarted a service',
            'service.start' => 'Started a service',
            'service.stop' => 'Stopped a service',
            'service.reload' => 'Reloaded a service configuration',
            'site.create' => 'Created a website',
            'site.delete' => 'Deleted a website',
            'system.setup' => 'Installed the system',
            default => $action,
        };
    }

    /**
     * @param string $result
     */
    public static function auditTone(string $result): string
    {
        return match ($result) {
            'ok' => 'ok',
            'denied' => 'warn',
            'error' => 'danger',
            default => 'muted',
        };
    }

    /** Initials for an avatar */
    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($name, 0, 1));
    }
}
