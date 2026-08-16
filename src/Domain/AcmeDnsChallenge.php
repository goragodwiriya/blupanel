<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Support\BinaryPath;

/**
 * Add/remove the `_acme-challenge` record for DNS-01 validation — PLAN-V2 Phase E7
 *
 * Uses the existing mechanism throughout: write a row into `dns_records`, then let
 * {@see BindZoneManager} regenerate the zone file and issue `rndc reload` — no
 * special path skips `named-checkzone`'s validation.
 *
 * **The core of this is the waiting, not the writing** — certbot treats a hook that
 * finished as meaning the record is ready, and immediately tells Let's Encrypt to go
 * query it · returning before BIND9 is actually serving the new record produces the
 * error "DNS problem: NXDOMAIN looking up TXT," which points at a misconfiguration
 * when the real cause was just querying one second too early.
 *
 * **A limitation worth knowing:** this only checks against the local nameserver
 * (`@127.0.0.1`), not whether the outside world can see it yet · if this domain has a
 * secondary NS that hasn't transferred yet, or Let's Encrypt's resolver already
 * cached a previous NXDOMAIN answer, the certificate request can still fail — the low
 * configured TTL ({@see TTL}) reduces that chance but doesn't eliminate it.
 */
final class AcmeDnsChallenge
{
    /** The record name per the ACME standard — must be exactly this name */
    public const RECORD_NAME = '_acme-challenge';

    /**
     * The lowest sensible TTL — this record only lives for a few minutes
     *
     * Set low because if Let's Encrypt's resolver already queried this name and got
     * NXDOMAIN, it will cache that answer per the SOA's negative TTL · a low value
     * means the next round gets queried fresh sooner.
     */
    public const TTL = 60;

    /** Wait no longer than this for BIND9 to serve the new record — past this, something is genuinely wrong */
    private const MAX_WAIT = 60;

    /** How many seconds between each retry */
    private const POLL_INTERVAL = 2;

