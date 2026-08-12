<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\SiteResource;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;
use Phpcp\Security\SessionStore;

/**
 * บัญชีผู้ใช้ทั้งหมด — `/api/v2/users`
 *
 * ตั้งแต่ migration 0005 ทรัพยากรนี้ครอบคลุมทั้งผู้ดูแลระบบและลูกค้าโฮสติ้ง เพราะเป็น
 * ตารางเดียวกันแล้ว · เส้นทาง `/api/v2/customers` เดิมถูกยุบมาที่นี่ทั้งหมด
 * ลูกค้าคือผู้ใช้ที่ `role=webadmin` และมีคอลัมน์โควตา/วันหมดอายุของตัวเอง
 *
 * **ขอบเขตสิทธิ์ — จุดสำคัญที่สุดของไฟล์นี้**
 * การยุบสองทรัพยากรเป็นอันเดียวทำให้เส้นทางที่ sysadmin เคยใช้จัดการลูกค้า กลายเป็น
 * เส้นทางเดียวกับที่ใช้จัดการบัญชี superadmin ได้ ถ้าปล่อยไว้ = ยกระดับสิทธิ์ทันที
 * จึงบังคับสองชั้น:
 *   1. route ต้องมี `customer.manage` (superadmin และ sysadmin มีทั้งคู่)
 *   2. `assertMayManage()` ที่นี่ ต้องมี `user.manage` เพิ่มถ้าเป้าหมายไม่ใช่ webadmin
 *      หรือกำลังจะตั้ง/เปลี่ยนบทบาทเป็นอย่างอื่นที่ไม่ใช่ webadmin
 * และชั้น agent ยังกันซ้ำอีกชั้นด้วย `CustomerCapability::loadHostingAccount()`
 *
 * **ทำไมทรัพยากรนี้ไม่เดินผ่าน agent ทั้งหมด**
 * บัญชีผู้ใช้เป็นสถานะภายในของ panel — ไม่แตะระบบปฏิบัติการ ไม่สร้าง system user
 * (การสร้างบัญชี Linux จะเกิดตอนสร้างเว็บแรกในเฟส M3 ไม่ใช่ตอนสร้างผู้ใช้)
 * ส่วนที่มี capability อยู่แล้ว (สร้างบัญชีโฮสติ้ง แก้โควตา มอบเว็บ) ยังต้องเดินผ่าน agent
 * เสมอ เพราะกฎโควตาและ audit ต้องมีที่เดียว — มีเทสต์เฝ้าอยู่ว่าชั้นเว็บไม่เรียก repository เอง
 *
 * ราคาที่จ่ายคือ **ต้องเขียน audit เองทุกเมธอดที่เปลี่ยนข้อมูล** เพราะไม่มี Dispatcher มาเขียนให้
 */
