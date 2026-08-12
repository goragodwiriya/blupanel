<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ค่าตั้ง SSH — `/api/v2/ssh-config`
 *
 * แก้ได้เฉพาะคีย์ใน allowlist ของ `SshManager` และค่าที่อยู่ใน enum ของแต่ละคีย์เท่านั้น
 * — ไม่มีทางเขียนบรรทัดอิสระลง sshd_config ผ่าน API นี้ ซึ่งเป็นสิ่งที่กัน
 * "แก้ config แล้ว sshd ไม่ขึ้นอีกเลย" ตั้งแต่ต้นทาง
 *
 * **การเปลี่ยนค่าที่นี่อาจตัดการเชื่อมต่อของผู้สั่งเอง** (เปลี่ยนพอร์ต, ปิด password auth)
 * จึงต้องยืนยันภายในเวลาเสมอ · คำตอบแนบ `pending_rollback` กลับไปให้ SPA ขึ้นตัวนับถอยหลัง
 */
final class SshConfigController extends ApiController
{
    public function show(Request $request): Response
    {
        $data = $this->agent()->data('ssh.config_get', [], $this->ctx->actor($request));

        return $this->ok($data + [
            'editable_keys' => SshManager::keys(),
            // ปุ่มบันทึกถาม can['manage'] จากคำตอบนี้ตรง ๆ — ฟอร์มโหลดข้อมูลผ่าน
            // data-load-api ของตัวเอง ไม่ได้อยู่ใต้ data-component="api" ของ /session
            'can' => $this->can(['manage' => 'ssh.manage']),
        ], [
            'pending_rollback' => $this->pendingRollback(),
            'default_window' => RollbackGuard::DEFAULT_WINDOW,
        ]);
    }

    /**
     * แก้ค่าบางส่วน — ส่งมาเฉพาะคีย์ที่ต้องการเปลี่ยน
     *
     * คีย์ที่ไม่ได้ส่งมาจะไม่ถูกแตะ (นี่คือความหมายของ PATCH) · คีย์ที่ไม่รู้จักถูกปฏิเสธ
     * พร้อมบอกรายการที่ใช้ได้ แทนที่จะเงียบ ๆ ไม่ทำอะไรแล้วผู้ใช้คิดว่าบันทึกแล้ว
     */
    public function update(Request $request): Response
    {
        $changes = [];
        $unknown = [];

        foreach ($request->json() + $request->post as $key => $value) {
            if (in_array($key, ['window', '_token'], true)) {
                continue;
            }

            if (!in_array($key, SshManager::keys(), true)) {
                $unknown[] = (string) $key;

                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                $changes[$key] = $text;
            }
        }

        if ($unknown !== []) {
            return $this->problem(
                ApiProblem::ValidationError,
                $this->t('These keys cannot be changed') . ': ' . implode(', ', $unknown),
                ['keys' => 'Editable keys: ' . implode(', ', SshManager::keys())],
            );
        }

        if ($changes === []) {
            return $this->problem(
                ApiProblem::ValidationError,
                'Send at least one value to change',
                ['keys' => 'Editable keys: ' . implode(', ', SshManager::keys())],
            );
        }

        $result = $this->agent()->data(
            'ssh.config_set',
            $changes + ['window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW)],
            $this->ctx->actor($request),
        );

        $result = $result + ['pending_rollback' => $this->pendingRollback()];

        return $this->completed((string) ($result['message'] ?? 'SSH settings saved — confirm them before the automatic rollback runs out'), '', is_array($result) ? $result : []);
    }

    /** @return array<string,mixed>|null */
    private function pendingRollback(): ?array
    {
        $pending = (new RollbackGuard($this->app->db()))->pending();

        if ($pending === null) {
            return null;
        }

        return [
            'id' => (int) $pending['id'],
            'action' => (string) $pending['action'],
            'description' => (string) $pending['description'],
            'expires_at' => (int) $pending['expires_at'],
            'remaining_seconds' => RollbackGuard::remaining($pending),
        ];
    }
}
