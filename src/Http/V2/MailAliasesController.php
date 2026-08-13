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
    /**
     * รายการที่อยู่ส่งต่อ — ตารางดึงเอง (`data-source`)
     *
     * **ต้องมี endpoint ของตัวเอง ไม่ใช่แถมมากับ `/api/v2/mailboxes`** · ตารางที่ดึง
     * ข้อมูลเองเท่านั้นที่สั่ง "โหลดใหม่" ได้จริงหลังลบสำเร็จ — ตอนที่ผูกกับข้อมูลของหน้า
     * การลบทำงานถูกต้องบนเซิร์ฟเวอร์แต่ตารางยังโชว์แถวเดิมค้างอยู่
     */
    public function index(Request $request): Response
    {
        $canManage = $this->ctx->can('mail.manage');

        return $this->ok(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'row_id' => (int) $row['id'],
                // ว่าง = catch-all · แสดงเป็น `@โดเมน` ให้ตรงกับที่ Postfix เขียน
                'source' => ((string) $row['source'] === '' ? '' : $row['source']) . '@' . $row['domain'],
                'destination' => (string) $row['destination'],
                'domain' => (string) $row['domain'],
                'can_manage' => $canManage,
            ],
            $this->repository()->listAliases($this->scopeOwner()),
        ));
    }

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

        return $this->saved(
            (string) ($result['message'] ?? 'Forwarder saved'),
            'mailAliases',
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

        // สั่งให้ตารางโหลดใหม่ด้วยชนิดคำสั่งที่เฟรมเวิร์กมีตัวรับอยู่แล้ว
        return $this->completed(
            (string) ($result['message'] ?? 'Forwarder deleted'),
            'mailAliases',
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
