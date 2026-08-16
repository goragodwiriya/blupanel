<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * Creates a new folder inside the folder currently open
 *
 * Accepts a "parent folder" and a "name" separately, not one full path — so the
 * name gets validated as a single segment with no `/` inside it, meaning a user
 * can never create a folder several levels deep or walk back up just by typing a name.
 */
final class FileMkdir extends FileCapability
{
    public static function name(): string
    {
        return 'file.mkdir';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Create new folder';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'name' => PathGuard::name(Validator::requireString($args, 'name'), 'ชื่อโฟลเดอร์')
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

        $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor): array {
                if ($executor->stat($target) !== null) {
                    throw new ValidationError('A file or folder with this name already exists');
                }

                // 0750, not 0755: the web server's group can access it, but no other user on the machine can
                $executor->makeDirectory($target, 0o750);

                return [];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $args['name'],
            'message' => 'Created folder '.$args['name']
        ];
    }
}
