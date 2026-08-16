<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Domain\Site;

/**
 * One website
 *
 * File-path values (`root`, `docroot`) are computed from `Site`, never read
 * directly from database columns — `Site` is the single source of truth for
 * path inference, so reading the `docroot` column directly could return a
 * value that no longer matches what the vhost actually uses once `sites.dir` changes
 */
final class SiteResource extends Resource
{
    public static function one(array $row): array
    {
        $site = self::toSite($row);

        return [
            'id' => (int) $row['id'],
            'domain' => self::string($row['primary_domain'] ?? ''),
            'php_version' => self::string($row['php_version'] ?? ''),
            'ssl_mode' => self::string($row['ssl_mode'] ?? 'off'),
            'status' => self::string($row['status'] ?? 'active'),
            // The pill's color comes from the server, so the template can
            // write `pill-${status_tone}` directly with no JS-side lookup
            // table — same pattern as UserResource::service_tone
            'status_tone' => match (self::string($row['status'] ?? 'active')) {
                'active' => 'ok',
                'suspended' => 'warn',
                default => 'muted',
            },
            // The Linux account this website runs under belongs to the
            // **owner**, not the website, as of migration 0006 (the
            // sites.system_user/uid/gid columns were dropped — moved to live with the user instead)
            'system_user' => $site?->systemUser() ?? self::string($row['owner_system_user'] ?? ''),
            'root' => $site?->root(),
            'docroot' => $site?->docroot() ?? self::string($row['docroot'] ?? ''),
            'docroot_override' => self::string($row['docroot_override'] ?? ''),
            'owner_user_id' => self::intOrNull($row['owner_user_id'] ?? null),
            // **Bytes are the API's only unit**, even though the database stores MB
            //
            // Still a raw number, not pre-formatted text — but in the unit the
            // framework's standard formatter (`data-format="bytes"`) can
            // consume directly · returning MB instead would mean the screen
            // needs its own project-specific JS function to multiply by 1024² everywhere this value is shown
            'disk_used' => ((int) ($row['disk_used_mb'] ?? 0)) * 1048576,
            'disk_quota' => isset($row['disk_quota_mb']) && $row['disk_quota_mb'] !== null
                ? ((int) $row['disk_quota_mb']) * 1048576
                : null,
            'created_at' => self::intOrNull($row['created_at'] ?? null),
            'updated_at' => self::intOrNull($row['updated_at'] ?? null),
        ] + self::counts($row);
    }

    /**
     * Numbers that come from listWithCounts() — present only when fetched as a list
     *
     * These keys are left out entirely when the data isn't there, rather than
     * filled with a fake 0 that the screen can't tell apart from "genuinely no domains at all"
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function counts(array $row): array
    {
        $counts = [];

        foreach (['domain_count', 'database_count', 'cron_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $counts[$key] = (int) $row[$key];
            }
        }

        if (array_key_exists('cert_status', $row)) {
            $counts['certificate'] = [
                'status' => self::string($row['cert_status'] ?? ''),
                'expires_at' => self::intOrNull($row['cert_expires'] ?? null),
            ];
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function toSite(array $row): ?Site
    {
        // A row that hasn't finished being created yet (empty system_user)
        // makes Site throw — return null instead, so the API can still
        // report that the website exists, just in an incomplete state
        try {
            return Site::fromRow($row);
        } catch (\Throwable) {
            return null;
        }
    }
}
