<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Driver\RollbackGuard;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The item waiting for confirmation — `/api/v2/rollbacks`
 *
 * This mechanism is what makes it safe to edit the firewall/SSH remotely
 * without locking yourself out: change the value → confirm within the time
 * window if still reachable → if not confirmed in time, the system reverts it automatically
 *
 * **The timer always lives server-side, never in the browser** — the case
 * that needs recovering from is exactly the case where the user has already
 * been cut off, so the browser never gets a chance to run · what actually
 * triggers it is `phpcp-scheduler` calling `rollback.run` every minute (phase A1)
 *
 * Two sub-resources, nouns per §4.1:
 *   `confirmation` — confirms the connection is still reachable · the request
 *                    itself arriving is its own proof
 *   `execution`    — reverts immediately without waiting for the timeout, for
 *                    when the mistake is already obvious
 */
final class RollbacksController extends ApiController
{
    public function index(Request $request): Response
    {
        $guard = new RollbackGuard($this->app->db());
        $pending = $guard->pending();

        return $this->ok($pending === null ? [] : [$this->describe($pending)], [
            'default_window' => RollbackGuard::DEFAULT_WINDOW,
            // Items that already timed out but the scheduler hasn't swept up
            // yet — the admin should still see that something is pending
            'expired_waiting' => count($guard->expired()),
        ]);
    }

    /** Confirm the machine is still reachable — cancels the scheduled rollback */
    public function confirm(Request $request): Response
    {
        $result = $this->agent()->data(
            'rollback.confirm',
            ['rollback_id' => $request->paramInt('id')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'Change confirmed'), 'rollbacks', is_array($result) ? $result : []);
    }

    /**
     * Revert immediately
     *
     * Forces the item to expire first, then walks the same revert path the
     * scheduler uses — never a second copy of revert logic, so there's only ever one path to maintain and test
     */
    public function execute(Request $request): Response
    {
        $id = $request->paramInt('id');
        $db = $this->app->db();

        $exists = (int) $db->value('SELECT count(*) FROM pending_rollbacks WHERE id = :id', ['id' => $id], 0);

        if ($exists === 0) {
            return $this->problem(ApiProblem::NotFound, 'Nothing is waiting for confirmation — it may have been rolled back already');
        }

        $db->run('UPDATE pending_rollbacks SET expires_at = :t WHERE id = :id', ['t' => time() - 1, 'id' => $id]);

        $result = $this->agent()->data('rollback.run', [], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Change rolled back'), 'rollbacks', is_array($result) ? $result : []);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'description' => (string) $row['description'],
            'created_at' => (int) $row['created_at'],
            'expires_at' => (int) $row['expires_at'],
            'remaining_seconds' => RollbackGuard::remaining($row),
        ];
    }
}
