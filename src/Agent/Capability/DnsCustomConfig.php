<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * Writes BIND9's supplementary config file
 *
 * **A machine-level permission (`settings.manage`), not `domain.manage`** — this
 * file's content gets read when named starts, and affects every customer's zone
 * at once · a site owner with permission to edit their own domain's records must
 * never be able to touch this file, for the same reason as `dns.reload`.
 *
 * ## Rollback has to restore two files, not one
 *
 * RollbackGuard **deletes** a file when its original state was "this file didn't
 * exist yet", then immediately runs `systemctl reload-or-restart` · so the very
 * first save on a machine is the most dangerous case: if only the supplementary
 * file were paired for rollback, the `include` line in `named.conf.local` would
 * remain, pointing at a file that had just been deleted — **the rollback
 * mechanism that exists to prevent breakage would become the very thing that
 * takes down DNS for the whole machine**, and it would go down without the admin
 * having done anything wrong at all.
 *
 * {@see BindZoneManager::writeCustomConfig()} therefore returns the original
 * state of both files, bound together into a single rollback.
 */
final class DnsCustomConfig implements Capability
{
    public static function name(): string
    {
        return 'dns.custom_config';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Write BIND9 supplementary config file';
    }

    public function validate(array $args): array
    {
        return [
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window']) ? (int) $args['window'] : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        /*
         * Decided from the registry again whether this file can actually be
         * edited — a button that doesn't appear on screen is not a security
         * gate, since a hand-crafted request can still send the key of a
         * system-generated file
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(
                ConfigFileCatalog::forDns(BindZoneManager::customConfigPath($context->config)),
                $args['key'],
            );

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'This file cannot be edited from the web page — the panel overwrites the whole '
                    . 'file every time a zone is added or removed, so anything edited here would '
                    . 'silently vanish · write the value into the supplementary file instead',
                );
            }
        }

        $manager = new BindZoneManager($executor, $context->config, $context->db);
        $written = $manager->writeCustomConfig($args['content']);

        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: 'Edit BIND9 supplementary configuration',
            // Both files in a single rollback — see the reasoning at the top of this class
            files: $written['files'],
            reloadUnits: ['named'],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'service' => 'bind',
            'path' => $written['path'],
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => 'Saved BIND9\'s supplementary configuration — test that domains still resolve '
                . 'correctly, then confirm within the time given, or the system will revert it automatically',
        ];
    }
}
