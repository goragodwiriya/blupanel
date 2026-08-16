<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Changes a single website's PHP version — PROMPT.md
 *
 *   example.com        → PHP 8.4
 *   legacy.example.com → PHP 7.4
 *
 * Since migration 0006, pools are shared at the (owner × PHP version) level, so
 * switching one site's version has to be careful of that same owner's other
 * sites: the old version's pool file is only deleted once no other site of that
 * owner is still using it.
 *
 * This is a Hosting-side capability — it never controls the PHP-FPM process
 * directly. Starting/stopping the service itself belongs to the Services page,
 * per the Important UX Rule.
 */
final class SiteSetPhp extends SiteCapability
{
    public static function name(): string
    {
        return 'site.set_php';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Change website PHP version';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'php_version' => self::assertPhpVersion(Validator::requireString($args, 'php_version', 8)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $current = $this->loadSite($context, $args['site_id']);
        $target = $args['php_version'];

        if ($current->phpVersion === $target) {
            return [
                'site_id' => $current->id,
                'domain' => $current->domain,
                'php_version' => $target,
                'changed' => false,
                'message' => "Website {$current->domain} already uses PHP {$target}",
            ];
        }

        if (!$provisioner->fpm()->isVersionInstalled($executor, $target)) {
            throw new ValidationError("PHP {$target} is not installed on this machine");
        }

        $previousVersion = $current->phpVersion;
        $updated = $current->withPhpVersion($target);

        $transaction = new ConfigTransaction($executor);

        // Pools have been shared across an account since migration 0006 — the old
        // version's file can only be deleted once the owner has no other site left
        // using it, or moving one site to a new PHP version would take down every
        // sibling site still on the old one
        $othersOnOldVersion = $repository->phpVersionsOwnedBy($current->owner->userId, exceptSiteId: $current->id);

        if (!in_array($previousVersion, $othersOnOldVersion, true)) {
            $transaction->delete($current->fpmPoolFile());
        }

        $provisioner->stageConfigs(
            $transaction,
            $updated,
            $executor,
            $repository->pointerDocrootsOwnedBy($updated->owner->userId, $target),
        );

        $transaction->commit(static fn (): array => $provisioner->validate($executor, $updated));

        // Saved before reload, not after — the config file has already been
        // committed at this point. If reload failed and this saved afterward, the
        // database would stay on the old version while the new pool had already
        // been written to disk — the screen and reality would disagree
        $repository->setPhpVersion($updated->id, $target);

        $provisioner->reload($executor, $updated, alsoPhpVersion: $previousVersion);

        return [
            'site_id' => $updated->id,
            'domain' => $updated->domain,
            'php_version' => $target,
            'previous_version' => $previousVersion,
            'fpm_socket' => $updated->fpmSocket(),
            'changed' => true,
            'message' => "Changed {$updated->domain} from PHP {$previousVersion} to PHP {$target}",
        ];
    }
}
