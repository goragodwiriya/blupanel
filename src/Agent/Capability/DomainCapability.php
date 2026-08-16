<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;

/**
 * The base for capabilities that work on a single domain — gathers the "who can
 * touch which domain" rule into one place
 *
 * **The agent must never trust the caller**, even though the web tier has already
 * checked permission — the same pattern as `SiteCapability::assertSiteAccess()` ·
 * this rule used to be copied inside `DnsZoneWrite` alone, and once a second
 * capability that works on domains appeared, that copy became a real risk:
 * someday someone edits the rule in one place, forgets the other, and that hole
 * has nothing to say about it.
 */
abstract class DomainCapability implements Capability
{
    /**
     * Loads a domain with a permission check — throws immediately if not
     * permitted or not found
     *
     * @return array<string,mixed>
     */
    protected function loadDomain(Context $context, int $domainId): array
    {
        $domain = $context->db->first(
            'SELECT d.*, s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :id',
            ['id' => $domainId],
        );

        if ($domain === null) {
            throw new ValidationError('The specified domain was not found');
        }

        $actor = $context->actor;
        $ownerUserId = (int) ($domain['owner_user_id'] ?? 0);

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return $domain;
        }

        if ($ownerUserId !== $actor->userId) {
            throw new PermissionDenied('You do not have permission over the specified domain');
        }

        return $domain;
    }
}