    /** @var list<string> */
    private const DIG_PATHS = ['/usr/bin/dig', '/usr/sbin/dig'];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Executor $executor,
    ) {
    }

    /**
     * Add the record and wait until it actually resolves
     *
     * @return array{domain:string,serial:int,waited:int}
     */
    public function publish(string $domain, string $validation): array
    {
        $row = $this->requireZone($domain);

        if ($validation === '' || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $validation) !== 1) {
            // This value comes from certbot, not the user, but it gets written into
            // a zone file that BIND9 reads — a malformed value must never have a way
            // to reach that file.
            throw new ValidationError('Malformed CERTBOT_VALIDATION value');
        }

        // Always delete the old one first — certbot calls auth twice when
        // requesting both example.com and *.example.com in one certificate ·
        // **the second call must not overwrite the first**, since Let's Encrypt
        // checks both values at the same name · so only an exact duplicate value
        // gets deleted.
        $this->db->run(
            'DELETE FROM dns_records WHERE domain_id = :id AND type = :t AND name = :n AND value = :v',
            ['id' => (int) $row['id'], 't' => 'TXT', 'n' => self::RECORD_NAME, 'v' => $validation],
        );

        $this->db->insert('dns_records', [
            'domain_id' => (int) $row['id'],
            'type' => 'TXT',
            'name' => self::RECORD_NAME,
            'value' => $validation,
            'ttl' => self::TTL,
            'priority' => null,
        ]);

        $result = $this->zoneManager()->writeZone($this->reload($row));

        if (($result['pushed'] ?? false) !== true) {
            throw new ExecutionFailed(
                'Failed to push the zone to BIND9: ' . (string) ($result['message'] ?? 'unknown reason'),
            );
        }

        return [
            'domain' => (string) $row['domain'],
            'serial' => (int) ($result['serial'] ?? 0),
            'waited' => $this->waitUntilVisible((string) $row['domain'], $validation),
        ];
    }

    /**
     * Remove every validation record entirely
     *
     * Deletes **every value** for this name, not just the most recent one, because
     * requesting a certificate that covers both the primary domain and its wildcard
     * leaves two rows behind, and certbot calls cleanup twice separately — the first
     * call should already sweep everything · this record has no meaning outside of
     * validation.
     *
     * @return array{domain:string,removed:int}
     */
    public function cleanup(string $domain): array
    {
        $row = $this->findZone($domain);

        if ($row === null) {
            // No zone at all = nothing to delete · cleanup must never fail over
            // this, or certbot would report the certificate request as failed even
            // though the certificate was actually issued.
            return ['domain' => $domain, 'removed' => 0];
        }

        $removed = $this->db->run(
            'DELETE FROM dns_records WHERE domain_id = :id AND type = :t AND name = :n',
            ['id' => (int) $row['id'], 't' => 'TXT', 'n' => self::RECORD_NAME],
        )->rowCount();

        if ($removed > 0) {
            $this->zoneManager()->writeZone($this->reload($row));
        }

        return ['domain' => (string) $row['domain'], 'removed' => $removed];
    }

    /**
     * Query the local nameserver repeatedly until the freshly written value shows up
     *
     * @return int total seconds waited
     */
    private function waitUntilVisible(string $domain, string $validation): int
    {
        $dig = BinaryPath::resolve($this->executor, self::DIG_PATHS, 'dnsutils');
        $name = self::RECORD_NAME . '.' . $domain;
        $waited = 0;

        while ($waited < self::MAX_WAIT) {
            $result = $this->executor->exec(
                [$dig, '@127.0.0.1', '+short', '+time=2', '+tries=1', 'TXT', $name],
                timeout: 10,
            );

            // dig returns a TXT value with quotes attached — strip them before comparing
            if (str_contains(str_replace('"', '', $result->output()), $validation)) {
                return $waited;
            }

            sleep(self::POLL_INTERVAL);
            $waited += self::POLL_INTERVAL;
        }

        throw new ExecutionFailed(sprintf(
            "BIND9 still isn't serving the record %s after waiting %d seconds\n\n"
            . "Check with: dig @127.0.0.1 TXT %s\n"
            . 'If there\'s no answer, the zone was written but BIND9 hasn\'t loaded it yet — check `rndc status`',
            $name,
            self::MAX_WAIT,
            $name,
        ));
    }

    /**
     * Find the domain row that owns this name's zone
     *
     * certbot sends the name being validated, which may be a subdomain with no zone
     * of its own (e.g. `blog.example.com` lives inside `example.com`'s zone) · this
     * must walk upward one level at a time until it finds a domain with a real zone,
     * otherwise the record would be written into the wrong place.
     *
     * @return array<string,mixed>|null
     */
    private function findZone(string $domain): ?array
    {
        $candidate = ltrim($domain, '*.');

        while (str_contains($candidate, '.')) {
            $row = $this->db->first(
                'SELECT * FROM domains WHERE domain = :d AND zone_serial > 0',
                ['d' => $candidate],
            );

            if ($row !== null) {
                return $row;
            }

            $candidate = substr($candidate, (int) strpos($candidate, '.') + 1);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function requireZone(string $domain): array
    {
        if (!$this->config->dnsEnabled()) {
            throw new ValidationError(
                "A wildcard certificate must be validated through DNS, which requires `dns.enabled` first\n\n"
                . 'Set it in /etc/phpcp/config.php, then run `phpcp dns:sync` to push every existing zone out',
            );
        }

        $row = $this->findZone($domain);

        if ($row === null) {
            throw new ValidationError(sprintf(
                "No zone found for %s on this machine — a wildcard certificate requires this machine's"
                . " own BIND9 to own that domain's zone\n\n"
                . 'Add a DNS record for this domain in the panel and click "Push to DNS" first',
                $domain,
            ));
        }

        return $row;
    }

    /**
     * Reload the domain row fresh from the database before handing it to BindZoneManager
     *
     * Necessary because `writeZone()` reads `zone_serial` from the row it's given,
     * not from the database — a row still held in a variable from before a previous
     * write would carry a stale serial, and the new serial wouldn't actually
     * increment, leaving a secondary NS with no way to know anything changed.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function reload(array $row): array
    {
        return $this->db->first('SELECT * FROM domains WHERE id = :id', ['id' => (int) $row['id']]) ?? $row;
    }

    private function zoneManager(): BindZoneManager
    {
        return new BindZoneManager($this->executor, $this->config, $this->db);
    }
}
