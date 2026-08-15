<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * Reads the tail of a log file — read-only
 *
 * The most important safety property of this capability: the caller names a "key",
 * never a "file path". The real path comes from LogCatalog, a fixed allowlist in
 * code, so there is no way to read a file outside that list no matter what kind of
 * traversal is attempted.
 *
 * A key shaped `site:<id>:<kind>` reads one website's log. The path comes from
 * `Site`, which derives it from the owner's home, so the caller still can't name a
 * path directly — the only thing they choose is **the site id**, and ownership of
 * that is re-checked here.
 *
 * Reads backward from the end of the file rather than loading the whole thing into
 * memory — a multi-gigabyte log can be opened without bringing the machine down.
 */
final class LogTail implements Capability
{
    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 2000;
    private const CHUNK = 8192;

    /** Never scans further back than this — guards against a file that's one enormous line */
    private const MAX_SCAN_BYTES = 4_194_304;

    public static function name(): string
    {
        return 'system.logs_tail';
    }

    public function permission(): string
    {
        // Per-source permission is re-checked more precisely in validate()
        return 'log.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read the tail of a log file';
    }

    public function validate(array $args): array
    {
        $source = Validator::requireString($args, 'source', 32);

        // Only the *shape* of the key can be checked here — this layer has no
        // database to ask whether the site exists or who owns it. Both happen in
        // run(), which has a Context.
        if (!LogCatalog::has($source) && LogCatalog::parseSiteKey($source) === null) {
            throw new ValidationError("Unknown log source: {$source}");
        }

        $lines = isset($args['lines'])
            ? Validator::requireInt($args, 'lines', 10, self::MAX_LINES)
            : self::DEFAULT_LINES;

        // The search term is plain text, not a regex — a user can't hang the system
        // with catastrophic backtracking, and there's no dangerous pattern to worry about
        $search = Validator::optionalString($args, 'search', max: 200);

        $level = Validator::optionalString($args, 'level', max: 16);
        if ($level !== '' && !in_array($level, LogCatalog::levels(), true)) {
            throw new ValidationError('Invalid log level');
        }

        return [
            'source' => $source,
            'lines' => $lines,
            'search' => $search,
            'level' => $level,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        ['label' => $label, 'format' => $format, 'path' => $path]
            = $this->resolve((string) $args['source'], $executor, $context);

        if (!$executor->exists($path)) {
            return [
                'source' => $args['source'],
                'label' => $label,
                'path' => $path,
                'exists' => false,
                'lines' => [],
                'total' => 0,
                'size_bytes' => 0,
                'message' => 'This log file does not exist on the machine yet',
            ];
        }

        $raw = $this->tail($path, $args['lines']);
        $filtered = $this->filter($raw, $args['search'], $args['level']);

        return [
            'source' => $args['source'],
            'label' => $label,
            'path' => $path,
            'format' => $format,
            'exists' => true,
            'lines' => $filtered,
            'total' => count($filtered),
            'scanned' => count($raw),
            'size_bytes' => (int) (@filesize($path) ?: 0),
            'read_at' => time(),
        ];
    }

    /**
     * Turns a key into a real file path, checking that source's permission on the way
     *
     * The only place a key becomes a path — every branch ends at a constant in code,
     * or at a path `Site` derives from a domain that already passed the validator. No
     * branch ever accepts a path from the caller.
     *
     * @return array{label:string,format:string,path:string}
     */
    private function resolve(string $key, Executor $executor, Context $context): array
    {
        $siteRef = LogCatalog::parseSiteKey($key);

        if ($siteRef !== null) {
            return $this->siteLog($siteRef['site_id'], $siteRef['kind'], $executor, $context);
        }

        $source = LogCatalog::get($key);
        // A finer-grained permission than the capability's own — the audit log needs
        // audit.view, not just log.view
        if (!$context->actor->can($source['permission'])) {
            throw new PermissionDenied('You do not have permission to read this log');
        }

        // The panel's own logs live somewhere else depending on the layout, so ask
        // Paths rather than use a constant. System logs use the catalog path and let
        // the executor map it for the current mode.
        return [
            'label' => $source['label'],
            'format' => $source['format'],
            'path' => isset($source['panel_log'])
                ? $context->config->paths->logFile($source['panel_log'])
                : $executor->path($source['path']),
        ];
    }

    /**
     * One website's log
     *
     * Ownership is re-checked at this layer even though the web tier already
     * filtered the list — the agent must not trust its caller. Skip this and a
     * website admin could guess an id and read another customer's access log on the
     * whole machine, visitor IPs and requested URLs included.
     *
     * @return array{label:string,format:string,path:string}
     */
    private function siteLog(int $siteId, string $kind, Executor $executor, Context $context): array
    {
        $actor = $context->actor;

        if (!$actor->can(LogCatalog::SITE_PERMISSION)) {
            throw new PermissionDenied('You do not have permission to read this log');
        }

        $sites = new SiteRepository($context->db);

        if ($actor->userId !== 0
            && !Permissions::seesAllSites($actor->role)
            && !$sites->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('You do not have permission to read this website\'s logs');
        }

        $site = $sites->load($siteId);

        if ($site === null) {
            throw new ValidationError('Website not found');
        }

        $meta = LogCatalog::siteKinds()[$kind];

        $path = match ($kind) {
            'access' => $site->accessLog(),
            'error' => $site->errorLog(),
            'php' => $site->phpErrorLog(),
            // A kind newly added to siteKinds() with no path wired up yet — fail loudly, not quietly
            default => throw new ValidationError("Log kind not supported yet: {$kind}"),
        };

        return [
            'label' => $site->domain.' · '.$meta['label'],
            'format' => $meta['format'],
            // The path is always logical — the executor maps it into the current sandbox mode's prefix
            'path' => $executor->path($path),
        ];
    }

    /**
     * Reads the last n lines by walking backward from the end of the file
     *
     * @return list<string>
     */
    private function tail(string $path, int $wanted): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            if ($position === false || $position === 0) {
                return [];
            }

            $floor = max(0, $position - self::MAX_SCAN_BYTES);
            $buffer = '';
            $newlines = 0;

            while ($position > $floor && $newlines <= $wanted) {
                $read = (int) min(self::CHUNK, $position - $floor);
                $position -= $read;

                fseek($handle, $position, SEEK_SET);
                $chunk = (string) fread($handle, $read);

                $buffer = $chunk . $buffer;
                $newlines = substr_count($buffer, "\n");
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\R/', rtrim($buffer, "\r\n")) ?: [];

        // Drop the first line if the read landed mid-line (started partway through one)
        if ($position > 0 && count($lines) > 1) {
            array_shift($lines);
        }

        return array_slice($lines, -$wanted);
    }

    /**
     * @param list<string> $lines
     * @return list<array{n:int,level:string,text:string}>
     */
    private function filter(array $lines, string $search, string $level): array
    {
        $out = [];
        $number = 0;

        foreach ($lines as $line) {
            $number++;

            if ($line === '') {
                continue;
            }

            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }

            $lineLevel = LogCatalog::levelOf($line);

            if ($level !== '' && $lineLevel !== $level) {
                continue;
            }

            $out[] = [
                'n' => $number,
                'level' => $lineLevel,
                // Trim abnormally long lines so the page doesn't freeze because one
                // log line is megabytes long
                'text' => mb_substr($line, 0, 2000),
            ];
        }

        return $out;
    }
}
