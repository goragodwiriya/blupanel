<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\SiteRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

/**
 * The shared base for Hosting-side endpoints tied to a website
 *
 * Consolidates the "a webadmin sees only their own websites" rule into one
 * place — this rule must be enforced at the query level, not the screen
 * (SECURITY §2.5, prevents IDOR), and must be called from every endpoint that
 * takes a `site_id` from the user · having a single place makes it possible to actually verify every call site does it
 *
 * The check here is **in addition to**, not instead of, what the agent
 * checks: a permission can only answer "can this user edit websites at all?",
 * never "which specific website can they edit?"
 */
abstract class HostingController extends ApiController
{
    protected function sites(): SiteRepository
    {
        return new SiteRepository($this->app->db());
    }

    /** The owner id to filter the list by — null = sees everything */
    protected function scopeOwner(): ?int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : null;
    }

    protected function mayAccessSite(int $siteId): bool
    {
        if ($this->ctx->role() !== Permissions::WEBADMIN) {
            return true;
        }

        return $this->sites()->isOwnedBy($siteId, $this->ctx->userId());
    }

    /**
     * Load a website the caller has permission to see — returns null if not permitted or it doesn't exist
     *
     * Deliberately answers 404 for both cases: answering 403 for "exists but
     * isn't yours" would let a customer probe ids to find out what websites exist on the machine
     *
     * @return array<string,mixed>|null
     */
    protected function findSite(int $siteId): ?array
    {
        if ($siteId < 1 || !$this->mayAccessSite($siteId)) {
            return null;
        }

        return $this->sites()->find($siteId);
    }

    protected function siteNotFound(): \Phpcp\Kernel\Response
    {
        return $this->problem(ApiProblem::NotFound, 'Website not found');
    }
}
