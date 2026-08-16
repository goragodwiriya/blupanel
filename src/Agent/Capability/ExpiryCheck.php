<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserRepository;

/**
 * Checks hosting accounts' expiry dates and sends notifications
 *
 * Runs as a cron job, checking accounts that:
 * - expire in 30, 7, or 1 days from now
 * - have already expired
 *
 * Does:
 * - records the notification into expiry_notifications
 * - sets the customer's status to 'expired' if it's already past due
 */
final class ExpiryCheck implements Capability
{
    public static function name(): string
    {
        return 'expiry.check';
    }

    public function permission(): string
    {
        return 'customer.view';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Check customer expiry dates';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = new UserRepository($context->db);

        $now = time();
        $results = [
            'checked' => 0,
            'notified' => 0,
            'expired' => 0,
            'notifications' => [],
        ];

        // Three advance-warning windows — one account can match several at once
        // (1 day left also matches the 7- and 30-day windows), so they have to be
        // collapsed down to just the closest one, or a single customer would get
        // three emails on the same day
        $due = [];
        foreach ([30, 7, 1] as $daysBefore) {
            foreach ($users->findExpiring($daysBefore) as $user) {
                $due[(int) $user['id']] = $daysBefore;
            }
        }

        $results['checked'] = count($due);

        foreach ($due as $userId => $daysBefore) {
            if ($users->recordExpiryNotification($userId, $daysBefore)) {
                $results['notifications'][] = [
                    'user_id' => $userId,
                    'days_before' => $daysBefore,
                    'action' => 'notified',
                ];
                $results['notified']++;
            }
        }

        // Accounts already past their expiry date — decommission the service, but never touch login access (a separate axis)
        foreach ($users->hostingAccounts() as $user) {
            $expiry = $user['expiry_at'];

            if ($user['service_status'] !== 'active' || $expiry === null || (int) $expiry >= $now) {
                continue;
            }

            $users->setServiceStatus((int) $user['id'], 'expired');

            $results['notifications'][] = [
                'user_id' => (int) $user['id'],
                'days_before' => 0,
                'action' => 'expired',
            ];
            $results['expired']++;
        }

        // The audit log is already written by Dispatcher around every run() call (ARCHITECTURE §4.1)
        return $results;
    }
}
