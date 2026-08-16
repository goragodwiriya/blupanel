<?php

declare(strict_types=1);

namespace Phpcp\Driver\Dns;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Support\BinaryPath;

/**
 * Writes a domain's zone file and genuinely tells BIND9 to reload it — PLAN-V2 phase E3
 *
 * **Why this only just got connected:** `dns_records` has existed since the
 * first migration, but used to only be "the value it's meant to be",
 * exported as text for a user to go paste at an external DNS provider
 * themselves ({@see DnsRecord::toZoneFile()}) — `install.sh` installs the
 * `bind9` package but there was no code anywhere that wrote a file or
 * triggered a reload, so an admin could easily believe "a DNS server is
 * already working" when it wasn't.
 *
 * **Always off by default (`dns.enabled = false`)** — the same reasoning
 * as `Config::sharedOwner()`: an infrastructure-level decision that has to
 * be turned on deliberately after manually confirming this machine is
 * genuinely ready, and every endpoint calling this class must state
 * plainly when it's off, never behave as if it succeeded silently
 * (ARCHITECTURE §10, the same fail-closed rule as `sites.shared_owner`).
 *
 * **The order always validated before genuinely enforcing anything (through {@see ConfigTransaction}):**
 *   1. Write the zone file (and the whole `named.conf.local` file if this domain never had a zone before)
 *   2. `named-checkzone` validates the zone file
 *   3. If it's a new domain, `named-checkconf` validates the entire set (catches duplicate stanzas / cross-file syntax errors)
 *   4. Either check fails → every file is automatically restored, no reload, the existing zone is never damaged
 *   5. Both pass → `rndc reload`, then the new serial is saved to the database
 *
 * **`named.conf.local` is a file phpcp manages in full**, never just
 * appending a stanza — the whole file is rewritten every time, from the
 * list of domains with `zone_serial > 0` in the database (the same
 * approach vhosts/FPM pools always take, derived from the database rather
 * than patched piecemeal) — **if this machine ever had a zone configured by
 * hand before `dns.enabled` was turned on, that zone disappears from
 * `named.conf.local`** — an admin must always be warned about this before turning it on.
 *
 * ## An admin's supplementary file — why BIND needs more care than other services
 *
 * Every other service in this project attaches an admin's own file through
 * an include that tolerates a missing file (Apache's `IncludeOptional`,
 * Dovecot's `!include_try`), or doesn't use include at all (Postfix appends
 * the content directly at the end of `main.cf`) · **BIND offers neither
 * option** — its `include` is strict, and a missing referenced file means
 * `parsing failed: file not found`, and named refuses to start at all
 * (tested against named-checkconf 9.18, confirmed there is no `include_try`).
 *
 * So this does two things no other service here has to:
 *
 *   1. **The include line is derived from whether the file exists**, never
 *      a fixed line — no file, no include · the result is a machine that's
 *      never used this feature has nothing that can ever break, and a
 *      machine whose file was deleted by hand gets it repaired automatically the next time a zone is written.
 *   2. **A restore must restore both files together** ({@see
 *      writeCustomConfig()}) — RollbackGuard *deletes* a file when it
 *      didn't originally exist, then triggers `systemctl
 *      reload-or-restart` · restoring only the supplementary file without
 *      also restoring `named.conf.local` would leave the include line
 *      pointing at a file that was just deleted, and **the safety restore
 *      itself would become what takes down DNS for the entire machine**.
 *
 * **A limitation not yet addressed:** a glue record (the A/AAAA of a
 * nameserver that lives in the same zone) is never generated automatically
 * — if `dns.nameservers` is set to a name that lives inside the zone about
 * to be created, an admin has to add that nameserver's A record as an
 * ordinary DNS record themselves, or `named-checkzone` warns (though
 * doesn't outright reject) with "has no address records".
 */
final class BindZoneManager
{
    /**
     * BIND9's own tools live in different places depending on the distro —
     * Debian/Ubuntu puts `named-checkzone` and `named-checkconf` at
     * `/usr/bin` (the `bind9-utils` package), while RHEL/Alma/Rocky puts
     * them at `/usr/sbin` · `rndc` lives at `/usr/sbin` on both.
     *
     * This used to be hardcoded to `/usr/sbin` for all three and broke on
     * a real Ubuntu machine — the test suite never caught it, since
     * `DryRunExecutor` never runs a genuine command, and the test that does
     * genuinely invoke `named-checkzone` calls it through PATH, never
     * through these constants · this now searches a list, the same
     * approach `MariaDbManager` takes, with a test pinning that the path
     * specified genuinely exists on the machine running the test.
     *
     * @var list<string>
     */
    public const CHECKZONE_PATHS = ['/usr/bin/named-checkzone', '/usr/sbin/named-checkzone'];

