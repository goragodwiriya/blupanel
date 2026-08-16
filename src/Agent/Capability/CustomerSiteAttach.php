<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * Hands a website an admin holds over to a hosting account
 *
 * Does:
 * - checks the site genuinely exists and isn't already owned by another customer (if it is, the owner-transfer command is required instead)
 * - checks the recipient's service status and quota, including everything the site carries with it
 * - sets sites.owner_user_id, the single source of truth for ownership since migration 0005
 */
final class CustomerSiteAttach extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.site_attach';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Attach website to customer as owner';
    }

    public function validate(array $args): array
    {
        $userId = Validator::requireInt($args, 'user_id', 1);

        // Validates site_id (can be an array to attach several sites at once)
        if (isset($args['site_ids']) && is_array($args['site_ids'])) {
            $siteIds = [];
            foreach ($args['site_ids'] as $id) {
                $siteIds[] = Validator::requireInt(['id' => $id], 'id', 1);
            }
            $siteIds = array_unique($siteIds);
        } else {
            $siteId = Validator::requireInt($args, 'site_id', 1);
            $siteIds = [$siteId];
        }

        // Checks that at least one site_id was given
        if (empty($siteIds)) {
            throw new ValidationError('At least one website to attach must be specified');
        }

        return [
            'user_id' => $userId,
            'site_ids' => $siteIds,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $quotaChecker = $this->quotaChecker($context);
        $owner = $this->loadHostingAccount($context, $args['user_id']);

        $results = [];
        $attachedCount = 0;
        $alreadyAttachedCount = 0;
        $siteNotFoundCount = 0;
        $quotaExceededCount = 0;

        foreach ($args['site_ids'] as $siteId) {
            $site = $context->db->first('SELECT * FROM sites WHERE id = :id', ['id' => $siteId]);

            if ($site === null) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'not_found',
                    'message' => 'Website not found',
                ];
                $siteNotFoundCount++;
                continue;
            }

            // Ownership now lives in exactly one place, sites.owner_user_id —
            // ownership used to be stored in both customer_sites and
            // owner_user_id, which could genuinely disagree with each other in
            // the real database
            //
            // And every site always has an owner (the database enforces it) — so
            // "a site not yet sold" means a site an admin is holding, not a site
            // with no owner at all
            $currentOwner = (int) ($site['owner_user_id'] ?? 0);

            if ($currentOwner === $args['user_id']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'already_attached',
                    'message' => 'This website already belongs to this account',
                ];
                $alreadyAttachedCount++;
                continue;
            }

            // A site can't be pulled away from another customer with this
            // command — it requires the deliberate owner-transfer command,
            // because since Phase M3, transferring ownership means moving files
            // across homes and changing uid throughout the whole tree
            // (a site an admin is holding doesn't meet this condition — handing
            // it to a customer is exactly what this command is for)
            $currentRole = (string) $context->db->value(
                'SELECT role FROM users WHERE id = :id',
                ['id' => $currentOwner],
                '',
            );

            if ($currentRole === Permissions::WEBADMIN) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'already_attached',
                    'message' => 'This website already belongs to another customer — transfer ownership first',
                ];
                continue;
            }

            $service = $this->users($context)->checkService($args['user_id']);
            if (!$service['ok']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'customer_suspended',
                    'message' => $service['message'],
                ];
                continue;
            }

            // A site being attached already carries its own subdomains/aliases/databases
            // with it, so those have to count against quota before attaching —
            // not let it overflow and only get discovered when the customer
            // can't add their next domain with no idea why. Checked fresh every
            // time, so it also counts other sites just attached in this same command.
            $quotaCheck = $this->checkAttachQuota($context, $args['user_id'], $siteId);
            if (!$quotaCheck['ok']) {
                $results[] = [
                    'site_id' => $siteId,
                    'status' => 'quota_exceeded',
                    'message' => $quotaCheck['message'],
                ];
                $quotaExceededCount++;
                continue;
            }

            $context->db->update('sites', [
                'owner_user_id' => $args['user_id'],
                'updated_at' => time(),
            ], ['id' => $siteId]);

            $results[] = [
                'site_id' => $siteId,
                'status' => 'attached',
                'message' => "Attached website {$site['primary_domain']} to {$owner['username']}",
            ];
            $attachedCount++;
        }

        // The audit log is already written by Dispatcher around every run() call (ARCHITECTURE §4.1)
        $message = $attachedCount > 0
            ? "Attached {$attachedCount} website(s) to {$owner['username']}"
            : 'No websites were newly attached';

        // A site skipped for exceeding quota has to be in the summary message,
        // not hidden inside results — an admin who selected 5 sites and got 2
        // attached needs to know immediately why the other 3 didn't go through
        if ($quotaExceededCount > 0) {
            $message .= " ({$quotaExceededCount} skipped for exceeding quota)";
        }

        return [
            'user_id' => $owner['id'],
            'username' => $owner['username'],
            'attached_count' => $attachedCount,
            'already_attached_count' => $alreadyAttachedCount,
            'not_found_count' => $siteNotFoundCount,
            'quota_exceeded_count' => $quotaExceededCount,
            'results' => $results,
            'message' => $message,
        ];
    }
}
