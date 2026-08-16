<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * Reads the mail system's config files — a counterpart to a website's `config.file_read`
 *
 * Kept as a separate capability because the scope genuinely differs: a website's
 * version needs to know which site, mail's version belongs to the whole machine ·
 * but the response shape matches field for field, so the screen can reuse the same
 * table and Modal without needing to know which scope it's looking at.
 */
final class MailConfigRead implements Capability
{
    /** Files the panel generates for the mail system — viewable, but not editable */
    private const GENERATED = [
        '/etc/postfix/main.cf',
        MailboxManager::DOVECOT_CONF,
    ];

    public static function name(): string
    {
        return 'mail.config_read';
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
        return 'Read mail system config files';
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
        $files = ConfigFileCatalog::forMail(self::GENERATED);

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
