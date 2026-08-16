<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * Writes the mail system's supplementary config file — Postfix or Dovecot
 *
 * ## Why write the file and then call `sync()`, instead of just writing the file
 *
 * These two services accept an admin's values in different ways, and that
 * difference matters:
 *
 *   dovecot  `!include_try` points straight at the file — writing it takes effect immediately on reload
 *   postfix  **has no include directive for `main.cf` at all** — its content
 *            has to be appended to the end of `main.cf` when the panel rewrites
 *            that file · so just writing the file has no effect at all until
 *            `main.cf` gets rewritten
 *
 * `sync()` is called in both cases so the same path behaves identically — and
 * because `sync()` is the one place that knows which files need writing to match
 * the machine's real state.
 *
 * ## Rollback order on failure
 *
 * The admin's file is written first, then `sync()` runs, which has `postfix
 * check`/`doveconf -n` inside it · if validation fails, `sync()` throws, and
 * **the admin's file has to be reverted too**, not just the generated file —
 * otherwise the broken file stays in place, and the next sync (triggered by
 * someone else editing a mailbox) fails right along with it, with nobody
 * knowing why.
 */
final class MailCustomConfig extends MailCapability
{
    public static function name(): string
    {
        return 'mail.custom_config';
    }

    /** Mail service configuration belongs to the whole machine, not to any one domain */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return 'Write mail system supplementary config file';
    }

    public function validate(array $args): array
    {
        $service = Validator::requireEnum($args, 'service', ['postfix', 'dovecot']);

        return [
            'service' => $service,
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window']) ? (int) $args['window'] : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $service = $args['service'];

        /*
         * Decided from the registry again whether this file can actually be
         * edited — a button that doesn't appear on screen is not a security
         * gate, since a hand-crafted request can still send the key of a
         * system-generated file
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(ConfigFileCatalog::forMail(), $args['key']);

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'This file cannot be edited from the web page — the system overwrites the whole '
                    . 'file every time mail data changes, so anything edited here would silently '
                    . 'vanish · write the value into the supplementary file instead',
                );
            }
        }

        $path = CustomConfig::servicePath($service);
        $previous = (new CustomConfig())->read($executor, $service);

        $executor->makeDirectory($executor->path(CustomConfig::serviceDirectory($service)), 0755);

        $transaction = new ConfigTransaction($executor);
        $transaction->write($path, $args['content'], 0644);

        // Nothing to validate about the admin's own file at this point — the real validation lives in sync()
        $transaction->commitWithoutValidation();

        try {
            $this->sync($executor, $context);
        } catch (\Throwable $e) {
            // Reverts the admin's file too, not just the generated one — see the reasoning at the top of this class
            $transaction->rollback();
            $this->sync($executor, $context);

            throw new ExecutionFailed(
                "The configuration written failed " . $service . "'s validation and was reverted\n\n"
                . $e->getMessage(),
            );
        }

        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: sprintf('Edit %s supplementary configuration', $service),
            files: [$path => $previous],
            reloadUnits: [$service],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'service' => $service,
            'path' => $path,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => sprintf(
                'Saved %s\'s supplementary configuration — test that mail still sends and receives, '
                . 'then confirm within the time given, or the system will revert it automatically',
                $service,
            ),
        ];
    }
}
