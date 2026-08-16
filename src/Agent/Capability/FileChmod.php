<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * Changes a file's or folder's permissions
 *
 * Only accepts values on a safe allowlist, not any octal number at all: 0777 on
 * a website file lets another user on the same machine overwrite that site's
 * scripts, and there's never a reason to set setuid/setgid/the sticky bit from a
 * web control panel.
 */
final class FileChmod extends FileCapability
{
    /** @var list<int> */
    private const ALLOWED = [0o600, 0o640, 0o644, 0o700, 0o750, 0o755];

    public static function name(): string
    {
        return 'file.chmod';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Change file permissions';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('A file or folder must be specified');
        }

        $raw = Validator::requireString($args, 'mode', 4);
        if (preg_match('/^[0-7]{3,4}$/', $raw) !== 1) {
            throw new ValidationError('Permissions must be an octal number with 3 or 4 digits');
        }

        $mode = (int) octdec($raw);
        if (!in_array($mode, self::ALLOWED, true)) {
            throw new ValidationError('Only these permissions are allowed: '.implode(', ', array_map(
                static fn(int $m): string => sprintf('%04o', $m),
                self::ALLOWED,
            )));
        }

        return $base + ['mode' => $mode, 'recursive' => self::flag($args, 'recursive')];
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
        $mode = $args['mode'];
        $recursive = $args['recursive'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $mode, $recursive): array {
            $info = $executor->stat($target);
            if ($info === null) {
                throw new ValidationError('The specified file was not found');
            }
            if ($info['type'] === 'link') {
                throw new ValidationError('Cannot change permissions on a symlink');
            }

            $changed = 1;
            $executor->changeMode($target, $mode);

            if ($recursive && $info['type'] === 'dir') {
                $changed += self::descend($executor, $target, $mode);
            }

            return ['changed' => $changed];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'mode' => sprintf('%04o', $mode),
            'changed' => $result['changed'],
            'message' => sprintf('Changed permissions on %d item(s) to %04o', $result['changed'], $mode)
        ];
    }

    /**
     * Walks down changing permissions in subfolders
     *
     * Files and folders need different permissions: a folder with no execute bit
     * can't be entered at all, so applying 0644 to a whole tree would break the
     * entire site — execute is added only to folders.
     */
    private static function descend(Executor $executor, string $directory, int $mode): int
    {
        // Anyone who can read must also be able to enter the folder — shifts the
        // read bit (4) into the execute bit (1), so 0644 becomes 0755 for a
        // folder, and 0640 becomes 0750
        $directoryMode = $mode | (($mode & 0o444) >> 2);
        $count = 0;

        foreach ($executor->listDirectory($directory) as $entry) {
            if ($entry['type'] === 'link') {
                continue; // Never follows a link — its target could sit outside the website's home
            }

            $child = $directory.'/'.$entry['name'];

            if ($entry['type'] === 'dir') {
                $executor->changeMode($child, $directoryMode);
                $count += 1 + self::descend($executor, $child, $mode);
                continue;
            }

            $executor->changeMode($child, $mode);
            $count++;
        }

        return $count;
    }
}
