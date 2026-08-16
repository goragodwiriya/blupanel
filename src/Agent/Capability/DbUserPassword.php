<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * Sets a new password for a database user
 *
 * Used when a password leaks or is lost — the system generates a new random one
 * and shows it exactly once, same as at creation time, because the panel never
 * stores database passwords at all.
 */
final class DbUserPassword extends DbCapability
{
    public static function name(): string
    {
        return 'db.user_password';
    }

    public function permission(): string
    {
        return 'db.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Set a new password for a database user';
    }

    public function validate(array $args): array
    {
        return [
            'username' => MariaDbManager::assertUserName(Validator::requireString($args, 'username', 32)),
            'host' => Validator::requireEnum(
                ['host' => $args['host'] ?? 'localhost'],
                'host',
                ['localhost', '127.0.0.1', '%'],
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('MariaDB or MySQL is not installed on this machine');
        }

        // The user must be bound to at least one database the caller has permission over
        $databases = $context->db->all(
            'SELECT d.db_name FROM db_grants g
             JOIN db_users u ON u.id = g.db_user_id
             JOIN databases_ d ON d.id = g.db_id
             WHERE u.username = :u AND u.host = :h',
            ['u' => $args['username'], 'h' => $args['host']],
        );

        if ($databases === []) {
            throw new ValidationError('This database user was not found');
        }

        foreach ($databases as $row) {
            $this->assertOwnership($context, (string) $row['db_name']);
        }

        $password = self::randomPassword();
        $manager->setPassword($executor, $args['username'], $args['host'], $password);

        return [
            'username' => $args['username'],
            'host' => $args['host'],
            'password' => $password,
            'databases' => array_column($databases, 'db_name'),
            'message' => "Set a new password for {$args['username']}@{$args['host']}",
        ];
    }
}
