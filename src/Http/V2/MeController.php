<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\SessionStore;

/**
 * บัญชีของผู้ที่กำลังล็อกอินอยู่ — `/api/v2/me`
 *
 * แยกจาก `/users/{id}` โดยเจตนา: การแก้ข้อมูลตัวเองไม่ต้องใช้สิทธิ์ `user.manage`
 * (ลูกค้าที่เป็น webadmin ต้องเปลี่ยนรหัสผ่านตัวเองได้ แต่ต้องแตะบัญชีคนอื่นไม่ได้เลย)
 * ถ้าใช้เส้นทางเดียวกันแล้วเช็คว่า id ตรงกับตัวเองไหม จะมีวันที่ลืมเช็ค — เส้นทางแยก
 * ทำให้ผิดพลาดแบบนั้นไม่ได้ตั้งแต่โครงสร้าง
 */
final class MeController extends ApiController
{
    public function show(Request $request): Response
    {
        $user = (new UserRepository($this->app->db()))->find($this->ctx->userId());

        if ($user === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'Your account was not found');
        }

        return $this->ok(UserResource::withPermissions($user));
    }

    /**
     * เปลี่ยนรหัสผ่านของตัวเอง
     *
     * ตัด session อื่นทั้งหมดทิ้งหลังเปลี่ยนสำเร็จ เผื่อว่ารหัสเดิมรั่วและมีคนสวมรอยอยู่
     * แล้วสร้าง session ใหม่ให้ผู้เรียกทันที เพื่อไม่ให้ตัวเองหลุดออกจากระบบไปด้วย
     */
    public function changePassword(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $user = $users->find($this->ctx->userId());

        if ($user === null) {
            return $this->problem(ApiProblem::Unauthenticated, 'Your account was not found');
        }

        $current = $request->payloadString('current_password');
        $new = $request->payloadString('new_password');
        $minLength = $this->app->config->int('security.password_min_length', 12);

        $fields = [];

        if (!Password::verify($current, (string) $user['password_hash'])) {
            $fields['current_password'] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        }

        if ($new === $current) {
            $fields['new_password'] = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
        }

        $problems = Password::problems($new, $minLength, (string) $user['username']);

        if ($problems !== []) {
            // ข้อความรวมกันในฟิลด์เดียว เพราะทุกข้อพูดถึงช่องเดียวกันคือรหัสผ่านใหม่
            $fields['new_password'] = implode(' · ', array_unique($problems));
        }

        if ($fields !== []) {
            return $this->problem(ApiProblem::ValidationError, 'The new password cannot be used', $fields);
        }

        $users->setPassword((int) $user['id'], $new);

        $store = new SessionStore($this->app->db(), $this->app->config);
        $revoked = $store->destroyAllFor((int) $user['id']);

        $this->ctx->sessionId = $store->create(
            (int) $user['id'],
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
            false,
        );

        $this->ctx->session = $store->load(
            $this->ctx->sessionId,
            $request->ip,
            SessionStore::hashUserAgent($request->userAgent),
        );

        $this->app->audit()->write(
            $this->ctx->actor($request),
            'auth.password_changed',
            (string) $user['username'],
            'ok',
            ['via' => 'api', 'sessions_revoked' => $revoked],
        );

        $result = [
            'changed' => true,
            'sessions_revoked' => $revoked,
            'message' => 'Password changed',
        ];

        return $this->completed('Password changed', '', is_array($result) ? $result : []);
    }
}
