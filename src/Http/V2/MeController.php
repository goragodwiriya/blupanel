<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\SessionStore;

/**
 * The currently signed-in user's own account — `/api/v2/me`
 *
 * Deliberately separate from `/users/{id}`: editing your own data doesn't
 * need the `user.manage` permission (a customer who's a webadmin must be able
 * to change their own password, but must never be able to touch anyone
 * else's account) · sharing the same route and checking whether the id
 * matches yourself would eventually get forgotten somewhere — a separate
 * route makes that mistake structurally impossible
 */
final class MeController extends ApiController
{
    public function show(Request $request): Response
    {
        $user = (new UserRepository($this->app->db()))->find($this->ctx->userId());

        if ($user === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'Your account was not found');
        }

        $presented = UserResource::withPermissions($user);
        $presented['role_label'] = $this->t($presented['role_label']);

        return $this->ok($presented);
    }

    /**
     * Change your own password
     *
     * Destroys every other session right after a successful change, in case
     * the old password had leaked and someone else was impersonating this
     * account · then immediately creates a new session for the caller, so
     * they don't get signed out along with everyone else
     */
    public function changePassword(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $user = $users->find($this->ctx->userId());

        if ($user === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'Your account was not found');
        }

        $current = $request->payloadString('current_password');
        $new = $request->payloadString('new_password');
        $minLength = $this->app->config->int('security.password_min_length', 12);

        $fields = [];

        if (!Password::verify($current, (string) $user['password_hash'])) {
            $fields['current_password'] = 'The current password is incorrect';
        }

        if ($new === $current) {
            $fields['new_password'] = 'The new password must not be the same as the current one';
        }

        $problems = Password::problems($new, $minLength, (string) $user['username']);

        if ($problems !== []) {
            // Joined into a single field, since every item is about the same field: the new password
            $fields['new_password'] = implode(' · ', array_unique($problems));
        }

        if ($fields !== []) {
            return $this->problem(ApiProblem::ValidationError, 'The new password cannot be used', $fields);
        }

        $users->setPassword((int) $user['id'], $new);

        $store = new SessionStore($this->app->db(), $this->app->config);
        $revoked = $store->destroyAllFor((int) $user['id']);

        $this->ctx->sessionId = $store->create(
            (int) $user['id'],
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
            false,
        );

        $this->ctx->session = $store->load(
            $this->ctx->sessionId,
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
        );

        $this->app->audit()->write(
            $this->ctx->actor($request),
            'auth.password_changed',
            (string) $user['username'],
            'ok',
            ['via' => 'api', 'sessions_revoked' => $revoked],
        );

        $result = [
            'changed' => true,
            'sessions_revoked' => $revoked,
            'message' => 'Password changed',
        ];

        return $this->completed('Password changed', '', is_array($result) ? $result : []);
    }
}
