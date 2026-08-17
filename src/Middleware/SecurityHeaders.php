<?php

declare(strict_types=1);

namespace Phpcp\Middleware;

use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Security headers — SECURITY §2.3
 *
 * The CSP has no unsafe-inline and no unsafe-eval at all, which is why the
 * whole system had to stop using inline onclick= and stop using the
 * Tailwind CDN that compiles CSS in the browser
 *
 * Runs as the outermost layer so the headers attach to every response, including error pages and 404s
 */
final class SecurityHeaders implements Middleware
{
    public function handle(Request $request, Ctx $ctx, callable $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'none'",
            "script-src 'self' 'nonce-{$ctx->nonce}'",
            "style-src 'self'",
            "img-src 'self' data:",
            // Audio/video in the file manager plays from a `data:` URI
            // assembled in the browser from the file content that `GET
            // /files/download` sends — it never points back at a server URL
            //
            // Has to be `data:`, not a direct URL, because the download
            // endpoint always deliberately sends `application/octet-stream` +
            // `attachment` (prevents stored XSS from a user's .html file),
            // which the browser can't play as media
            //
            // Without this line, media-src falls back to `default-src 'none'` and gets blocked silently
            "media-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "manifest-src 'self'",
        ]);

        $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader(
                'Permissions-Policy',
                'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
            );

        // HSTS is only sent when the request genuinely arrived over HTTPS — sending it over HTTP has no effect and makes local dev harder
        if ($request->isSecure()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The panel's pages hold sensitive data — never allowed to be cached by a proxy or the browser
        if (!str_starts_with($request->path, '/assets/')) {
            $response
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
                ->withHeader('Pragma', 'no-cache');
        }

        return $response;
    }
}
