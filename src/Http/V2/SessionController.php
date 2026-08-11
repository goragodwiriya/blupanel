<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Actor;
use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Security\SessionStore;
use Phpcp\Security\Totp;

/**
 * เซสชันของ REST API v2 — PLAN-V2 §4.4
 *
 * `GET /api/v2/session` เป็นจุดเริ่มต้นของ SPA ทุกครั้งที่เปิดแอป และเป็นทางเดียว
 * ที่ฝั่งหน้าเว็บได้ CSRF token มา (เดิม token ฝังอยู่ใน `<meta>` ของหน้า HTML
 * ซึ่ง SPA ไม่มีให้ขูด — นี่คือช่องว่างที่แผนระบุว่าต้องเพิ่มใหม่)
 *
 * **สิ่งที่จงใจไม่เปลี่ยนจากของเดิม:** คุกกี้ session ยังเป็น HttpOnly + SameSite=Strict
 * และยังผูกกับ IP/User-Agent เหมือนเดิมทุกประการ · ไม่มีการย้าย token ไปเก็บใน JS
 * (ตัดสินใจ N6) เพราะนั่นจะเป็นการถอยหลังจากที่มีอยู่แล้ว
 */
final class SessionController extends ApiController
{
    /**
     * สถานะปัจจุบัน — เรียกได้โดยไม่ต้องล็อกอิน
     *
     * ตอบ 200 เสมอ ไม่ว่าจะล็อกอินอยู่หรือไม่ · "ยังไม่ล็อกอิน" ไม่ใช่ข้อผิดพลาด
     * แต่เป็นสถานะหนึ่งที่ SPA ต้องรู้เพื่อตัดสินใจว่าจะแสดงหน้าล็อกอินหรือหน้าหลัก
     */
    public function show(Request $request): Response
    {
        return $this->ok($this->state($request));
    }

    /**
     * เข้าสู่ระบบ
     *
     * ตอบเหมือนกันหมดทุกกรณีที่ล้มเหลว (ชื่อผู้ใช้ผิด/รหัสผ่านผิด/บัญชีถูกระงับ)
     * และคำนวณ Argon2id เสมอแม้ไม่พบผู้ใช้ เพื่อไม่ให้เวลาตอบกลับบอกว่าชื่อนี้มีอยู่จริงไหม
     */
    public function create(Request $request): Response
    {
        $username = trim($request->payloadString('username'));
        $password = $request->payloadString('password');

        $users = new UserRepository($this->app->db());
        $user = $username === '' ? null : $users->findByUsername($username);

        // ค่าคงที่ชุดเดียวกับ AuthController — ต้องคำนวณจริงเพื่อให้เวลาตอบใกล้เคียงกัน
        $dummyHash = '$argon2id$v=19$m=65536,t=4,p=2$YWFhYWFhYWFhYWFhYWFhYQ$'
            . 'c2FtcGxlaGFzaHZhbHVlZm9ydGltaW5nZXF1YWxpdHl4eA';

        if ($user === null) {
            Password::verify($password, $dummyHash);

            return $this->loginFailed($request, $username, 'ไม่พบผู้ใช้');
        }

        if ($users->isLocked($user)) {
            $seconds = $users->lockRemaining($user);

            $this->audit($request, 'auth.login', $username, 'denied', ['reason' => 'บัญชีถูกล็อกชั่วคราว']);

            return $this->problem(
                ApiProblem::RateLimited,
                sprintf('บัญชีถูกล็อกชั่วคราวจากการเข้าสู่ระบบผิดหลายครั้ง กรุณารออีก %d นาที', (int) ceil($seconds / 60)),
            )->withHeader('Retry-After', (string) max(1, $seconds));
        }

        if ($user['status'] !== 'active') {
            return $this->loginFailed($request, $username, 'บัญชีถูกระงับ');
        }

        // บัญชีที่ถูกระงับหรือหมดอายุห้ามเข้าใช้งาน แม้สิทธิ์ล็อกอินจะยัง active
        // ข้อความตอบกลับไม่บอกเหตุผลละเอียด เพราะเป็นการตอบก่อนตรวจรหัสผ่าน —
        // ถ้าบอกชัดจะกลายเป็นช่องให้เดาว่าชื่อผู้ใช้ไหนมีอยู่จริง
        if (!$users->checkService((int) $user['id'])['ok']) {
            return $this->loginFailed($request, $username, 'สถานะบัญชีไม่อนุญาตให้เข้าใช้งาน');
        }

        if (!Password::verify($password, (string) $user['password_hash'])) {
            $users->registerFailure(
                (int) $user['id'],
                $this->app->config->int('security.max_login_attempts', 5),
                $this->app->config->int('security.lockout_seconds', 900),
            );

            return $this->loginFailed($request, $username, 'รหัสผ่านไม่ถูกต้อง');
        }

        // สร้าง session ใหม่เสมอหลังยืนยันตัวตน (กัน session fixation)
        $needs2fa = (int) $user['totp_enabled'] === 1;
        $store = new SessionStore($this->app->db(), $this->app->config);

        $this->ctx->sessionId = $store->create(
            (int) $user['id'],
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
            $needs2fa,
        );

        $users->registerSuccess((int) $user['id'], $request->ip, (string) $user['password_hash']);

        // actor สร้างจากข้อมูลผู้ใช้ตรง ๆ เพราะ ctx->session ยังไม่ถูกโหลดในคำขอนี้
        $this->app->audit()->write(
            new Actor(
                userId: (int) $user['id'],
                username: (string) $user['username'],
                role: (string) $user['role'],
                ip: $request->ip,
                requestId: $request->requestId,
            ),
            'auth.login',
            $username,
            'ok',
            ['2fa_required' => $needs2fa, 'ip' => $request->ip, 'via' => 'api'],
        );

        if ($needs2fa) {
            // 401 พร้อมรหัส TWO_FACTOR_REQUIRED — คุกกี้ session ถูกตั้งแล้วแต่ยังใช้ทำอะไรไม่ได้
            // นอกจากยืนยัน 2FA · SPA ใช้รหัสนี้ตัดสินใจแสดงหน้ากรอกรหัส ไม่ใช่หน้าเข้าสู่ระบบใหม่
            return $this->problem(ApiProblem::TwoFactorRequired, 'ต้องยืนยันรหัส 2FA ก่อนใช้งาน');
        }

        // โหลด session ที่เพิ่งสร้างเข้า ctx เพื่อให้คำตอบสะท้อนสถานะจริงทันที
        $this->loadSessionIntoCtx($request);

        return $this->ok($this->state($request));
    }

