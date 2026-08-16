<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\Executor\Executor;

/**
 * A destination a backup file is pushed off to — PLAN-V2 phase E1
 *
 * **The contract every driver has to keep**
 *
 * 1. `push()` must confirm the file genuinely arrived at the destination
 *    complete, not just that "the command didn't error" · a backup file
 *    that arrived half-written is more dangerous than no file at all,
 *    because an admin would believe something is there.
 * 2. A failure must throw `ExecutionFailed` with a message that says **what
 *    to do next**, never silently return false — a destination that fails
 *    silently is the worst possible outcome in this whole area.
 * 3. `delete()` must only ever delete within that destination's own path,
 *    and must tolerate a file that's already gone (so the cleanup job can
 *    call it again without failing).
 *
 * **Why no off-the-shelf library:** this project deliberately has no
 * Composer (ARCHITECTURE §2), so every driver runs the system's own tools
 * through `Executor`, the same path every other capability uses — getting
 * audit logging, dryrun mode, and permission limits all for free.
 */
interface Destination
{
    /** The driver name stored in the `driver` column */
    public static function driver(): string;

    /**
     * Pushes a backup file out to the destination
     *
     * @param string $localPath the full path on this machine
     * @param string $remoteName the destination filename (name only, no directory)
     * @return string the destination path that can be used to refer to this file later
     */
    public function push(Executor $executor, string $localPath, string $remoteName): string;

    /**
     * Pulls a file back down to a path on this machine
     *
     * Used by a driver's own `test()`, for drivers that talk to another
     * machine (ssh/rsync/s3), to prove that **what was written and what
     * gets read back are the same content** — not just that "the command
     * didn't error" — a destination that accepts a file but can't return it
     * is a destination that's only discovered broken the moment the file is actually needed.
     *
     * (`backup.import` used to also use this to pull a file back and
     * register it · that capability has since been removed — a destination
     * only ever has one set of files, and having an admin copy the file
     * back manually is more direct.)
     */
    public function pull(Executor $executor, string $remotePath, string $localPath): void;

    /** Deletes a file at the destination — a file that's already gone counts as success */
    public function delete(Executor $executor, string $remotePath): void;

    /**
     * Tests that the destination genuinely works — can write, can read back, can delete
     *
     * Tests by **genuinely writing a file and reading it back**, not just
     * connecting, because the most common failure is a connection that
     * succeeds but has no write permission in that directory.
     *
     * @return array<string,mixed> details the screen can display
     */
    public function test(Executor $executor): array;
}
