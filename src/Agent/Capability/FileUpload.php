<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Accepts a file the user uploaded
 *
 * The file's content travels as base64, because the agent's protocol is
 * single-line JSON, which can't carry raw bytes. base64 inflates data by 4/3, so
 * the accepted size is capped comfortably under Protocol::MAX_FRAME
 * (FileCatalog::MAX_TRANSFER_BYTES).
 *
 * A filename the user sends is never trusted — trimmed down to just its
 * basename, then validated with PathGuard before it's joined to any path at all.
 */
final class FileUpload extends FileCapability
{
    public static function name(): string
    {
        return 'file.upload';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Upload file to website';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        // basename before validating: some browsers send the user's full local
        // path, and an attacker might send '../../etc/cron.d/x' directly
        $name = PathGuard::name(basename(Validator::requireString($args, 'name', 255)), 'ชื่อไฟล์');

        $encoded = $args['content'] ?? '';
        if (!is_string($encoded)) {
            throw new ValidationError('Invalid file content');
        }

        $content = base64_decode($encoded, true);
        if ($content === false) {
            throw new ValidationError('Failed to decode file content');
        }
        if (strlen($content) > FileCatalog::MAX_TRANSFER_BYTES) {
            throw new ValidationError('File exceeds '.(int) (FileCatalog::MAX_TRANSFER_BYTES / 1048576).' MB');
        }

        return $base + [
            'name' => $name,
            'content' => $content,
            'overwrite' => self::flag($args, 'overwrite')
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
        $relative = PathGuard::join($args['path'], $args['name']);
        $content = $args['content'];
        $overwrite = $args['overwrite'];

        // The exact size is already known from validate() — this check compares real numbers, not a guess
        $this->assertQuotaAllows($context, $scope, strlen($content));

        $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor, $content, $overwrite): array {
                $existing = $executor->stat($target);

                if ($existing !== null) {
                    if (!$overwrite) {
                        throw new ValidationError('A file with this name already exists');
                    }
                    if ($existing['type'] !== 'file') {
                        throw new ValidationError('The destination is not a regular file');
                    }
                }

                // 0640, not 0644: an uploaded file might hold secrets that other
                // users on the machine shouldn't be able to read, and the execute
                // bit is never set, even if the file is a script
                $executor->writeFile($target, $content, 0o640);

                return [];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $args['name'],
            'size' => strlen($content),
            'message' => 'Uploaded '.$args['name']
        ];
    }
}
