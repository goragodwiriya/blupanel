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
 * โดเมนทั้งระบบและ DNS record ของแต่ละโดเมน — `/api/v2/domains`
 *
 * ต่างจาก `/sites/{id}/domains` ตรงที่นี่มองข้ามเว็บไซต์: ใช้ตอนที่ผู้ดูแลอยากเห็น
 * "โดเมนทั้งหมดบนเครื่องนี้" โดยไม่ต้องไล่เปิดทีละเว็บ
 *
 * DNS record ยังไม่ผ่าน agent เพราะ panel ยังไม่ได้เป็น DNS server (ARCHITECTURE §15 Q1)
 * — ตารางนี้เก็บค่าที่ตั้งใจให้เป็นแล้วส่งออกเป็น zone file · เมื่อเฟส E3 เชื่อม BIND9 จริง
 * เส้นทางเหล่านี้จะเปลี่ยนไปสั่งผ่าน capability `dns.zone_write` แทนโดยที่สัญญาไม่เปลี่ยน
 */
final class DomainsController extends HostingController
{
    /** รายการโดเมนทั้งหมดที่ผู้เรียกมีสิทธิ์เห็น */
    public function index(Request $request): Response
    {
        $owner = $this->scopeOwner();
        $where = $owner === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $owner === null ? [] : ['owner' => $owner];

        $rows = $this->app->db()->all(
            'SELECT d.*, s.name AS site_name, s.primary_domain, s.status AS site_status
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

    /** เพิ่มโดเมนย่อย/alias โดยระบุเว็บไซต์ปลายทางใน body */
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

    /** ลบโดเมนด้วย id ของแถวโดเมนเอง */
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

    /** DNS record ทั้งหมดของโดเมนหนึ่ง */
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

        // เงื่อนไขปุ่มลบในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        // (หน้าฟอร์มเพิ่มเรกคอร์ดใช้ `permissions['domain.manage']` จาก /api/v2/session แทน
        // เพราะไม่ใช่แถวของตาราง — ดูหมายเหตุใน domain.html)
        $manage = $this->ctx->can('domain.manage');

        return $this->ok(array_map(
            static fn (array $row): array => $row + ['can_manage' => $manage],
            DnsRecordResource::collection($rows),
        ));
    }

    /** เพิ่ม DNS record */
    /**
     * โครงเปล่าของฟอร์มเพิ่มเรกคอร์ด พร้อมคำสั่งเปิด modal
     *
     * ปลายทางของฟอร์มขึ้นกับโดเมน จึงส่ง `form_action` มาให้ผูกกับแอตทริบิวต์ action
     * ตรง ๆ — เทมเพลตของ modal ถูกโหลดทีหลังจึงไม่ผ่านการแทนค่า `{id}` ของ
     * RouterManager เหมือน HTML ของหน้า (ดูหมายเหตุใน domain.html)
     *
     * เรกคอร์ด DNS แก้ไม่ได้ (เพิ่มกับลบเท่านั้น) จึงมีแต่ฟอร์มของใหม่
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

        // ValidationError ที่โยนออกไปถูกแปลงเป็น 422 โดย HttpKernel ให้แล้ว
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
     * แจ้งเตือนเพิ่มเมื่อการซิงก์ BIND9 ล้มเหลวจริง (ไม่ใช่แค่ปิด dns.enabled ไว้)
     *
     * ปิด dns.enabled คือสถานะปกติของการติดตั้งส่วนใหญ่ — ถ้าเตือนทุกครั้งที่แก้เรกคอร์ด
     * ผู้ดูแลจะเห็นข้อความนี้ทุกครั้งจนเลิกอ่าน (หลักการเดียวกับ `Notifier`)
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

    /** ลบ DNS record — ใช้ id ของเรกคอร์ดตรง ๆ ตาม §4.6 */
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
            // เรกคอร์ดมีอยู่จริงแต่โดเมนไม่ใช่ของผู้เรียก — ตอบ 404 เหมือนกรณีไม่มีอยู่
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
     * ส่ง zone ของโดเมนนี้ไปยัง BIND9 หลังแก้เรกคอร์ด — ล้มได้โดยไม่ทำให้การแก้เรกคอร์ด
     * (ที่บันทึกลงฐานข้อมูลสำเร็จไปแล้ว) กลายเป็นคำขอที่ล้มเหลวไปด้วย
     *
     * ต่างจาก `Notifier` ตรงที่**ไม่เงียบ** — ความล้มเหลวของการซิงก์ BIND9 สำคัญพอที่ผู้ใช้
     * ต้องเห็นในคำตอบเดียวกันนี้เลย ไม่ใช่ต้องไปเปิด audit log เอง (PLAN-V2 เฟส E3)
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
     * zone file ของโดเมน
     *
     * คืนเป็น JSON ที่มีเนื้อหาอยู่ในฟิลด์ `content` ไม่ใช่ไฟล์แนบ — สัญญาของ v2 คือ
     * "ทุก endpoint ตอบ JSON" ฝั่ง SPA เป็นคนสร้างไฟล์ให้ดาวน์โหลดจากข้อความนี้เอง
     * ซึ่งทำได้ในเบราว์เซอร์อยู่แล้วและไม่ต้องแลกกับความสม่ำเสมอของ API
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
         * **ไฟล์จริงบนดิสก์มาก่อนเสมอถ้าอ่านได้** — สองอย่างนี้ต่างกันได้ และเวลาที่มัน
         * ต่างกันคือเวลาที่ผู้ดูแลต้องการคำตอบมากที่สุด ("ทำไมค่าที่เห็นในหน้าจอไม่ตรงกับ
         * ที่ DNS ตอบจริง") · การแสดงค่าที่ประกอบใหม่ในนาทีนั้นคือการยืนยันความเข้าใจผิด
         * ด้วยหน้าจอที่ดูน่าเชื่อถือ
         *
         * อ่านไม่ได้ก็ไม่ใช่เหตุให้ทั้งหน้าพัง — เครื่องที่ยังไม่เปิด `dns.enabled` ไม่มีไฟล์
         * นี้เลยตามปกติ และ agent ที่ไม่ตอบต้องไม่ทำให้ดูเรกคอร์ดไม่ได้
         */
        $disk = $this->zoneOnDisk($request, (int) $domain['id']);

        return $this->ok([
            'domain' => (string) $domain['domain'],
            'filename' => $domain['domain'] . '.zone',
            'record_count' => count($records),
            'content' => $disk['content'] !== ''
                ? $disk['content']
                : DnsRecord::toZoneFile((string) $domain['domain'], $records),
            /*
             * **เซิร์ฟเวอร์เป็นคนตัดสินว่าแก้ได้ไหม ไม่ใช่หน้าจอ** — รูปแบบเดียวกับ
             * `writable`/`readonly` ของไฟล์ตั้งค่า · ส่งมาเป็นคู่ตรงข้ามเพราะเทมเพลต
             * ต้องเลือกแสดงอย่างใดอย่างหนึ่ง และตัวผูกค่าไม่มีเครื่องหมาย "ไม่ใช่"
             */
            'can_edit' => $this->ctx->can('domain.manage'),
            'read_only' => !$this->ctx->can('domain.manage'),
        ] + $disk);
    }

    /**
     * สภาพของ zone file ตัวจริงบนดิสก์ — ค่าว่างเมื่ออ่านไม่ได้ด้วยเหตุผลใดก็ตาม
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
     * แทนที่เรกคอร์ดทั้ง zone จากข้อความที่ผู้ดูแลแก้
     *
     * **ไม่ได้เขียนไฟล์ที่ส่งมาลงดิสก์** — ข้อความถูกแปลงกลับเป็นเรกคอร์ดในฐานข้อมูล
     * แล้วระบบเขียนไฟล์เองตามปกติ · ดูเหตุผลที่ `DnsZoneImport`
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
     * ซิงก์ zone ของทุกโดเมนกับ BIND9 ใหม่ทั้งหมด — `dns.manage` (ทั้งเครื่อง ไม่ใช่รายโดเมน)
     *
     * ใช้ตอนเพิ่งเปิด `dns.enabled` ครั้งแรก (มีเรกคอร์ดที่เพิ่มไว้ก่อนหน้าค้างอยู่) หรือมีใคร
     * แก้ไฟล์ของ BIND9 ตรง ๆ แล้วต้องการให้ panel เขียนทับคืนสภาพที่ควรจะเป็น
     */
    public function reloadAll(Request $request): Response
    {
        $result = $this->agent()->data('dns.reload', [], $this->ctx->actor($request));
        $message = (string) ($result['message'] ?? 'All DNS zones synced');

        // ล้มบางโดเมน = ต้องขึ้นแถบเหลือง ไม่ใช่เขียว · ล้มทั้งหมด agent โยน error ออกมาแล้ว
        // (`completed()` ใส่ level success ตายตัว จึงประกอบ actions เองตรงนี้)
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
     * โหลดโดเมนที่ผู้เรียกมีสิทธิ์เห็น
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
