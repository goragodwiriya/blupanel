<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;

/**
 * One generated password, for a form that is about to ask for one
 *
 * ## Why this is a route and not three lines of JavaScript
 *
 * Every form in the panel that creates a credential now opens with a strong
 * password already filled in, and each one needs a way to ask for another.
 * Generating it in the browser would mean a second generator to keep in step
 * with {@see Password::random()} — and in step with `Password::problems()`,
 * which is the rule the server will judge the value against a moment later.
 * Two generators drifting apart shows up as a suggested password the server
 * then refuses, which is the worst possible moment to find out.
 *
 * ## It is not a secret in itself
 *
 * Nothing here is stored, bound to an account, or usable on its own — it is a
 * random string that only becomes a credential once a form is submitted with
 * it. The one requirement is that the caller is signed in, so the endpoint
 * can't be used as a public random-number service, hence `dashboard.view`,
 * the permission every role holds.
 */
final class PasswordsController extends ApiController
{
    public function suggest(Request $request): Response
    {
        return $this->ok(['password' => Password::random(self::suggestedLength())]);
    }

    /**
     * 20 characters, matching every generated password the panel already hands out
     *
     * Long enough that `Password::problems()`'s 12-character floor is never the
     * binding constraint, and short enough to still be read off a screen and
     * typed into a mail client on a phone — the place these actually get typed.
     */
    public static function suggestedLength(): int
    {
        return 20;
    }
}
