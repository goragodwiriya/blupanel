<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\DnsRecord;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\DnsRecordResource;
use Phpcp\Http\Resource\DomainResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Every domain in the system and each one's DNS records — `/api/v2/domains`
 *
 * Different from `/sites/{id}/domains` in that this looks past websites
 * entirely: used when an admin wants to see "every domain on this machine"
 * without opening each website one by one
 *
 * DNS records don't go through the agent yet, because the panel isn't a DNS
 * server itself (ARCHITECTURE §15 Q1) — this table stores the intended values
 * and exports them as a zone file · once phase E3 connects BIND9 for real,
 * these routes will switch to going through the `dns.zone_write` capability
 * instead, with the contract unchanged
 */
final class DomainsController extends HostingController
{
    /** Every domain the caller has permission to see */
    public function index(Request $request): Response
    {
        $owner = $this->scopeOwner();
        $where = $owner === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $owner === null ? [] : ['owner' => $owner];

        $rows = $this->app->db()->all(
            'SELECT d.*, s.primary_domain, s.status AS site_status
             FROM domains d JOIN sites s ON s.id = d.site_id' . $where . '
             ORDER BY s.primary_domain, d.type, d.domain',
            $params,
        );

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(mb_strtolower((string) $row['domain']), $needle),
            ));
        }

        $type = $request->get('type');
        if (in_array($type, ['primary', 'subdomain', 'alias', 'redirect'], true)) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type));
        }

        $page = $this->pagination($request);

        return $this->paginate(
            DomainResource::collection(array_slice($rows, $page['offset'], $page['per_page'])),
            count($rows),
            $page['page'],
            $page['per_page'],
        );
    }

    /** Add a subdomain/alias, naming the destination website in the body */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);
        $site = $this->findSite($siteId);

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.add_domain', [
            'site_id' => $siteId,
            'host' => trim($request->payloadString('host')),
            'path' => trim($request->payloadString('path')),
        ], $this->ctx->actor($request));

        $row = $this->app->db()->first(
            'SELECT * FROM domains WHERE domain = :d',
            ['d' => (string) ($result['domain'] ?? '')],
        );

        $domain = (string) ($result['domain'] ?? '');

        return $this->done(
            $this->t('Domain {domain} added', ['domain' => $domain]),
            [
                ['type' => 'notification', 'level' => 'success', 'message' => $this->t('Domain {domain} added', ['domain' => $domain])],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'domains'],
            ],
            ['domain_id' => (int) ($row['id'] ?? 0), 'domain' => $domain],
            201,
        )->withHeader('Location', '/api/v2/domains' . ($row === null ? '' : '/' . $row['id']));
    }

    /** Delete a domain using the domain row's own id */
    public function destroy(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        if (!in_array($domain['type'], ['subdomain', 'alias', 'redirect'], true)) {
            return $this->problem(
                ApiProblem::Conflict,
                'The primary domain cannot be removed on its own — delete the website instead',
            );
        }

        $this->agent()->data('site.remove_domain', [
            'site_id' => (int) $domain['site_id'],
            'domain' => (string) $domain['domain'],
        ], $this->ctx->actor($request));

        return $this->completed(
            $this->t('Domain {domain} removed', ['domain' => (string) $domain['domain']]),
            'domains',
            ['domain_id' => (int) $domain['id']],
        );
    }

    /** Every DNS record belonging to one domain */
    public function records(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        $rows = $this->app->db()->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domain['id']],
        );

        // The delete button's condition in the table can only read values in the
        // same row — so permission must travel with the row (the add-record
        // form instead uses `permissions['domain.manage']` from /api/v2/session,
        // since it isn't a table row — see the note in domain.html)
        $manage = $this->ctx->can('domain.manage');

        return $this->ok(array_map(
            static fn (array $row): array => $row + ['can_manage' => $manage],
            DnsRecordResource::collection($rows),
        ));
    }

    /** Add a DNS record */
    /**
     * The empty shell of the add-record form, with the command to open its modal
     *
     * The form's destination depends on the domain, so `form_action` is sent
     * ready to bind straight to the action attribute — the modal's template is
     * loaded later, so it never goes through RouterManager's `{id}` substitution
     * the way a page's own HTML does (see the note in domain.html)
     *
     * A DNS record can't be edited (only added or deleted), so there's only a form for a new one
     */
    public function recordForm(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        return $this->ok(
            [
                'form_action' => '/api/v2/domains/' . (int) $domain['id'] . '/dns-records',
                'type' => 'A',
                'name' => '',
                'value' => '',
                'ttl' => 3600,
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'dns-record-form.html',
                'title' => '{LNG_Add DNS record}',
                'titleClass' => 'icon-list',
            ]],
        );
    }

    public function addRecord(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        // A thrown ValidationError is already converted to a 422 by HttpKernel
        $clean = DnsRecord::validate([
            'type' => $request->payloadString('type'),
            'name' => $request->payloadString('name'),
            'value' => $request->payloadString('value'),
            'ttl' => (int) $request->payload('ttl', 3600),
            'priority' => $request->payload('priority'),
        ]);

        $id = $this->app->db()->insert('dns_records', ['domain_id' => (int) $domain['id']] + $clean);

        $this->app->audit()->write(
            $this->ctx->actor($request),
            'dns.record_add',
            (string) $domain['domain'],
            'ok',
            ['type' => $clean['type'], 'name' => $clean['name'], 'via' => 'api'],
        );

        $sync = $this->syncZone($request, (int) $domain['id']);

        return $this->done(
            $this->t('{type} record added', ['type' => $clean['type']]),
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success',
                 'message' => $this->t('{type} record added', ['type' => $clean['type']])],
                ...$this->dnsSyncWarning($sync),
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'dnsRecords'],
            ],
            ['record_id' => $id, 'domain_id' => (int) $domain['id']] + $sync,
            201,
        )->withHeader('Location', '/api/v2/domains/' . $domain['id'] . '/dns-records');
    }

    /**
     * An extra warning only when syncing to BIND9 genuinely fails (not just because dns.enabled is off)
     *
     * dns.enabled being off is the normal state for most installs — warning on
     * every record edit would mean an admin sees this message so often they
     * stop reading it (same principle as `Notifier`)
     *
     * @param array{dns_synced:bool,dns_message:string} $sync
     * @return list<array<string,mixed>>
     */
    private function dnsSyncWarning(array $sync): array
    {
        if ($sync['dns_synced'] || !$this->app->config->dnsEnabled()) {
            return [];
        }

        return [['type' => 'notification', 'level' => 'warning', 'message' => $this->t('Sync to BIND9 failed') . ': ' . $sync['dns_message']]];
    }

    /** Delete a DNS record — uses the record's own id directly per §4.6 */
    public function deleteRecord(Request $request): Response
    {
        $record = $this->app->db()->first(
            'SELECT * FROM dns_records WHERE id = :id',
            ['id' => $request->paramInt('id')],
        );

        if ($record === null) {
            return $this->problem(ApiProblem::NotFound, 'DNS record not found');
        }

        $domain = $this->findDomain((int) $record['domain_id']);

        if ($domain === null) {
            // The record genuinely exists, but the domain isn't the caller's own — answer 404 as if it didn't exist
            return $this->problem(ApiProblem::NotFound, 'DNS record not found');
        }

        $this->app->db()->run('DELETE FROM dns_records WHERE id = :id', ['id' => $record['id']]);

        $this->app->audit()->write(
            $this->ctx->actor($request),
            'dns.record_delete',
            (string) $domain['domain'],
            'ok',
            ['type' => $record['type'], 'name' => $record['name'], 'via' => 'api'],
        );

        $sync = $this->syncZone($request, (int) $domain['id']);
        $message = $this->t('{type} record removed', ['type' => (string) $record['type']]);

        return $this->done(
            $message,
            [
                ['type' => 'notification', 'level' => 'success', 'message' => $message],
                ...$this->dnsSyncWarning($sync),
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'dnsRecords'],
            ],
            ['record_id' => (int) $record['id']] + $sync,
        );
    }

    /**
     * Push this domain's zone to BIND9 after a record changes — allowed to fail
     * without turning the record edit (already saved to the database
     * successfully) into a failed request
     *
     * Different from `Notifier` in that it's **not silent** — a BIND9 sync
     * failure matters enough that the user must see it in this same response,
     * not have to go open the audit log themselves (PLAN-V2 phase E3)
     *
     * @return array{dns_synced:bool,dns_message:string}
     */
    private function syncZone(Request $request, int $domainId): array
    {
        try {
            $result = $this->agent()->data('dns.zone_write', ['domain_id' => $domainId], $this->ctx->actor($request));

            return [
                'dns_synced' => (bool) ($result['pushed'] ?? false),
                'dns_message' => (string) ($result['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['dns_synced' => false, 'dns_message' => $e->getMessage()];
        }
    }

    /**
     * A domain's zone file
     *
     * Returns JSON with the content in a `content` field, not an attachment —
     * v2's contract is "every endpoint answers JSON," and the SPA builds a
     * downloadable file from this text itself, which the browser can already do
     * with no need to trade away the API's consistency
     */
    public function zoneFile(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        $records = $this->app->db()->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domain['id']],
        );

        /*
         * **The real file on disk always wins when it can be read** — the two
         * can genuinely disagree, and the moment they do is exactly the moment
         * an admin needs an answer most ("why doesn't what I see on screen match
         * what DNS actually answers") · showing a freshly-reassembled value at
         * that exact moment would confirm the misunderstanding with a
         * screen that looks trustworthy
         *
         * Failing to read it isn't cause to break the whole page either — a
         * machine that hasn't turned on `dns.enabled` normally has no such file
         * at all, and an agent that doesn't answer must never make records unviewable
         */
        $disk = $this->zoneOnDisk($request, (int) $domain['id']);

        return $this->ok(
            [
                'domain' => (string) $domain['domain'],
                'filename' => $domain['domain'] . '.zone',
                'record_count' => count($records),
                'content' => $disk['content'] !== ''
                    ? $disk['content']
                    : DnsRecord::toZoneFile((string) $domain['domain'], $records),
                /*
                 * **Sent as the inverted value because the binder has no "not"
                 * operator** — the template must choose to show either the
                 * "no file yet" warning or the file's content, one or the other
                 */
                'no_file' => !$disk['on_disk'],
            ] + $disk,
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'zone-file.html',
                'title' => (string) $domain['domain'] . '.zone',
                'titleClass' => 'icon-document',
            ]],
        );
    }

    /**
     * The edit-all-records box — opens in a modal like this page's other forms
     *
     * **The starting text is the records the system has stored, not the whole
     * file** — so the user only ever edits what they actually own · the
     * `SOA`/`NS` records at the domain's apex are always generated from the
     * machine's own settings, and showing them in the edit box would invite
     * editing something that can't be edited · to see the full file, click the
     * view-file button, which shows the real file on disk in full
     */
    public function zoneForm(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        $records = $this->app->db()->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domain['id']],
        );

        return $this->ok(
            [
                'form_action' => '/api/v2/domains/' . (int) $domain['id'] . '/zone-file',
                'domain' => (string) $domain['domain'],
                'record_count' => count($records),
                'content' => DnsRecord::toEditableRecords((string) $domain['domain'], $records),
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'zone-records-form.html',
                'title' => '{LNG_Edit all records}',
                'titleClass' => 'icon-edit',
            ]],
        );
    }

    /**
     * The real zone file's state on disk — empty values when it can't be read, for whatever reason
     *
     * @return array{content:string,path:string,on_disk:bool,drift:bool,drift_reason:string,source:string,source_label:string}
     */
    private function zoneOnDisk(Request $request, int $domainId): array
    {
        $missing = [
            'content' => '',
            'path' => '',
            'on_disk' => false,
            'drift' => false,
            'drift_reason' => '',
            'source' => 'generated',
            'source_label' => $this->t('Built from the records in the panel — this domain has no zone file on the server yet'),
        ];

        try {
            $result = $this->agent()->data('dns.zone_read', ['domain_id' => $domainId], $this->ctx->actor($request));
        } catch (\Throwable) {
            return $missing;
        }

        if (!($result['exists'] ?? false)) {
            return $missing + ['path' => (string) ($result['path'] ?? '')];
        }

        return [
            'content' => (string) ($result['content'] ?? ''),
            'path' => (string) ($result['path'] ?? ''),
            'on_disk' => true,
            'drift' => (bool) ($result['drift'] ?? false),
            'drift_reason' => (string) ($result['drift_reason'] ?? ''),
            'source' => 'disk',
            'source_label' => $this->t('The real file BIND9 is serving right now'),
        ];
    }

    /**
     * Replace the whole zone's records from the text an admin edited
     *
     * **The submitted text is never written to disk directly** — it's parsed
     * back into database records, and the system writes the file itself as
     * normal · see `DnsZoneImport` for why
     */
    public function zoneImport(Request $request): Response
    {
        $domain = $this->findDomain($request->paramInt('id'));

        if ($domain === null) {
            return $this->problem(ApiProblem::NotFound, 'Domain not found');
        }

        $result = $this->agent()->data('dns.zone_import', [
            'domain_id' => (int) $domain['id'],
            'content' => $request->payloadString('content'),
        ], $this->ctx->actor($request));

        return $this->saved(
            (string) ($result['message'] ?? 'DNS records replaced'),
            'dnsRecords',
            is_array($result) ? $result : [],
        );
    }

    /**
     * Resync every domain's zone with BIND9 from scratch — `dns.manage` (the whole machine, not per-domain)
     *
     * Used right after turning on `dns.enabled` for the first time (records
     * added before that are sitting unsynced), or when someone edited BIND9's
     * files directly and wants the panel to write over them back to what they should be
     */
    public function reloadAll(Request $request): Response
    {
        $result = $this->agent()->data('dns.reload', [], $this->ctx->actor($request));
        $message = (string) ($result['message'] ?? 'All DNS zones synced');

        // Some domains failing = must show a yellow bar, not green · failing
        // entirely already means the agent threw an error
        // (`completed()` hardcodes level success, so actions are assembled by hand here instead)
        $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];

        return $this->done(
            $message,
            [[
                'type' => 'notification',
                'level' => $failed === [] ? 'success' : 'warning',
                'message' => $message,
            ]],
            is_array($result) ? $result : [],
        );
    }

    /**
     * Load a domain the caller has permission to see
     *
     * @return array<string,mixed>|null
     */
    private function findDomain(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->app->db()->first(
            'SELECT d.*, s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :id',
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

        if ($this->ctx->role() === Permissions::WEBADMIN
            && (int) ($row['owner_user_id'] ?? 0) !== $this->ctx->userId()) {
            return null;
        }

        return $row;
    }
}
