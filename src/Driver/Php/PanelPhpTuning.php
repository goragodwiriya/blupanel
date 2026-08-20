<?php

declare(strict_types=1);

namespace Phpcp\Driver\Php;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\PhpSettings;
use Phpcp\Kernel\Config;

/**
 * Changes the PHP values of **the panel's own** pool, in the file the installer wrote
 *
 * ## Why this patches lines instead of re-rendering the template
 *
 * A customer's pool file is generated from `templates/fpm/pool.conf.tpl` every
 * time, because everything in it is derived from the database. The panel's
 * `panel.conf` is not: the installer fills it with values only the installer
 * knows (which PHP binary this machine has, which user the panel runs as, where
 * Apache keeps its modules). Re-rendering it from the agent would mean
 * re-deriving all of that, and getting any one of them wrong takes the control
 * panel itself offline — the one page an admin would use to undo the mistake.
 *
 * So this reads the file that is genuinely on disk and rewrites only the
 * directives this screen owns. Everything else in it is carried through
 * untouched, whatever the installer decided.
 *
 * ## Why the installer's copy still wins on update, and why that is handled
 *
 * `install.sh` rewrites `panel.conf` from the template on every update
 * ("the panel's own config tree — regenerated every time, because these files
 * belong to the installer"). That resets these values to the template's
 * defaults, so the installer calls `phpcp panel:php-apply` after migrating,
 * which puts the admin's stored values back. The settings table is the source of
 * truth; the file is a rendering of it.
 *
 * ## The Apache half
 *
 * `LimitRequestBody` in the panel's `httpd.conf` is rewritten in the same
 * command, because Apache refuses an over-sized body **before** PHP ever sees
 * the request — raising `post_max_size` alone would change nothing, and the 413
 * that comes back has no matching line in any PHP log to explain it.
 */
final class PanelPhpTuning
{
    /** The panel's own pool, relative to the config tree root */
    public const POOL_FILE = '/fpm/pool.d/panel.conf';

    /** The panel's own Apache config, relative to the same root */
    public const HTTPD_FILE = '/httpd/httpd.conf';

    /** The unit that has to re-read the pool file — reload, never restart (see reload()) */
    public const FPM_UNIT = 'phpcp-fpm';

    /** The panel's own Apache */
    public const HTTPD_UNIT = 'phpcp-web';

    /** Where the installer writes the unit whose ExecStart names the FPM binary in use */
    private const FPM_UNIT_FILE = '/etc/systemd/system/phpcp-fpm.service';

    public static function poolPath(Config $config): string
    {
        return rtrim($config->paths->etc, '/') . self::POOL_FILE;
    }

    public static function httpdPath(Config $config): string
    {
        return rtrim($config->paths->etc, '/') . self::HTTPD_FILE;
    }

    /**
     * The pool file with this screen's directives rewritten, everything else kept
     *
     * `pm.max_children` is rewritten too — for the panel it is the number of
     * admin requests that can be in flight at once, and an SSE stream holds one
     * of those slots for as long as the tab is open.
     *
     * **`request_terminate_timeout` is deliberately not touched here**, unlike in
     * a customer's pool. The panel's is `0` on purpose: a live log stream is a
     * request that is *supposed* to stay open for half an hour, and a terminate
     * timeout would cut every one of them at the same mark.
     */
    public static function applyToPool(string $conf, PhpSettings $php): string
    {
        $result = $conf;
        $appended = [];
        $directives = $php->iniDirectives();

        foreach ($directives as $ini => $value) {
            $prefix = PhpSettings::isFlag($ini) ? 'php_admin_flag' : 'php_admin_value';
            $line = sprintf('%s[%s] = %s', $prefix, $ini, $value);
            [$result, $found] = self::replaceDirective($result, $ini, $line);

            if (!$found) {
                $appended[] = $line;
            }
        }

        /*
         * A directive that is set to its "leave it alone" value has to be
         * genuinely removed, not written as an empty value — `date.timezone =`
         * with nothing after it makes every date call in the panel warn, which
         * is worse than the setting never having existed
         */
        foreach (array_keys(PhpSettings::FIELDS) as $field) {
            $ini = PhpSettings::FIELDS[$field]['ini'];

            if ($ini !== '' && !array_key_exists($ini, $directives)) {
                $result = self::removeDirective($result, $ini);
            }
        }

        $result = self::replacePm($result, $php->maxChildren);

        if ($appended !== []) {
            $result = rtrim($result, "\n") . "\n\n"
                . "; ค่าที่ผู้ดูแลตั้งจากหน้าตั้งค่าของ panel — เพิ่มเข้ามาเพราะไฟล์เดิมยังไม่มีบรรทัดเหล่านี้\n"
                . implode("\n", $appended) . "\n";
        }

        return $result;
    }

