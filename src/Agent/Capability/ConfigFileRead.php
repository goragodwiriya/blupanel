<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * Reads one config file from the registry — a shared resource every screen can call
 *
 * Accepts a **key**, not a path · the real path is assembled inside
 * `ConfigFileCatalog` from already-validated data, so a caller can never request
 * a file outside the registry no matter what they send.
 *
 * Reads from the **real file on disk**, never re-renders a template to display —
 * the two can diverge, and the moment they diverge is exactly the moment an admin
 * needs the answer most ("why doesn't what I see on screen match what the server
 * is actually doing").
 */
final class ConfigFileRead extends SiteCapability
{
    public static function name(): string
    {
        return 'config.file_read';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read config file from registry';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // Empty = request the full list · a value = request one file's content
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->loadSite($context, $args['site_id']);
        $driver = self::webServer($context, new Template($context->config->paths->templates()));

        $files = ConfigFileCatalog::forSite(
            $site->id,
            $site->domain,
            $driver->name(),
            $driver->vhostPaths($site),
        );

        // Fills in the real file's status on every row — the screen has to tell "no file yet" apart from "empty"
        foreach ($files as $index => $file) {
            $exists = $executor->exists($executor->path($file['path']));
            $files[$index]['exists'] = $exists;
            $files[$index]['size'] = $exists ? (int) (($executor->stat($executor->path($file['path']))['size']) ?? 0) : 0;
        }

        if ($args['key'] === '') {
            return ['site_id' => $site->id, 'domain' => $site->domain, 'files' => $files];
        }

        $file = ConfigFileCatalog::find($files, $args['key']);

        if ($file === null) {
            throw new ValidationError('This config file is not in the registry');
        }

        $content = $this->contents($executor, (string) $file['path']);

        /*
         * A file never written yet → opens with starter content carrying the full
         * explanation and examples (all commented out) · so an admin never opens
         * a blank page and has to guess what can be written, and the explanation
         * always travels with the file, rather than living only on a screen that
         * disappears once closed.
         */
        if ($content === '' && ($file['kind'] ?? '') === ConfigFileCatalog::KIND_WRITABLE) {
            $content = (new CustomConfig())->seed(
                new Template($context->config->paths->templates()),
                $driver->name() === 'nginx' ? 'nginx' : 'apache',
                $site->domain,
            );
        }

        return $file + ['content' => $content];
    }

    private function contents(Executor $executor, string $path): string
    {
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return '';
        }

        try {
            return $executor->readFile($resolved);
        } catch (\Throwable) {
            return '';
        }
    }
}
