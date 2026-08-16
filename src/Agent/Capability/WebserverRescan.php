<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * Checks whether the vhost files on disk still match each website's real state, and rewrites them if not
 *
 * **Why this job exists:** in nginx-proxy mode, whether nginx can answer a static
 * file itself depends on the content of `.htaccess` at the site root, which
 * **the customer can edit at any time over SFTP or the file manager** with no way
 * for the panel to know · without something re-checking this, a customer who just
 * added a protection rule would believe it's protected while it's actually still
 * open, until someone happens to click edit on that site.
 *
 * Subdirectories already have another safety net (nginx checks `.htaccess` on
 * every incoming request), so this job mainly exists to close the gap for
 * **the file at the site root**.
 *
 * Only rewrites when the content is genuinely different — not an hourly reload for no reason.
 */
final class WebserverRescan extends SiteCapability
{
    public static function name(): string
    {
        return 'webserver.rescan';
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
        return 'Check whether website config files still match reality';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $webserver = $this->provisioner($context)->webserver();
        $changed = [];

        foreach ($repository->listWithCounts() as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            foreach ($webserver->vhostFiles($site, $executor) as $path => $expected) {
                $resolved = $executor->path($path);

                if (!$executor->exists($resolved) || $executor->readFile($resolved) !== $expected) {
                    $changed[] = $site->domain;
                    break;
                }
            }
        }

        if ($changed === []) {
            return [
                'changed' => [],
                'rebuilt' => false,
                'message' => 'Every website\'s config files still match reality',
            ];
        }

        // Even one site being different rewrites the whole set in a single
        // transaction — cheaper than writing site by site with several reloads,
        // and it gets a machine-wide configtest along the way
        $result = (new SiteRebuild())->run([], $executor, $context);

        return [
            'changed' => array_values(array_unique($changed)),
            'rebuilt' => true,
            'message' => sprintf(
                '%d website(s) no longer matched reality, so everything was rewritten (%s)',
                count(array_unique($changed)),
                implode(', ', array_slice(array_unique($changed), 0, 5)),
            ),
        ] + ['detail' => $result['message'] ?? ''];
    }
}
