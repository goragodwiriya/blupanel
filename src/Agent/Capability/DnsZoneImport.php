<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * Edits an entire zone's records at once by writing them as zone-file text
 *
 * ## Why the file on disk isn't editable directly
 *
 * The zone file is rewritten in full from the database every time someone
 * touches even one record — so editing the file directly would take effect
 * immediately, look entirely correct, and then vanish silently the day someone
 * clicks to add another record · the same trap this entire project has been
 * dodging throughout.
 *
 * So this instead **parses the text back into records in the database**, and
 * lets the system write the file itself as usual · from the user's point of
 * view it's "edit the file and it takes effect", but the database remains the
 * single source of truth, so the table view and the file always agree, and
 * nothing vanishes later.
 *
 * ## Why this has to be a "replace the whole set", not "add to it"
 *
 * The real job that made this page necessary is moving mail to another
 * provider (entering Google's five MX records, say), which **requires removing
 * every old MX record first**, or mail would flow two ways at once · clicking
 * delete one at a time and then add one at a time would leave the zone
 * genuinely half-configured on the internet for that whole window · replacing
 * the whole set in one command is therefore safer, not just more convenient.
 *
 * ## Order of operations and rollback
 *
 * The entire text is parsed successfully before the database is touched →
 * records are swapped in a single transaction → the zone file is written
 * (which has `named-checkzone` inside it and reverts the file on its own if
 * that fails) → **if that last step fails, the original records must be
 * restored to the database too**, or the system would be left with new values
 * BIND refuses to accept, and the next sync anyone triggers would fail right
 * along with it, with nobody knowing why.
 */
final class DnsZoneImport extends DomainCapability
{
    public static function name(): string
    {
        return 'dns.zone_import';
    }

    /** Same permission as adding/removing records one at a time — the same job, done in bulk */
    public function permission(): string
    {
        return 'domain.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Replace entire zone records from zone-file text';
    }

    public function validate(array $args): array
    {
        return [
            'domain_id' => Validator::requireInt($args, 'domain_id', 1),
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $this->loadDomain($context, $args['domain_id']);
        $domainId = (int) $domain['id'];
        $name = (string) $domain['domain'];

        // Everything is parsed successfully before the database is touched — an
        // error on line 40 must never leave the first 39 records in a state the user never asked for
        $records = DnsRecord::parseZoneFile($name, $args['content']);

        $previous = $context->db->all(
            'SELECT type, name, value, ttl, priority FROM dns_records WHERE domain_id = :id',
            ['id' => $domainId],
        );

        $context->db->transaction(function () use ($context, $domainId, $records): void {
            $this->replace($context, $domainId, $records);
        });

        try {
            $sync = (new BindZoneManager($executor, $context->config, $context->db))->writeZone($domain);
        } catch (\Throwable $e) {
            // Restores the original records — see the reasoning at the top of
            // this class for why leaving the new values in place is more dangerous
            $context->db->transaction(function () use ($context, $domainId, $previous): void {
                $this->replace($context, $domainId, $previous);
            });

            throw new ExecutionFailed(
                "The new set of records failed BIND9's validation, so everything was reverted\n\n" . $e->getMessage(),
            );
        }

        return [
            'domain' => $name,
            'record_count' => count($records),
            'previous_count' => count($previous),
            'pushed' => (bool) ($sync['pushed'] ?? false),
            'message' => sprintf(
                'Replaced records for %s — was %d record(s), now %d record(s)%s',
                $name,
                count($previous),
                count($records),
                ($sync['pushed'] ?? false) ? '' : ' (not yet pushed to BIND9: ' . ($sync['message'] ?? '') . ')',
            ),
        ];
    }

    /**
     * Overwrites all of one domain's records
     *
     * @param list<array<string,mixed>> $records
     */
    private function replace(Context $context, int $domainId, array $records): void
    {
        $context->db->run('DELETE FROM dns_records WHERE domain_id = :id', ['id' => $domainId]);

        foreach ($records as $record) {
            $context->db->insert('dns_records', [
                'domain_id' => $domainId,
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'ttl' => (int) $record['ttl'],
                // The dns_records table has no timestamp column — including one would fail the whole insert
                'priority' => $record['priority'] === null ? null : (int) $record['priority'],
            ]);
        }
    }
}
