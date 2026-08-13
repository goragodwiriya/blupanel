<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\DomainResource;
use Phpcp\Http\Resource\SiteResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * เว็บไซต์ — `/api/v2/sites` (PLAN-V2 §4.6)
 *
 * ทุกคำสั่งที่เปลี่ยนแปลงอะไรเดินผ่าน agent ทั้งหมด controller ตัวนี้จึงมีหน้าที่แค่
 * แปลงคำขอ HTTP เป็น argument ของ capability และแปลงผลลัพธ์กลับเป็น JSON
 * — ไม่มีตรรกะการสร้าง/ลบเว็บไซต์อยู่ที่นี่เลยแม้แต่บรรทัดเดียว
 *
 * `AgentException` ไม่ต้อง catch: HttpKernel แปลงเป็นรหัสที่ถูกต้องให้แล้ว
 * (ValidationError→422, PermissionDenied→403, TransportError→503)
 */
final class SitesController extends HostingController
{
    /** ฟิลด์ที่ยอมให้เรียงได้ — ค่านี้ต่อท้าย ORDER BY จึงต้องเป็น allowlist เท่านั้น */
    private const SORTABLE = ['primary_domain', 'name', 'created_at', 'disk_used', 'php_version', 'status'];

    /**
     * ชื่อฟิลด์ที่ API ใช้ → ชื่อคอลัมน์ในฐานข้อมูล
     *
     * มีรายการเดียวเพราะเป็นที่เดียวที่หน่วยของ API (ไบต์) ไม่ตรงกับหน่วยที่เก็บ (MB)
     * การเรียงให้ผลเหมือนกันทั้งสองหน่วย จึงเรียงด้วยคอลัมน์ดิบได้เลย
     */
    private const SORT_COLUMN = ['disk_used' => 'disk_used_mb'];

