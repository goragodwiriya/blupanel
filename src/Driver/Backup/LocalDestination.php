<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * A destination that's just another path on this same machine — a NAS mount point, a second disk, a USB drive
 *
 * **Does this still count as "offsite"?** Depends entirely on what's
 * mounted there · if it's a genuinely separate disk or another machine's
 * NFS share, it genuinely solves the problem E1 was meant to solve · if
 * it's just a folder on the same disk, it solves nothing at all — so
 * `test()` reports back whether the destination sits on the same device as
 * the source backup directory, so an admin sees this truth at setup time,
 * not the day the disk dies.
 *
 * This driver also doubles as the one used to test the whole mechanism without needing a second machine.
 */
final class LocalDestination implements Destination
{
    /**
     * @param string $sourceDir the source backup directory — used to answer whether the destination is on the same device
     */
    public function __construct(
        private readonly string $root,
        private readonly string $sourceDir = '',
    ) {
        if ($this->root === '' || !str_starts_with($this->root, '/')) {
            throw new ValidationError('The destination path must be a full path starting with /');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->root) === 1) {
            throw new ValidationError('The destination path must not contain ..');
        }
    }

    public static function driver(): string
    {
        return 'local';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $target = $this->remotePathFor($remoteName);

        $executor->makeDirectory($executor->path($this->root), 0750);
        $executor->copyPath($executor->path($localPath), $executor->path($target));

        // Confirms the file genuinely arrived at the destination complete, not just that the copy command didn't error
        //
        // Comparing size alone isn't enough for something meant to recover
        // data — a disk on its way to failing can return a file with the
        // right size but corrupted content · so a full-file checksum is compared instead
        $this->assertSameContent($executor, $localPath, $target);

        return $target;
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsideRoot($remotePath);

        if (!$executor->exists($executor->path($remotePath))) {
            throw new ExecutionFailed('Backup file not found at destination: ' . $remotePath);
        }

        $executor->copyPath($executor->path($remotePath), $executor->path($localPath));
        $this->assertSameContent($executor, $remotePath, $localPath);
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsideRoot($remotePath);

        $resolved = $executor->path($remotePath);

        if (!$executor->exists($resolved)) {
            return;   // Already deleted — the cleanup job must be able to call this again without failing
        }

        // Symlinks are resolved before deleting · a link pointing outside the destination could pass the string comparison above
        $real = $executor->realPath($resolved);
        $root = rtrim($executor->path($this->root), '/');

        if ($real === null || !str_starts_with($real, $root . '/')) {
            throw new ValidationError('This file points outside the backup destination, so it cannot be deleted through this path');
        }

        $executor->removePath($real);
    }

    public function test(Executor $executor): array
    {
        $executor->makeDirectory($executor->path($this->root), 0750);

        $probe = $this->remotePathFor('.phpcp-probe-' . bin2hex(random_bytes(4)));
        $content = 'phpcp destination probe ' . time();

        $executor->writeFile($executor->path($probe), $content, 0600);

        $readBack = $executor->readFile($executor->path($probe));
        $executor->removePath($executor->path($probe));

        if ($readBack !== $content) {
            throw new ExecutionFailed('Wrote the test file successfully, but reading it back returned different content');
        }

        $space = $executor->diskSpace($executor->path($this->root));

        return [
            'root' => $this->root,
            'free_bytes' => (int) ($space['free'] ?? 0),
            'total_bytes' => (int) ($space['total'] ?? 0),
            // An admin has to see this at setup time, not the day the disk dies
            'same_device' => $this->sameDeviceAsSource($executor),
        ];
    }

    /**
     * Does the destination sit on the same device as the source backup directory?
     *
     * The Executor's `stat()` doesn't return a device number, so this is
     * compared by total free space instead — a genuinely usable indicator
     * that needs no interface change — two paths on the same filesystem
     * always report identical free space.
     */
    private function sameDeviceAsSource(Executor $executor): bool
    {
        if ($this->sourceDir === '') {
            return false;   // Source unknown = cannot answer · never guess "safe"
        }

        $source = $executor->diskSpace($executor->path($this->sourceDir));
        $target = $executor->diskSpace($executor->path($this->root));

        return ($source['total'] ?? -1) === ($target['total'] ?? -2)
            && ($source['free'] ?? -1) === ($target['free'] ?? -2);
    }

    private function remotePathFor(string $name): string
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ValidationError('The destination filename must be a name only, no directory');
        }

        return rtrim($this->root, '/') . '/' . $name;
    }

    private function assertInsideRoot(string $path): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new ValidationError('The destination file path must not contain ..');
        }

        if (!str_starts_with($path, rtrim($this->root, '/') . '/')) {
            throw new ValidationError('This path is outside the configured backup destination');
        }
    }

    private function assertSameContent(Executor $executor, string $a, string $b): void
    {
        $left = @hash_file('sha256', $executor->path($a));
        $right = @hash_file('sha256', $executor->path($b));

        if ($left === false || $right === false) {
            throw new ExecutionFailed('Failed to read the files to confirm they arrived complete');
        }

        if (!hash_equals($left, $right)) {
            throw new ExecutionFailed('The file at the destination does not match the original — treated as a failed push');
        }
    }
}
