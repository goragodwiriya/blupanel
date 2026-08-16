<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * Reads a text file's content to open in the editor
 *
 * Only opens extensions on FileCatalog's allowlist, and no larger than 5 MB — not
 * to prevent reading the file (the user already owns it), but to keep a large
 * binary file from being pulled into memory and sent across the protocol until it
 * overflows the frame.
 */
final class FileRead extends FileCapability
{
    public static function name(): string
    {
        return 'file.read';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read text file content';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('A file to read must be specified');
        }

        return $base;
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
        $name = basename($relative);

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $name): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'file') {
                throw new ValidationError('Only regular files can be read');
            }

            if (!FileCatalog::isEditable($name, $info['size'])) {
                throw new ValidationError(
                    $info['size'] > FileCatalog::MAX_EDIT_BYTES
                        ? 'File is too large to open in the editor (over 5 MB)'
                        : 'This file type cannot be opened in the text editor',
                );
            }

            $content = $executor->readFile($target);

            // A file whose extension claims it's text but whose content is
            // actually binary (overwritten by something else, say) must be
            // rejected — otherwise the browser would get JSON that fails to encode
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new ValidationError('This file is not UTF-8 text, so it cannot be edited');
            }

            return [
                'content' => $content,
                'size' => $info['size'],
                'mode' => sprintf('%04o', $info['mode']),
                'mtime' => $info['mtime']
            ];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $name,
            'syntax' => FileCatalog::syntax($name),
            ...$result
        ];
    }
}