    /**
     * The panel's Apache config with its body ceiling matched to `post_max_size`
     *
     * Apache counts this in bytes and holds it in a signed 32-bit value, which is
     * why {@see PhpSettings} caps the megabyte fields at 2048 — a larger number
     * here would not mean what it says.
     */
    public static function applyToHttpd(string $conf, PhpSettings $php): string
    {
        $bytes = $php->bodyLimitMb() * 1048576;
        $replaced = preg_replace(
            '/^(\h*)LimitRequestBody\h+\d+\h*$/mi',
            '${1}LimitRequestBody ' . $bytes,
            $conf,
            -1,
            $count,
        );

        if ($replaced === null) {
            return $conf;
        }

        if ($count === 0) {
            // Missing entirely (a config from before this existed) — appended at
            // the server level, which is a context Apache accepts for this
            // directive and which covers the panel's single vhost
            return rtrim($replaced, "\n") . "\n\n"
                . "# เพดานขนาด body ของคำขอ — ต้องไม่น้อยกว่า post_max_size ของ PHP\n"
                . 'LimitRequestBody ' . $bytes . "\n";
        }

        return $replaced;
    }

    /**
     * php-fpm's own validator, run against the panel's config tree
     *
     * Uses the binary named in the unit file rather than guessing, because a
     * machine can have several php-fpm versions installed and validating with
     * the wrong one proves nothing about the one that will actually read this file.
     *
     * @return array{0:bool,1:string}
     */
    public static function checkConfig(Executor $executor, Config $config): array
    {
        $binary = self::fpmBinary($executor);
        $main = rtrim($config->paths->etc, '/') . '/fpm/php-fpm.conf';

        if ($binary === null || !$executor->exists($executor->path($main))) {
            // Nothing to validate with = "not validated", never "validated and
            // passed" · said out loud instead of silently returning success
            return [true, 'Skipped pool validation: the panel php-fpm binary or its config was not found on this machine'];
        }

        $result = $executor->exec([$binary, '-t', '-y', $executor->path($main)], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), trim($output)];
    }

    /**
     * Graceful reload of both halves — never a restart
     *
     * The request being answered right now is the one from the admin who just
     * clicked save. A restart would drop it, and the screen would report a
     * failure for a change that actually succeeded.
     *
     * @return list<string> the units that were genuinely reloaded
     */
    public static function reload(Executor $executor): array
    {
        $reloaded = [];

        foreach ([self::FPM_UNIT, self::HTTPD_UNIT] as $unit) {
            $result = $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'reload', $unit],
                timeout: 30,
            );

            if ($result->ok()) {
                $reloaded[] = $unit;
            }
        }

        return $reloaded;
    }

    /** The php-fpm binary the panel genuinely runs, read from its unit file */
    private static function fpmBinary(Executor $executor): ?string
    {
        $unit = $executor->path(self::FPM_UNIT_FILE);

        if ($executor->exists($unit)) {
            $contents = $executor->readFile($unit);

            if (preg_match('~^ExecStart=(/\S*php-fpm[0-9.]*)~m', $contents, $m) === 1
                && $executor->exists($executor->path($m[1]))) {
                return $m[1];
            }
        }

        // A portable/dev install has no unit file — fall back to whatever the
        // machine has, which is still the right binary in the single-version case
        foreach (glob($executor->path('/usr/sbin') . '/php-fpm*') ?: [] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Rewrites one directive wherever it appears, whatever form it was written in
     *
     * Matches `php_value`/`php_flag` as well as the `php_admin_*` forms, so a
     * file that once had a non-admin version of the directive ends up with one
     * line, not two that contradict each other.
     *
     * @return array{0:string,1:bool} the new text, and whether anything matched
     */
    private static function replaceDirective(string $conf, string $ini, string $line): array
    {
        $pattern = '/^\h*php_(?:admin_)?(?:value|flag)\[' . preg_quote($ini, '/') . '\]\h*=.*$/m';
        $first = true;

        $result = preg_replace_callback(
            $pattern,
            static function () use ($line, &$first): string {
                if ($first) {
                    $first = false;

                    return $line;
                }

                // A duplicate of the same directive is dropped rather than
                // repeated — FPM takes the last one, so leaving duplicates would
                // mean the file says one thing and the process does another
                return '';
            },
            $conf,
            -1,
            $count,
        );

        if ($result === null) {
            return [$conf, false];
        }

        return [self::squeezeBlankLines($result), $count > 0];
    }

    private static function removeDirective(string $conf, string $ini): string
    {
        $pattern = '/^\h*php_(?:admin_)?(?:value|flag)\[' . preg_quote($ini, '/') . '\]\h*=.*\R/m';
        $result = preg_replace($pattern, '', $conf);

        return $result ?? $conf;
    }

    private static function replacePm(string $conf, int $maxChildren): string
    {
        $result = preg_replace(
            '/^(\h*pm\.max_children\h*=\h*)\d+\h*$/m',
            '${1}' . $maxChildren,
            $conf,
            -1,
            $count,
        );

        if ($result === null) {
            return $conf;
        }

        return $count > 0
            ? $result
            : rtrim($result, "\n") . "\npm.max_children = " . $maxChildren . "\n";
    }

    /** Removing a line leaves the blank it sat on — three blank lines in a row is a file nobody wants to read */
    private static function squeezeBlankLines(string $conf): string
    {
        return (string) preg_replace('/\R{3,}/', "\n\n", $conf);
    }
}
