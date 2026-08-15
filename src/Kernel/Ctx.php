<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use Phpcp\Agent\Actor;
use Phpcp\Security\Csrf;
use Phpcp\Security\Permissions;

/**
 * State for one request, passed along and filled in as middleware runs
 *
 * A mutable object (unlike Request, which is readonly) because middleware has to add
 * to it in sequence — Session fills in the user, Csrf fills in the token, and so on.
 */
final class Ctx
{
    /** @var array<string,mixed>|null the row from the sessions table, with user data */
    public ?array $session = null;

    /** The session's raw id (only its hash is stored in the DB) */
    public string $sessionId = '';

    public ?Route $route = null;

    public string $nonce = '';

    public string $csrfToken = '';


    public function __construct(public readonly App $app)
    {
        $this->nonce = base64_encode(random_bytes(16));
    }

    public function isAuthenticated(): bool
    {
        return $this->session !== null && (int) ($this->session['pending_2fa'] ?? 0) === 0;
    }

    public function awaiting2fa(): bool
    {
        return $this->session !== null && (int) ($this->session['pending_2fa'] ?? 0) === 1;
    }

    public function userId(): int
    {
        return (int) ($this->session['user_id'] ?? 0);
    }

    public function username(): string
    {
        return (string) ($this->session['username'] ?? '');
    }

    public function displayName(): string
    {
        $name = (string) ($this->session['username'] ?? '');

        return $name !== '' ? $name : $this->username();
    }

    public function role(): string
    {
        return (string) ($this->session['role'] ?? '');
    }

    public function mustChangePassword(): bool
    {
        return (int) ($this->session['must_change_password'] ?? 0) === 1;
    }

    public function can(string $permission): bool
    {
        return $this->session !== null && Permissions::roleHas($this->role(), $permission);
    }

    /**
     * Issues a fresh CSRF token matching the current session id
     *
     * Must be called every time a controller changes `sessionId` itself (login, 2FA
     * confirmation, password change) — the token middleware issued at the start of
     * the request is bound to the **old** session. Skip this and the response hands
     * the SPA a token that no longer works, and the very next request gets a 419.
     *
     * ## `'guest'` is a decision, not something left over
     *
     * Every visitor with no session yet gets the same token, because it's
     * `HMAC(secret, "guest|session")`, which is constant. **This isn't a hole** —
     * the only thing that token is good for is a "log in" request, and that already
     * has to carry a valid username and password in the request body itself. CSRF
     * exists to stop someone else from spending authority the browser is already
     * holding — before login there's no authority yet to spend.
     *
     * What it does guard against for real is **login CSRF** (an attacker silently
     * forcing a victim to log into an account *the attacker* controls, then watching
     * what the victim does next) — that's still stopped, because a page firing the
     * request cross-site has no way to read the token back out of our response (no
     * CORS allows it), even though the value itself is guessable.
     *
     * The alternative would be issuing an empty session to everyone who opens a page
     * just so the token differs per visitor — a database row for every single page
     * view, bots included, a cost that doesn't earn its keep.
     */
    public function refreshCsrfToken(): void
    {
        $this->csrfToken = (new Csrf($this->app->config->secretKey()))->token(
            $this->sessionId !== '' ? $this->sessionId : 'guest',
            Csrf::SCOPE,
        );
    }

    /** The actor to send to the agent — permission is recomputed from the role on that side */
    public function actor(Request $request): Actor
    {
        return new Actor(
            userId: $this->userId(),
            username: $this->username(),
            role: $this->role(),
            ip: $request->ip,
            requestId: $request->requestId,
        );
    }
}
