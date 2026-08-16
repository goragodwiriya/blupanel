<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Extracts a compressed archive into a folder — `.zip`, `.tar.gz`/`.tgz`/`.tar`, and a single `.gz` file
 *
 * ## Why more than zip has to be supported
 *
 * The system's own backup files are `.tar.gz` (and `.sql.gz` for a
 * database), which have lived in the customer's own home since
 * PLAN-BACKUP-V2 · if this couldn't extract those through the web page, a
 * customer who wants just one file back from yesterday's copy would have to
 * restore the entire site over the current one — using a sledgehammer to
 * kill a fly, and the single most dangerous command in the system.
 *
 * ## Blocking a path from climbing outside its folder
 *
 * zip: handled at the executor layer (`safeEntryPath`) — an entry name never passes through the capability's hands at all.
 *
 * tar: **the list of entries is checked before disk is ever touched**, via
 * `--list`, rejecting the whole file if any entry starts with `/` or
 * contains `..` · GNU tar already strips both of those on its own in
 * practice, but that's a behavior of a tool we don't control — our own gate
 * has to be our own (the same lesson as `--exclude backup.json` in
 * BackupManager::restoreSite).
 *
 * Rejects the whole file, not just skips the entry, because an archive with
 * an entry like that is a deliberately malicious archive, not a normal one
 * that happens to have one odd file mixed in.
 */
final class FileUnzip extends FileCapability
{
    private const TAR = '/usr/bin/tar';
    private const GZIP = '/usr/bin/gzip';

    /** The ceiling on entry count in a tar — the same one the executor uses for zip */
    private const MAX_ENTRIES = 10_000;

    /** Extensions that are tar (compressed or not) → tar reads its own kind from the file's content */
    private const TAR_SUFFIXES = ['.tar.gz', '.tgz', '.tar'];

    public static function name(): string
    {
        return 'file.unzip';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Extract a compressed archive (.zip, .tar.gz, .gz)';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('An archive to extract must be specified');
        }

        if (self::kindOf($base['path']) === null) {
            throw new ValidationError('Only .zip, .tar.gz, .tgz, .tar, and .gz files are supported');
        }

