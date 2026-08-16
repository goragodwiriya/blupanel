<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\HostnameManager;

/**
 * Sets the machine's hostname — previously only doable from the console
 *
 * This name shows up in Postfix (`myhostname`, when it introduces itself to a
 * destination mail server) and is the name used to request the management
 * certificate · being unable to edit it from the web page would mean a machine
 * misnamed at install time needs someone at the console to fix it, which is
 * exactly what the panel exists to make unnecessary.
 *
 * Uses `settings.manage`, the same permission as other machine-level settings —
 * not `service.control`, since it isn't commanding a service, and not a hosting
 * category permission, since it affects the whole machine.
 */
final class HostnameSet implements Capability
{
    public static function name(): string
    {
        return 'system.hostname_set';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Set the machine hostname';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        return ['hostname' => HostnameManager::assertHostname((string) ($args['hostname'] ?? ''))];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $result = (new HostnameManager())->apply($executor, $args['hostname']);

        $message = $result['previous'] === $result['hostname']
            ? sprintf('Hostname is already %s', $result['hostname'])
            : sprintf('Changed hostname from %s to %s', $result['previous'], $result['hostname']);

        if ($result['hosts_updated']) {
            // Mentions touching /etc/hosts too, since it's a file many admins edit by hand
            $message .= ' · updated the matching line in /etc/hosts';
        }

        return $result + ['message' => $message];
    }
}
