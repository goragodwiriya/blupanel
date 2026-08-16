<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Compresses selected files and folders into a zip file
 *
 * The resulting file is created in the folder the user has open, not in the
 * system's temp location — the user sees the result immediately, and the space
 * it uses counts toward that website's quota as normal.
 */
final class FileZip extends FileCapability
{
    private const MAX_ITEMS = 100;

    public static function name(): string
    {
        return 'file.zip';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Compress files into a zip';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $items = [];
        foreach (Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096) as $item) {
            $relative = PathGuard::clean($item, 'เส้นทางที่จะบีบอัด');
            if ($relative === '') {
                throw new ValidationError('Select a file or folder to compress first');
            }

            $items[] = $relative;
        }

        if ($items === []) {
            throw new ValidationError('Select a file or folder to compress first');
        }

        $name = PathGuard::name(Validator::requireString($args, 'archive', 200), 'ชื่อไฟล์บีบอัด');
        if (!str_ends_with(mb_strtolower($name), '.zip')) {
            $name .= '.zip';
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'Invalid file scope key',
            ),
            'path' => PathGuard::clean(Validator::optionalString($args, 'path', max: 4096)),
            'items' => $items,
            'archive' => $name
        ];
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
        $items = $args['items'];
        $folder = $args['path'];
        $archive = PathGuard::join($folder, $args['archive']);

        // The compressed file's size can't be known before compression finishes —
        // all that can be checked is that the quota isn't already full
        // (`RealExecutor::zip()` already has its own 512 MB ceiling against something genuinely oversized)
        $this->assertQuotaAllows($context, $scope);

        $result = $this->withSite($executor, $scope, static function (callable $resolve) use ($executor, $items, $folder, $archive): array {
            $target = $resolve($archive, false);
            if ($executor->stat($target) !== null) {
                throw new ValidationError('A file with this name already exists');
            }

            // Names inside the archive are relative to the folder the user has
            // open, not the website's root — extracting it back out reproduces
            // the structure seen on screen, with no stray folder level
            $summary = $executor->zip(
                array_map(static fn(string $relative): string => $resolve($relative), $items),
                $resolve($folder),
                $target,
            );

            $executor->changeMode($target, 0o640);

            return $summary;
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'archive' => $archive,
            'entries' => $result['entries'],
            'bytes' => $result['bytes'],
            'message' => sprintf('Compressed %d item(s) into %s', $result['entries'], $args['archive'])
        ];
    }
}
