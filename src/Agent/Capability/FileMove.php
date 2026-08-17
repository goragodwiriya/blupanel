<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Moves, renames, or copies files and folders
 *
 * These three jobs are combined into one capability because they share the exact
 * same set of security rules: both the source and destination must fall inside
 * the same website's home — splitting this into three would mean the rules get
 * copied three times and later edited incompletely.
 */
final class FileMove extends FileCapability
{
    /** The most items allowed per command — guards against a request that makes the agent run too long */
    private const MAX_ITEMS = 100;

    public static function name(): string
    {
        return 'file.move';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Move, rename, or copy files';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $sources = Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096);

        $clean = [];
        foreach ($sources as $source) {
            $relative = PathGuard::clean($source, 'Source path');
            if ($relative === '') {
                throw new ValidationError('Cannot move or copy the scope root');
            }

            $clean[] = $relative;
        }

        if ($clean === []) {
            throw new ValidationError('At least one item must be selected first');
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'Invalid file scope key',
            ),
            'items' => $clean,
            'destination' => PathGuard::clean(Validator::optionalString($args, 'destination', max: 4096), 'Destination folder'),
            // Rename = move one item while giving it a new name, only ever usable for a single item
            'rename' => isset($args['rename']) && $args['rename'] !== ''
                ? PathGuard::name((string) $args['rename'], 'New name')
                : '',
            'copy' => self::flag($args, 'copy'),
            'overwrite' => self::flag($args, 'overwrite')
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
        $destination = $args['destination'];
        $rename = $args['rename'];
        $copy = $args['copy'];
        $overwrite = $args['overwrite'];

        if ($rename !== '' && count($items) !== 1) {
            throw new ValidationError('Only one item can be renamed at a time');
        }

        return $this->withSite($executor, $scope, static function (callable $resolve) use (
            $executor,
            $items,
            $destination,
            $rename,
            $copy,
            $overwrite,
            $scope,
        ): array {
            $realDestination = $resolve($destination);
            if (($executor->stat($realDestination)['type'] ?? '') !== 'dir') {
                throw new ValidationError('The destination must be a folder');
            }

            $done = [];

            foreach ($items as $relative) {
                $from = $resolve($relative);
                $name = $rename !== '' ? $rename : basename($relative);
                $to = $realDestination.'/'.$name;

                if ($from === $to) {
                    continue; // Destination same as source = nothing to do, not an error
                }

                // Moving a folder into itself disconnects the tree from the
                // filesystem and is hard to recover from. The kernel's rename()
                // guards against this case, but copy would loop forever until the disk fills up
                if (str_starts_with($realDestination.'/', $from.'/')) {
                    throw new ValidationError('Cannot move a folder into itself: '.basename($relative));
                }

                if ($executor->stat($to) !== null) {
                    if (!$overwrite) {
                        throw new ValidationError($name.' already exists at the destination');
                    }

                    $executor->removePath($to);
                }

                if ($copy) {
                    $executor->copyPath($from, $to);
                } else {
                    $executor->rename($from, $to);
                }

                $done[] = $name;
            }

            return [
                'root' => $scope->key,
            'site_id' => $scope->siteId,
                'moved' => $done,
                'count' => count($done),
                'destination' => $destination,
                'message' => sprintf('%s %d item(s)', $copy ? 'Copied' : 'Moved', count($done))
            ];
        });
    }
}
