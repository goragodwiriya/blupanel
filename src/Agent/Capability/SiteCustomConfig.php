<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\Template;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * Writes a website's supplementary config file — for an admin who knows exactly
 * what they're doing
 *
 * ## Four layers that stop this from breaking the whole machine
 *
 *   1. **Written through `ConfigTransaction`** — the original file is always kept before writing
 *   2. **The web server's own validator** (`apachectl configtest` / `nginx -t`)
 *      is what decides whether to commit or revert — never a syntax checker
 *      written by hand here, which could never match the real thing and would
 *      end up blocking perfectly valid config
 *   3. **`RollbackGuard`** — config that passes configtest but genuinely breaks
 *      the site (an infinite redirect loop, denying everything) still gets
 *      reverted automatically if nobody confirms in time
 *   4. **The file path never comes from the user** — assembled only from the
 *      website's own domain, already passed through Validator · a caller can
 *      only ever send the file's content
 *
 * Layer 3 is the one people tend to forget: `configtest` only answers "is the
 * syntax correct", never "does the site still work" · a rule with every
 * character spelled correctly that blocks every single request passes
 * configtest without any trouble at all.
 */
final class SiteCustomConfig extends SiteCapability
{
    public static function name(): string
    {
        return 'site.custom_config';
    }

    /**
     * **Machine admins only, never a site owner**
     *
     * This file is read by the web server, which runs as a single system user
     * shared across the whole machine · a bad value fails configtest, and
     * **every site** on the machine stops picking up new config along with it,
     * not just the site whose owner wrote it · `site.edit`, which a site owner
     * holds, is therefore never enough for this job.
     */
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
        return 'Write website supplementary config file';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            // The key from the screen — can be empty (a machine caller), but if sent, must be a genuinely editable file
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window'])
                ? (int) $args['window']
                : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->loadSite($context, $args['site_id']);
        $templates = new Template($context->config->paths->templates());
        $driver = self::webServer($context, $templates);

        // The file's type must match the server actually in use, never what the caller claims
        $server = $driver->name() === 'nginx' ? 'nginx' : 'apache';

        /*
         * **Decided from the registry again whether this file is genuinely
         * editable**, never trusting that the screen sent the right key
         *
         * A button that doesn't appear on screen is not a security gate — a
         * hand-crafted request can still send the key of a system-generated
         * file (`site.N.vhost.0`) · the gate has to live at the lowest layer.
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(
                ConfigFileCatalog::forSite($site->id, $site->domain, $driver->name(), $driver->vhostPaths($site)),
                $args['key'],
            );

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'This file cannot be edited from the web page — the system overwrites the whole '
                    . 'file every time the website changes, so anything edited here would silently '
                    . 'vanish · write the value into the supplementary file instead',
                );
            }
        }

        $path = CustomConfig::sitePath($server, $site->domain);
        $custom = new CustomConfig();
        $previous = $custom->read($executor, $server, $site->domain);

        $executor->makeDirectory($executor->path(CustomConfig::siteDirectory($server, $site->domain)), 0755);

        $transaction = new ConfigTransaction($executor);

        /*
         * Empty content = the admin deleted what they'd previously entered ·
         * the file is still written (leaving just its header) rather than
         * deleted, because a file that exists but is empty is easier to
         * understand than a missing file — deleting it would leave the admin
         * with no way to know there was ever a place to write here.
         */
        /*
         * Written exactly as sent, **the header is never added automatically**
         * — the explanation already ships with the starter file. Adding it
         * again here would stack another copy of the header on top of the
         * last one every single save · the admin is free to delete the
         * comments — this file belongs to them.
         */
        $transaction->write($path, $args['content'], 0644);

        $transaction->commit(fn (): array => $driver->testConfig($executor));

        try {
            $driver->reload($executor);
        } catch (\Throwable $e) {
            // Passed configtest, but reload didn't come up — revert immediately, no need to wait out the timer
            $transaction->rollback();
            $driver->reload($executor);

            throw new ExecutionFailed(
                "The configuration passed validation, but telling the web server to reload failed, so it was reverted\n\n"
                . $e->getMessage(),
            );
        }

        /*
         * A timed rollback is armed — passing `configtest` doesn't mean the site still works
         *
         * A rule with every character spelled correctly that rejects every
         * request, or loops in an infinite redirect, passes configtest without
         * any trouble · the admin has to actually open the site and confirm
         * it, or it reverts on its own.
         */
        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: sprintf('Edit supplementary configuration for %s', $site->domain),
            files: [$path => $previous],
            reloadUnits: [$driver->unit()],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'server' => $server,
            'path' => $path,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => sprintf(
                'Saved supplementary configuration for %s — open the site to confirm it genuinely works, '
                . 'then confirm within the time given, or the system will revert it automatically',
                $site->domain,
            ),
        ];
    }
}
