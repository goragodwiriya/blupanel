<?php

declare (strict_types = 1);

namespace Phpcp\Support;

use Phpcp\Agent\ValidationError;

/**
 * The argument validator every capability shares
 *
 * Every method throws a ValidationError with a message ready to show the
 * user immediately, and rejects a malformed value at the source — there's no
 * "try to fix it up," which is exactly where vulnerabilities come from
 */
final class Validator
{
    /** @param array<string,mixed> $args */
    public static function requireString(array $args, string $key, int $max = 255): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ValidationError("{$key} is required");
        }
        if (str_contains($value, "\0")) {
            throw new ValidationError("{$key} contains a character that isn't allowed");
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationError("{$key} is longer than {$max} characters");
        }

        return $value;
    }

    /** @param array<string,mixed> $args */
    public static function optionalString(array $args, string $key, string $default = '', int $max = 255): string
    {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return $default;
        }

        return self::requireString($args, $key, $max);
    }

    /** @param array<string,mixed> $args */
    public static function requireInt(array $args, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $args[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new ValidationError("{$key} must be a number");
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new ValidationError("{$key} must be between {$min} and {$max}");
        }

        return $int;
    }

    /**
     * A numeric value that's "not sent at all" is different from "sent as 0"
     * — used for commands that update a value partially
     *
     * Has to be separate from optionalInt() because optionalInt() forces the
     * default value to be an int — passing null in as the default died with a
     * TypeError immediately on call, a real bug in customer.quota_update that
     * nobody had ever hit because nobody had ever called that capability
     *
     * @param array<string,mixed> $args
     */
    public static function nullableInt(
        array $args,
        string $key,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
    ): ?int {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return null;
        }

        return self::requireInt($args, $key, $min, $max);
    }

    /** @param array<string,mixed> $args */
    public static function optionalInt(
        array $args,
        string $key,
        int $default,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
    ): int {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            return $default;
        }

        return self::requireInt($args, $key, $min, $max);
    }

    /**
     * @param array<string,mixed> $args
     * @param list<string> $allowed
     */
    public static function requireEnum(array $args, string $key, array $allowed): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationError("{$key} must be one of: ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * A boolean value from a form or JSON
     *
     * Has to accept several shapes because callers come from different
     * places: JSON sends a genuine `true`, an HTML form sends `"1"` or
     * `"on"`, and a query string sends `"true"` as text
     *
     * **An unrecognized value is treated as false, never thrown as an
     * error** — deliberately different from enum, because a checkbox that's
     * left unchecked is **never sent at all** — requiring a value to always
     * be sent would break every ordinary form
     *
     * @param array<string,mixed> $args
     */
    public static function requireBool(array $args, string $key): bool
    {
        return in_array($args[$key] ?? null, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * Like {@see requireEnum} but allows the value to be left out, falling back to a default
     *
     * A value that's sent but isn't in the list is still rejected the same
     * as before — "not sent" and "sent something wrong" are different
     * things, and the latter is the caller's mistake, which must be reported
     *
     * @param array<string,mixed> $args
     * @param list<string> $allowed
     */
    public static function optionalEnum(array $args, string $key, array $allowed, string $default): string
    {
        $value = $args[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return self::requireEnum($args, $key, $allowed);
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    public static function requireStringList(array $args, string $key, int $maxItems = 64, int $maxLength = 255): array
    {
        $value = $args[$key] ?? null;
        if (!is_array($value)) {
            throw new ValidationError("{$key} must be a list");
        }
        if (count($value) > $maxItems) {
            throw new ValidationError("{$key} has more than {$maxItems} items");
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || str_contains($item, "\0")) {
                throw new ValidationError("{$key} has an invalid item");
            }
            if (mb_strlen($item) > $maxLength) {
                throw new ValidationError("{$key} has an item that's too long");
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param string $value
     * @param string $regex
     * @param string $message
     * @return mixed
     */
    public static function pattern(string $value, string $regex, string $message): string
    {
        if (preg_match($regex, $value) !== 1) {
            throw new ValidationError($message);
        }

        return $value;
    }

    /** A domain name per RFC 1123 — always used before assembling it into a vhost filename */
    public static function domain(string $value): string
    {
        $value = strtolower(trim($value));

        if (mb_strlen($value) > 253) {
            throw new ValidationError('The domain name is too long');
        }

        if (preg_match(self::DOMAIN_PATTERN, $value) === 1) {
            return $value;
        }

        throw new ValidationError(self::domainFault($value));
    }

    /** A domain name per RFC 1123 — the one rule, kept in one place so the reason below can't drift from it */
    private const DOMAIN_PATTERN = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    /**
     * **Why** a name was refused — never just "Invalid domain name format"
     *
     * That message was all this validator ever said, and it is unusable on the
     * screen it is read from: the website form has a Domain box and an Aliases
     * box, both of which land here, so the message named neither the box nor
     * the value · an admin who pasted `http://gcms.test` was told their domain
     * was invalid, looked at a box reading `gcms.test`, and had nothing to go on
     * (seen on this machine, 2026-08-21).
     *
     * Every branch returns a **fixed sentence with no value spliced into it** —
     * a message assembled with the value in it would never match a key in
     * `th.json` and would go out in English, which is the one thing the
     * catalogue exists to prevent · the reason itself is what makes the value
     * obvious, and the value is already on screen in the box.
     */
    private static function domainFault(string $value): string
    {
        if ($value === '') {
            return 'A domain name is required';
        }

        // Checked first, because it is the one that catches a pasted URL, a
        // port number, a stray space, and an invisible character copied along
        // with the name — by far the most common of these in practice
        if (preg_match('/[^a-z0-9.-]/', $value) === 1) {
            return 'A domain name may only contain the letters a-z, digits, - and . — a scheme like http://, '
                . 'a port number, a path, a space or an invisible character copied along with it is not part of the name';
        }

        if (str_starts_with($value, '.') || str_ends_with($value, '.')) {
            return 'A domain name must not start or end with a dot';
        }

        if (!str_contains($value, '.')) {
            return 'A domain name needs at least one dot, for example example.com';
        }

        foreach (explode('.', $value) as $label) {
            if ($label === '') {
                return 'A domain name must not have two dots in a row';
            }

            if (mb_strlen($label) > 63) {
                return 'Each part of a domain name must be 63 characters or fewer';
            }

            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return 'Each part of a domain name must not start or end with -';
            }
        }

        return 'Invalid domain name format';
    }

    /**
     * A domain name that may be a wildcard (`*.example.com`) — PLAN-V2 phase E7
     *
     * `*` isn't a valid hostname character per RFC 1123, so it can't pass
     * {@see domain()} · this is its own method instead of loosening `domain()`'s
     * rule, because there are very few places that can accept a wildcard
     * (certificates and ServerAlias) — everywhere else, especially anywhere
     * that assembles a **vhost filename**, must never be able to get a `*` into it
     *
     * Only supports a single leading `*.`, matching what Let's Encrypt
     * issues · `*.*.example.com` or `www.*.example.com` don't actually exist in the certificate system
     */
    public static function wildcardDomain(string $value): string
    {
        $value = strtolower(trim($value));

        return str_starts_with($value, '*.')
            ? '*.' . self::domain(substr($value, 2))
            : self::domain($value);
    }

    /**
     * @param string $value
     * @return mixed
     */
    public static function ipAddress(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('Invalid IP format');
        }

        return $value;
    }

    /**
     * @param int $value
     * @return mixed
     */
    public static function port(int $value): int
    {
        if ($value < 1 || $value > 65535) {
            throw new ValidationError('The port number must be between 1 and 65535');
        }

        return $value;
    }

    /** A database/user identifier name — deliberately stricter than what MariaDB itself accepts */
    public static function identifier(string $value): string
    {
        return self::pattern(
            $value,
            '/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/',
            'The name must start with a letter, followed by letters, numbers, or _, up to 32 characters long'
        );
    }

    /**
     * @param string $value
     */
    public static function phpVersion(string $value): string
    {
        return self::pattern($value, '/^\d\.\d{1,2}$/', 'Invalid PHP version format');
    }

    /**
     * An absolute path safe to put into a web server config file
     *
     * Only checks the shape — restricting whether this path is actually
     * within an allowed boundary is a separate concern handled by
     * absolutePathWithin(), since the boundary comes from a config file
     *
     * Never allows `..`, since the path gets assembled into DocumentRoot and
     * open_basedir · never allows a character that could make a directive be
     * misinterpreted (trailing whitespace, newline, quote)
     */
    public static function absolutePath(string $value): string
    {
        // Must check before trim() — trim() strips a trailing \0 or \n on its
        // own, so a value with a null byte attached to the end of the path
        // would slip through if checked afterward
        if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $value) === 1) {
            throw new ValidationError('The path contains a character that cannot be used in a config file');
        }

        $value = rtrim(trim($value), '/');

        if ($value === '' || !str_starts_with($value, '/')) {
            throw new ValidationError('The path must be an absolute path starting with /');
        }

        if (mb_strlen($value) > 4096) {
            throw new ValidationError('The path is too long');
        }

        if (in_array('..', explode('/', $value), true)) {
            throw new ValidationError('The path must not contain ..');
        }

        return $value;
    }

    /**
     * An absolute path that must fall within one of the given boundaries
     *
     * Always compared with a trailing / marker, otherwise /srv/phpcp-evil
     * would pass while the boundary is /srv/phpcp
     *
     * @param list<string> $allowedRoots
     */
    public static function absolutePathWithin(string $value, array $allowedRoots): string
    {
        $path = self::absolutePath($value);

        foreach ($allowedRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && ($path === $root || str_starts_with($path, $root.'/'))) {
                return $path;
            }
        }

        throw new ValidationError(
            'The path '.$path.' is outside the allowed boundary — '.
            'add the parent folder on the settings page first, under the folders websites may be served from'
        );
    }

    /**
     * A subfolder name under a pointer root — must not start with / and must not contain ..
     *
     * Used with the Domain Pointer form, which only asks for the destination, e.g. my-project or shop/public
     */
    public static function relativePath(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f"\'\\\\]/', $value) === 1) {
            throw new ValidationError('The path contains a character that cannot be used in a config file');
        }

        $value = trim($value);
        $value = trim($value, '/');

        if ($value === '') {
            throw new ValidationError('A destination folder name is required');
        }

        if (mb_strlen($value) > 4096) {
            throw new ValidationError('The path is too long');
        }

        if (in_array('..', explode('/', $value), true) || in_array('.', explode('/', $value), true)) {
            throw new ValidationError('The path must not contain . or ..');
        }

        return $value;
    }

    /**
     * Converts a Domain Pointer form value into a safe absolute path
     *
     * Accepts either a full path (/mnt/.../shop) or a subfolder name (shop)
     * — a subfolder name gets joined with the selected pointer root, or the
     * only one present in the config
     *
     * @param list<string> $allowedRoots from Config::docrootRoots()
     * @param list<string> $pointerRoots from sites.pointer_roots
     */
    public static function resolvePointerDocroot(
        string $value,
        array $allowedRoots,
        array $pointerRoots,
        string $pointerRoot = '',
    ): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return self::absolutePathWithin($value, $allowedRoots);
        }

        $relative = self::relativePath($value);
        $roots = [];
        foreach ($pointerRoots as $root) {
            $root = rtrim(trim($root), '/');
            if ($root !== '' && str_starts_with($root, '/')) {
                $roots[] = $root;
            }
        }
        $roots = array_values(array_unique($roots));

        if ($roots === []) {
            /*
             * **Says where to set it, and says the one thing that bites**
             *
             * The old message named `sites.pointer_roots` in config.php — which on
             * a real install is `/etc/phpcp/config.php`, root-owned and 0640, a
             * file the panel cannot read, let alone offer to edit · so it pointed
             * at the one place the reader could not go.
             *
             * The restart clause is not padding. `bin/phpcp-agentd` builds `Config`
             * once and `Server` holds it for the daemon's whole life, while the web
             * tier rebuilds it every request — so a value added to config.php while
             * the agent is running makes the field **appear on the form** and then
             * fail on save with this very message (seen on this machine,
             * 2026-08-21) · the settings page has no such gap: `Server` re-reads
             * the settings table on every single request, deliberately.
             */
            throw new ValidationError(
                'No folder has been set that a website may be served from — add one on the settings page, '
                . 'under the folders websites may be served from · setting it in config.php instead also works, '
                . 'but only takes effect once the agent is restarted'
            );
        }

        $pointerRoot = rtrim(trim($pointerRoot), '/');
        if ($pointerRoot !== '') {
            if (!in_array($pointerRoot, $roots, true)) {
                throw new ValidationError(
                    'The parent folder that was chosen is no longer one of the folders websites may be served from',
                );
            }
            $base = $pointerRoot;
        } elseif (count($roots) === 1) {
            $base = $roots[0];
        } else {
            throw new ValidationError('There are multiple parent folders — which one to point from must be specified');
        }

        return self::absolutePathWithin($base.'/'.$relative, $allowedRoots);
    }
}
