<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;

/**
 * Information about a single file or folder — the file manager's "Properties" box
 *
 * Most of this duplicates what `file.list` already sends per row, but this
 * endpoint still has to exist for two cases a row can't answer: opening
 * properties from **search results**, which skip past the folder listing, and
 * looking at something someone else just edited after the table already loaded.
 *
 * **Never computes a folder's total size** — that means walking the whole tree,
 * which on the `server` scope means walking the entire disk for one click ·
 * reports the top-level entry count instead, which comes from reading the
 * directory once and is what a user actually wants to know before deleting or
 * moving it.
 */
final class FileInfo extends FileCapability
{
    public static function name(): string
    {
        return 'file.info';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read file information';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('A file or folder must be specified');
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $name): array {
            $info = $executor->stat($target);
            if ($info === null) {
                throw new ValidationError('The specified file or folder was not found');
            }

            $described = self::describe($name, $relative, $info);
            $described['kind'] = $info['type'] === 'dir' ? 'folder' : FileCatalog::kind($name);
            $described['editable'] = $info['type'] === 'file' && FileCatalog::isEditable($name, $info['size']);
            $described['syntax'] = FileCatalog::syntax($name);
            $described['parent'] = PathGuard::parent($relative);

            if ($info['type'] === 'dir') {
                // One read is enough · `listDirectory` already has its own
                // ceiling, so the count becomes "at least this many" once a
                // folder exceeds it
                try {
                    $described['entries'] = count($executor->listDirectory($target));
                } catch (\Throwable) {
                    $described['entries'] = null;
                }

                $described['size'] = null;
            }

            return $described;
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            ...$result
        ];
    }
}
