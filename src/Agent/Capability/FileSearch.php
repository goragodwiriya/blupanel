<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\Validator;

/**
 * Searches for files and folders by name, under the folder currently open
 *
 * **Compares by name only, never opens a file's content** — searching content
 * would mean reading every byte of every file for every query, which on the
 * `server` scope (root `/`) means reading the entire disk.
 *
 * **Four ceilings working together**, each guarding a different case genuinely
 * seen on a server:
 *
 *   MAX_RESULTS   keeps the response from growing past the protocol's frame size
 *   MAX_VISITED   a folder with hundreds of thousands of files (an uncleared cache or log) doesn't hang for a long time
 *   MAX_DEPTH     guards against an abnormally deep tree
 *   TIME_LIMIT    a slow-responding disk (NFS, a failing drive) doesn't hang the request until the web tier times out
 *
 * Never follows a symlink, for the same reason as `file.tree` — both to stay
 * inside its scope and so a link pointing back at itself can't be walked forever.
 */
final class FileSearch extends FileCapability
{
    private const MIN_QUERY = 2;
    private const MAX_RESULTS = 200;
    private const MAX_VISITED = 20000;
    private const MAX_DEPTH = 12;
    private const TIME_LIMIT_SECONDS = 5.0;

    public static function name(): string
    {
        return 'file.search';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Search files by name';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $query = trim(Validator::requireString($args, 'q', 100));

        if (mb_strlen($query) < self::MIN_QUERY) {
            throw new ValidationError('The search query must be at least '.self::MIN_QUERY.' characters long');
        }

        // A control character in the query can never match a filename the
        // system allows to be created (PathGuard forbids it) — it could only
        // ever end up in a log and disguise itself as another line
        if (preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
            throw new ValidationError('The search query contains a disallowed control character');
        }

        return self::baseArgs($args) + ['q' => $query];
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
        $query = $args['q'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $query): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new ValidationError('This path is not a folder');
            }

            $state = ['visited' => 0, 'deadline' => microtime(true) + self::TIME_LIMIT_SECONDS];
            $matches = [];

            self::scan($executor, $target, $relative, $query, self::MAX_DEPTH, $matches, $state);

            // Folders before files, then sorted by name — same as `file.list`,
            // so results shown in the same table always come in the same order
            usort($matches, static function (array $a, array $b): int {
                $rank = static fn(array $e): int => $e['type'] === 'dir' ? 0 : 1;

                return [$rank($a), mb_strtolower($a['name'])] <=> [$rank($b), mb_strtolower($b['name'])];
            });

            return [
                'entries' => $matches,
                'visited' => $state['visited'],
                // States plainly that results were truncated — a user who finds
                // nothing needs to tell "the file doesn't exist" apart from "the
                // search stopped before reaching it", or they'd trust an answer that isn't true
                'truncated' => count($matches) >= self::MAX_RESULTS
                || $state['visited'] >= self::MAX_VISITED
                || microtime(true) > $state['deadline']
            ];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'q' => $query,
            ...$result
        ];
    }

    /**
     * Walks every level under one folder, collecting entries whose name contains the query
     *
     * @param list<array<string,mixed>> $matches
     * @param array{visited:int,deadline:float} $state
     */
    private static function scan(
        Executor $executor,
        string $absolute,
        string $relative,
        string $query,
        int $depth,
        array &$matches,
        array &$state,
    ): void {
        if ($depth <= 0 || count($matches) >= self::MAX_RESULTS || $state['visited'] >= self::MAX_VISITED) {
            return;
        }

        try {
            $entries = $executor->listDirectory($absolute);
        } catch (\Throwable) {
            return; // A folder the user can't access counts as no results, not an error for the whole request
        }

        // The deadline is checked after reading the directory finishes, not before — the expensive cost is the syscall that just ran
        if (microtime(true) > $state['deadline']) {
            return;
        }

        foreach ($entries as $entry) {
            $state['visited']++;

            if (count($matches) >= self::MAX_RESULTS || $state['visited'] >= self::MAX_VISITED) {
                return;
            }

            $childRelative = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];

            if (self::nameMatches($entry['name'], $query)) {
                $described = self::describe($entry['name'], $childRelative, $entry);
                $described['kind'] = $entry['type'] === 'dir' ? 'folder' : FileCatalog::kind($entry['name']);
                $described['editable'] = $entry['type'] === 'file'
                && FileCatalog::isEditable($entry['name'], $entry['size']);
                // The result's parent folder — used by the screen to take the user to where the file actually lives
                $described['parent'] = $relative;

                if ($entry['type'] === 'dir') {
                    $described['size'] = null;
                }

                $matches[] = $described;
            }

            if ($entry['type'] === 'dir') {
                self::scan($executor, $absolute.'/'.$entry['name'], $childRelative, $query, $depth - 1, $matches, $state);
            }
        }
    }

    /**
     * Does this name contain the query — case-insensitively
     *
     * A filename on disk is a byte sequence, not text — a name that isn't UTF-8
     * (an old file named in TIS-620, or a name written by another program) has
     * to be skipped, not returned, because `json_encode` on the whole response
     * would fail over that one name, leaving the user with a blank screen and
     * nothing explaining why.
     */
    private static function nameMatches(string $name, string $query): bool
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return false;
        }

        return mb_stripos($name, $query) !== false;
    }
}
