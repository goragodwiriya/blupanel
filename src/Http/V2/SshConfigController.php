<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * SSH settings — `/api/v2/ssh-config`
 *
 * Only keys in `SshManager`'s allowlist can be edited, and only values within
 * each key's own enum — there's no way to write an arbitrary line into
 * sshd_config through this API, which prevents "edited the config and sshd
 * never came back up" at the source
 *
 * **A change here can cut off the very connection that requested it**
 * (changing the port, disabling password auth), so it always requires
 * confirming within the time window · the response attaches
 * `pending_rollback` so the SPA can show a countdown
 */
final class SshConfigController extends ApiController
{
    public function show(Request $request): Response
    {
        $data = $this->agent()->data('ssh.config_get', [], $this->ctx->actor($request));

        return $this->ok($data + [
            'editable_keys' => SshManager::keys(),
            // The save button reads can['manage'] directly from this
            // response — the form loads its own data via data-load-api, it's
            // not nested under /session's data-component="api"
            'can' => $this->can(['manage' => 'ssh.manage']),
        ], [
            'pending_rollback' => $this->pendingRollback(),
            'default_window' => RollbackGuard::DEFAULT_WINDOW,
        ]);
    }

    /**
     * A partial update — only the keys that need changing are sent
     *
     * A key that isn't sent is left untouched (that's what PATCH means) ·
     * an unknown key is rejected along with the list of valid ones, instead
     * of silently doing nothing while the user thinks it saved
     */
    public function update(Request $request): Response
    {
        $changes = [];
        $unknown = [];

        foreach ($request->json() + $request->post as $key => $value) {
            if (in_array($key, ['window', '_token'], true)) {
                continue;
            }

            if (!in_array($key, SshManager::keys(), true)) {
                $unknown[] = (string) $key;

                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                $changes[$key] = $text;
            }
        }

        if ($unknown !== []) {
            return $this->problem(
                ApiProblem::ValidationError,
                $this->t('These keys cannot be changed') . ': ' . implode(', ', $unknown),
                ['keys' => 'Editable keys: ' . implode(', ', SshManager::keys())],
            );
        }

        if ($changes === []) {
            return $this->problem(
                ApiProblem::ValidationError,
                'Send at least one value to change',
                ['keys' => 'Editable keys: ' . implode(', ', SshManager::keys())],
            );
        }

        $result = $this->agent()->data(
            'ssh.config_set',
            $changes + ['window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW)],
            $this->ctx->actor($request),
        );

        $result = $result + ['pending_rollback' => $this->pendingRollback()];

        return $this->completed((string) ($result['message'] ?? 'SSH settings saved — confirm them before the automatic rollback runs out'), '', is_array($result) ? $result : []);
    }

    /** @return array<string,mixed>|null */
    private function pendingRollback(): ?array
    {
        $pending = (new RollbackGuard($this->app->db()))->pending();

        if ($pending === null) {
            return null;
        }

        return [
            'id' => (int) $pending['id'],
            'action' => (string) $pending['action'],
            'description' => (string) $pending['description'],
            'expires_at' => (int) $pending['expires_at'],
            'remaining_seconds' => RollbackGuard::remaining($pending),
        ];
    }
}
