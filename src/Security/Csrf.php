<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * A CSRF token bound to both the session and the form name
 *
 * Stateless: token = HMAC(session, form name), so nothing extra needs to be
 * stored in the database, and there's no issue with multiple tabs open where an older token stops working
 *
 * Binding to the form name means the "edit website" form's token can't be
 * reused on "delete website" — this prevents a token that leaked from a
 * low-risk page from being usable on a dangerous action
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';

    /**
     * The binding name shared across the whole system
     *
     * Lives here because there are two places that must generate an
     * identical token: the middleware that issues it, and the login
     * controller, which must issue a fresh one immediately after the session
     * id changes · if this value differed by even one character, every form would get 419 with nothing to explain why
     */
    public const SCOPE = 'session';

    public function __construct(private readonly string $secretKey)
    {
    }

    public function token(string $sessionId, string $form = 'default'): string
    {
        return hash_hmac('sha256', $sessionId . '|' . $form, $this->secretKey);
    }

    public function verify(string $sessionId, string $token, string $form = 'default'): bool
    {
        if ($token === '') {
            return false;
        }

        return hash_equals($this->token($sessionId, $form), $token);
    }
}
