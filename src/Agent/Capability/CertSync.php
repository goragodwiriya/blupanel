<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * Compares the certificates table against the real certificate files on disk
 *
 * Why this needs to exist when `ssl.list` already reads straight from the files:
 * the SSL page always reads the real thing, true, but the parts that need to
 * "know in advance" without anyone opening the page — expiry warnings and the
 * dashboard — read from this table instead. Without something syncing it, the
 * table would sit at the value from when the certificate was first issued
 * forever, and a certificate certbot renewed on its own (which never goes
 * through the panel at all) would never get recorded.
 *
 * Read-only in the agent's sense: touches nothing on the machine, only writes to the panel's own cache table.
 */
final class CertSync extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'cert.sync';
    }

    public function permission(): string
    {
        return 'ssl.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Sync certificate status in the database with the real files';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $certbot = $this->certbot();
        $repository = $this->repository($context);

        $synced = 0;
        $expiring = 0;
        $missing = 0;
        $seen = [];

        foreach ($context->db->all('SELECT id FROM sites ORDER BY id') as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            $certificate = $certbot->inspect($executor, $site);

            if ($certificate['status'] === 'none') {
                // No certificate means no row should be left behind — otherwise the screen would warn about a certificate that's already gone
                $missing += $this->forget($context, $site->domain);

                continue;
            }

            $seen[] = $site->domain;
            $status = self::normalizeStatus((string) $certificate['status']);

            if (in_array($status, ['expiring', 'expired'], true)) {
                $expiring++;
            }

            $this->store($context, $site->domain, $status, $certificate);
            $synced++;
        }

        return [
            'synced' => $synced,
            'expiring' => $expiring,
            'removed' => $missing,
            'domains' => $seen,
            'message' => sprintf('Synced %d certificate(s) (%d expiring)', $synced, $expiring),
        ];
    }

    /**
     * Normalizes the status from the file reader into a value the column accepts
     *
     * Has to be normalized right here, not by loosening the table's CHECK
     * constraint — the constraint is what stops a strange status from slipping in
     * and the screen ending up showing a word nobody recognizes.
     */
    private static function normalizeStatus(string $status): string
    {
        return in_array($status, ['valid', 'expiring', 'expired'], true) ? $status : 'error';
    }

    /** @param array<string,mixed> $certificate */
    private function store(Context $context, string $domain, string $status, array $certificate): void
    {
        $existing = $context->db->first(
            'SELECT id FROM certificates WHERE domain = :domain',
            ['domain' => $domain],
        );

        $data = [
            'issuer' => mb_substr((string) $certificate['issuer'], 0, 190),
            'status' => $status,
            'not_after' => (int) $certificate['expires_at'],
            'auto_renew' => ($certificate['auto_renew'] ?? false) ? 1 : 0,
            'last_error' => null,
        ];

        if ($existing === null) {
            $context->db->insert('certificates', ['domain' => $domain] + $data);

            return;
        }

        $context->db->update('certificates', $data, ['id' => (int) $existing['id']]);
    }

    private function forget(Context $context, string $domain): int
    {
        return $context->db->run(
            'DELETE FROM certificates WHERE domain = :domain',
            ['domain' => $domain],
        )->rowCount();
    }
}
