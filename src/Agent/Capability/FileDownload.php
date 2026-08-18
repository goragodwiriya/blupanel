<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\Validator;

/**
 * Sends a file's content back for the user to download — **in chunks, with no file size limit**
 *
 * ## Why chunks are necessary
 *
 * The file's content is base64-encoded, because the protocol is single-line JSON
 * with a 4 MB per-frame ceiling · this used to mean flatly refusing any file
 * larger than 2.5 MB, with a message saying "compress it into a zip first" —
 * advice that doesn't work for the thing a user most wants to download: **their
 * own backup file**, already compressed and always larger than that (ever since
 * PLAN-BACKUP-V2, those files live in the customer's own home, and they genuinely
 * need to be able to pull them out, or the whole point of keeping them there is lost).
 *
 * So a caller requests one range at a time (`offset`/`length`) and the HTTP tier
 * concatenates them itself, streaming continuously without holding the whole
 * file in memory · the response includes `size` (the file's total size) and
 * `eof`, so the caller knows whether to request more without having to guess.
 *
 * ## Why the file is read with plain PHP functions directly
 *
 * `Executor` has no partial-read method, and adding one would mean editing every
 * implementation of it, including nine test doubles, for a capability used in
 * exactly one place · the path `path()` has already translated is a real path on
 * disk, and the agent runs as root, so it can always read it — the same pattern
 * `BackupManager` uses when it calls `hash_file()` on an already-translated path
 * · **the permission gate is still fully intact**, since the path goes through
 * `withPath()`, which has already checked scope and symlinks.
 */
final class FileDownload extends FileCapability
{
    public static function name(): string
    {
        return 'file.download';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Download file';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('A file to download must be specified');
        }

        return $base + [
            'offset' => Validator::optionalInt($args, 'offset', 0, 0),
            // 0 = as much as fits in one frame · a larger value gets clamped
            // down, never rejected, since a caller asking for too much hasn't
            // done anything wrong, they just don't know the protocol's ceiling
            'length' => Validator::optionalInt($args, 'length', 0, 0)
        ];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $relative = $args['path'];

        $offset = $args['offset'];
        $length = $args['length'] > 0
            ? min($args['length'], FileCatalog::MAX_TRANSFER_BYTES)
            : FileCatalog::MAX_TRANSFER_BYTES;

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $offset, $length): array {
            $info = $executor->stat($target);

            if ($info === null || $info['type'] !== 'file') {
                throw new ValidationError('Only regular files can be downloaded (compress a folder into a zip first)');
            }

            $size = (int) $info['size'];

            // Requesting past the end of the file = done, not an error · an empty file also travels this path
            if ($offset >= $size) {
                return ['content' => '', 'size' => $size, 'bytes' => 0];
            }

            $handle = @fopen($target, 'rb');

            if ($handle === false) {
                throw new \Phpcp\Agent\ExecutionFailed('Failed to open file for download');
            }

            try {
                if ($offset > 0 && fseek($handle, $offset) !== 0) {
                    throw new \Phpcp\Agent\ExecutionFailed('Failed to seek within the file');
                }

                $chunk = fread($handle, $length);
            } finally {
                fclose($handle);
            }

            if ($chunk === false) {
                throw new \Phpcp\Agent\ExecutionFailed('Failed to read file for download');
            }

            return ['content' => base64_encode($chunk), 'size' => $size, 'bytes' => strlen($chunk)];
        }, actor: $context->actor);

        $end = $offset + $result['bytes'];

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => basename($relative),
            'size' => $result['size'],
            'offset' => $offset,
            'bytes' => $result['bytes'],
            // The caller needs to know it's done without computing it themselves — one miscalculation and the file comes out truncated
            'eof' => $end >= $result['size'],
            'content' => $result['content']
        ];
    }
}
