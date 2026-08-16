<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Actor;
use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Security\SessionStore;
use Phpcp\Security\Totp;

/**
 * REST API v2's session — PLAN-V2 §4.4
 *
 * `GET /api/v2/session` is the SPA's starting point every time the app opens,
 * and the only way the frontend gets a CSRF token (the token used to sit in the
 * HTML page's `<meta>` tag, which the SPA has none of to scrape — this is the
 * gap the plan says must be filled in)
 *
 * **What was deliberately left unchanged:** the session cookie is still
 * HttpOnly + SameSite=Strict, and still bound to IP/User-Agent exactly as
 * before · the token was never moved into JS storage (decision N6), because
 * that would be a step backward from what already existed
 */
final class SessionController extends ApiController
{
    /**
     * The current state — callable without being logged in
     *
     * Always answers 200, whether logged in or not · "not logged in" isn't an
     * error, it's a state the SPA needs to know to decide whether to show the
     * login page or the main app
     */
    public function show(Request $request): Response
    {
        return $this->ok($this->state($request));
    }

    /**
     * Sign in
     *
     * Answers identically for every failure case (wrong username / wrong
     * password / suspended account), and always computes Argon2id even when no
     * user is found, so response timing never reveals whether that username exists
     */
    public function create(Request $request): Response
    {
        $username = trim($request->payloadString('username'));
        $password = $request->payloadString('password');

        $users = new UserRepository($this->app->db());
        $user = $username === '' ? null : $users->findByUsername($username);

        // The same dummy hash AuthController uses — must genuinely compute it so response timing stays close
        $dummyHash = '$argon2id$v=19$m=65536,t=4,p=2$YWFhYWFhYWFhYWFhYWFhYQ$'
            . 'c2FtcGxlaGFzaHZhbHVlZm9ydGltaW5nZXF1YWxpdHl4eA';

        if ($user === null) {
            Password::verify($password, $dummyHash);

            return $this->loginFailed($request, $username, 'User not found');
        }

        if ($users->isLocked($user)) {
            $seconds = $users->lockRemaining($user);

            $this->audit($request, 'auth.login', $username, 'denied', ['reason' => 'Account temporarily locked']);

            return $this->problem(
                ApiProblem::RateLimited,
                $this->t('Too many failed sign-ins — this account is locked for another {minutes} minutes', ['minutes' => (int) ceil($seconds / 60)]),
            )->withHeader('Retry-After', (string) max(1, $seconds));
        }

        if ($user['status'] !== 'active') {
            return $this->loginFailed($request, $username, 'Account suspended');
        }

        // A suspended or expired account may not sign in, even if its login
        // credentials are still active · the response never states the exact
        // reason, since this check runs before the password is even verified —
        // stating it plainly would become a way to guess which usernames exist
        if (!$users->checkService((int) $user['id'])['ok']) {
            return $this->loginFailed($request, $username, 'Account status does not allow sign-in');
        }

        if (!Password::verify($password, (string) $user['password_hash'])) {
            $users->registerFailure(
                (int) $user['id'],
                $this->app->config->int('security.max_login_attempts', 5),
                $this->app->config->int('security.lockout_seconds', 900),
            );

            return $this->loginFailed($request, $username, 'Incorrect password');
        }

        // Always create a new session after authenticating (guards against session fixation)
        $needs2fa = (int) $user['totp_enabled'] === 1;
        $store = new SessionStore($this->app->db(), $this->app->config);

        $this->ctx->sessionId = $store->create(
            (int) $user['id'],
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
            $needs2fa,
        );

        $users->registerSuccess((int) $user['id'], $request->ip, (string) $user['password_hash']);

        // The actor is built straight from the user's data, because ctx->session hasn't been loaded yet in this request
        $this->app->audit()->write(
            new Actor(
                userId: (int) $user['id'],
                username: (string) $user['username'],
                role: (string) $user['role'],
                ip: $request->ip,
                requestId: $request->requestId,
            ),
            'auth.login',
            $username,
            'ok',
            ['2fa_required' => $needs2fa, 'ip' => $request->ip, 'via' => 'api'],
        );

        if ($needs2fa) {
            // 401 with the TWO_FACTOR_REQUIRED code — the session cookie is already
            // set but can't be used for anything except verifying 2FA · the SPA
            // uses this code to decide to show the code-entry screen, not a fresh login page
            return $this->problem(ApiProblem::TwoFactorRequired, 'Two-factor verification is required first');
        }

        // Loads the session just created into ctx, so the response reflects the real state immediately
        $this->loadSessionIntoCtx($request);

        return $this->ok($this->state($request));
    }

