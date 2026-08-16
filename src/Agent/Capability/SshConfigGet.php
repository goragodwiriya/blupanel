<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\SshManager;

/** Reads the current SSH configuration — read-only */
final class SshConfigGet implements Capability
{
    public static function name(): string
    {
        return 'ssh.config_get';
    }

    public function permission(): string
    {
        return 'ssh.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read SSH configuration';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new SshManager();
        $values = $manager->read($executor);

        // Assesses risk from the values just read, so the screen can warn about the right thing
        $warnings = [];

        if (in_array($values['PermitRootLogin']['value'], ['yes'], true)) {
            $warnings[] = 'root can log in with a password — this should be disabled';
        }

        if ($values['PasswordAuthentication']['value'] === 'yes') {
            $warnings[] = 'Password login is enabled — a key should be used instead to prevent password guessing';
        }

        if ($values['PermitEmptyPasswords']['value'] === 'yes') {
            $warnings[] = 'Empty passwords are allowed — very dangerous, should be disabled immediately';
        }

        if ($values['PubkeyAuthentication']['value'] === 'no') {
            $warnings[] = 'Key-based login is disabled — leaving password as the only way in';
        }

        return [
            'installed' => $manager->isInstalled($executor),
            'config' => SshManager::CONFIG,
            'values' => $values,
            'warnings' => $warnings,
        ];
    }
}
