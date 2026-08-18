<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * A tree structure of **folders only**, for the file manager's left-hand navigation panel
 *
 * Kept separate from `file.list` because it answers a different question:
 * `file.list` answers "what's inside this folder" (files too, one level at a
 * time), while this one answers "what does the folder structure around here look
 * like". Drawing a tree with `file.list` would mean firing one request per
 * folder and getting unused file data attached every time.
 *
 * **Two ceilings that can't be skipped** — a real machine has folders like
 * `node_modules` and `/proc` that are deep and wide enough to hang for minutes
 * if walked entirely:
 *
 *   `depth`     no more than 3 levels · defaults to 1, since the navigation panel expands one level at a time as the user clicks
 *   MAX_NODES   a ceiling on the total number of folders in the whole response
 *               · cut off in the agent, same as `file.list`, because a message
 *               larger than the protocol's MAX_FRAME can never be sent out in the first place
 *
 * Never descends into a symlink — `listDirectory` uses lstat, so a link comes
 * back typed as `link`, not `dir`, and gets skipped right along with files ·
 * this is what keeps the tree walk from escaping its own scope even though
 * `resolve()` is only ever called at the single starting point.
 */
final class FileTree extends FileCapability
{
    /** The ceiling on folder count per response */
    private const MAX_NODES = 1500;

    /** The deepest level allowed to be walked in a single request */
    private const MAX_DEPTH = 3;

    public static function name(): string
    {
        return 'file.tree';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read folder tree structure';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'depth' => Validator::optionalInt($args, 'depth', 1, min: 1, max: self::MAX_DEPTH)
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
        $depth = $args['depth'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $depth): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new ValidationError('This path is not a folder');
            }

            $budget = self::MAX_NODES;
            $nodes = self::walk($executor, $target, $relative, $depth, $budget);

            return ['nodes' => $nodes, 'truncated' => $budget <= 0];
        }, actor: $context->actor);

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'depth' => $depth,
            ...$result
        ];
    }

    /**
     * Walks down folders one level at a time until `$depth` or the budget runs out
     *
     * `$budget` is passed by reference so every branch shares the same pool — if
     * each branch had its own budget, a single branch with a thousand children
     * could still make the response too large.
     *
     * @return list<array<string,mixed>>
     */
    private static function walk(
        Executor $executor,
        string $absolute,
        string $relative,
        int $depth,
        int &$budget,
    ): array {
        $nodes = [];

        foreach (self::directories($executor, $absolute) as $entry) {
            if ($budget <= 0) {
                break;
            }

            $budget--;

            $childRelative = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];
            $childAbsolute = $absolute.'/'.$entry['name'];

            // The last level doesn't recurse further, but still **needs to know
            // whether it has children** — otherwise the navigation panel would
            // show an expand arrow on every folder, only for the user to click
            // and find nothing there · one check per folder is already bounded
            // by the same $budget
            $children = $depth > 1
                ? self::walk($executor, $childAbsolute, $childRelative, $depth - 1, $budget)
                : [];

            $nodes[] = [
                'name' => $entry['name'],
                'path' => $childRelative,
                'mtime' => $entry['mtime'],
                'has_children' => $depth > 1 ? $children !== [] : self::hasSubdirectory($executor, $childAbsolute),
                'children' => $children
            ];
        }

        usort($nodes, static fn(array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return $nodes;
    }

    /**
     * The subentries that are genuinely folders — a folder that can't be opened counts as empty, not an error
     *
     * A user looking at `/etc` will always have some folders mixed in they can't
     * access themselves; throwing an error would empty out the whole navigation
     * panel over a single unreachable folder.
     *
     * @return list<array{name:string,type:string,mtime:int}>
     */
    private static function directories(Executor $executor, string $absolute): array
    {
        try {
            $entries = $executor->listDirectory($absolute);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn(array $entry): bool => $entry['type'] === 'dir',
        ));
    }

    /**
     * @param Executor $executor
     * @param string $absolute
     */
    private static function hasSubdirectory(Executor $executor, string $absolute): bool
    {
        return self::directories($executor, $absolute) !== [];
    }
}