    /** ยืนยันรหัส 2FA ของ session ที่ผ่านรหัสผ่านมาแล้ว */
    public function verifyTwoFactor(Request $request): Response
    {
        if (!$this->ctx->awaiting2fa()) {
            return $this->problem(ApiProblem::Unauthenticated, 'ไม่มีการเข้าสู่ระบบที่รอยืนยัน');
        }

        $users = new UserRepository($this->app->db());
        $user = $users->find($this->ctx->userId());

        if ($user === null || $user['totp_secret'] === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'บัญชีนี้ไม่ได้เปิดใช้งาน 2FA');
        }

        $code = trim($request->payloadString('code'));
        $secret = new Secret($this->app->config->secretKey());
        $accepted = Totp::verify($secret->decrypt((string) $user['totp_secret']), $code);

        // รหัสสำรองใช้ได้ครั้งเดียว เผื่อทำอุปกรณ์ยืนยันตัวตนหาย
        if (!$accepted) {
            $accepted = $users->consumeRecoveryCode((int) $user['id'], $code);
        }

        if (!$accepted) {
            $this->audit($request, 'auth.2fa', (string) $user['username'], 'denied');

            return $this->problem(ApiProblem::TwoFactorRequired, 'รหัสยืนยันไม่ถูกต้อง', ['code' => 'รหัสยืนยันไม่ถูกต้อง']);
        }

        $store = new SessionStore($this->app->db(), $this->app->config);
        $store->markAuthenticated($this->ctx->sessionId);

        // หมุน id อีกครั้งหลังยกระดับสิทธิ์ของ session
        // rotate() คืน null เมื่อคำขออื่นหมุนไปก่อนแล้ว — กรณีนั้นให้ใช้ id เดิมต่อ
        // (ยังใช้งานได้อยู่ในช่วงผ่อนผัน) ไม่ใช่เขียนทับด้วย null
        $this->ctx->sessionId = $store->rotate($this->ctx->sessionId) ?? $this->ctx->sessionId;

        $this->audit($request, 'auth.2fa', (string) $user['username'], 'ok');
        $this->loadSessionIntoCtx($request);

