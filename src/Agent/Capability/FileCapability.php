<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DiskQuota;
use Phpcp\Domain\FileRoots;
use Phpcp\Domain\FileScope;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * The shared base for every capability in the file manager
 *
 * Every file operation always follows the same order, and that order is
 * exactly what stops the file manager from turning into a way to read/write
 * any file on the machine:
 *
 *   1. Check the path's shape (PathGuard::clean) before ever touching disk
 *   2. Check the caller genuinely owns that website (blocks IDOR)
 *   3. Drop to the website's own user (asUser), only then do the file work
 *   4. Check the path again, after resolving symlinks, under the already-dropped privileges
 *
 * Step 4 has to live inside asUser and nowhere else — a realpath() run as
 * root would see files the website's user can't reach at all, so its check
 * result wouldn't match reality at actual read/write time.
 */
abstract class FileCapability implements Capability
{
    /**
     * @return mixed
     */
    public function permission(): string
    {
        return $this->isMutating() ? 'file.manage' : 'file.view';
    }

    /**
     * The argument shape every one of them shares — a scope key, plus a path relative to that scope
     *
     * A caller never sends a "full path" at all — only a scope key the
     * system already declared, plus a path underneath that scope — the real
     * path is assembled in exactly one place, the agent.
     *
     * @param array<string,mixed> $args
     * @return array{root:string,path:string}
     */
    protected static function baseArgs(array $args): array
    {
        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'Invalid file scope key',
            ),
            'path' => PathGuard::clean(Validator::optionalString($args, 'path', max: 4096))
        ];
    }

    /**
     * Reads a true/false value out of an argument — a web form always sends it as text
     *
     * Only accepts the shapes deliberately meant as "true" — everything else
     * counts as false · never cast with (bool) directly, since the strings
     * '0' and 'false' would give confusingly different results.
     *
     * @param array<string,mixed> $args
     */
    protected static function flag(array $args, string $key): bool
    {
        $value = $args[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Resolves the file scope while checking permission — the single gate that decides who can open what
     *
     * Checked again at the agent layer even though the web layer already
     * checked, because the agent has to be able to stand on its own if the
     * web layer is ever compromised (ARCHITECTURE §4.2) — policy detail
     * lives in FileRoots.
     *
     * @param array<string,mixed> $args
     * @throws PermissionDenied
     */
    protected function scope(Context $context, array $args): FileScope
    {
        return FileRoots::require($context->actor, $context->db, (string) $args['root']);
    }

    /**
     * Can the website owner's disk quota take this write?
     *
     * **The file manager never had this gate at all**, despite being the
     * most direct way to write data onto the machine — uploading, writing a
     * file, unzipping, zipping all ran through here without anyone ever
     * asking whether this account had any room left · a single account
     * could fill the shared disk until other customers' sites could no
     * longer write a session or an upload at all.
     *
     * A machine-level scope skips this gate, since it's only ever open to a
     * server admin, who already isn't quota-limited (the same rule as
     * QuotaChecker) and system files don't belong to any one account.
     *
     * @param int $bytes the size about to be written · {@see DiskQuota::UNKNOWN} when not yet known
     * @throws ValidationError
     */
    protected function assertQuotaAllows(Context $context, FileScope $scope, int $bytes = DiskQuota::UNKNOWN): void
    {
        if (!$scope->isSite() || $scope->siteId < 1) {
            return;
        }

        $row = $context->db->first(
            'SELECT owner_user_id FROM sites WHERE id = :id',
            ['id' => $scope->siteId],
        );

        if ($row === null) {
            return;
        }

        DiskQuota::assertFits($context->db, (int) $row['owner_user_id'], $bytes);
    }

    /**
     * The scope's root, as that mode's executor sees it
     *
     * Test mode adds its prefix automatically, so the path a capability uses never has to know about mode at all.
     */
    protected function root(Executor $executor, FileScope $scope): string
    {
        return $executor->path($scope->root);
    }

    /**
     * Runs file work under the website's own privileges, passing a "path
     * resolver" instead of an already-resolved path
     *
     * Work like move/copy/zip has both a source and a destination, and both
     * have to be resolved past symlinks and re-checked *under the same
     * already-dropped privileges* — so the resolver itself is passed in, to
     * be called as many times as needed.
     *
     * @param callable(callable(string,bool=):string,string):array<string,mixed> $work
     * @return array<string,mixed>
     */
    protected function withSite(Executor $executor, FileScope $scope, callable $work, ?Actor $actor = null): array
    {
        $root = $this->root($executor, $scope);
        $mutating = $this->isMutating();

        // A website scope drops to that site owner's privileges before touching any file.
        // A server scope runs under the agent's own privileges, since system
        // files don't belong to any one user — so it's only ever open to a server admin.
        return $executor->asUser($scope->systemUser, static function () use ($executor, $root, $work, $mutating, $actor) {
            $realRoot = $executor->realPath($root);
            if ($realRoot === null) {
                throw new ValidationError('This directory does not exist on the machine yet');
            }

            $resolve = static function (string $relative, bool $mustExist = true) use ($executor, $root, $mutating, $actor): string {
                $real = PathGuard::resolve($executor, $root, PathGuard::join($root, $relative), $mustExist);

                // The control panel's own files can't be touched — neither read nor written.
                // Blocks both unrecoverably breaking itself, and reading panel.db, which holds password hashes.
                FileRoots::assertReadable($real, $actor);

                if ($mutating) {
                    FileRoots::assertWritable($real, $actor);
                }

                return $real;
            };

            return $work($resolve, $realRoot);
        });
    }

    /**
     * Runs file work against a single path, passing along the checked, resolved real path
     *
     * @param callable(string,string):array<string,mixed> $work receives (real root, real target)
     * @param bool $mustExist false when the target is something about to be newly created
     * @return array<string,mixed>
     */
    protected function withPath(
        Executor $executor,
        FileScope $scope,
        string $relative,
        callable $work,
        bool $mustExist = true,
        ?Actor $actor = null,
    ): array {
        return $this->withSite(
            $executor,
            $scope,
            static fn(callable $resolve, string $realRoot): array
            => $work($realRoot, $resolve($relative, $mustExist)),
            $actor,
        );
    }

    /**
     * Turns stat data into the shape the screen can use directly
     *
     * @param array{type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string} $info
     * @return array<string,mixed>
     */
    protected static function describe(string $name, string $relative, array $info): array
    {
        return [
            'name' => $name,
            'path' => $relative,
            'type' => $info['type'],
            'size' => $info['size'],
            'mode' => sprintf('%04o', $info['mode']),
            'mtime' => $info['mtime'],
            'owner' => self::resolveUserName((int) ($info['uid'] ?? -1)),
            'group' => self::resolveGroupName((int) ($info['gid'] ?? -1)),
            'link' => $info['link']
        ];
    }

    /**
     * @param int $id
     */
    private static function resolveUserName(int $id): string
    {
        if ($id < 0) {
            return '?';
        }

        if (!function_exists('posix_getpwuid')) {
            return (string) $id;
        }

        $info = @posix_getpwuid($id);

        return is_array($info) && isset($info['name']) ? (string) $info['name'] : (string) $id;
    }

    /**
     * @param int $id
     */
    private static function resolveGroupName(int $id): string
    {
        if ($id < 0) {
            return '?';
        }

        if (!function_exists('posix_getgrgid')) {
            return (string) $id;
        }

        $info = @posix_getgrgid($id);

        return is_array($info) && isset($info['name']) ? (string) $info['name'] : (string) $id;
    }
}