    /** Verify the 2FA code for a session that already passed the password check */
    public function verifyTwoFactor(Request $request): Response
    {
        if (!$this->ctx->awaiting2fa()) {
            return $this->problem(ApiProblem::Unauthenticated, 'No sign-in is waiting for verification');
        }

        $users = new UserRepository($this->app->db());
        $user = $users->find($this->ctx->userId());

        if ($user === null || $user['totp_secret'] === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'This account does not have two-factor enabled');
        }

        /*
         * An account locked from guessing must be stopped at this gate too, not only the password gate
         *
         * The middleware's rate limiter is bound to **IP** (`login:<ip>`), which
         * stops one person hammering it but not a spread attack from many IPs ·
         * a six-digit code has a million possibilities, few enough that a spread
         * attack genuinely works if failures aren't counted **per account** ·
         * uses the same counter as the password, since both gates guard against
         * the same thing: guessing
         */
        if ($users->isLocked($user)) {
            $seconds = $users->lockRemaining($user);

            $this->audit($request, 'auth.2fa', (string) $user['username'], 'denied', ['reason' => 'Account temporarily locked']);

            return $this->problem(
                ApiProblem::RateLimited,
                $this->t('Too many failed sign-ins — this account is locked for another {minutes} minutes', ['minutes' => (int) ceil($seconds / 60)]),
            )->withHeader('Retry-After', (string) max(1, $seconds));
        }

        $code = trim($request->payloadString('code'));
        $secret = new Secret($this->app->config->secretKey());

        // Only accepts a code newer than the time step already used — the same code can't be reused even before it expires
        $counter = Totp::verifyAt(
            $secret->decrypt((string) $user['totp_secret']),
            $code,
            (int) ($user['totp_last_counter'] ?? 0),
        );

        $accepted = $counter !== null;

        if ($accepted) {
            $users->recordTotpCounter((int) $user['id'], $counter);
        }

        // A recovery code is single-use, kept in case the authenticator device is lost
        if (!$accepted) {
            $accepted = $users->consumeRecoveryCode((int) $user['id'], $code);
        }

        if (!$accepted) {
            $users->registerFailure(
                (int) $user['id'],
                $this->app->config->int('security.max_login_attempts', 5),
                $this->app->config->int('security.lockout_seconds', 900),
            );

            $this->audit($request, 'auth.2fa', (string) $user['username'], 'denied');

            return $this->problem(ApiProblem::TwoFactorRequired, 'The verification code is not correct', ['code' => 'The verification code is not correct']);
        }

        // Must clear the counter once passed, or failures accumulated from
        // earlier attempts would lock the account on the next sign-in even
        // though the user did everything right this time
        $users->registerSuccess((int) $user['id'], $request->ip, (string) $user['password_hash']);

        $store = new SessionStore($this->app->db(), $this->app->config);
        $store->markAuthenticated($this->ctx->sessionId);

        // Rotate the id again after the session's privileges are elevated
        // rotate() returns null when another request already rotated it first —
        // in that case keep using the existing id (still valid during the grace
        // period), never overwrite with null
        $this->ctx->sessionId = $store->rotate($this->ctx->sessionId) ?? $this->ctx->sessionId;

