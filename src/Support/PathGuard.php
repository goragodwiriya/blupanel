<?php

declare (strict_types = 1);

namespace Phpcp\Support;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * The single place in the system that decides whether a file path is safe
 *
 * Protection happens in two layers, and both must pass:
 *
 *  1. The shape layer (clean/join) — runs during a capability's validate(),
 *     before touching disk · rejects `..`, null bytes, control characters,
 *     absolute paths, and names longer than the filesystem's own limit
 *
 *  2. The truth layer (resolve) — runs after privileges have been dropped,
 *     comparing the path after resolving symlinks against the website's
 *     home · this layer is necessary because the first layer can't see a symlink that points outside the home
 *
 * Neither layer alone is enough: the first layer alone can be fooled by a
 * symlink, and the second layer alone would still let a bizarre name like a
 * newline or null byte flow down into the shell or the log
 */
final class PathGuard
{
    /** ext4/xfs's filename limit is 255 bytes, not 255 characters */
    private const MAX_SEGMENT_BYTES = 255;

    /** The maximum depth accepted — guards against a path long enough to hit the kernel's PATH_MAX */
    private const MAX_DEPTH = 32;

    /**
     * Validates and normalizes a relative path — returns '' when it means the website's root
     *
     * Accepts '', '/', 'public', '/public/index.php' alike, and returns 'public/index.php'
     *
     * @throws ValidationError when the path has a disallowed shape
     */
    public static function clean(string $path, string $label = 'The path'): string
    {
        if (str_contains($path, "\0")) {
            throw new ValidationError("{$label} contains a character that isn't allowed");
        }

        // Strips the leading / and always treats everything as relative to
        // the website's root — the user sees '/public' on screen, but the
        // system never interprets it as the machine's own root
        $path = trim($path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || $path === '.') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // `..` is rejected, never collapsed away — collapsing it would
            // turn '/a/../../b' into '/b', which hides the caller's real
            // intent and has been the source of traversal vulnerabilities in many systems
            if ($segment === '..') {
                throw new ValidationError("{$label} must not contain ..");
            }

            self::assertSegment($segment, $label);
            $parts[] = $segment;
        }

        if (count($parts) > self::MAX_DEPTH) {
            throw new ValidationError("{$label} is nested too deeply");
        }

        return implode('/', $parts);
    }

    /**
     * Validates a single file or folder name — must not contain a /
     *
     * @throws ValidationError
     */
    public static function name(string $name, string $label = 'The name'): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationError("{$label} is required");
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new ValidationError("{$label} must not contain a /");
        }
        if ($name === '.' || $name === '..') {
            throw new ValidationError("{$label} cannot be . or ..");
        }

        self::assertSegment($name, $label);

        return $name;
    }

    /** Joins a relative path that's already passed clean() onto a root */
    public static function join(string $root, string $relative): string
    {
        return $relative === '' ? $root : rtrim($root, '/').'/'.$relative;
    }

    /**
     * The parent path of a relative path — returns '' when already at the top level
     */
    public static function parent(string $relative): string
    {
        $at = strrpos($relative, '/');

        return $at === false ? '' : substr($relative, 0, $at);
    }

    /**
     * Confirms the real path, after resolving symlinks, is still under the allowed root
     *
     * Must only be called after privileges have already been dropped, since
     * realpath() only sees what that user can actually access
     *
     * @param bool $mustExist false when this is a destination about to be created — checks the parent directory instead
     * @throws ValidationError when the path escapes the root, or doesn't exist when it must
     */
    public static function resolve(Executor $executor, string $root, string $target, bool $mustExist = true): string
    {
        $realRoot = $executor->realPath($root);
        if ($realRoot === null) {
            throw new ValidationError('The website directory was not found');
        }

        $real = $executor->realPath($target);

        if ($real === null) {
            if ($mustExist) {
                throw new ValidationError('The specified file or folder was not found');
            }

            // The destination doesn't exist yet, so the parent directory —
            // which must already exist — is checked instead, then the path
            // is reassembled from that checked parent · the final name already passed clean() earlier
            $parentReal = $executor->realPath(dirname($target));
            if ($parentReal === null) {
                throw new ValidationError('The destination folder was not found');
            }

            self::assertInside($realRoot, $parentReal);

            return $parentReal.'/'.basename($target);
        }

        self::assertInside($realRoot, $real);

        return $real;
    }

    /** Verifies the symlink-resolved path is genuinely under the root */
    private static function assertInside(string $realRoot, string $real): void
    {
        $realRoot = rtrim($realRoot, '/');

        // Must compare against "$realRoot/", never bare "$realRoot" —
        // otherwise /srv/sites/example.com-evil would pass the check for /srv/sites/example.com
        if ($real !== $realRoot && !str_starts_with($real, $realRoot.'/')) {
            throw new ValidationError('The path is outside the website boundary');
        }
    }

    /** @throws ValidationError */
    private static function assertSegment(string $segment, string $label): void
    {
        if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
            throw new ValidationError("{$label} is longer than ".self::MAX_SEGMENT_BYTES.' bytes');
        }

        // A control character lets a filename disguise itself in a log or terminal, so the whole range is disallowed
        if (preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
            throw new ValidationError("{$label} contains a disallowed control character");
        }

        if (!mb_check_encoding($segment, 'UTF-8')) {
            throw new ValidationError("{$label} is not valid UTF-8 text");
        }
    }
}
