<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Support\Validator;

/**
 * Disables SFTP for a hosting account — PLAN-V2 Phase E4
 *
 * Removes the account from the `phpcp-sftp` group and locks the system account's
 * password · **never deletes the system account or its files**, because the
 * user's website still has to keep running under that uid — disabling SFTP closes
 * an access channel, it isn't decommissioning the service (that's `service_status
 * = suspended`, a completely separate axis).
 *
 * Safe to call again without failing — an already-disabled account is already in
 * the desired state.
 */
final class SftpDisable extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'sftp.disable';
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
        return 'Disable SFTP for a hosting account';
    }

    public function validate(array $args): array
    {
        return ['user_id' => Validator::requireInt($args, 'user_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);

        // No system account = it was never enabled to begin with · just clears the database status to match reality
        if (($user['system_user'] ?? null) !== null) {
            (new SftpAccessManager($executor))->disable(UserAccount::fromRow($user));
        }

        $context->db->update('users', [
            'sftp_enabled' => 0,
            'updated_at' => time(),
        ], ['id' => $args['user_id']]);

        return [
            'user_id' => (int) $args['user_id'],
            'username' => (string) $user['username'],
            'message' => sprintf('Disabled SFTP for %s', (string) $user['username']),
        ];
    }
}
