<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Ssh\SftpAccessManager;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * An account owner changes the SFTP password of **their own** account
 *
 * Deliberately separate from `sftp.enable`, whose `customer.manage` permission
 * encodes the decision that *opening* the sshd channel is a server admin's
 * call — changing the password of a channel that is already open adds no new
 * access, so the owner may do it themselves, exactly like they can change
 * their own panel password.
 *
 * The user id is never an argument: it is always taken from the actor, so
 * there is no way to shape a request that touches anyone else's account.
 */
final class SftpOwnPassword extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'sftp.own_password';
    }

    /** `file.manage` — the SFTP channel is file access; whoever may write files may hold its key */
    public function permission(): string
    {
        return 'file.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Change the SFTP password of your own account';
    }

    public function validate(array $args): array
    {
        // Length and character rules are asserted again by
        // SftpAccessManager::assertPassword() — this only guards the transport size
        return [
            'password' => Validator::requireString($args, 'password', 128),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if ($context->actor->role !== Permissions::WEBADMIN) {
            throw new ValidationError('Only a hosting account has an SFTP password');
        }

        $user = $this->loadHostingAccount($context, $context->actor->userId);

        if ((int) ($user['sftp_enabled'] ?? 0) !== 1) {
            // Not "access denied" — the honest state: the channel is closed, and
            // opening it is the admin's decision (see SftpEnable)
            throw new ValidationError(
                'SFTP is not enabled for this account — ask your hosting provider to enable it first',
            );
        }

        $account = UserAccount::fromRow($user);
        (new SftpAccessManager($executor))->enable($account, $args['password']);

        // sftp_enabled_at is left alone on purpose — it answers "open since
        // when", and a password change is not a new opening

        return [
            'user_id' => (int) $user['id'],
            'username' => $account->username,
            'message' => 'SFTP password changed',
        ];
    }
}
