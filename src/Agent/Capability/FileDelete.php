<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Deletes files and folders
 *
 * Refuses to delete a website's structural folders (public, logs, tmp, backup) —
 * not out of fear the user destroys their own data, but because the vhost and
 * pool point at these folders directly. Delete one and the site 500s
 * immediately, with a symptom that looks like a PHP problem and is very hard to trace back.
 *
 * `backup` is protected as a folder only — **files inside it delete normally**
 * and must be deletable, since the customer owns their own copies and decides for
 * themselves what to keep (PLAN-BACKUP-V2 item B4).
 */
final class FileDelete extends FileCapability
{
    private const MAX_ITEMS = 100;

    /** The top-level folders the system creates that can never be deleted */
    private const PROTECTED_ROOTS = ['public', 'logs', 'tmp', 'backup'];

    public static function name(): string
    {
        return 'file.delete';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Delete file or folder';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $items = [];
        foreach (Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096) as $item) {
            $relative = PathGuard::clean($item, 'Path to delete');

            if ($relative === '') {
                throw new ValidationError('Cannot delete the scope root');
            }

            $items[] = $relative;
        }

        if ($items === []) {
            throw new ValidationError('At least one item must be selected before deleting');
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'Invalid file scope key',
            ),
            'items' => $items
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

        // A website's structural folders can't be deleted — this rule applies
        // only to the site scope; the server scope has no such structure, so it
        // shouldn't be restricted by the same names
        if ($scope->isSite()) {
            foreach ($args['items'] as $relative) {
                if (in_array($relative, self::PROTECTED_ROOTS, true)) {
                    throw new ValidationError("Folder {$relative} is part of the website's structure and cannot be deleted");
                }
            }
        }
        $items = $args['items'];

        $result = $this->withSite($executor, $scope, static function (callable $resolve) use ($executor, $items): array {
            $deleted = [];

            foreach ($items as $relative) {
                $executor->removePath($resolve($relative));
                $deleted[] = basename($relative);
            }

            return ['deleted' => $deleted];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'deleted' => $result['deleted'],
            'count' => count($result['deleted']),
            'message' => sprintf('Deleted %d item(s)', count($result['deleted']))
        ];
    }
}