        $this->audit($request, 'auth.2fa', (string) $user['username'], 'ok');
        $this->loadSessionIntoCtx($request);

        return $this->ok($this->state($request));
    }

    /**
     * Sign out
     *
     * Always answers 204 even when not logged in — signing out twice isn't an
     * error, and answering differently would tell the caller whether the cookie
     * they're holding is actually valid
     */
    public function destroy(Request $request): Response
    {
        if ($this->ctx->sessionId !== '') {
            (new SessionStore($this->app->db(), $this->app->config))->destroy($this->ctx->sessionId);
            $this->audit($request, 'auth.logout', $this->ctx->username(), 'ok');
        }

        $this->ctx->session = null;
        $this->ctx->sessionId = '';

        return $this->noContent();
    }

    /**
     * The state the SPA needs at bootstrap — §4.4
     *
     * @return array<string,mixed>
     */
    private function state(Request $request): array
    {
        $config = $this->app->config;

        $state = [
            'authenticated' => $this->ctx->isAuthenticated(),
            'csrf_token' => $this->ctx->csrfToken,
            'mode' => $config->mode->value,
            'mode_label' => $this->t($config->mode->label()),
            'agent_available' => $this->app->agent()->isAvailable(),
            'two_factor_pending' => $this->ctx->awaiting2fa(),
        ];

        if (!$this->ctx->isAuthenticated() && !$this->ctx->awaiting2fa()) {
            return $state;
        }

        $user = (new UserRepository($this->app->db()))->find($this->ctx->userId());

        if ($user === null) {
            return $state;
        }

        return $state + [
            'user' => UserResource::one($user),
            'permissions' => $this->permissionMap(),
            'must_change_password' => $this->ctx->mustChangePassword(),
        ];
    }

    /**
     * Permissions as a map that **carries every permission the system knows
     * about**, each with a true/false value
     *
     * A map, not a list, because a screen can write a condition directly as
     * `data-if="permissions['user.manage']"` with no JS helper function needed
     * at all (on an array, referring to it by name would get `undefined`, which
     * `data-if` interprets as "show it")
     *
     * **Every permission is sent even when its value is deliberately false** —
     * sending only the ones granted would leave missing keys as `undefined`,
     * and that element would **appear** instead of staying hidden · sending all
     * of them means every condition in a template always has a clearly defined
     * value, leaving only one risk: a misspelled name, which a test sweeping
     * every template already guards against
     *
     * @return array<string,bool>
     */
    private function permissionMap(): array
    {
        $granted = $this->ctx->isAuthenticated() ? Permissions::forRole($this->ctx->role()) : [];
        $map = [];

        foreach (array_keys(Permissions::all()) as $permission) {
            $map[$permission] = in_array($permission, $granted, true);
        }

        return $map;
    }

    /**
     * Load the session just created/rotated into ctx
     *
     * Needed because SessionMiddleware already ran before reaching the
     * controller — without reloading, the login request's own response would
     * say "not logged in" even though the cookie was already set
     */
    private function loadSessionIntoCtx(Request $request): void
    {
        $store = new SessionStore($this->app->db(), $this->app->config);

        $this->ctx->session = $store->load(
            $this->ctx->sessionId,
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
        );

        // The token the middleware issued is bound to the old session — must issue a new one before putting it into the response
        $this->ctx->refreshCsrfToken();
    }

    private function loginFailed(Request $request, string $username, string $reason): Response
    {
        $this->audit($request, 'auth.login', $username, 'denied', ['reason' => $reason, 'ip' => $request->ip]);

        return $this->problem(ApiProblem::Unauthenticated, 'The username or password is not correct');
    }

    /** @param array<string,mixed> $detail */
    private function audit(Request $request, string $action, string $target, string $result, array $detail = []): void
    {
        $this->app->audit()->write($this->ctx->actor($request), $action, $target, $result, $detail);
    }
}
