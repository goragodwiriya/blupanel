<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * Deletes a website — an irreversible operation, so it carries two extra layers of protection
 *
 *   1. The domain name has to be sent to confirm it matches what's being deleted (guards against clicking the wrong row)
 *   2. Files aren't deleted immediately — they're moved to a holding area, and
 *      genuinely deleted later on a time policy per SECURITY §4 — a mistaken
 *      delete can still be recovered
 *
 * A website's MySQL database is never touched here (that's Phase 3) — a row in
 * the databases_ table gets unlinked via ON DELETE SET NULL, not deleted along with it.
 */
final class SiteDelete extends SiteCapability
{
    public static function name(): string
    {
        return 'site.delete';
    }

    public function permission(): string
    {
        return 'site.delete';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Delete website, moving files to a holding area before real deletion';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'confirm_domain' => Validator::domain(Validator::requireString($args, 'confirm_domain', 253)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);

        // Layer 1 — the typed confirmation name must match the website genuinely being deleted
        if ($args['confirm_domain'] !== $site->domain) {
            throw new ValidationError(
                'The confirmation domain name does not match the website being deleted — deletion cancelled for safety',
            );
        }

        // The PHP versions the owner still uses **after this site is deleted** —
        // those versions' pool files have to stay, since the same customer's
        // sibling sites are still using them
        $stillUsed = $repository->phpVersionsOwnedBy($site->owner->userId, exceptSiteId: $site->id);

        // Removes the fail2ban jail before anything else — this has to happen
        // before the files are moved (PLAN-V2 Phase E5)
        //
        // A leftover jail would watch an access log that's already been moved to
        // the holding area · fail2ban warns on every reload that it can't find
        // the file, and if the same domain is ever recreated later, the old jail
        // still pointing at the wrong file would make the new setup fail with
        // nobody knowing why.
        //
        // A failure here must never stop the site deletion — the user already
        // gave the delete command and confirmed the domain name. Getting stuck
        // halfway because of a fail2ban problem is worse than leaving one orphaned jail file.
        try {
            (new Fail2banManager($executor))->remove($site);
        } catch (\Throwable) {
            // A leftover file can be cleaned up by hand · it has no effect on
            // other sites, since files are kept separate per site
        }

        // Config is removed from the system first, so the site stops being served immediately
        $transaction = new ConfigTransaction($executor);
        $provisioner->stageRemoval($transaction, $site, ServiceCatalog::PHP_VERSIONS, $stillUsed);
        $transaction->commit(static fn (): array => $provisioner->webserver()->testConfig($executor));

        $provisioner->reload($executor, $site);

        // Layer 2 — files move to the holding area, never deleted immediately
        $trash = $this->moveToTrash($executor, $site->root(), $site->domain);

        // The FPM pool is shared with the owner's other sites, so it's rewritten
        // from the sites genuinely still remaining, rather than the pool file
        // being deleted — deleting it would take down sibling sites instantly
        $repository->delete($site->id);

        $remaining = $repository->countOwnedBy($site->owner->userId);

        if ($remaining === 0) {
            // No sites left, so the system account is reclaimed · as long as
            // even one site remains, deleting the account would leave that
            // site's files owned by a uid with no owner immediately
            $provisioner->account()->remove($executor, $site->owner);
            $context->db->update('users', [
                'system_user' => null,
                'uid' => 0,
                'gid' => 0,
                'updated_at' => time(),
            ], ['id' => $site->owner->userId]);
        }

        if ($this->isLocalEnvironment($executor, $context)) {
            if (str_ends_with($site->domain, '.test')) {
                $this->updateHostsFile($executor, $site->domain, false);
            }
            foreach ($site->aliases as $alias) {
                if (str_ends_with($alias, '.test')) {
                    $this->updateHostsFile($executor, $alias, false);
                }
            }
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'trash_path' => $trash,
            'account_removed' => $remaining === 0,
            'message' => "Deleted website {$site->domain} — files moved to the holding area, recoverable until cleared",
        ];
    }

    /**
     * Moves a website's home to the holding area, returns the destination path
     *
     * Uses rename, which is atomic within the same filesystem — no half-finished state
     */
    private function moveToTrash(Executor $executor, string $root, string $domain): string
    {
        $source = $executor->path($root);
        $target = $executor->path('/var/lib/phpcp/trash/' . $domain . '-' . date('Ymd-His'));

        if (!$executor->exists($source)) {
            return '';
        }

        $executor->makeDirectory(dirname($target), 0750);

        if (!@rename($source, $target)) {
            // rename can't cross a filesystem boundary — mv handles that case instead
            $executor->exec(['/usr/bin/mv', $source, $target], timeout: 120);
        }

        return $target;
    }
}