    /** @var list<string> */
    public const CHECKCONF_PATHS = ['/usr/bin/named-checkconf', '/usr/sbin/named-checkconf'];

    /** @var list<string> */
    public const RNDC_PATHS = ['/usr/sbin/rndc', '/usr/bin/rndc'];

    public function __construct(
        private readonly Executor $executor,
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    /**
     * A single domain's zone file location
     *
     * Kept static so a capability that only needs to "read" the file
     * doesn't have to construct the whole manager (which requires a Db) ·
     * and it means there's exactly one place that answers where a domain's file lives.
     */
    public static function zonePath(Config $config, string $domain): string
    {
        return $config->dnsZoneDir() . '/' . $domain . '.zone';
    }

    /**
     * An admin's supplementary file — lives **next to `named.conf.local`**, never under `/etc/phpcp`
     *
     * Purely a matter of read permission, and measured against a real
     * machine: named drops root the instant it starts (`named -u bind`),
     * left with only `cap_net_bind_service` and `cap_sys_resource` —
     * **no `cap_dac_read_search`** · `/etc/phpcp` is 750 root:phpcp, so it
     * can never traverse into that directory at all, and `rndc reload`
     * would fail with permission denied every single time, even though the
     * file was written successfully and every check passed.
     *
     * Opening up `/etc/phpcp` for bind to read isn't a trade worth making —
     * that directory holds `config.php`, which stores the panel's own
     * secret key · BIND's own directory is already where the panel writes
     * zone files and `named.conf.local`, so placing this file there adds no new exposure at all.
     *
     * Based on `named.conf.local`'s own location, never a hardcoded
     * `/etc/bind` — a machine that moved that location through
     * `dns.named_conf_local` must always get both files living together.
     */
    public static function customConfigPath(Config $config): string
    {
        return dirname($config->dnsNamedConfLocal()) . '/phpcp-custom.conf';
    }

    /**
     * Writes a single domain's zone from the current records in the database, then triggers a reload
     *
     * @param array<string,mixed> $domain a row from the `domains` table
     * @return array{pushed:bool,message:string,domain?:string,serial?:int,record_count?:int}
     */
    public function writeZone(array $domain): array
    {
        if (!$this->config->dnsEnabled()) {
            return [
                'pushed' => false,
                'message' => 'The BIND9 connection is not turned on yet (dns.enabled = false) — '
                    . 'the record has already been saved in the system, but has not been pushed out to a real DNS server yet',
            ];
        }

        $nameservers = $this->config->dnsNameservers();

        if ($nameservers === []) {
            throw new ValidationError(
                'dns.nameservers is not set yet — at least one is required before a zone can be created '
                . '(BIND9 always rejects a zone with no NS record)',
            );
        }

        $domainName = (string) $domain['domain'];
        $domainId = (int) $domain['id'];
        $isNewZone = (int) $domain['zone_serial'] === 0;
        $serial = $this->nextSerial((int) $domain['zone_serial']);

        $records = $this->db->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domainId],
        );

        $content = DnsRecord::toAuthoritativeZoneFile(
            $domainName,
            $records,
            $serial,
            $nameservers,
            $this->config->dnsSoaEmail(),
        );

        $zoneDir = $this->config->dnsZoneDir();
        $zonePath = self::zonePath($this->config, $domainName);

        $this->executor->makeDirectory($this->executor->path($zoneDir), 0755);

        $tx = new ConfigTransaction($this->executor);
        $tx->write($zonePath, $content, 0644);

        if ($isNewZone) {
            $tx->write($this->config->dnsNamedConfLocal(), $this->buildNamedConfLocal($domainId, $domainName, $zonePath));
        }

        $tx->commit(function () use ($domainName, $zonePath): array {
            return $this->validate($domainName, $zonePath);
        });

        $this->reload();

        $this->db->update('domains', ['zone_serial' => $serial], ['id' => $domainId]);

