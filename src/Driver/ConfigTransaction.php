<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * Writes several config files reversibly — ARCHITECTURE §10
 *
 * Writing a web server config wrong and then reloading it is a control
 * panel's single most common accident, because it takes down *every* site
 * on the machine at once, not just the one being edited.
 *
 * The order enforced here:
 *   1. Back up every original file about to be touched
 *   2. Write all the new files
 *   3. Validate the config with that service's own tool
 *   4a. Passes    → reload → delete the backups
 *   4b. Fails     → restore every original file → never reload → throw with the real stderr
 *
 * Step 4b is this class's entire reason for existing.
 */
final class ConfigTransaction
{
    /** @var array<string,string|null> path => original content (null = this file never existed) */
    private array $backups = [];

    /** @var list<string> files written or deleted so far in this transaction */
    private array $touched = [];

    private bool $finished = false;

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * Writes a single config file (not considered successful until commit)
     *
     * @param string $path a real-system path — automatically mapped to match the current mode
     */
    public function write(string $path, string $content, int $mode = 0644): void
    {
        $this->assertOpen();

        $resolved = $this->executor->path($path);
        $this->backup($path, $resolved);

        $this->executor->writeFile($resolved, $content, $mode);
        $this->touched[] = $path;
    }

    public function delete(string $path): void
    {
        $this->assertOpen();

        $resolved = $this->executor->path($path);
        $this->backup($path, $resolved);

        if ($this->executor->exists($resolved)) {
            // Cannot delete by overwriting with an empty file — Apache
            // would still include an empty file — so it has to be genuinely
            // deleted, relying on the backup to restore it
            @unlink($resolved);
        }

        $this->touched[] = $path;
    }

    /**
     * Validates, then commits — $validate must return [ok, error message]
     *
     * @param callable():array{0:bool,1:string} $validate
     */
    public function commit(callable $validate): void
    {
        $this->assertOpen();

        [$ok, $error] = $validate();

        if (!$ok) {
            $this->rollback();

            throw new ExecutionFailed(
                "The generated configuration failed validation, so everything was reverted\n\n" . trim($error),
            );
        }

        $this->finished = true;
        $this->backups = [];
    }

    /** Commits without validating — used for files with no validation tool of their own, e.g. an FPM pool */
    public function commitWithoutValidation(): void
    {
        $this->assertOpen();

        $this->finished = true;
        $this->backups = [];
    }

    /** Restores every file back to its state before the transaction began */
    public function rollback(): void
    {
        foreach ($this->backups as $path => $original) {
            $resolved = $this->executor->path($path);

            if ($original === null) {
                // This file never existed — delete it to restore that state
                if ($this->executor->exists($resolved)) {
                    @unlink($resolved);
                }
                continue;
            }

            $this->executor->writeFile($resolved, $original);
        }

        $this->backups = [];
        $this->finished = true;
    }

    /** @return list<string> */
    public function touched(): array
    {
        return $this->touched;
    }

    private function backup(string $path, string $resolved): void
    {
        if (array_key_exists($path, $this->backups)) {
            return;   // Already backed up in this transaction — never overwrite it with content just written
        }

        $this->backups[$path] = $this->executor->exists($resolved)
            ? $this->executor->readFile($resolved)
            : null;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new \LogicException('This transaction has already finished');
        }
    }
}
