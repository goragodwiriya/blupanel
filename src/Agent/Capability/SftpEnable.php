<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Support\Validator;

/**
 * Enables SFTP for a hosting account and sets its password — PLAN-V2 Phase E4
 *
 * Also used to change the password (safe to call again), so there's no separate
 * `sftp.password` capability — enabling and setting a new password are
 * technically the same action, and having two paths that do the same thing is
 * exactly how one path ends up forgetting a check the other one has.
 *
 * **The `customer.manage` permission (superadmin/sysadmin) is not something a
 * customer holds themselves** — enabling SFTP opens a channel into the machine
 * through sshd, which has none of the rate limiting or 2FA the panel has, so this
 * is a server admin's decision, not the account owner's.
 *
 * **The system account must already exist** — created lazily when the first site
 * is created, so an account with no site yet can't have SFTP enabled, which is
 * correct, since there's nothing to access yet.
 */
final class SftpEnable extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'sftp.enable';
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
        return 'Enable SFTP and set password for a hosting account';
    }

    public function validate(array $args): array
    {
        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'password' => Validator::requireString($args, 'password', 128),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);

        // Quota of 0 = this package doesn't include SFTP · -1 or >0 = can be
        // enabled (see the reasoning at migration 0013)
        if (Quota::isDisabled((int) ($user['quota_ftp_users'] ?? 0))) {
            throw new ValidationError(
                'This account has its FTP/SFTP quota set to 0, so it cannot be enabled — '
                . 'change the quota first if it should be usable',
            );
        }

        if (($user['system_user'] ?? null) === null) {
            throw new ValidationError(
                'This account has no system account yet (created automatically when the first '
                . 'website is created) — create a website first before SFTP can be enabled',
            );
        }

        $account = UserAccount::fromRow($user);
        $result = (new SftpAccessManager($executor))->enable($account, $args['password']);

        $context->db->update('users', [
            'sftp_enabled' => 1,
            'sftp_enabled_at' => time(),
            'updated_at' => time(),
        ], ['id' => $args['user_id']]);

        // The password never appears in the response — Dispatcher::redact()
        // already masks it in the audit log, but the response sent back to the
        // web page shouldn't repeat it either
        return $result + [
            'user_id' => (int) $args['user_id'],
            'message' => sprintf('Enabled SFTP for %s', $account->username),
        ];
    }
}