        return [
            'pushed' => true,
            'domain' => $domainName,
            'serial' => $serial,
            'record_count' => count($records),
            'message' => sprintf("Pushed %s's zone to BIND9 (serial %d)", $domainName, $serial),
        ];
    }

    /**
     * Rewrites every existing domain's zone entirely, then reloads once at
     * the end — used when an admin edited something directly on the BIND9
     * side and wants the panel to overwrite it back to what it should be,
     * or used the first time after turning on `dns.enabled`, to fully push out every record that already existed
     *
     * @return array{pushed:bool,message:string,domains?:int,failed?:list<array{domain:string,error:string}>}
     */
    public function reloadAll(): array
    {
        if (!$this->config->dnsEnabled()) {
            return [
                'pushed' => false,
                'message' => 'The BIND9 connection is not turned on yet (dns.enabled = false)',
            ];
        }

        // Only domains that genuinely have a DNS record — a domain that never had a record added needs no zone at all
        $domains = $this->db->all(
            'SELECT DISTINCT d.* FROM domains d
             JOIN dns_records r ON r.domain_id = d.id',
        );

        $pushed = 0;
        $failed = [];

        foreach ($domains as $domain) {
            try {
                $this->writeZone($domain);
                $pushed++;
            } catch (\Throwable $e) {
                // One domain's failure (e.g. an old record with corrupted
                // data) must not stop every other domain from being synced too
                $failed[] = ['domain' => (string) $domain['domain'], 'error' => $e->getMessage()];
            }
        }

        // Every domain failing = a genuine failure, never "partial
        // success" — this used to always return pushed=true, making the
        // screen show a green "success" banner even though not a single
        // domain synced, the exact same kind of silent failure phase E1's
        // own reasoning warned against: "a destination that fails silently
        // is just as dangerous as no destination at all" (found through
        // testing on the real production server, 2026-08-10).
        $allFailed = $pushed === 0 && $failed !== [];

        if ($allFailed) {
            throw new ExecutionFailed(sprintf(
                "Failed to sync any zone at all (%d domain(s))\n\n%s",
                count($failed),
                implode("\n", array_map(
                    static fn (array $f): string => "· {$f['domain']}: {$f['error']}",
                    $failed,
                )),
            ));
        }

        return [
            'pushed' => true,
            'domains' => $pushed,
            'failed' => $failed,
            'message' => $failed === []
                ? sprintf('Synced all %d domain(s)', $pushed)
                : sprintf('Synced %d domain(s) · %d domain(s) failed', $pushed, count($failed)),
        ];
    }

    /**
     * The next serial number — always increases only (never repeats or
     * goes backward, or some secondaries/resolvers won't pull the new data
     * at all, believing it's an older copy than what they already hold) ·
     * starts from the YYYYMMDDnn shape DNS convention uses, so reading it
     * immediately shows the last edit date, but isn't tied to that shape if
     * something is edited more than 100 times in a single day (a plain `+1` is always still correct).
     */
    private function nextSerial(int $current): int
    {
        $dateSeed = (int) date('Ymd') * 100;

        return max($current + 1, $dateSeed);
    }

    /**
     * Rewrites the entire `named.conf.local` file from the list of domains
     * that already have a zone in the database (`zone_serial > 0`), unioned
     * with the domain currently being created (not committed yet, so its
     * zone_serial is still 0 — has to be added in by hand).
     */
    private function buildNamedConfLocal(int $newDomainId = 0, string $newDomainName = '', string $newZonePath = '', ?bool $withCustom = null): string
    {
        $existing = $this->db->all('SELECT domain FROM domains WHERE zone_serial > 0 AND id != :id', ['id' => $newDomainId]);

        $lines = [
            '// Managed entirely and automatically by phpcp — do not edit by hand',
            '// Any edit disappears the instant a zone is next added or removed through the panel',
            '// Add or edit a zone only through the panel\'s DNS page',
            '',
        ];

        foreach ($existing as $row) {
            $domainName = (string) $row['domain'];
            $lines[] = $this->zoneStanza($domainName, $this->config->dnsZoneDir() . '/' . $domainName . '.zone');
        }

        if ($newDomainName !== '') {
            $lines[] = $this->zoneStanza($newDomainName, $newZonePath);
        }

        /*
         * The supplementary file's include line — **only exists when that file genuinely does**
         *
         * BIND's `include` doesn't tolerate a missing file — a referenced
         * file that doesn't exist stops named from starting on the whole
         * machine · deriving this from the genuine state on disk, instead
         * of writing a fixed line, means a machine that's never used this
         * feature has nothing that can ever break, and a machine whose
         * file was deleted by hand gets it repaired automatically the next time a zone is written.
         *
         * `$withCustom` can be stated directly for a caller that's writing
         * that same file within the same transaction — while the text is
         * being assembled the file hasn't been written to disk yet, so asking disk would give the wrong answer.
         */
        $customPath = self::customConfigPath($this->config);

        if ($withCustom ?? $this->executor->exists($this->executor->path($customPath))) {
            $lines[] = "// An admin's own supplementary settings — read last, edited from the panel's domain page";
            $lines[] = sprintf('include "%s";', $customPath);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Writes an admin's supplementary file, then keeps `named.conf.local`'s include line in sync with it
     *
     * Both files live in the same transaction, because they're always
     * correct or wrong together — a file that's included but doesn't exist
     * stops named from starting, and a missing include line means what an
     * admin just wrote has no effect with nothing complaining.
     *
     * Returns **both files' previous state** for the caller to arm a
     * RollbackGuard with — see the class docblock for why restoring only
     * one file is more dangerous than restoring neither.
     *
     * @return array{path:string,files:array<string,string|null>}
     */
    public function writeCustomConfig(string $content): array
    {
        if (!$this->config->dnsEnabled()) {
            throw new ValidationError(
                'The BIND9 connection is not turned on yet (dns.enabled = false) — '
                . "the panel doesn't manage this machine's named.conf.local yet, so a supplementary file "
                . 'cannot be attached — turn on DNS from the settings page first',
            );
        }

        $customPath = self::customConfigPath($this->config);
        $namedConfLocal = $this->config->dnsNamedConfLocal();

        // The previous state is saved before touching anything at all · null = this file didn't exist (RollbackGuard will delete it)
        $previous = [
            $customPath => $this->currentContents($customPath),
            $namedConfLocal => $this->currentContents($namedConfLocal),
        ];

        $this->executor->makeDirectory($this->executor->path(dirname($customPath)), 0755);

        $transaction = new ConfigTransaction($this->executor);
        $transaction->write($customPath, $content, 0644);
        $transaction->write($namedConfLocal, $this->buildNamedConfLocal(withCustom: true));

        // BIND's own validator reads the entire chain starting from
        // named.conf, so it sees both the file just written and every
        // existing zone — catching a duplicate zone or duplicate option across files
        $transaction->commit(fn (): array => $this->checkConf());

        $this->reload();

        return ['path' => $customPath, 'files' => $previous];
    }

    /** The file's current content — null when the file doesn't exist yet, meaningfully different from an empty file */
    private function currentContents(string $path): ?string
    {
        $resolved = $this->executor->path($path);

        if (!$this->executor->exists($resolved)) {
            return null;
        }

        try {
            return $this->executor->readFile($resolved);
        } catch (\Throwable) {
            return null;
        }
    }

    private function zoneStanza(string $domain, string $zonePath): string
    {
        return sprintf(
            "zone \"%s\" {\n    type master;\n    file \"%s\";\n};\n",
            $domain,
            $zonePath,
        );
    }

    /**
     * Finds the first genuinely-existing binary from a list of candidates
     *
     * @param list<string> $candidates
     */
    private function binary(array $candidates, string $package): string
    {
        return BinaryPath::resolve($this->executor, $candidates, $package);
    }

    /** @return array{0:bool,1:string} */
    private function validate(string $domainName, string $zonePath): array
    {
        $zoneCheck = $this->executor->exec(
            [$this->binary(self::CHECKZONE_PATHS, 'bind9-utils'), $domainName, $this->executor->path($zonePath)],
            timeout: 30,
        );

        if (!$zoneCheck->ok()) {
            return [false, 'named-checkzone: ' . trim($zoneCheck->output() . $zoneCheck->stderr)];
        }

        // The entire set (the main named.conf, which already includes
        // named.conf.local) is always validated, not just for a new domain
        // — editing an existing zone file shouldn't affect anything else,
        // but this check is free, and skipping it saves nothing worth the risk
        return $this->checkConf();
    }

    /**
     * Validates the entire configuration with `named-checkconf`
     *
     * Deliberately accepts no path parameter — it reads the whole chain
     * starting from the main `named.conf` on its own, so it sees every
     * file named would genuinely see at start time, including an included
     * supplementary file and another domain's zone that might collide · checking one file at a time can never see that kind of collision.
     *
     * @return array{0:bool,1:string}
     */
    private function checkConf(): array
    {
        $check = $this->executor->exec([$this->binary(self::CHECKCONF_PATHS, 'bind9-utils')], timeout: 15);

        if (!$check->ok()) {
            return [false, 'named-checkconf: ' . trim($check->output() . $check->stderr)];
        }

        return [true, ''];
    }

    private function reload(): void
    {
        $result = $this->executor->exec([$this->binary(self::RNDC_PATHS, 'bind9'), 'reload'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'The zone file passed validation, but telling BIND9 to reload failed: '
                . trim($result->output() . $result->stderr)
                . "\n\nThe file on disk is already correct — try syncing again later",
            );
        }
    }
}