        return $this->ok($this->state($request));
    }

    /**
     * ออกจากระบบ
     *
     * ตอบ 204 เสมอแม้ไม่ได้ล็อกอินอยู่ — การออกจากระบบซ้ำไม่ใช่ข้อผิดพลาด
     * และการตอบต่างกันจะบอกผู้เรียกว่าคุกกี้ที่ถืออยู่ใช้ได้จริงหรือไม่
     */
    public function destroy(Request $request): Response
    {
        if ($this->ctx->sessionId !== '') {
            (new SessionStore($this->app->db(), $this->app->config))->destroy($this->ctx->sessionId);
            $this->audit($request, 'auth.logout', $this->ctx->username(), 'ok');
        }

        $this->ctx->session = null;
        $this->ctx->sessionId = '';

        return $this->noContent();
    }

    /**
     * สถานะที่ SPA ต้องใช้ตอน bootstrap — §4.4
     *
     * @return array<string,mixed>
     */
    private function state(Request $request): array
    {
        $config = $this->app->config;

        $state = [
            'authenticated' => $this->ctx->isAuthenticated(),
            'csrf_token' => $this->ctx->csrfToken,
            'mode' => $config->mode->value,
            'mode_label' => $config->mode->label(),
            'agent_available' => $this->app->agent()->isAvailable(),
            'two_factor_pending' => $this->ctx->awaiting2fa(),
        ];

        if (!$this->ctx->isAuthenticated() && !$this->ctx->awaiting2fa()) {
            return $state;
        }

        $user = (new UserRepository($this->app->db()))->find($this->ctx->userId());

        if ($user === null) {
            return $state;
        }

        return $state + [
            'user' => UserResource::one($user),
            'permissions' => $this->permissionMap(),
            'must_change_password' => $this->ctx->mustChangePassword(),
        ];
    }

    /**
     * สิทธิ์ในรูป map ที่**มีครบทุกตัวที่ระบบรู้จัก** พร้อมค่า true/false
     *
     * เป็นแผนที่ ไม่ใช่รายการ เพราะหน้าจอเขียนเงื่อนไขตรง ๆ ว่า
     * `data-if="permissions['user.manage']"` ได้โดยไม่ต้องมีฟังก์ชันช่วยฝั่ง JS เลย
     * (บนอาร์เรย์ การอ้างด้วยชื่อจะได้ `undefined` ซึ่ง `data-if` ตีความว่า "แสดง")
     *
     * **ส่งครบทุกตัวแม้ค่าจะเป็น false โดยเจตนา** — ถ้าส่งเฉพาะตัวที่มีสิทธิ์ คีย์ที่
     * ไม่มีจะเป็น `undefined` แล้วองค์ประกอบนั้นจะ**โผล่** ไม่ใช่ถูกซ่อน · ส่งครบแล้ว
     * ทุกเงื่อนไขในเทมเพลตมีค่าที่นิยามชัดเจนเสมอ เหลือความเสี่ยงเดียวคือสะกดชื่อผิด
     * ซึ่งมีเทสต์ไล่ตรวจทุกเทมเพลตคุมอยู่
     *
     * @return array<string,bool>
     */
    private function permissionMap(): array
    {
        $granted = $this->ctx->isAuthenticated() ? Permissions::forRole($this->ctx->role()) : [];
        $map = [];

        foreach (array_keys(Permissions::all()) as $permission) {
            $map[$permission] = in_array($permission, $granted, true);
        }

        return $map;
    }

    /**
     * โหลด session ที่เพิ่งสร้าง/หมุน เข้า ctx
     *
     * จำเป็นเพราะ SessionMiddleware ทำงานไปแล้วก่อนถึง controller — ถ้าไม่โหลดซ้ำ
     * คำตอบของคำขอล็อกอินจะบอกว่า "ยังไม่ได้ล็อกอิน" ทั้งที่คุกกี้ถูกตั้งไปแล้ว
     */
    private function loadSessionIntoCtx(Request $request): void
    {
        $store = new SessionStore($this->app->db(), $this->app->config);

        $this->ctx->session = $store->load(
            $this->ctx->sessionId,
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
        );

        // token ที่ middleware ออกไว้ผูกกับ session เก่า ต้องออกใหม่ก่อนใส่ลงคำตอบ
        $this->ctx->refreshCsrfToken();
    }

    private function loginFailed(Request $request, string $username, string $reason): Response
    {
        $this->audit($request, 'auth.login', $username, 'denied', ['reason' => $reason, 'ip' => $request->ip]);

        return $this->problem(ApiProblem::Unauthenticated, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
    }

    /** @param array<string,mixed> $detail */
    private function audit(Request $request, string $action, string $target, string $result, array $detail = []): void
    {
        $this->app->audit()->write($this->ctx->actor($request), $action, $target, $result, $detail);
    }
}
