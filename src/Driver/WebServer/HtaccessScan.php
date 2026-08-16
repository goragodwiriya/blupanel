<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;

/**
 * Decides how much of this site's static files nginx can safely serve
 * directly, without silently dropping any `.htaccess` rule
 *
 * **The problem this solves:** serving static files itself is the main
 * reason nginx sits in front of Apache at all, but `.htaccess` can also
 * control static files (`Require all denied`, `AuthType Basic`, a
 * `<FilesMatch>` denial, `Header set`) — if nginx serves those files
 * directly, those rules get silently skipped entirely — a folder a
 * customer believes is protected becomes open to everyone with nothing telling them.
 *
 * **How the decision is made** — separates two very different cases:
 *
 *   1. An `.htaccess` containing only **rewrite rules** (WordPress, Laravel,
 *      CodeIgniter routing every request that doesn't match a real file
 *      into index.php) — these rules only ever take effect when the file
 *      **doesn't actually exist**, so letting nginx serve a file that
 *      genuinely exists changes nothing about the outcome · **safe**.
 *   2. An `.htaccess` containing an **access-control or response-modifying
 *      rule** — must be served by Apache only, since nginx has no idea
 *      those rules exist.
 *
 * A subfolder with the second kind of `.htaccess` is forced through Apache
 * per-location, while the rest of the site still gets nginx's full speed.
 *
 * **Deliberately errs on the side of suspicion** — an unrecognized
 * directive is treated as unsafe, because a wrong guess in that direction
 * only makes things slower, while a wrong guess in the other direction leaks a customer's data.
 */
final class HtaccessScan
{
    /** Never descends past this — a site with very deeply nested folders shouldn't slow down writing the vhost */
    private const MAX_DEPTH = 3;

    /** The maximum number of folders allowed to be scanned — guards against a site with tens of thousands of them */
    private const MAX_DIRS = 500;

    /**
     * Directives that mean "files in this folder have to go through Apache"
     *
     * Covers access denial (Require/Deny/Allow), password prompts (Auth*),
     * per-file scoping (<Files>/<FilesMatch>), and response header changes
     * (Header/Expires/AddType) — all of which directly change the outcome
     * for a static file.
     */
    private const UNSAFE_DIRECTIVES = [
        'require', 'deny', 'allow', 'satisfy',
        'authtype', 'authname', 'authuserfile', 'authbasicprovider',
        '<files', '<filesmatch', '<directory', '<location', '<limit',
        'header', 'expiresbytype', 'expiresdefault', 'expiresactive',
        'addtype', 'addhandler', 'sethandler', 'forcetype',
        'errordocument', 'options', 'redirectmatch', 'redirect',
    ];

    /**
     * @return array{static_ok:bool,proxy_dirs:list<string>}
     *   static_ok  — can nginx serve this site's static files itself?
     *   proxy_dirs — the URL paths of folders that must be forced through Apache
     */
    public static function inspect(Executor $executor, string $docroot): array
    {
        $root = $executor->path($docroot);

        if (!$executor->exists($root)) {
            // No website files exist yet (just created) — safe to let nginx serve static files
            // The next time the vhost is written, it will be scanned again anyway
            return ['static_ok' => true, 'proxy_dirs' => []];
        }

        $rootFile = $root . '/.htaccess';
        $staticOk = !$executor->exists($rootFile) || self::isRewriteOnly($executor->readFile($rootFile));

        return [
            'static_ok' => $staticOk,
            'proxy_dirs' => $staticOk ? self::scan($executor, $root, '', 1) : [],
        ];
    }

    /**
     * Does this file contain only rules that don't affect a static file that genuinely exists?
     *
     * Only accepts lines confidently known to be harmless — blank lines,
     * comments, an `<IfModule mod_rewrite.c>` block, and the entire
     * Rewrite* family.
     */
    public static function isRewriteOnly(string $contents): bool
    {
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $lower = strtolower($line);

            // An IfModule block for mod_rewrite, and its closing tag, are treated as having no effect on their own
            if (str_starts_with($lower, '<ifmodule mod_rewrite')
                || str_starts_with($lower, '</ifmodule')
                || str_starts_with($lower, 'rewrite')
                || str_starts_with($lower, 'directoryindex')) {
                continue;
            }

            foreach (self::UNSAFE_DIRECTIVES as $directive) {
                if (str_starts_with($lower, $directive)) {
                    return false;
                }
            }

            // Unrecognized directive — err on the side of suspicion, let Apache serve the whole thing
            return false;
        }

        return true;
    }

    /**
     * Walks subfolders looking for an `.htaccess` that must go through Apache
     *
     * @return list<string> URL-style paths, starting and ending with /
     */
    private static function scan(Executor $executor, string $absolute, string $urlPath, int $depth): array
    {
        static $seen = 0;

        if ($depth === 1) {
            $seen = 0;
        }

        if ($depth > self::MAX_DEPTH || $seen >= self::MAX_DIRS) {
            return [];
        }

        $found = [];

        foreach ($executor->listDirectory($absolute) as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            $isDir = is_array($entry) ? (($entry['type'] ?? '') === 'dir') : false;

            if ($name === '' || !$isDir || str_starts_with($name, '.')) {
                continue;
            }

            $seen++;
            $childUrl = $urlPath . '/' . $name;
            $childAbs = $absolute . '/' . $name;

            if ($executor->exists($childAbs . '/.htaccess')
                && !self::isRewriteOnly($executor->readFile($childAbs . '/.htaccess'))) {
                // Found a rule nginx can't stand in for — send the whole folder to Apache and stop descending further
                $found[] = $childUrl . '/';
                continue;
            }

            $found = [...$found, ...self::scan($executor, $childAbs, $childUrl, $depth + 1)];
        }

        return $found;
    }
}
