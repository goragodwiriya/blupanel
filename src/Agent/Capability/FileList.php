<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Reads a file listing for a single folder — never recurses into subfolders
 *
 * Never recurses, on purpose: walking a whole tree makes a website with
 * node_modules hang for minutes and sends data past the protocol's frame size limit.
 *
 * Pagination happens right here, in the agent, for the same reason — a flat
 * folder with tens of thousands of files (an uncleared cache or log directory,
 * say) sent all at once would hit the protocol's MAX_FRAME just like walking a
 * whole tree would, so it has to be cut down before being assembled into the
 * message the agent sends out, not at the web tier, which would have already
 * received the oversized data by then.
 */
final class FileList extends FileCapability
{
    private const DEFAULT_PER_PAGE = 100;
    private const MAX_PER_PAGE = 500;

    public static function name(): string
    {
        return 'file.list';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read folder file listing';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'page' => Validator::optionalInt($args, 'page', 1, min: 1, max: 1_000_000),
            'per_page' => Validator::optionalInt(
                $args,
                'per_page',
                self::DEFAULT_PER_PAGE,
                min: 1,
                max: self::MAX_PER_PAGE,
            )
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $scope, $context): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new \Phpcp\Agent\ValidationError('This path is not a folder');
            }

            $entries = [];
            foreach ($executor->listDirectory($target) as $entry) {
                $childPath = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];
                $described = self::describe($entry['name'], $childPath, $entry);

                $described['editable'] = $entry['type'] === 'file'
                && FileCatalog::isEditable($entry['name'], $entry['size'], $context->actor->role);
                $described['kind'] = $entry['type'] === 'dir' ? 'folder' : FileCatalog::kind($entry['name']);
                // A folder's inode size (usually 4096) tells the user nothing —
                // null lets the table's standard formatter (data-format="bytes" +
                // data-empty-text) show "—" on its own, instead of needing an
                // ad-hoc formatter on the screen side
                if ($entry['type'] === 'dir') {
                    $described['size'] = null;
                }
                // Each row carries its own scope name, so one row's result can
                // build a download/edit URL purely from data in that row, with no
                // reliance on a value selected outside the row
                $described['root'] = $scope->key;

                $entries[] = $described;
            }

            // Folders always come first, then sorted by name case-insensitively —
            // sorted in the agent, not the browser, so results look the same on every machine
            usort($entries, static function (array $a, array $b): int {
                $rank = static fn(array $e): int => $e['type'] === 'dir' ? 0 : 1;

                return [$rank($a), mb_strtolower($a['name'])] <=> [$rank($b), mb_strtolower($b['name'])];
            });

            return [
                'entries' => $entries,
                'disk' => $executor->diskSpace($root)
            ];
        }, actor: $context->actor);

        $total = count($result['entries']);
        $perPage = $args['per_page'];
        $pages = max(1, (int) ceil($total / $perPage));
        // Easy to overshoot the last page if files get deleted while the user's page sits open — clamps back to the last page instead of returning an empty list
        $page = min($args['page'], $pages);

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'scope' => $scope->label,
            'path' => $relative,
            'parent' => $relative === '' ? null : PathGuard::parent($relative),
            'breadcrumb' => self::breadcrumb($relative),
            'entries' => array_slice($result['entries'], ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'disk' => $result['disk']
        ];
    }

    /**
     * Splits a path into pieces for the breadcrumb bar
     *
     * @return list<array{label:string,path:string}>
     */
    private static function breadcrumb(string $relative): array
    {
        $crumbs = [['label' => 'Home', 'path' => '']];

        if ($relative === '') {
            return $crumbs;
        }

        $walked = '';
        foreach (explode('/', $relative) as $segment) {
            $walked = $walked === '' ? $segment : $walked.'/'.$segment;
            $crumbs[] = ['label' => $segment, 'path' => $walked];
        }

        return $crumbs;
    }
}
