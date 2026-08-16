<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * Reads BIND9's config files — a counterpart to `config.file_read` and `mail.config_read`
 *
 * **Readable even while `dns.enabled` is off**, unlike writing, which requires it
 * to be on first — the moment an admin most wants to see the file is exactly when
 * DNS isn't working, and hiding the file then would hide the answer in the one
 * minute it's needed most · reading changes nothing on the machine anyway.
 */
final class DnsConfigRead implements Capability
{
    public static function name(): string
    {
        return 'dns.config_read';
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
        return 'Read BIND9 config files';
    }

    public function validate(array $args): array
    {
        return [
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $files = ConfigFileCatalog::forDns(
            BindZoneManager::customConfigPath($context->config),
            [$context->config->dnsNamedConfLocal()],
        );

        foreach ($files as $index => $file) {
            $exists = $executor->exists($executor->path($file['path']));
            $files[$index]['exists'] = $exists;
            $files[$index]['size'] = $exists
                ? (int) (($executor->stat($executor->path($file['path']))['size']) ?? 0)
                : 0;
        }

        if ($args['key'] === '') {
            return ['files' => $files];
        }

        $file = ConfigFileCatalog::find($files, $args['key']);

        if ($file === null) {
            throw new ValidationError('This config file is not in the registry');
        }

        $content = '';
        $resolved = $executor->path((string) $file['path']);

        if ($executor->exists($resolved)) {
            try {
                $content = $executor->readFile($resolved);
            } catch (\Throwable) {
                $content = '';
            }
        }

        // Never written yet → opens with the full explanation and examples, all commented out
        if ($content === '' && $file['kind'] === ConfigFileCatalog::KIND_WRITABLE) {
            $content = (new CustomConfig())->seed(
                new Template($context->config->paths->templates()),
                (string) $file['service'],
            );
        }

        return $file + ['content' => $content];
    }
}