final class UsersController extends ApiController
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $quota = new QuotaChecker($users);
        $rows = $users->all();

        $role = $request->get('role');
        if (Permissions::isValidRole($role)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['role'] === $role);
        }

        $status = $request->get('status');
        if (in_array($status, ['active', 'disabled'], true)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['status'] === $status);
        }

        $service = $request->get('service_status');
        if (in_array($service, ['active', 'suspended', 'expired'], true)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['service_status'] === $service);
        }

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_filter($rows, static fn(array $u): bool => str_contains(mb_strtolower((string) $u['username']), $needle)
                || str_contains(mb_strtolower((string) $u['display_name']), $needle)
                || str_contains(mb_strtolower((string) $u['email']), $needle));
        }

        $rows = array_values($rows);
        $page = $this->pagination($request);

        return $this->paginate(
            array_map(
                fn(array $row): array=> $this->present($row, $users, $quota),
                array_slice($rows, $page['offset'], $page['per_page']),
            ),
            count($rows),
            $page['page'],
            $page['per_page'],
        );
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            // ต้องตอบ 200 เสมอแม้ agent ล่ม — หน้านี้เป็นแค่ค่าเริ่มต้นของฟอร์ม
            // ไม่ใช่คำสั่งที่ต้องพึ่ง agent จริง ๆ (ดู SystemController::health)
            // เวอร์ชัน PHP เลือกไม่ได้แค่ช่วงนั้น ไม่ใช่ทั้งหน้าใช้งานไม่ได้
            $phpVersions = [];

            if ($this->agent()->isAvailable()) {
                foreach ($this->fetchPhpVersions($request)['versions'] as $version) {
                    $phpVersions[] = ['value' => $version['version'], 'text' => $version['version']];
                }
            }

            $body = [
                'data' => ['id' => 0],
                'options' => [
                    'php_version' => $phpVersions
                ]
            ];
        } else {

            $users = new UserRepository($this->app->db());
            $user = $users->find($id);

            if ($user === null) {
                return $this->problem(ApiProblem::NotFound, 'User not found');
            }

            $body = $this->present($user, $users, new QuotaChecker($users));

            if ($user['role'] === Permissions::WEBADMIN) {
                $body['sites'] = SiteResource::collection($users->sites((int) $user['id']));
            }
        }

        return $this->ok($body);
    }

    /**
     * สร้างผู้ใช้ใหม่
     *
     * บัญชีโฮสติ้ง (webadmin) เดินผ่าน capability `customer.create` เพราะมีกฎโควตาและ
     * ต้องบันทึก audit ที่ Dispatcher · บัญชีผู้ดูแลสร้างที่ชั้นนี้ตรง ๆ เพราะไม่มีโควตา
     * และต้องมีสิทธิ์ `user.manage` ซึ่งสูงกว่า
     *
     * รหัสผ่านสุ่มให้เสมอถ้าไม่ได้ส่งมา และบังคับเปลี่ยนตอนล็อกอินครั้งแรก — ผู้ดูแลไม่ต้อง
     * คิดรหัสเอง (ซึ่งมักจะได้รหัสที่อ่อน) และรหัสที่ผ่านตาคนกลางแล้วมีอายุสั้นที่สุด
     */
    public function store(Request $request): Response
    {
        $users = new UserRepository($this->app->db());

        $username = trim($request->payloadString('username'));
        $role = $request->payloadString('role') ?: Permissions::WEBADMIN;
        $displayName = trim($request->payloadString('display_name'));

        if (!Permissions::isValidRole($role)) {
            return $this->problem(ApiProblem::ValidationError, 'Invalid role', [
                'role' => 'Allowed: '.implode(', ', array_keys(Permissions::roleLabels()))
            ]);
        }

        if (($denied = $this->assertMayManageRole($role)) !== null) {
            return $denied;
        }

        try {
            UserRepository::assertUsername($username);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(ApiProblem::ValidationError, 'Invalid username', ['username' => $e->getMessage()]);
        }

        if ($users->findByUsername($username) !== null) {
            return $this->problem(ApiProblem::Conflict, 'That username is already taken');
        }

        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(20);
        }

        $createdSite = null;
        $siteError = '';

        if ($role === Permissions::WEBADMIN) {
            $result = $this->agent()->data('customer.create', [
                'username' => $username,
                'password' => $password,
                'display_name' => $displayName,
                'email' => trim($request->payloadString('email')),
                'quota_domains' => (int) $request->payload('quota_domains', 10),
                'quota_subdomains' => (int) $request->payload('quota_subdomains', 20),
                'quota_aliases' => (int) $request->payload('quota_aliases', 50),
                'quota_emails' => (int) $request->payload('quota_emails', 100),
                'quota_databases' => (int) $request->payload('quota_databases', 10),
                'quota_ftp_users' => (int) $request->payload('quota_ftp_users', 5),
                'disk_quota_mb' => (int) $request->payload('disk_quota_mb', 10240),
                'expiry_at' => $this->expiryFrom($request),
                'must_change_password' => $wasRandom
            ], $this->ctx->actor($request));

            $id = (int) $result['id'];

            // สร้างเว็บไซต์แรกให้เลยถ้าระบุโดเมนมาด้วย
            //
            // บัญชีโฮสติ้งที่ยังไม่มีเว็บสักเว็บใช้ทำอะไรไม่ได้เลย — การบังคับให้ผู้ดูแล
            // ไปสร้างต่ออีกหน้าหนึ่งคือขั้นตอนที่ลืมได้ และลืมแล้วลูกค้าล็อกอินเข้ามาเจอ
            // หน้าว่าง · โควตาถูกตรวจโดย capability `site.create` เองอยู่แล้ว
            $domain = trim($request->payloadString('domain'));

            if ($domain !== '') {
                // **ล้มที่ขั้นนี้ต้องไม่ทำให้ทั้งคำขอกลายเป็นล้มเหลว**
                //
                // บัญชีถูกสร้างไปแล้วและใช้งานได้จริง · การโยน error ออกไปทำให้ผู้ดูแล
                // อ่านว่า "สร้างไม่สำเร็จ" แล้วกดซ้ำ ซึ่งจะชนกับชื่อผู้ใช้ที่มีอยู่แล้ว
                // และไม่มีอะไรบอกว่ารหัสผ่านที่สุ่มให้รอบแรกคืออะไร
                //
                // จึงคืน 201 พร้อมบอกให้ชัดว่าอะไรสำเร็จและอะไรไม่ — ผู้ดูแลไปสร้างเว็บ
                // ต่อเองได้จากหน้าเว็บไซต์โดยไม่ต้องแตะบัญชีอีก
                try {
                    $site = $this->agent()->data('site.create', [
                        'domain' => $domain,
                        'name' => $displayName !== '' ? $displayName : $domain,
                        // ไม่ระบุมา = ใช้ตัวใหม่ที่สุดที่ระบบรองรับ · `site.create` ตรวจซ้ำอีกชั้น
                        'php_version' => $request->payloadString('php_version') ?: ServiceCatalog::PHP_VERSIONS[0],
                        'owner_user_id' => $id
                    ], $this->ctx->actor($request));

                    $createdSite = ['id' => (int) ($site['id'] ?? 0), 'domain' => $domain];
                } catch (\Throwable $e) {
                    $siteError = $e->getMessage();

                    $this->audit($request, 'site.create_failed', $domain, [
                        'user_id' => $id,
                        'reason' => $siteError
                    ]);
                }
            }
        } else {
            $id = $users->create($username, $password, $role, $displayName, mustChangePassword: $wasRandom);

            $this->audit($request, 'user.create', $username, ['user_id' => $id, 'role' => $role]);
        }

        // รหัสผ่านที่ระบบสุ่มให้อยู่ในคำตอบครั้งเดียว — panel ไม่เก็บไว้ที่ไหนเลย
        // (ของเดิมส่งผ่าน query string ตอน redirect ซึ่งไปโผล่ใน access log และประวัติเบราว์เซอร์)
        // คำตอบของ**คำสั่ง** — ไม่มีคีย์ `data` · ค่าที่ผู้เรียกต้องใช้อยู่ระดับบนสุด
        // (รหัสที่สุ่มให้ไม่มีที่อื่นให้ดูย้อนหลัง จึงต้องอยู่ในคำตอบและในกล่องที่กดปิดเอง)
        return $this->done(
            $this->t('Account {user} created', ['user' => $username]),
            $this->createdActions($username, $password, $wasRandom, $createdSite, $siteError),
            ['user_id' => $id, 'username' => $username, 'must_change_password' => $wasRandom]
             + ($wasRandom ? ['password' => $password] : [])
             + ($createdSite === null ? [] : ['site' => $createdSite])
             + ($siteError === '' ? [] : ['site_error' => $siteError]),
            201,
        )->withHeader('Location', '/api/v2/users/'.$id);
    }

    /**
     * แก้โปรไฟล์ บทบาท สถานะล็อกอิน สถานะบริการ หรือวันหมดอายุ — ส่งเฉพาะที่ต้องการเปลี่ยน
     *
     * กฎที่ห้ามละเมิด ตรวจก่อนแตะฐานข้อมูลเสมอ:
     *   - เปลี่ยนบทบาท/ระงับบัญชีตัวเองไม่ได้ — กันการล็อกตัวเองออกด้วยการกดพลาด
     *   - ต้องเหลือผู้ดูแลระบบที่ใช้งานได้อย่างน้อยหนึ่งบัญชีเสมอ
     */
    public function update(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $role = $request->payloadString('role');
        $status = $request->payloadString('status');
        $service = $request->payloadString('service_status');
        $displayName = $request->payloadString('display_name');
        $email = $request->payloadString('email');
        $hasExpiry = $request->payload('expiry_at') !== null || $request->get('expiry_at') !== '';

        if ($role === '' && $status === '' && $service === ''
            && $displayName === '' && $email === '' && !$hasExpiry) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        // การเปลี่ยนบทบาทและสถานะล็อกอินของตัวเองคือทางลัดไปสู่การล็อกตัวเองออกจากระบบ
        // ส่วนการแก้ชื่อ/อีเมลของตัวเองไม่อันตราย จึงไม่ห้าม
        if ($id === $this->ctx->userId() && ($role !== '' || $status !== '')) {
            return $this->problem(
                ApiProblem::Forbidden,
                'You cannot change your own role or status — ask another administrator to do it',
            );
        }

        $changes = [];

        if ($role !== '') {
            if (!Permissions::isValidRole($role)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid role', [
                    'role' => 'Allowed: '.implode(', ', array_keys(Permissions::roleLabels()))
                ]);
            }

            // ยกบทบาทขึ้นเป็นผู้ดูแลได้เฉพาะคนที่มีสิทธิ์จัดการผู้ใช้จริง ๆ
            if (($denied = $this->assertMayManageRole($role)) !== null) {
                return $denied;
            }

            if ($role !== Permissions::SUPERADMIN && $users->wouldRemoveLastSuperadmin($id)) {
                return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
            }

            $users->setRole($id, $role);
            $changes['role'] = ['from' => $user['role'], 'to' => $role];
        }

        if ($status !== '') {
            if (!in_array($status, ['active', 'disabled'], true)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid status', [
                    'status' => 'Allowed: active, disabled'
                ]);
            }

            if ($status !== 'active' && $users->wouldRemoveLastSuperadmin($id)) {
                return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
            }

            $users->setStatus($id, $status);
            $changes['status'] = ['from' => $user['status'], 'to' => $status];
        }

        if ($service !== '') {
            if (!in_array($service, ['active', 'suspended', 'expired'], true)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid service status', [
                    'service_status' => 'Allowed: active, suspended, expired'
                ]);
            }

            $users->setServiceStatus($id, $service);
            $changes['service_status'] = ['from' => $user['service_status'], 'to' => $service];
        }

        if ($displayName !== '' || $email !== '') {
            try {
                $users->updateProfile(
                    $id,
                    $displayName !== '' ? $displayName : (string) $user['display_name'],
                    $email !== '' ? $email : (string) $user['email'],
                );
            } catch (\InvalidArgumentException $e) {
                return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['email' => $e->getMessage()]);
            }

            $changes['profile'] = true;
        }

        if ($hasExpiry) {
            $expiry = $this->expiryFrom($request);

            if ($expiry !== null && $expiry < time()) {
                return $this->problem(ApiProblem::ValidationError, 'The expiry date must be in the future', [
                    'expiry_at' => 'Must be a time in the future'
                ]);
            }

            $users->updateExpiry($id, $expiry);
            $changes['expiry_at'] = $expiry;
        }

        // สิทธิ์หรือสถานะเปลี่ยนแล้ว session เดิมยังถือของเก่าอยู่ — ต้องให้ล็อกอินใหม่
        // (การแก้แค่ชื่อหรืออีเมลไม่ต้องตัด session ทิ้ง)
        $revoked = 0;
        if (isset($changes['role']) || isset($changes['status']) || isset($changes['service_status'])) {
            $revoked = (new SessionStore($this->app->db(), $this->app->config))->destroyAllFor($id);
        }

        $this->audit($request, 'user.update', (string) $user['username'], $changes + ['sessions_revoked' => $revoked]);

        $result = ['user_id' => $id, 'sessions_revoked' => $revoked] + $changes;

        return $this->completed('Account saved', 'users', is_array($result) ? $result : []);
    }

    /** อัปเดตโควตา — ผ่าน capability เสมอ (กฎ -1 = ไม่จำกัด อยู่ที่นั่นที่เดียว) */
    public function setQuota(Request $request): Response
    {
        $quotas = [];

        foreach (['quota_domains', 'quota_subdomains', 'quota_aliases',
            'quota_emails', 'quota_databases', 'quota_ftp_users', 'disk_quota_mb'] as $field) {
            $value = $request->payload($field);

            if ($value !== null && $value !== '') {
                $quotas[$field] = (int) $value;
            }
        }

        $result = $this->agent()->data(
            'customer.quota_update',
            ['user_id' => $request->paramInt('id')] + $quotas,
            $this->ctx->actor($request),
        );

        // ใช้ข้อความจาก capability ไม่ใช่ข้อความตายตัว — capability เป็นฝ่ายรู้ว่ามีผลข้างเคียง
        // อะไรเกิดขึ้นบ้าง เช่นการตัดสิทธิ์ SFTP ตามแพ็กเกจซึ่งปิดการเข้าถึงที่เปิดค้างอยู่ด้วย
        // (เดิมเขียนทับด้วย "บันทึกโควตาแล้ว" ผู้ดูแลจึงไม่รู้ว่าเพิ่งตัดการเข้าถึงของลูกค้าไป)
        $message = (string) ($result['message'] ?? 'Quota saved');
        $revoked = (bool) ($result['sftp_revoked'] ?? false);

        return $this->done(
            $message,
            [[
                'type' => 'notification',
                // ตัดการเข้าถึงของลูกค้าเป็นผลข้างเคียงที่ต้องสะดุดตา ไม่ใช่แถบเขียวปกติ
                'level' => $revoked ? 'warning' : 'success',
                'message' => $message,
            ]],
            is_array($result) ? $result : [],
        );
    }

    /**
     * เปิด SFTP พร้อมตั้งรหัสผ่าน — เรียกซ้ำได้เพื่อเปลี่ยนรหัสผ่าน (PLAN-V2 เฟส E4)
     *
     * รหัสผ่านมาจากผู้ดูแลเท่านั้น ไม่สุ่มให้ — ต่างจากรหัสผ่านบัญชี panel ตรงที่ผู้ใช้
     * เอาไปกรอกในโปรแกรม FTP client ของตัวเอง ไม่ได้เปลี่ยนตอนล็อกอินครั้งแรกได้
     */
    public function enableSftp(Request $request): Response
    {
        $result = $this->agent()->data('sftp.enable', [
            'user_id' => $request->paramInt('id'),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'SFTP enabled'),
            '',
            is_array($result) ? $result : [],
        );
    }

    public function disableSftp(Request $request): Response
    {
        $result = $this->agent()->data(
            'sftp.disable',
            ['user_id' => $request->paramInt('id')],
            $this->ctx->actor($request),
        );

        return $this->completed(
            (string) ($result['message'] ?? 'SFTP disabled'),
            '',
            is_array($result) ? $result : [],
        );
    }

    /** มอบเว็บไซต์ที่ยังไม่มีเจ้าของให้บัญชีโฮสติ้ง — capability ตรวจโควตาให้ด้วย */
    public function attachSites(Request $request): Response
    {
        $siteIds = $request->payload('site_ids', []);

        if (!is_array($siteIds)) {
            $siteIds = [$siteIds];
        }

        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));

        if ($siteIds === []) {
            return $this->problem(ApiProblem::ValidationError, 'Choose at least one website to attach', [
                'site_ids' => 'Send a list of website ids'
            ]);
        }

        $result = $this->agent()->data('customer.site_attach', [
            'user_id' => $request->paramInt('id'),
            'site_ids' => $siteIds
        ], $this->ctx->actor($request));

        // เชื่อมไม่ได้สักเว็บ = คำขอนี้ไม่สำเร็จ แม้ agent จะไม่ได้โยน error
        // (เหตุผลรายเว็บอยู่ใน results — เช่นเกินโควตา หรือมีเจ้าของอยู่แล้ว)
        return (int) $result['attached_count'] === 0
            ? $this->problem(ApiProblem::Conflict, (string) $result['message'])
            : $this->completed((string) $result['message'], 'userSites', $result);
    }

    /**
     * ถอนเว็บไซต์ออกจากบัญชีลูกค้า — เจ้าของจะกลายเป็นผู้ดูแลที่สั่ง ไม่ใช่ "ไม่มีเจ้าของ"
     *
     * ตั้งแต่ migration 0005 เว็บทุกแห่งต้องมีเจ้าของเสมอ (บังคับด้วย trigger) เพราะเว็บ
     * ไร้เจ้าของคือเว็บที่ยังรันอยู่บนเครื่องแต่ไม่มีใครรับผิดชอบและไม่ถูกนับเข้าโควตาใคร
     */
    public function detachSite(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        $siteId = $request->paramInt('site_id');
        $site = $this->app->db()->first('SELECT id, owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);

        if ($site === null || (int) $site['owner_user_id'] !== $id) {
            return $this->problem(ApiProblem::NotFound, 'That website is not in this account');
        }

        $newOwner = $this->ctx->userId();

        $this->app->db()->update(
            'sites',
            ['owner_user_id' => $newOwner, 'updated_at' => time()],
            ['id' => $siteId],
        );

        $this->audit($request, 'customer.site_detach', (string) $user['username'], [
            'site_id' => $siteId,
            'new_owner_user_id' => $newOwner
        ]);

        return $this->completed(
            $this->t('Websites moved away from {user} — the new owner is {owner}', ['user' => (string) $user['username'], 'owner' => $this->ctx->username()]),
            'userSites',
            [
                'site_id' => $siteId,
                'previous_owner_user_id' => $id,
                'new_owner_user_id' => $newOwner
            ],
        );
    }

    /** ตั้งรหัสผ่านใหม่ให้ผู้ใช้คนอื่น — ใช้ตอนลืมรหัสผ่าน */
    public function resetPassword(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(20);
        }

        $users->setPassword($id, $password, clearMustChange: false);

        if ($wasRandom) {
            $users->requirePasswordChange($id);
        }

        $revoked = (new SessionStore($this->app->db(), $this->app->config))->destroyAllFor($id);

        $this->audit($request, 'auth.password_reset', (string) $user['username'], [
            'by' => $this->ctx->username(),
            'sessions_revoked' => $revoked
        ]);

        return $this->done(
            $this->t('New password set for {user}', ['user' => (string) $user['username']]),
            // รหัสที่ระบบสุ่มให้ **ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย** — ถ้าผู้ดูแลพลาดตา
            // ต้องรีเซ็ตใหม่ทั้งรอบ · จึงสั่งให้หน้าจอเปิดกล่องที่ต้องกดปิดเอง ไม่ใช่
            // toast ที่หายไปเองใน 3 วินาที · ตารางถูกโหลดใหม่หลังจากนั้น
            $wasRandom ? [
                [
                    'type' => 'modal',
                    'action' => 'show',
                    'title' => 'New password',
                    'content' => sprintf(
                        '<p>รหัสผ่านใหม่ของ <strong>%s</strong> — คัดลอกไว้ก่อนปิดหน้าต่างนี้'
                        .' เพราะระบบไม่เก็บไว้ที่ใดอีก</p><p class="mono selectable">%s</p>'
                        .'<p class="muted">เซสชันที่เปิดค้างอยู่ %d รายการถูกตัดออกแล้ว'
                        .' และบัญชีนี้ต้องตั้งรหัสใหม่เมื่อเข้าใช้งานครั้งต่อไป</p>',
                        htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($password, ENT_QUOTES, 'UTF-8'),
                        $revoked,
                    )
                ],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'users']
            ] : [
                ['type' => 'notification', 'level' => 'success', 'message' => $this->t('New password set for {user}', ['user' => (string) $user['username']])],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'users']
            ],
            [
                'user_id' => $id,
                'username' => (string) $user['username'],
                'password' => $password,
                'must_change_password' => $wasRandom,
                'sessions_revoked' => $revoked
            ],
        );
    }

    /**
     * ปิด 2FA ของผู้ใช้คนอื่น — ใช้เมื่อทำอุปกรณ์ยืนยันตัวตนหาย
     *
     * เป็น `DELETE` บนทรัพยากร `two-factor` ตาม §4.1 · การเปิดใช้งานใหม่ต้องทำโดย
     * เจ้าของบัญชีเองเท่านั้น ไม่มี endpoint ให้ผู้ดูแลเปิดแทน (ไม่งั้นผู้ดูแลจะถือ
     * secret ของคนอื่นได้ ซึ่งทำลายความหมายของ 2FA)
     */
    public function disableTwoFactor(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $users->disableTotp($id);

        $this->audit($request, 'user.disable_2fa', (string) $user['username'], []);

        return $this->completed(
            $this->t('Two-factor disabled for {user}', ['user' => (string) $user['username']]),
            'users',
            ['user_id' => $id],
        );
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function destroy(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        if ($id === $this->ctx->userId()) {
            return $this->problem(ApiProblem::Forbidden, 'You cannot delete your own account');
        }

        if ($users->wouldRemoveLastSuperadmin($id)) {
            return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
        }

        // ฐานข้อมูลกันไว้อีกชั้นด้วย trigger แต่ตอบที่นี่ก่อนเพื่อให้ได้ข้อความที่อธิบายได้
        // ว่าต้องทำอะไรต่อ แทนที่จะเป็นข้อความ constraint ดิบ ๆ จาก SQLite
        if ($users->siteIds($id) !== []) {
            return $this->problem(
                ApiProblem::Conflict,
                'A user who still owns websites cannot be deleted — delete the websites or move them to another owner first',
            );
        }

        $users->delete($id);

        $this->audit($request, 'user.delete', (string) $user['username'], ['role' => $user['role']]);

        // ตอบ 200 พร้อม `actions` แทน 204
        //
        // 204 ไม่มีเนื้อคำตอบ หน้าจอจึงไม่มีอะไรให้ทำต่อ — แถวที่ลบไปแล้วยังค้าง
        // อยู่บนตารางจนกว่าผู้ใช้จะโหลดหน้าเอง ซึ่งอ่านได้ว่า "กดลบแล้วไม่เกิดอะไร"
        // · ให้เซิร์ฟเวอร์สั่งแจ้งผลและโหลดตารางใหม่ไปเลย เป็นรูปแบบเดียวกับที่
        // การสร้างและการรีเซ็ตรหัสผ่านใช้
        return $this->completed(
            $this->t('Account {user} deleted', ['user' => (string) $user['username']]),
            'users',
            ['user_id' => $id, 'username' => (string) $user['username']],
        );
    }

    /**
     * คำสั่งหน้าจอหลังสร้างบัญชี
     *
     * **รหัสที่ระบบสุ่มให้ต้องอยู่ในกล่องที่ผู้ใช้กดปิดเอง** ไม่ใช่ toast ที่หายไปเอง —
     * ไม่มีที่อื่นให้ดูย้อนหลัง พลาดตาแล้วต้องรีเซ็ตใหม่ทั้งรอบ · และห้ามพาออกจากหน้า
     * ทันทีเพราะการเปลี่ยนหน้าจะปิดกล่องนั้นไปพร้อมกัน (เจอจากการกดจริงในเบราว์เซอร์)
     *
     * @param array<string,mixed>|null $site
     * @return list<array<string,mixed>>
     */
    private function createdActions(string $username, string $password, bool $wasRandom, ?array $site, string $siteError): array
    {
        $actions = [];

        if ($siteError !== '') {
            $actions[] = [
                'type' => 'notification',
                'level' => 'warning',
                'duration' => 15000,
                'message' => $this->t('Account {user} created, but the website could not be created: {error}', ['user' => $username, 'error' => $siteError])
            ];
        }

        if ($wasRandom) {
            $summary = $site === null
                ? ''
                : '<p class="muted">' . $this->t('Website {domain} was created too', ['domain' => htmlspecialchars((string) $site['domain'], ENT_QUOTES, 'UTF-8')]) . '</p>';

            $actions[] = [
                'type' => 'modal',
                'action' => 'show',
                'title' => 'Account created',
                'content' => sprintf(
                    '<p>รหัสผ่านของ <strong>%s</strong> — คัดลอกไว้ก่อนปิดหน้าต่างนี้'
                    .' เพราะระบบไม่เก็บไว้ที่ใดอีก</p><p class="mono selectable">%s</p>%s',
                    htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($password, ENT_QUOTES, 'UTF-8'),
                    $summary,
                )
            ];

            return $actions;
        }

        $actions[] = [
            'type' => 'notification',
            'level' => 'success',
            'message' => $site === null
                ? $this->t('Account {user} created', ['user' => $username])
                : $this->t('Account {user} created with website {domain}', ['user' => $username, 'domain' => (string) $site['domain']])
        ];

        // พากลับหน้ารายการได้เฉพาะตอนที่ไม่มีกล่องรหัสผ่านค้างอยู่
        $actions[] = ['type' => 'redirect', 'url' => '/app/users', 'delay' => 1200];

        return $actions;
    }

    /**
     * ผู้ใช้หนึ่งคนในรูปที่ส่งออก API — แนบโควตาให้เฉพาะบัญชีโฮสติ้ง
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, UserRepository $users, QuotaChecker $quota): array
    {
        $id = (int) ($row['id'] ?? 0);

        $presented = ($row['role'] ?? '') !== Permissions::WEBADMIN
            ? UserResource::one($row)
            : UserResource::withHosting($row, $quota->summary($id) ?? [], count($users->siteIds($id)));

        // แถวรู้เองว่าเป็นบัญชีของผู้เรียกหรือไม่ — หน้าจอเขียนเงื่อนไขซ่อนปุ่มลบ
        // และปุ่มรีเซ็ตรหัสผ่านของตัวเองได้โดยไม่ต้องมีตรรกะฝั่ง JS
        //
        // เป็นเรื่อง UX ล้วน ๆ · API ปฏิเสธการลบตัวเองซ้ำอีกชั้นอยู่แล้ว
        $presented['is_self'] = $id === $this->ctx->userId();

        return $presented;
    }

    /**
     * ผู้เรียกมีสิทธิ์จัดการบัญชีเป้าหมายนี้หรือไม่
     *
     * `customer.manage` ให้จัดการได้เฉพาะบัญชีโฮสติ้ง · การแตะบัญชีผู้ดูแลต้องมี `user.manage`
     * ถ้าไม่มีด่านนี้ sysadmin จะรีเซ็ตรหัสผ่านของ superadmin ผ่านเส้นทางนี้ได้ทันที
     *
     * @param array<string,mixed> $target
     */
    private function assertMayManage(array $target): ?Response
    {
        if (($target['role'] ?? '') === Permissions::WEBADMIN) {
            return null;
        }

        return $this->ctx->can('user.manage')
            ? null
            : $this->problem(ApiProblem::Forbidden, 'Managing system users is required to edit an administrator account');
    }

    /** ตั้งบทบาทที่ไม่ใช่ webadmin ต้องมีสิทธิ์จัดการผู้ใช้ระบบ */
    private function assertMayManageRole(string $role): ?Response
    {
        if ($role === Permissions::WEBADMIN) {
            return null;
        }

        return $this->ctx->can('user.manage')
            ? null
            : $this->problem(ApiProblem::Forbidden, 'Managing system users is required to assign an administrator role');
    }

    /** วันหมดอายุที่รับได้ทั้ง unix timestamp และข้อความวันที่ */
    private function expiryFrom(Request $request): ?int
    {
        $value = $request->payload('expiry_at', $request->get('expiry_at'));

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }

    /**
     * บันทึก audit — ต้องเรียกจากทุกเมธอดที่เปลี่ยนแปลงข้อมูลในไฟล์นี้
     *
     * ทรัพยากรนี้ไม่ผ่าน Dispatcher จึงไม่มีใครเขียน audit ให้อัตโนมัติ
     * นี่คือราคาที่จ่ายเพื่อไม่ต้องขยายพื้นที่ผิวของชั้น agent
     *
     * @param array<string,mixed> $detail
     */
    private function audit(Request $request, string $action, string $target, array $detail): void
    {
        $this->app->audit()->write($this->ctx->actor($request), $action, $target, 'ok', $detail);
    }
}
