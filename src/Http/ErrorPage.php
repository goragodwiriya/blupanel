<?php

declare(strict_types=1);

namespace Phpcp\Http;

use Phpcp\Kernel\Response;

/**
 * The HTML error page — the last resort when a request isn't JSON
 *
 * **Why this still needs to exist when everything else is the SPA:** a user
 * can type a URL by hand, and some middleware decides *before* the router
 * even knows what that route is (such as the rate limiter, which runs ahead
 * of everything) · those requests never carry `Accept: application/json` —
 * answering with a blob of JSON on the browser page would be useless to whoever's reading it
 *
 * Assembles HTML by hand instead of using a template engine — there are only
 * four call sites, and every one of them fires at a moment when the system
 * is already having a problem · the fewer layers that need to work correctly
 * here, the better · the CSS lives at `/assets/css/error.css`, the one file that depends on none of the SPA's bundle
 *
 * Never add `style="..."` — the panel's CSP blocks all inline styles (SECURITY §2.3)
 */
final class ErrorPage
{
    public static function response(int $status, string $title, string $message, bool $home = true): Response
    {
        return Response::html(self::html($status, $title, $message, $home), $status);
    }

    private static function html(int $status, string $title, string $message, bool $home): string
    {
        $safe = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="en"><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$safe($title).'</title>'
            .'<link rel="stylesheet" href="/assets/css/error.css">'
            .'<body><div class="error-card">'
            .'<div class="error-code">'.$status.'</div>'
            .'<h1 class="error-title">'.$safe($title).'</h1>'
            .'<p class="error-message">'.$safe($message).'</p>'
            .($home ? '<a class="error-link" href="/app/">Back to home</a>' : '')
            .'</div>';
    }
}
