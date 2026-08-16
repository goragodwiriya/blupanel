<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The dashboard — `GET /api/v2/dashboard` (PLAN-V2 §4.6, the "Dashboard" row)
 *
 * Bundles everything the homepage needs into one request: the machine's
 * resource numbers, service status, resource counts, and recent activity ·
 * the reason the SPA doesn't fire four requests of its own is that the
 * homepage is the most frequently opened page, and every request has to run
 * the same seven middleware fresh
 *
 * **This page must still come up even when the agent is down** — the numbers
 * from the panel's own database are still correct, and the audit log can
 * still be read · if `AgentException` were allowed to propagate as a 503, an
 * admin would see a blank page at exactly the moment they most need to know
 * what's happening, so it's swallowed and reported through `agent.available`
 * instead (this is the **one and only place** in all of v2 that does this —
 * every route that issues a command still fails loudly, exactly as before)
 */
final class DashboardController extends HostingController
{
    public function index(Request $request): Response
    {
        $actor = $this->ctx->actor($request);
        $agentError = '';
        $metrics = [];
        $services = [];

        try {
            $metrics = $this->agent()->data('system.metrics', [], $actor);

            // A customer has no permission to view the machine's services — so the same page displays differently per role
            if ($this->ctx->can('service.view')) {
                $result = $this->agent()->data(
                    'service.status',
                    ['services' => ServiceCatalog::dashboardUnits()],
                    $actor,
                );
                $services = $result['services'] ?? [];
            }
        } catch (AgentException $e) {
            $agentError = $e->getMessage();
        }

        // The pill's color comes from the server, so the template can write `pill-${status_tone}` directly
        $services = array_map(static function (array $service): array {
            $service['status_tone'] = match ($service['status'] ?? '') {
                'running' => 'ok',
                'failed' => 'danger',
                'transitioning' => 'warn',
                default => 'muted',
            };

            return $service;
        }, $services);

        return $this->ok([
            'agent' => [
                'available' => $agentError === '',
                'error' => $agentError,
            ],
            'metrics' => $metrics,
            'services' => array_values($services),
            'counts' => $this->counts(),
            // A customer has no audit.view permission — an empty list is sent
            // instead of hiding the key, so the screen never has to
            // distinguish "no key" from "empty list," which is where undefined comes from
            'activity' => $this->ctx->can('audit.view') ? $this->activity() : [],
        ]);
    }

    /**
     * Recent activity, trimmed down to only the fields the screen uses
     *
     * Deliberately not the whole row: `prev_hash`/`hash` are the hash chain's
     * internal mechanism, and `detail_json` holds every command's raw
     * arguments (already passed through `Dispatcher::redact()`, but still data
     * with no reason to be shown on the homepage)
     *
     * @return list<array<string,mixed>>
     */
    private function activity(): array
    {
        return array_map(
            function (array $row): array {
                $result = (string) $row['result'];

                return [
                    'ts' => (int) $row['ts'],
                    'actor_name' => (string) $row['actor_name'],
                    'action' => (string) $row['action'],
                    'target' => (string) $row['target'],
                    'result' => $result,
                    'result_label' => $this->t(match ($result) {
                        'ok' => 'OK',
                        'denied' => 'Denied',
                        'error' => 'Error',
                        default => $result,
                    }),
                    // The pill's color comes from the server, so the template can write `pill-${result_tone}` directly
                    'result_tone' => match ($result) {
                        'ok' => 'ok',
                        'denied', 'error' => 'danger',
                        default => 'muted',
                    },
                ];
            },
            $this->app->audit()->recent(8),
        );
    }

    /**
     * The resource counts the caller may actually see
     *
     * A customer sees only their own — counted at the query level like
     * everywhere else, not fetched in full and filtered afterward, because the
     * machine's overall total is also data that shouldn't leak
     *
     * @return array<string,int>
     */
    private function counts(): array
    {
        $db = $this->app->db();
        $owner = $this->scopeOwner();

        if ($owner === null) {
            return [
                'sites' => (int) $db->value('SELECT count(*) FROM sites', [], 0),
                'domains' => (int) $db->value('SELECT count(*) FROM domains', [], 0),
                'databases' => (int) $db->value('SELECT count(*) FROM databases_', [], 0),
                'certificates' => (int) $db->value('SELECT count(*) FROM certificates', [], 0),
                'php_versions' => (int) $db->value('SELECT count(DISTINCT php_version) FROM sites', [], 0),
                'backups' => (int) $db->value('SELECT count(*) FROM backups', [], 0),
                'users' => (int) $db->value('SELECT count(*) FROM users', [], 0),
            ];
        }

        $scoped = ' WHERE site_id IN (SELECT id FROM sites WHERE owner_user_id = :o)';

        // `certificates` has no site_id column — it's bound to a **domain
        // name**, not a website (see migration 0001), so it must be chased
        // through the domains table instead of filtering by site_id directly
        $certScope = ' WHERE domain IN (SELECT domain FROM domains'.$scoped.')';

        return [
            'sites' => (int) $db->value('SELECT count(*) FROM sites WHERE owner_user_id = :o', ['o' => $owner], 0),
            'domains' => (int) $db->value('SELECT count(*) FROM domains'.$scoped, ['o' => $owner], 0),
            'databases' => (int) $db->value('SELECT count(*) FROM databases_'.$scoped, ['o' => $owner], 0),
            'certificates' => (int) $db->value('SELECT count(*) FROM certificates'.$certScope, ['o' => $owner], 0),
            'php_versions' => (int) $db->value(
                'SELECT count(DISTINCT php_version) FROM sites WHERE owner_user_id = :o',
                ['o' => $owner],
                0,
            ),
            'backups' => (int) $db->value('SELECT count(*) FROM backups'.$scoped, ['o' => $owner], 0),
            'users' => 0,
        ];
    }
}
