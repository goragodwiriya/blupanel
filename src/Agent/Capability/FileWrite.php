<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * Saves a text file's content from the editor
 *
 * Writes through a temporary file, then renames over the original (atomic) — if
 * the machine loses power mid-write, the original file is still fully intact,
 * with no half-written file left behind that would break the site instantly.
 */
final class FileWrite extends FileCapability
{
    public static function name(): string
    {
        return 'file.write';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Save file content';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('A file to save must be specified');
        }

        $content = $args['content'] ?? '';
        if (!is_string($content)) {
            throw new ValidationError('File content must be text');
        }
        if (strlen($content) > FileCatalog::MAX_EDIT_BYTES) {
            throw new ValidationError('Content exceeds 5 MB');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new ValidationError('Content must be UTF-8 text');
        }

        return $base + [
            'content' => $content,
            // A new file can be created when the user clicks "New file" — the default is to overwrite the existing one only
            'create' => self::flag($args, 'create')
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
        $name = basename($relative);
        $content = $args['content'];
        $create = $args['create'];

        if (!FileCatalog::isEditable($name, strlen($content), $context->actor->role)) {
            throw new ValidationError('This file type cannot be edited through the file manager');
        }

        // Compared against the whole file's size, not the difference from the
        // original — the file is entirely rewritten through an adjacent
        // temporary file, so there's a moment when both versions sit on disk at once
        $this->assertQuotaAllows($context, $scope, strlen($content));

        $result = $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor, $content, $create): array {
                $info = $executor->stat($target);

                if ($info !== null) {
                    if ($create) {
                        throw new ValidationError('A file with this name already exists');
                    }
                    if ($info['type'] !== 'file') {
                        throw new ValidationError('Only regular files can be overwritten');
                    }
                } elseif (!$create) {
                    throw new ValidationError('The file to save was not found');
                }

                // The temporary file is written right next to the original
                // (same folder), so the rename stays within the same filesystem
                // and is genuinely atomic
                $mode = $info['mode'] ?? 0o640;
                $temporary = $target.'.phpcp-'.bin2hex(random_bytes(6));

                /*
                 * The file's original owner (or the parent folder's, for a new file)
                 *
                 * **Editing a file must never change its owner** — an admin
                 * opening a customer's file through the "all websites" scope
                 * runs with the agent's own privileges (root), because a
                 * server-level scope doesn't belong to any one user · a file
                 * written out that way would end up owned by root, and **the
                 * customer's own FPM pool could never read it again**
                 *
                 * Found on the real server (2026-08-14): edited index.php from
                 * the file manager, and the whole site answered 403 instantly ·
                 * Apache said "Unable to open primary script (Permission
                 * denied)", with nothing connecting it back to that save click at all.
                 */
                $owner = $info ?? $executor->stat(dirname($target));

                $executor->writeFile($temporary, $content, $mode);

                try {
                    self::restoreOwner($executor, $temporary, $owner);
                    $executor->rename($temporary, $target);
                } catch (\Throwable $e) {
                    $executor->removePath($temporary);

                    throw $e;
                }

                return ['size' => strlen($content), 'mode' => sprintf('%04o', $mode)];
            },
            mustExist: false,
            actor: $context->actor,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $name,
            'created' => $create,
            ...$result
        ];
    }

    /**
     * Restores the file's owner to who it was — done before rename, so there's
     * never a moment where the real file is owned by root
     *
     * Skipped when the owner is unknown, or when already running under reduced
     * privileges (the website scope drops to the owner's privileges before
     * touching any file, so the file written out already belongs to them, and
     * `chown` would fail anyway since an ordinary user can't change a file's
     * owner) · a failure here is never rethrown for that reason — but it's still
     * always attempted, since the case that matters is running as root.
     *
     * @param array<string,mixed>|null $owner the result of stat() on the original file or its parent folder
     */
    private static function restoreOwner(Executor $executor, string $path, ?array $owner): void
    {
        $uid = (int) ($owner['uid'] ?? -1);
        $gid = (int) ($owner['gid'] ?? -1);

        if ($uid < 0 || $gid < 0) {
            return;
        }

        $executor->exec(
            [$executor->path('/usr/bin/chown'), sprintf('%d:%d', $uid, $gid), $path],
            timeout: 10,
        );
    }
}
