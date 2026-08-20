<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServerAddress;
use Phpcp\Driver\SshManager;

/**
 * The host and port an SFTP client has to be pointed at
 *
 * ## Why this exists instead of reading `ssh.config_get`
 *
 * The SFTP page told a customer their account was enabled and what their
 * username was, and then stopped — no host, no port. On a machine whose admin
 * moved SSH off port 22 (which the panel's own Security Center recommends)
 * that leaves the customer with a client that times out and no way to find out
 * why.
 *
 * The obvious fix, calling `ssh.config_get`, cannot work: that capability
 * requires `ssh.view`, a server-admin permission, and rightly so — it answers
 * with the whole sshd configuration, including whether root may log in and
 * whether empty passwords are accepted. Handing a customer a permission that
 * broad to learn one number would be the wrong trade.
 *
 * So this returns **only the number**, under `file.view` — the permission the
 * SFTP page already runs on, because SFTP is file access. Nothing else about
 * sshd leaves the machine through here.
 */
final class SftpConnection implements Capability
{
    public static function name(): string
    {
        return 'sftp.connection';
    }

    public function permission(): string
    {
        return 'file.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read the host and port for SFTP clients';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        /*
         * The public IP, not the network card's — on any NAT'd cloud machine
         * (Lightsail, EC2) the card only ever sees a private address, and
         * handing that to a customer gives them a host that resolves nowhere ·
         * `ServerAddress` already solved this for DNS records
         */
        $host = ServerAddress::detect($executor, $context->config->string('server.public_ip'));

        // Read from the real sshd_config, never from a stored value — the admin
        // may have moved the port by hand, and a remembered number would send
        // every customer to a port nothing is listening on
        $values = (new SshManager())->read($executor);
        $port = (int) ($values['Port']['value'] ?? 22);

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 22,
        ];
    }
}
