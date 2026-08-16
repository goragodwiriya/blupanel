<?php

declare(strict_types=1);

namespace Phpcp\Http;

use Phpcp\Controller\Controller;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The SPA's shell sender — PLAN-V2 §3.1, phase C1
 *
 * Does exactly one thing: **sends the `public/assets/spa/index.html` file
 * exactly as it is** — no HTML assembly, no values substituted into it · the
 * entire page's content comes from REST API v2 on the browser side, so this
 * doesn't conflict with the "no HTML generated from PHP" rule phase D will enforce
 *
 * **Why this still goes through PHP even though it's a static file:** the
 * SPA's router uses history mode, so a route like `/app/sites` has no file
 * that genuinely exists on disk · leaving it to Apache alone would mean
 * refreshing the page or opening a direct link gets an instant 404 · a side
 * benefit is the shell gets the same security headers as every other panel
 * response (CSP, HSTS, X-Frame-Options) from `SecurityHeaders`, which a file
 * Apache serves directly would never get
 *
 * **Why the real file lives at `public/assets/spa/`, not `public/app/`:**
 * Apache's `FallbackResource` **skips a URL that points at a file or
 * directory that genuinely exists** — if a `public/app/` directory existed,
 * `mod_dir` would step in first, answering with a 301 from `/app` to `/app/`
 * and then looking for a DirectoryIndex in it, finding none, and ending in
 * Apache's own 404 **without PHP ever seeing that request** · keeping the
 * file's storage location separate from the screen's URL is the fix that
 * needs zero changes to Apache's config
 *
 * Deliberately a public route — the shell carries no user data at all, not
 * even a username · the decision of whether to show the login page or the
 * main app happens at `GET /api/v2/session`, a route that still enforces full
 * permissions exactly as before
 */
final class SpaController extends Controller
{
    public function shell(Request $request): Response
    {
        $file = $this->app->config->paths->spa().'/index.html';
        $html = is_file($file) ? file_get_contents($file) : false;

        if ($html === false) {
            // Answers as plain text, not a pretty HTML page — if the shell file
            // is missing, that means the install is incomplete, and hiding it
            // behind a normal-looking page would make the cause harder to find
            return Response::text($this->app->t('The panel web page file could not be found — check with `phpcp doctor`'), 500);
        }

        return Response::html($html);
    }

    /**
     * The domain root → the app
     *
     * Redirects instead of sending the shell directly, so the whole system has
     * one single URL per screen · if the shell also answered at `/`, the
     * dashboard page would have two genuinely working addresses, and the
     * SPA's own router (base = `/app`) would rewrite the URL to `/app/`
     * the moment it starts running anyway
     */
    public function root(Request $request): Response
    {
        return Response::redirect('/app/');
    }
}