        return $base + [
            // Empty means extract into the same folder the archive itself is in
            'destination' => Validator::optionalString($args, 'destination') !== ''
                ? PathGuard::name((string) $args['destination'], 'ชื่อโฟลเดอร์ปลายทาง')
                : ''
        ];
    }

    /**
     * The file's kind, from its extension — null = cannot be extracted
     *
     * `.tar.gz` must always be checked before `.gz` — otherwise a website
     * backup file would be seen as "a single compressed file" and produce a
     * loose `.tar` instead of the site's folder coming back.
     */
    public static function kindOf(string $path): ?string
    {
        $name = mb_strtolower($path);

        if (str_ends_with($name, '.zip')) {
            return 'zip';
        }

        foreach (self::TAR_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return 'tar';
            }
        }

        return str_ends_with($name, '.gz') ? 'gz' : null;
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     * @return mixed
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $archive = $args['path'];
        $kind = self::kindOf($archive);
        $folder = PathGuard::parent($archive);

        // The size after extraction can't be known ahead of time — the only
        // check available is that quota isn't already full · still
        // necessary, since the tar path has no byte ceiling of its own the
        // way `RealExecutor::unzip()` does
        $this->assertQuotaAllows($context, $scope);

        /*
         * A single `.gz` file has no "destination folder" — it decompresses
         * into a file right next to itself (`x.sql.gz` → `x.sql`) · creating
         * a folder for it would produce a folder containing a single file,
         * which isn't what anyone expects from clicking "extract" on a dump file.
         */
        $destination = $kind === 'gz' || $args['destination'] === ''
            ? $folder
            : PathGuard::join($folder, $args['destination']);

        $result = $this->withSite($executor, $scope, function (callable $resolve) use ($executor, $archive, $destination, $kind): array {
            $source = $resolve($archive);

            if (($executor->stat($source)['type'] ?? '') !== 'file') {
                throw new ValidationError('The specified archive was not found');
            }

            // The destination folder is created first, then resolved again
            // — resolve needs a genuinely symlink-resolved path, which is
            // only possible once the folder already exists on disk
            $prepared = $resolve($destination, false);
            if ($executor->stat($prepared) === null) {
                $executor->makeDirectory($prepared, 0o750);
            }

            $target = $resolve($destination);

            return match ($kind) {
                'zip' => $executor->unzip($source, $target),
                'tar' => $this->extractTar($executor, $source, $target),
                default => $this->extractGz($executor, $source),
            };
        });

        $message = sprintf('Extracted %d entries', $result['entries']);
        if ($result['skipped'] > 0) {
            $message .= sprintf(' (skipped %d unsafe entries)', $result['skipped']);
        }

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'archive' => $archive,
            'destination' => $destination,
            'entries' => $result['entries'],
            'skipped' => $result['skipped'],
            'bytes' => $result['bytes'],
            'message' => $message
        ];
    }

    /**
     * Extracts a tar, after checking what's listed inside it
     *
     * `--no-same-owner`/`--no-same-permissions` are always included even
     * though this already runs under the customer's own privileges (see
     * `withSite`) — the ownership and permissions embedded in the archive
     * belong to **the machine it came from**, whose uid numbers don't match
     * this one · the same lesson `BackupManager::restoreSite()` learned,
     * needing to chown by hand after extracting.
     *
     * @return array{entries:int,skipped:int,bytes:int}
     */
    private function extractTar(Executor $executor, string $source, string $destination): array
    {
        if (!$executor->exists(self::TAR)) {
            throw new ExecutionFailed('This machine has no tar command, so this file type cannot be extracted');
        }

        $list = $executor->exec([self::TAR, '--list', '--file', $source], timeout: 120);

        if (!$list->ok()) {
            throw new ExecutionFailed('Failed to read the archive\'s entry list: ' . trim($list->stderr));
        }

        /*
         * **Rejects when the listing hits exec()'s own ceiling** — not just
         * checks whatever came back.
         *
         * `exec()` truncates its output at {@see Executor::MAX_OUTPUT_BYTES}
         * · the check below, which walks line by line, used to only see the
         * head of an archive longer than that, letting the rest through
         * silently — an entry that climbs outside the folder just had to
         * sit near the end to get through · and the `count()` below was
         * also counting from the already-truncated list, so the
         * MAX_ENTRIES ceiling never actually engaged against a genuinely
         * large archive — the one case it exists to guard against.
         *
         * An archive that long is already past the entry-count ceiling in
         * practice anyway, so the message is the same one either way —
         * "couldn't check it all" and "too many entries" both lead to the
         * same answer: it isn't extracted.
         */
        if (strlen($list->stdout) >= Executor::MAX_OUTPUT_BYTES) {
            throw new ExecutionFailed('This archive has too many entries');
        }

        $entries = array_values(array_filter(array_map('trim', explode("\n", $list->stdout)), static fn (string $l): bool => $l !== ''));

        if (count($entries) > self::MAX_ENTRIES) {
            throw new ExecutionFailed('This archive has too many entries');
        }

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '/') || in_array('..', explode('/', $entry), true)) {
                throw new ExecutionFailed(
                    'This archive has an entry that points outside the destination folder (' . $entry . '), so it was not extracted',
                );
            }
        }

        $result = $executor->exec([
            self::TAR,
            '--extract', '--file', $source,
            '--directory', $destination,
            '--no-same-owner', '--no-same-permissions',
        ], timeout: 900);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to extract: ' . trim($result->stderr));
        }

        return ['entries' => count($entries), 'skipped' => 0, 'bytes' => 0];
    }

    /**
     * Decompresses a single `.gz` file (e.g. a database's `.sql.gz`)
     *
     * Uses `gzip -dk` and lets it write the destination file itself —
     * content is never pulled through PHP, because a genuine database dump
     * file can run into gigabytes, and reading it all into memory before
     * writing would make this command fail on memory_limit exactly when
     * someone actually needs it.
     *
     * `-k` keeps the original file — clicking "extract" was never a request to delete the compressed copy.
     *
     * @return array{entries:int,skipped:int,bytes:int}
     */
    private function extractGz(Executor $executor, string $source): array
    {
        if (!$executor->exists(self::GZIP)) {
            throw new ExecutionFailed('This machine has no gzip command, so this file type cannot be decompressed');
        }

        $target = substr($source, 0, -3);

        // Never overwrites an existing file — the user may have already edited it since the last time it was extracted
        if ($executor->exists($target)) {
            throw new ValidationError('File ' . basename($target) . ' already exists — rename or delete it before extracting this one');
        }

        $result = $executor->exec([self::GZIP, '--decompress', '--keep', $source], timeout: 900);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to decompress: ' . trim($result->stderr));
        }

        $stat = $executor->stat($target);

        return ['entries' => 1, 'skipped' => 0, 'bytes' => (int) ($stat['size'] ?? 0)];
    }
}
