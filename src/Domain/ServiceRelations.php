<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * The relationship between a server service and what hosting actually uses it — PROMPT.md
 *
 *   Nginx / Apache  → the sites currently in use
 *   PHP-FPM 8.4     → sites configured to use this version
 *   MariaDB         → the databases in use
 *
 * The key point per the "Important UX Rule": this page "shows" the relationship so
 * impact is visible, but doesn't open a way to manage sites from here — the two
 * layers stay clearly separate.
 *
 * Pulled with a single set of queries per page load, not one query per service (avoids N+1).
 */
final class ServiceRelations
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every service's relationship, all in one call
     *
     * @param list<string> $units
     * @return array<string,array{kind:string,label:string,items:list<array{name:string,detail:string,url:string}>,total:int}>
     */
    public function forUnits(array $units): array
    {
        $sites = $this->db->all(
            'SELECT id, primary_domain, php_version, status FROM sites ORDER BY primary_domain'
        );
        $databases = $this->db->all(
            'SELECT d.id, d.db_name, d.size_bytes, s.primary_domain
             FROM databases_ d LEFT JOIN sites s ON s.id = d.site_id
             ORDER BY d.db_name'
        );
        $crons = $this->db->all(
            'SELECT c.id, c.name, c.schedule, c.enabled, s.primary_domain
             FROM cron_jobs c LEFT JOIN sites s ON s.id = c.site_id
             ORDER BY c.name'
        );

        $result = [];

        foreach ($units as $unit) {
            $result[$unit] = match (ServiceCatalog::kind($unit)) {
                ServiceCatalog::KIND_WEBSERVER => $this->webserver($sites),
                ServiceCatalog::KIND_PHP => $this->php($sites, ServiceCatalog::phpVersionFromUnit($unit)),
                ServiceCatalog::KIND_DATABASE => $this->databases($databases),
                ServiceCatalog::KIND_SCHEDULER => $this->crons($crons),
                default => ['kind' => 'none', 'label' => '', 'items' => [], 'total' => 0],
            };
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $sites */
    private function webserver(array $sites): array
    {
        $items = [];

        foreach ($sites as $site) {
            if ($site['status'] !== 'active') {
                continue;
            }

            $items[] = [
                'name' => (string) $site['primary_domain'],
                'detail' => 'PHP ' . $site['php_version'],
                'url' => '/sites/' . $site['id'],
            ];
        }

        return ['kind' => 'sites', 'label' => 'Sites in use', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $sites */
    private function php(array $sites, ?string $version): array
    {
        $items = [];

        foreach ($sites as $site) {
            if ($version === null || $site['php_version'] !== $version) {
                continue;
            }

            $items[] = [
                'name' => (string) $site['primary_domain'],
                'detail' => $site['status'] === 'active' ? 'Active' : 'Suspended',
                'url' => '/sites/' . $site['id'],
            ];
        }

        return ['kind' => 'sites', 'label' => 'Sites in use', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $databases */
    private function databases(array $databases): array
    {
        $items = [];

        foreach ($databases as $database) {
            $items[] = [
                'name' => (string) $database['db_name'],
                'detail' => (string) ($database['primary_domain'] ?? 'Not tied to a site'),
                'url' => '/databases',
            ];
        }

        return ['kind' => 'databases', 'label' => 'Databases in use', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $crons */
    private function crons(array $crons): array
    {
        $items = [];

        foreach ($crons as $cron) {
            if ((int) $cron['enabled'] !== 1) {
                continue;
            }

            $items[] = [
                'name' => (string) $cron['name'],
                'detail' => (string) $cron['schedule'],
                'url' => '/cron',
            ];
        }

        return ['kind' => 'crons', 'label' => 'Enabled scheduled jobs', 'items' => $items, 'total' => count($items)];
    }

    /**
     * A warning message about the impact of stopping a service — used in a
     * confirmation dialog (SECURITY §4), computed from real data in the system,
     * not generic text
     *
     * @param array{items:list<array{name:string,detail:string,url:string}>,total:int} $relation
     */
    public static function impactMessage(string $unit, array $relation, string $action): string
    {
        $label = ServiceCatalog::label($unit);
        $total = $relation['total'] ?? 0;

        if ($action === 'reload') {
            return "Reload {$label}'s configuration — the service will not stop";
        }

        if ($total === 0) {
            return $action === 'stop'
                ? "Stop the {$label} service?"
                : "Restart the {$label} service?";
        }

        $names = array_slice(array_column($relation['items'], 'name'), 0, 5);
        $list = implode(', ', $names);
        if ($total > 5) {
            $list .= ' and ' . ($total - 5) . ' more';
        }

        $kindWord = match ($relation['kind'] ?? '') {
            'databases' => 'database',
            'crons' => 'scheduled job',
            default => 'site',
        };

        return $action === 'stop'
            ? "Stopping this service may make the related {$kindWord}s unavailable\n\n{$total} affected {$kindWord}(s): {$list}"
            : "Restarting this service will briefly stop the related {$kindWord}s\n\n{$total} affected {$kindWord}(s): {$list}";
    }
}
