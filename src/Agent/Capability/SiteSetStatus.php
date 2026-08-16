<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Suspends or resumes a website
 *
 * Suspending deletes nothing at all — it rewrites the vhost to answer 503 on
 * every path, with files and database left fully intact. 503 was chosen over 403
 * because it signals a temporary stop, so search engines won't drop the page from
 * their index.
 *
 * The FPM pool is deleted too, to free the memory — a suspended site shouldn't
 * keep consuming resources.
 */
abstract class SiteSetStatus extends SiteCapability
{
    abstract protected function targetStatus(): string;

    public function permission(): string
    {
        return 'site.suspend';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);
        $target = $this->targetStatus();

        if ($site->status === $target) {
            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'status' => $target,
                'changed' => false,
                'message' => $target === 'active'
                    ? "Website {$site->domain} is already active"
                    : "Website {$site->domain} is already suspended",
            ];
        }

        $updated = $site->withStatus($target);
        $transaction = new ConfigTransaction($executor);

        if ($target === 'active') {
            $provisioner->stageConfigs(
                $transaction,
                $updated,
                $executor,
                $repository->pointerDocrootsOwnedBy($updated->owner->userId, $updated->phpVersion),
            );
        } else {
            // Suspend: the vhost answers 503, and there's no pool left for the site to run on
            $transaction->delete($updated->fpmPoolFile());
            $provisioner->stageVhost($transaction, $updated, $executor);
        }

        $transaction->commit(static fn (): array => $provisioner->validate($executor, $updated));
        $provisioner->reload($executor, $updated);

        $repository->setStatus($site->id, $target);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'status' => $target,
            'changed' => true,
            'message' => $target === 'active'
                ? "Activated website {$site->domain}"
                : "Suspended website {$site->domain} — files and database remain intact",
        ];
    }
}