    /**
     * รายการเว็บไซต์ — รองรับค้นหา กรองสถานะ เรียง และแบ่งหน้าตาม §4.5
     *
     * การกรองตามเจ้าของทำที่ระดับ query เสมอ ไม่ใช่กรองหลังดึงมาแล้ว —
     * ข้อมูลของลูกค้ารายอื่นต้องไม่ถูกอ่านขึ้นมาในหน่วยความจำตั้งแต่แรก
     */
    public function index(Request $request): Response
    {
        $rows = $this->sites()->listWithCounts($this->scopeOwner());

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => str_contains(mb_strtolower((string) $row['primary_domain']), $needle)
                || str_contains(mb_strtolower((string) $row['name']), $needle),
            ));
        }

        $status = $request->get('status');
        if (in_array($status, ['active', 'suspended'], true)) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === $status));
        }

        $phpVersion = $request->get('php_version');
        if ($phpVersion !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['php_version'] === $phpVersion));
        }

        $sort = $this->sort($request, self::SORTABLE, 'primary_domain');
        $column = self::SORT_COLUMN[$sort['field']] ?? $sort['field'];
        usort($rows, static function (array $a, array $b) use ($sort, $column): int {
            $left = $a[$column] ?? '';
            $right = $b[$column] ?? '';
            $result = is_numeric($left) && is_numeric($right)
                ? $left <=> $right
                : strcasecmp((string) $left, (string) $right);

            return $sort['desc'] ? -$result : $result;
        });

        $page = $this->pagination($request);
        $total = count($rows);
        $slice = array_slice($rows, $page['offset'], $page['per_page']);

        return $this->paginate(SiteResource::collection($slice), $total, $page['page'], $page['per_page']);
    }

    /** สร้างเว็บไซต์ใหม่ */
    public function store(Request $request): Response
    {
        $aliases = $request->payload('aliases', []);

        // รับได้ทั้ง array (JSON) และข้อความคั่นด้วยเว้นวรรค/จุลภาค (ฟอร์มและ curl)
        if (is_string($aliases)) {
            $aliases = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $aliases) ?: [])));
        }

        $result = $this->agent()->data('site.create', [
            'domain' => trim($request->payloadString('domain')),
            'name' => trim($request->payloadString('name')),
            'php_version' => $request->payloadString('php_version'),
            'aliases' => is_array($aliases) ? array_values($aliases) : [],
            'docroot' => trim($request->payloadString('docroot')),
            'pointer_root' => trim($request->payloadString('pointer_root')),
            // ลูกค้าสร้างเว็บได้เฉพาะของตัวเอง — ไม่ยอมให้ระบุเจ้าของคนอื่นเด็ดขาด
            'owner_user_id' => $this->ctx->role() === Permissions::WEBADMIN
                ? $this->ctx->userId()
                : (int) $request->payload('owner_user_id', 0)
        ], $this->ctx->actor($request));

        $siteId = (int) $result['site_id'];
        $site = $this->sites()->find($siteId);

        return $this->done(
            $this->t('Website {domain} created', ['domain' => (string) ($site['primary_domain'] ?? $result['domain'] ?? '')]),
            [
                ['type' => 'notification', 'level' => 'success',
                    'message' => $this->t('Website {domain} created', ['domain' => (string) ($site['primary_domain'] ?? '')])],
                ['type' => 'redirect', 'url' => '/app/sites', 'delay' => 800]
            ],
            ['site_id' => $siteId, 'domain' => (string) ($site['primary_domain'] ?? '')],
            201,
        )->withHeader('Location', '/api/v2/sites/'.$siteId);
    }

    /**
     * รายละเอียดเว็บไซต์เดียว พร้อมโดเมนทั้งหมดของมัน
     *
     * `id=0` เป็นค่าจองพิเศษที่แปลว่า "ยังไม่มีเว็บไซต์นี้อยู่จริง — ขอค่าเริ่มต้น
     * สำหรับสร้างใหม่" แทนที่จะเป็น 404 · หน้าฟอร์มสร้างเว็บไซต์ (site-create.html)
     * จึงโหลดข้อมูลด้วย endpoint เดียวกับหน้าแก้ไข (site.html) ได้ — ไม่ต้องมี
     * endpoint แยกต่างหากที่ทำหน้าที่คล้ายกันแค่เพื่อกรณีสร้างใหม่
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            $pointerRoots = [];
            foreach ($this->app->config->list('sites.pointer_roots') as $root) {
                $pointerRoots[] = ['value' => $root, 'text' => $root];
            }

            // ต้องตอบ 200 เสมอแม้ agent ล่ม — หน้านี้เป็นแค่ค่าเริ่มต้นของฟอร์ม
            // ไม่ใช่คำสั่งที่ต้องพึ่ง agent จริง ๆ (ดู SystemController::health)
            // เวอร์ชัน PHP เลือกไม่ได้แค่ช่วงนั้น ไม่ใช่ทั้งหน้าใช้งานไม่ได้
            $phpVersions = [];

            if ($this->agent()->isAvailable()) {
                foreach ($this->fetchPhpVersions($request)['versions'] as $version) {
                    $phpVersions[] = ['value' => $version['version'], 'text' => $version['version']];
                }
            }

            return $this->ok([
                // ค่าเริ่มต้นของ select เจ้าของ — ผู้ดูแลเปลี่ยนได้ ลูกค้าเปลี่ยนไม่ได้เพราะ
                // ownerOptions() คืนตัวเลือกเดียวคือตัวเอง
                'owner_user_id' => $this->ctx->userId(),
                'has_pointer_roots' => $pointerRoots !== [],
                'options' => [
                    'php_version' => $phpVersions,
                    'pointer_root' => $pointerRoots,
                    'owner_user_id' => $this->ownerOptions()
                ]
            ]);
        }

        $site = $this->findSite($id);

        if ($site === null) {
            return $this->siteNotFound();
        }

        $domains = $this->app->db()->all(
            'SELECT * FROM domains WHERE site_id = :id ORDER BY type, domain',
            ['id' => $site['id']],
        );

        return $this->ok(SiteResource::one($site) + [
            'domains' => DomainResource::collection($domains),
            'aliases' => $this->sites()->aliasesOf((int) $site['id']),
            // ปุ่มบนหน้าจอถาม `can[...]` จากคำตอบนี้ ไม่ได้เดาเองจากบทบาท
            'can' => $this->can([
                'edit' => 'site.edit',
                'suspend' => 'site.suspend',
                'delete' => 'site.delete',
                'manage_domains' => 'domain.manage'
            ])
        ]);
    }

    /**
     * ตัวเลือกเจ้าของเว็บไซต์ใหม่ — โครงเดียวกับตัวเลือก php_version คือ list ของ
     * {value, text} ให้ select ฝั่ง SPA ใช้ตรง ๆ
     *
     * ผู้ดูแล (ทุกบทบาทที่ไม่ใช่ webadmin) มอบเว็บให้บัญชีโฮสติ้งไหนก็ได้ จึงเห็นบัญชี
     * role=webadmin ทั้งหมด ส่วนลูกค้า (webadmin) สร้างเว็บได้เฉพาะของตัวเอง — เห็น
     * ตัวเลือกเดียวคือชื่อตัวเอง ให้ตรงกับกฎเดียวกับที่ store() บังคับอยู่แล้ว
     *
     * @return list<array{value:int,text:string}>
     */
    private function ownerOptions(): array
    {
        if ($this->ctx->role() === Permissions::WEBADMIN) {
            return [['value' => $this->ctx->userId(), 'text' => $this->ctx->displayName()]];
        }

        return array_map(
            static fn(array $user): array=> [
                'value' => (int) $user['id'],
                'text' => sprintf('%s (%s)', $user['display_name'], $user['username'])
            ],
            (new UserRepository($this->app->db()))->hostingAccounts(),
        );
    }

    /**
     * แก้ไขบางส่วน
     *
     * ตอนนี้รองรับเฉพาะ `php_version` เพราะเป็นฟิลด์เดียวของเว็บไซต์ที่มี capability
     * รองรับอยู่จริง · การเปลี่ยนชื่อหรือ docroot ต้องรอ capability ของมันเอง —
     * ชั้นนี้จะไม่เขียนฐานข้อมูลตรง ๆ เพื่อความสะดวก เพราะนั่นคือการข้ามชั้นที่ 2
     * และทำให้การเปลี่ยนแปลงนั้นไม่มีใน audit log
     */
    public function update(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $phpVersion = trim($request->payloadString('php_version'));

        if ($phpVersion === '') {
            return $this->problem(
                ApiProblem::ValidationError,
                'php_version is the only field that can be changed here',
                ['php_version' => 'ต้องระบุเวอร์ชัน PHP ที่ต้องการเปลี่ยนไปใช้'],
            );
        }

        return $this->applyPhpVersion($request, (int) $site['id'], $phpVersion);
    }

    /** เปลี่ยนเวอร์ชัน PHP — แทนที่ค่าทั้งค่า จึงเป็น PUT */
    public function setPhpVersion(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        return $this->applyPhpVersion($request, (int) $site['id'], trim($request->payloadString('php_version')));
    }

    /**
     * ระงับหรือเปิดใช้งานเว็บไซต์
     *
     * เป็น `PUT` บนทรัพยากรที่เป็นคำนาม (`suspension`) ตาม §4.1 ไม่ใช่ `POST /suspend`
     * ข้อดีที่ได้จริงคือสั่งซ้ำได้โดยผลไม่เปลี่ยน — หน้าจอที่กดปุ่มสองครั้งเพราะเน็ตช้า
     * จะไม่ได้ผลลัพธ์แปลก ๆ
     */
    public function setSuspension(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $suspended = $request->payload('suspended');

        if (!is_bool($suspended) && !in_array($suspended, ['1', '0', 'true', 'false', 1, 0], true)) {
            return $this->problem(
                ApiProblem::ValidationError,
                'The wanted status is required',
                ['suspended' => 'ต้องเป็น true (ระงับ) หรือ false (เปิดใช้งาน)'],
            );
        }

        $wantSuspended = in_array($suspended, [true, '1', 'true', 1], true);

        $result = $this->agent()->data(
            $wantSuspended ? 'site.suspend' : 'site.resume',
            ['site_id' => (int) $site['id']],
            $this->ctx->actor($request),
        );

        return $this->refreshed(
            (string) ($result['message'] ?? ($wantSuspended ? 'Website suspended' : 'Website resumed')),
            extra: ['site_id' => (int) $site['id'], 'suspended' => $wantSuspended],
        );
    }

    /**
     * อ่านค่าการจำกัดอัตราคำขอ พร้อมสถานะจริงจาก fail2ban
     *
     * `status` มาจากตัว fail2ban ไม่ใช่จากฐานข้อมูลของ panel เพราะสองอย่างนี้ไม่ตรงกันได้:
     * ผู้ดูแลอาจสั่ง `fail2ban-client` เองจากบรรทัดคำสั่ง หรือ fail2ban อาจไม่ได้โหลด jail
     * เพราะไฟล์ผิด · หน้าจอต้องบอกสิ่งที่เป็นจริงบนเครื่อง ไม่ใช่สิ่งที่เราคิดว่าตั้งไว้
     */
    public function rateLimit(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $row = $this->app->db()->first(
            'SELECT * FROM site_rate_limits WHERE site_id = :id',
            ['id' => (int) $site['id']],
        );

        // ยังไม่เคยตั้ง = ส่งค่าเริ่มต้นให้ฟอร์มกรอกไว้ ไม่ใช่ค่าว่างที่ผู้ใช้ต้องเดาเอง
        $data = [
            'site_id' => (int) $site['id'],
            'domain' => (string) $site['primary_domain'],
            'enabled' => (bool) ($row['enabled'] ?? false),
            'max_requests' => (int) ($row['max_requests'] ?? 300),
            'window_seconds' => (int) ($row['window_seconds'] ?? 60),
            'ban_seconds' => (int) ($row['ban_seconds'] ?? 600),
            'ignore_ips' => (string) ($row['ignore_ips'] ?? ''),
        ];

        // ถามสถานะจริงเฉพาะเมื่อเปิดไว้ — การเรียก fail2ban-client ทุกครั้งที่เปิดหน้า
        // ของเว็บที่ไม่ได้ใช้ฟีเจอร์นี้เป็นการเสียเวลาเปล่าและทำให้หน้าโหลดช้าโดยไม่จำเป็น
        if ($data['enabled']) {
            $result = $this->agent()->data(
                'site.rate_limit_status',
                ['site_id' => (int) $site['id']],
                $this->ctx->actor($request),
            );

            $data['status'] = $result['status'] ?? null;
            $data['banned_ips'] = $result['banned_ips'] ?? [];
        }

        return $this->ok($data, [
            // ข้อแลกเปลี่ยนที่ผู้ดูแลต้องรู้ก่อนเปิด — หน้าจอเอาไปแสดงเป็นคำเตือน
            'ban_scope' => 'เครื่อง',
            'notice' => 'การแบนมีผลทั้งเครื่อง ไม่ใช่เฉพาะเว็บนี้ — IP ที่ถูกแบนจะเข้าเว็บอื่นบนเครื่องเดียวกันไม่ได้ด้วย',
        ]);
    }

    /**
     * รายการ IP ที่ถูกแบนอยู่ — แยก endpoint เพราะตารางของ SPA ต้องการ `data` ที่เป็น array
     *
     * `GET /rate-limit` คืน `data` เป็น object (ค่าตั้งของฟอร์ม) ซึ่ง `data-table`
     * ผูกไม่ได้ · การยัดสองรูปแบบไว้ใน endpoint เดียวแล้วให้หน้าจอเลือกเอง แปลว่า
     * ต้องเขียน JS ประจำหน้า ซึ่งทั้ง SPA นี้ไม่มีเลยสักหน้า
     */
    public function rateLimitBans(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data(
            'site.rate_limit_status',
            ['site_id' => (int) $site['id']],
            $this->ctx->actor($request),
        );

        $rows = array_map(
            static fn (string $ip): array => ['ip' => $ip],
            is_array($result['banned_ips'] ?? null) ? $result['banned_ips'] : [],
        );

        return $this->ok($rows, [
            'total' => count($rows),
            'jail' => (string) ($result['jail'] ?? ''),
            'active' => (bool) ($result['status']['active'] ?? false),
        ]);
    }

    /** เปิด ปิด หรือปรับค่าการจำกัดอัตราคำขอ */
    public function setRateLimit(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.rate_limit_set', [
            'site_id' => (int) $site['id'],
            'enabled' => $request->payload('enabled'),
            'max_requests' => $request->payload('max_requests'),
            'window_seconds' => $request->payload('window_seconds'),
            'ban_seconds' => $request->payload('ban_seconds'),
            'ignore_ips' => $request->payloadString('ignore_ips'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Rate limit saved'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * ปลดแบน IP หนึ่ง
     *
     * ต้องมีเพราะการแบนสั่งที่ firewall ซึ่งไม่รู้จัก vhost — ผู้ดูแลที่ทดสอบเว็บตัวเอง
     * แรงไปหน่อยแล้วโดนแบน จะเข้า panel ไม่ได้เลยถ้าไม่มีทางปลดจากที่อื่น
     */
    public function unbanIp(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.rate_limit_unban', [
            'site_id' => (int) $site['id'],
            'ip' => $request->payloadString('ip'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Ban removed'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * ลบเว็บไซต์ — ต้องยืนยันด้วยชื่อโดเมนเสมอ
     *
     * ชื่อโดเมนรับทาง body หรือ query ก็ได้ เพราะ `DELETE` ที่มี body ยังมีไคลเอนต์
     * บางตัวที่ตัดทิ้ง การบังคับให้ส่งได้ทางเดียวจะทำให้ลบผ่าน curl ไม่ได้ในบางเครื่อง
     */
    public function destroy(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $confirm = trim($request->payloadString('confirm_domain')) ?: trim($request->get('confirm_domain'));

        $this->agent()->data('site.delete', [
            'site_id' => (int) $site['id'],
            'confirm_domain' => $confirm
        ], $this->ctx->actor($request));

        // กลับไปหน้ารายการเสมอ — กดลบจากหน้ารายละเอียดแล้วอยู่ที่เดิมจะได้ 404
        // ส่วนกดลบจากหน้ารายการ การไปที่เส้นทางเดิมทำให้ตารางโหลดใหม่พอดี
        return $this->done(
            $this->t('Website {domain} deleted', ['domain' => (string) $site['primary_domain']]),
            [
                ['type' => 'notification', 'level' => 'success',
                    'message' => $this->t('Website {domain} deleted', ['domain' => (string) $site['primary_domain']])],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'sites']
            ],
            ['site_id' => (int) $site['id']],
        );
    }

    /** ตั้งเจ้าของไฟล์ของเว็บไซต์กลับให้ถูกต้อง */
    public function resetOwner(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.reset_owner', [
            'site_id' => (int) $site['id'],
            'fix_permissions' => (bool) $request->payload('fix_permissions', false)
        ], $this->ctx->actor($request));

        return $this->refreshed((string) ($result['message'] ?? 'File ownership reset'), extra: $result);
    }

    /** โดเมนทั้งหมดของเว็บไซต์นี้ */
    public function domains(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $rows = $this->app->db()->all(
            'SELECT * FROM domains WHERE site_id = :id ORDER BY type, domain',
            ['id' => $site['id']],
        );
        $filter = [
            'status' => [
                ['value' => 'active', 'text' => 'Active'],
                ['value' => 'suspended', 'text' => 'Suspended']
            ]
        ];

        return $this->ok(
            DomainResource::collection($rows),
            [],
            [],
            $filter
        );
    }

    /** เพิ่มโดเมนย่อยหรือ alias ให้เว็บไซต์นี้ */
    /**
     * โครงเปล่าของฟอร์มเพิ่มโดเมนให้เว็บนี้ พร้อมคำสั่งเปิด modal
     *
     * ปลายทางของฟอร์มขึ้นกับเว็บไซต์ จึงส่ง `form_action` มาให้ผูกกับแอตทริบิวต์
     * action ตรง ๆ — เทมเพลตของ modal ถูกโหลดทีหลังจึงไม่ผ่านการแทนค่า `{id}`
     * ของ RouterManager เหมือน HTML ของหน้า
     */
    public function domainForm(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        return $this->ok(
            [
                'form_action' => '/api/v2/sites/' . (int) $site['id'] . '/domains',
                'host' => '',
                'path' => '',
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'site-domain-form.html',
                'title' => '{LNG_Add domain}',
                'titleClass' => 'icon-link',
            ]],
        );
    }

    public function addDomain(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.add_domain', [
            'site_id' => (int) $site['id'],
            'host' => trim($request->payloadString('host')),
            'path' => trim($request->payloadString('path'))
        ], $this->ctx->actor($request));

        $domain = (string) ($result['domain'] ?? '');
        $row = $this->app->db()->first(
            'SELECT * FROM domains WHERE site_id = :s AND domain = :d',
            ['s' => $site['id'], 'd' => $domain],
        );

        return $this->done(
            $this->t('Domain {domain} added', ['domain' => $domain]),
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success', 'message' => $this->t('Domain {domain} added', ['domain' => $domain])],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'sites']
            ],
            ['site_id' => (int) $site['id'], 'domain' => $domain],
            201,
        )->withHeader('Location', '/api/v2/sites/'.$site['id'].'/domains');
    }

    /**
     * แทนที่รายการโดเมนทั้งชุดตามชนิดที่ระบุ
     *
     * ใช้ตอนแก้ไขรายการทั้งก้อนจากหน้าจอเดียว — capability จะคำนวณเองว่าต้องเพิ่มหรือลบอะไร
     */
    public function setDomains(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $domains = $request->payload('domains', []);

        if (is_string($domains)) {
            $domains = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $domains) ?: [])));
        }

        $result = $this->agent()->data('site.set_domains', [
            'site_id' => (int) $site['id'],
            'domains' => is_array($domains) ? array_values($domains) : [],
            'type' => $request->payloadString('type') === 'subdomain' ? 'subdomain' : 'alias'
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Domain list saved'),
            extra: $result,
        );
    }

    /** ลบโดเมนย่อยหรือ alias ออกจากเว็บไซต์ */
    public function removeDomain(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $this->agent()->data('site.remove_domain', [
            'site_id' => (int) $site['id'],
            // ชื่อโดเมนมาจาก path จึงต้องถอด urlencode ก่อน (จุดกับขีดไม่ถูกเข้ารหัส แต่กันไว้)
            'domain' => rawurldecode($request->param('domain'))
        ], $this->ctx->actor($request));

        return $this->refreshed($this->t('Domain {domain} removed', ['domain' => rawurldecode($request->param('domain'))]), 'sites');
    }

    /** ส่งคำสั่งเปลี่ยนเวอร์ชัน PHP ไปที่ agent — ใช้ร่วมกันระหว่าง PATCH และ PUT */
    private function applyPhpVersion(Request $request, int $siteId, string $phpVersion): Response
    {
        $result = $this->agent()->data('site.set_php', [
            'site_id' => $siteId,
            'php_version' => $phpVersion
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'PHP version changed'),
            extra: ['site_id' => $siteId, 'php_version' => $phpVersion],
        );
    }
}
