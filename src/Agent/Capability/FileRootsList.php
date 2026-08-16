<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\FileRoots;

/**
 * The file scopes this actor may open, limited to the ones that genuinely exist on disk
 *
 * `FileRoots::forActor()` only reads the database — it has no way to know
 * whether the folder a scope points at was ever actually created (an old
 * site carried over from before a layout change, provisioning that never
 * finished). Checking existence needs privilege-dropped access to the site
 * owner's own home, the same `asUser()` every other file capability already
 * uses — the web tier's `/files/roots` endpoint has no executor of its own
 * to do that with, so this capability does the check here and the web tier
 * just relays the filtered result. Offering a scope that immediately errors
 * out the moment it's opened is worse than not listing it at all.
 */
final class FileRootsList extends FileCapability
{
    public static function name(): string
    {
        return 'file.roots';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'List the file scopes this actor may open';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $scopes = [];

        foreach (FileRoots::forActor($context->actor, $context->db) as $scope) {
            $root = $this->root($executor, $scope);

            $result = $executor->asUser(
                $scope->systemUser,
                static fn (): array => ['exists' => $executor->realPath($root) !== null],
            );

            if ($result['exists']) {
                // Converted to a plain array here, not left as the FileScope object,
                // so the agent boundary never carries `root` (the real machine path)
                // any further than it has to — the web tier only ever reads the
                // named fields it explicitly asks for.
                $scopes[] = $scope->toArray();
            }
        }

        return ['scopes' => $scopes];
    }
}
