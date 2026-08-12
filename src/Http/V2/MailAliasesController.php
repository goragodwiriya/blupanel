<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\MailboxRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * ที่อยู่ส่งต่อ — `/api/v2/mail-aliases` (PLAN-MAIL เฟส M2)
 *
 * รายการอยู่ใน `meta.aliases` ของหน้ากล่องจดหมาย จึงไม่มี index ของตัวเอง — ตารางบน
 * หน้าเว็บผูกกับข้อมูลที่โหลดมาแล้ว (`data-attr="data:aliases"`) ไม่ได้ยิงคำขอแยก
 */
final class MailAliasesController extends ApiController
{
    /** โครงเปล่าของฟอร์ม พร้อมคำสั่งเปิด modal */
    public function form(Request $request): Response
    {
        return $this->ok(
            [
                'source' => '',
                'domain' => '',
                'destination' => '',
                'domains' => $this->repository()->selectableDomains($this->scopeOwner()),
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'mail-alias-form.html',
                'title' => '{LNG_Add forwarder}',
                'titleClass' => 'icon-link',
            ]],
        );
    }

    public function store(Request $request): Response
    {
        $result = $this->agent()->data('mail.alias_set', [
            'domain' => trim($request->payloadString('domain')),
            'source' => trim($request->payloadString('source')),
            'destination' => trim($request->payloadString('destination')),
        ], $this->ctx->actor($request));

        // ตารางผูกกับข้อมูลของหน้า ไม่ได้ยิงคำขอเอง — ต้องให้ทั้งหน้าโหลดใหม่
        return $this->done(
            (string) ($result['message'] ?? 'Forwarder saved'),
            [
                ['type' => 'notification', 'level' => 'success',
                    'message' => (string) ($result['message'] ?? 'Forwarder saved')],
                ['type' => 'event', 'event' => self::RELOAD_EVENT],
            ],
            is_array($result) ? $result : [],
        );
    }

    public function destroy(Request $request): Response
    {
        $id = $request->paramInt('id');

        if (!$this->owns($id)) {
            return $this->problem(ApiProblem::NotFound, 'Forwarder not found');
        }

        $result = $this->agent()->data('mail.alias_delete', ['id' => $id], $this->ctx->actor($request));

        // ตารางผูกกับข้อมูลของหน้า ไม่ได้ยิงคำขอเอง — ต้องให้ทั้งหน้าโหลดใหม่
        return $this->done(
            (string) ($result['message'] ?? 'Forwarder deleted'),
            [
                ['type' => 'notification', 'level' => 'success',
                    'message' => (string) ($result['message'] ?? 'Forwarder deleted')],
                ['type' => 'event', 'event' => self::RELOAD_EVENT],
            ],
            is_array($result) ? $result : [],
        );
    }

    /** ผู้เรียกเห็นรายการนี้ได้จริงไหม — ตรวจจากรายการที่ scope ให้แล้ว */
    private function owns(int $id): bool
    {
        foreach ($this->repository()->listAliases($this->scopeOwner()) as $row) {
            if ((int) $row['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    private function repository(): MailboxRepository
    {
        return new MailboxRepository($this->app->db());
    }

    private function scopeOwner(): int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : 0;
    }
}
