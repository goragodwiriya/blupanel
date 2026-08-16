<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\ServiceRelations;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * System services — `/api/v2/services`
 *
 * The list of manageable units is a **fixed allowlist** in `ServiceCatalog`,
 * and the panel's own service is already filtered out at that layer
 * (SelfProtection) — not just a hidden button on screen, so a user can never
 * hit the API directly to stop phpcp-agentd, no matter how hard they try
 *
 * `POST /services/{unit}/actions` is a noun (`actions`) per §4.1, not
 * `/restart` — the command lives in the body, so a new command can be added
 * without adding a new route, and the audit log records every command in the
 * same shape
 */
final class ServicesController extends ApiController
{
    /** Accepted commands — matches only the service.<action> capabilities that genuinely exist */
    private const ACTIONS = ['start', 'stop', 'restart', 'reload'];

    public function index(Request $request): Response
    {
        $units = ServiceCatalog::units();
        $data = $this->agent()->data('service.status', ['services' => $units], $this->ctx->actor($request));
        $services = $data['services'] ?? [];

        // Relationships with websites — an admin must see "which websites go
        // down if this stops" before clicking, not find out when a customer calls to report it
        $relations = (new ServiceRelations($this->app->db()))->forUnits($units);

        $canControl = $this->ctx->can('service.control');
        $rows = [];

        foreach ($units as $unit) {
            $service = $services[$unit] ?? null;

            if ($service === null) {
                continue;
            }

            $affects = $relations[$unit] ?? ['items' => []];

            if (isset($affects['label'])) {
                $affects['label'] = $this->t((string) $affects['label']);
            }

            $affects['items'] = array_map(
                fn (array $item): array => ['detail' => $this->t((string) ($item['detail'] ?? ''))] + $item,
                $affects['items'] ?? [],
            );

            $affectedNames = array_column($affects['items'] ?? [], 'name');
            $status = (string) ($service['status'] ?? '');

            $rows[] = $service + [
                'unit' => $unit,
                'kind' => ServiceCatalog::kind($unit),
                'label' => $this->t(ServiceCatalog::label($unit)),
                'critical' => ServiceCatalog::isCritical($unit),
                'affects' => $affects,
                // A ready-composed summary string — Now.js's data-template can't
                // join a list into readable text on its own, only substitute
                // ${key} directly
                //
                // This column used to always show "—" no matter how many
                // websites were genuinely affected, because formatList (the old
                // js/formatters.js) checked Array.isArray(), but affects has
                // always been an object {kind,label,items,total}, never an array
                'affects_label' => $affectedNames === [] ? '—' : implode(', ', $affectedNames),
                // The pill's color comes from the server, so the template can write `pill-${status_tone}` directly
                'status_tone' => match ($status) {
                    'running' => 'ok',
                    'failed' => 'danger',
                    'transitioning' => 'warn',
                    default => 'muted',
                },
                'can_manage' => $canControl,
            ];
        }

        return $this->ok($rows, [
            'total' => count($rows),
            'actions' => self::ACTIONS,
        ]);
    }

    /** Issue a command to one service */
    public function action(Request $request): Response
    {
        $action = $request->payloadString('action');

        // Checked here too, even though the agent checks again — an unknown
        // command must never be sent out at all (letting it through would mean
        // the capability name gets assembled from a user-submitted value, a shape that should never exist)
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->problem(
                ApiProblem::ValidationError,
                'Invalid command',
                ['action' => 'Allowed: ' . implode(', ', self::ACTIONS)],
            );
        }

        $result = $this->agent()->data(
            'service.' . $action,
            ['service' => $request->param('unit')],
            $this->ctx->actor($request),
        );

        return $this->completed(
            (string) ($result['message'] ?? $this->t('{action} sent to {unit}', ['action' => $action, 'unit' => $request->param('unit')])),
            'services',
            $result + ['unit' => $request->param('unit'), 'action' => $action],
        );
    }
}
