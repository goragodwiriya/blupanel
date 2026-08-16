<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Support\Validator;

/**
 * Reads a domain's **real zone file on disk**, and reports whether it still matches the system
 *
 * ## Why read the real file when it could just be rebuilt from the database
 *
 * These two can diverge, and **the moment they diverge is exactly the moment an
 * admin needs the answer most** — "why doesn't what I see on screen match what
 * DNS actually answers". Showing a freshly rebuilt value at that exact moment
 * would confirm their misunderstanding with a screen that looks trustworthy.
 *
 * There are genuinely several ways for them to diverge: `dns.enabled` is off (the
 * file was never written at all) · the last write failed partway through ·
 * someone edited the file by hand over SSH · or the zone was written before the
 * most recent record edit and the sync failed silently.
 *
 * ## How "different" is measured
 *
 * Compares **meaning**, not text — the file on disk is parsed back into records
 * and compared against the database · a literal text comparison would report a
 * difference every single time, because the SOA serial changes on every write,
 * which would be an alarm ringing constantly until nobody listens to it anymore.
 */
final class DnsZoneRead extends DomainCapability
{
    public static function name(): string
    {
        return 'dns.zone_read';
    }

    public function permission(): string
    {
        return 'domain.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read real zone file on disk';
    }

    public function validate(array $args): array
    {
        return ['domain_id' => Validator::requireInt($args, 'domain_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $this->loadDomain($context, $args['domain_id']);
        $name = (string) $domain['domain'];
        $path = BindZoneManager::zonePath($context->config, $name);
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return [
                'domain' => $name,
                'path' => $path,
                'exists' => false,
                'content' => '',
                'drift' => false,
                'drift_reason' => '',
            ];
        }

        try {
            $content = $executor->readFile($resolved);
        } catch (\Throwable $e) {
            return [
                'domain' => $name,
                'path' => $path,
                'exists' => true,
                'content' => '',
                'drift' => true,
                'drift_reason' => 'Failed to read file: ' . $e->getMessage(),
            ];
        }

        [$drift, $reason] = $this->compare($context, (int) $domain['id'], $name, $content);

        return [
            'domain' => $name,
            'path' => $path,
            'exists' => true,
            'content' => $content,
            'drift' => $drift,
            'drift_reason' => $reason,
        ];
    }

    /**
     * Does the file on disk match the records in the system?
     *
     * A file that fails to parse back (a record type the system doesn't support
     * yet, or someone's hand edit broke the format) **counts as different, with
     * the real reason stated** — never swallowed into a false "matches", which
     * would say "everything's fine" about a file the system can't even read.
     *
     * @return array{0:bool,1:string}
     */
    private function compare(Context $context, int $domainId, string $name, string $content): array
    {
        $rows = $context->db->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domainId],
        );

        try {
            $onDisk = DnsRecord::parseZoneFile($name, $content);
        } catch (\Throwable $e) {
            return [true, 'The file on disk has something the system cannot parse — ' . $e->getMessage()];
        }

        $expected = self::fingerprint(array_map(
            static fn (array $row): array => [
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'value' => (string) $row['value'],
                'ttl' => (int) $row['ttl'],
                'priority' => $row['priority'] === null ? null : (int) $row['priority'],
            ],
            $rows,
        ));

        $actual = self::fingerprint($onDisk);

        if ($expected === $actual) {
            return [false, ''];
        }

        return [true, sprintf(
            'The file on disk has %d record(s), the system has %d — click sync to rewrite the file from the system\'s values',
            count($onDisk),
            count($rows),
        )];
    }

    /**
     * An order-independent fingerprint of a set of records
     *
     * @param list<array<string,mixed>> $records
     * @return list<string>
     */
    private static function fingerprint(array $records): array
    {
        $keys = array_map(
            static fn (array $r): string => sprintf(
                '%s|%s|%s|%d|%s',
                $r['type'],
                $r['name'],
                $r['value'],
                $r['ttl'],
                $r['priority'] === null ? '-' : $r['priority'],
            ),
            $records,
        );

        sort($keys);

        return $keys;
    }
}
