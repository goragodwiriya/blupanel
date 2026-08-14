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
 * กล่องจดหมายและที่อยู่ส่งต่อ — `/api/v2/mailboxes` (PLAN-MAIL เฟส M2)
 *
 * ทุกคำสั่งที่เปลี่ยนแปลงอะไรเดินผ่าน agent ทั้งหมด · ที่นี่มีหน้าที่แค่แปลงคำขอ HTTP
 * เป็น argument ของ capability และแปลงผลลัพธ์กลับเป็น JSON
 *
 * **การมองเห็นถูกจำกัดที่ query ไม่ใช่กรองทีหลัง** — เจ้าของเว็บเห็นเฉพาะกล่องของโดเมน
 * ตัวเอง โดยที่กล่องของลูกค้ารายอื่นไม่ถูกอ่านขึ้นมาในหน่วยความจำเลยตั้งแต่แรก
 */
final class MailboxesController extends ApiController
{
    /**
     * รายการกล่องจดหมาย พร้อมที่อยู่ส่งต่อและโดเมนที่เลือกได้ใน meta
     */
    public function index(Request $request): Response
    {
        $repository = $this->repository();
        $scope = $this->scopeOwner();
        $canManage = $this->ctx->can('mail.manage');

        $rows = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'row_id' => (int) $row['id'],
                'address' => $row['local_part'] . '@' . $row['domain'],
                'local_part' => (string) $row['local_part'],
                'domain' => (string) $row['domain'],
                'quota_mb' => (int) $row['quota_mb'],
                'enabled' => (int) $row['enabled'] === 1,
                'created_at' => (int) $row['created_at'],
                'can_manage' => $canManage,
            ],
            $repository->listMailboxes($scope),
        );

        /*
         * **คืนเป็นรายการล้วน เพราะตารางดึงข้อมูลเอง (`data-source`)**
         *
         * ตารางที่ดึงข้อมูลเองเท่านั้นที่สั่ง "โหลดใหม่" ได้จริงหลังลบสำเร็จ · ตอนที่
         * ผูกกับข้อมูลของหน้า การลบทำงานถูกต้องบนเซิร์ฟเวอร์แต่ตารางยังโชว์แถวเดิมค้างอยู่
         * — ที่อยู่ส่งต่อย้ายไปมี endpoint ของตัวเองด้วยเหตุผลเดียวกัน
         */
        return $this->ok($rows);
    }

    /**
     * โครงเปล่าของฟอร์มสร้างกล่อง พร้อมคำสั่งเปิด modal
     *
     * กล่องแก้ไขได้ (รหัสผ่านกับโควตา) แต่ที่อยู่เปลี่ยนไม่ได้ — การเปลี่ยนที่อยู่คือ
     * การย้ายเมลทั้งกล่องไปที่ใหม่ ซึ่งเป็นคนละเรื่องกับการแก้ค่า · ฟอร์มแก้ไขจึงมี
     * ช่องที่อยู่เป็นข้อความอ่านอย่างเดียว
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            [
                'id' => 0,
                'local_part' => '',
                'domain' => '',
                'quota_mb' => 1024,
                'domains' => $this->repository()->selectableDomains($this->scopeOwner()),
            ],
            [],
            $this->modal('mailbox-form.html', '{LNG_Add mailbox}', 'icon-email'),
        );
    }

    /** ฟอร์มแก้ไขกล่องที่มีอยู่ — ที่อยู่แก้ไม่ได้ เปลี่ยนได้แค่รหัสผ่านกับโควตา */
    public function show(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        return $this->ok(
            [
                'id' => (int) $row['id'],
                'address' => $row['local_part'] . '@' . $row['domain'],
                'local_part' => (string) $row['local_part'],
                'domain' => (string) $row['domain'],
                'quota_mb' => (int) $row['quota_mb'],
                'domains' => [],
            ],
            [],
            $this->modal('mailbox-form.html', '{LNG_Edit mailbox}', 'icon-email'),
        );
    }

    public function store(Request $request): Response
    {
        // ฟอร์มเดียวทำทั้งสร้างและแก้ไข — `id` ที่ซ่อนอยู่เป็นตัวตัดสิน แบบเดียวกับหน้าอื่น
        $id = (int) $request->payload('id', 0);

        if ($id > 0) {
            return $this->update($request->withParams(['id' => $id]));
        }

        $result = $this->agent()->data('mail.box_create', [
            'address' => trim($request->payloadString('local_part')) . '@' . trim($request->payloadString('domain')),
            'quota_mb' => (int) $request->payload('quota_mb', 1024),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        $message = (string) ($result['message'] ?? 'Mailbox created');

        return $this->done($message, $this->passwordActions($result, 'Mailbox created', $message), $result, 201);
    }

    public function update(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        $result = $this->agent()->data('mail.box_update', [
            'address' => $row['local_part'] . '@' . $row['domain'],
            'quota_mb' => (int) $request->payload('quota_mb', 0),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        $message = (string) ($result['message'] ?? 'Mailbox saved');

        return $this->done($message, $this->passwordActions($result, 'New password', $message), $result);
    }

    public function destroy(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        $result = $this->agent()->data('mail.box_delete', [
            'address' => $row['local_part'] . '@' . $row['domain'],
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Mailbox deleted'),
            'mailboxes',
            is_array($result) ? $result : [],
        );
    }

    /**
     * ความพร้อมของเมลหกข้อ — PLAN-MAIL §7
     *
     * เติม `fix_label` ให้หน้าจอไม่ต้องรู้จักรหัสภายใน และเติมคำอธิบายของแต่ละข้อ
     * ที่ฝั่งเซิร์ฟเวอร์ เพราะเป็นความรู้เรื่องเมล ไม่ใช่เรื่องการแสดงผล
     */
    public function readiness(Request $request): Response
    {
        $result = $this->agent()->data('mail.readiness', [], $this->ctx->actor($request));

        $labels = [
            'hostname' => 'Mail hostname',
            'listening' => 'Listening for incoming mail',
            'outbound' => 'Outgoing port 25',
            'rdns' => 'Reverse DNS (PTR)',
            'dkim' => 'DKIM signing key',
            'tls' => 'TLS certificate',
        ];

        $rows = array_map(
            fn (array $check): array => $check + [
                'label' => $this->t($labels[$check['key']] ?? $check['key']),
                'tone' => $check['ok'] ? 'ok' : 'danger',
                'status' => $check['ok'] ? $this->t('Ready') : $this->t('Not ready'),
                // ข้อที่ panel แก้ให้ไม่ได้ต้องบอกให้ชัดว่าต้องไปทำที่ไหน
                'fix_label' => $check['fix'] === 'provider'
                    ? $this->t('Set this at your VPS provider — the panel cannot do it')
                    : $this->t('The panel can fix this'),
            ],
            (array) ($result['checks'] ?? []),
        );

        // อยู่ใน `data` ด้วยเหตุผลเดียวกับ index() — ป้ายสรุปที่ผูกกับ `meta` ไม่เคยขึ้นเลย
        return $this->ok([
            'ready' => (bool) ($result['ready'] ?? false),
            'failed' => (int) ($result['failed'] ?? 0),
            'domains' => (array) ($result['domains'] ?? []),
            'checks' => $rows,
        ]);
    }

    /**
     * ผูกใบรับรองของ mail hostname เข้ากับ Postfix และ Dovecot — PLAN-MAIL เฟส M3
     *
     * ปุ่มนี้ไม่ได้ "ขอใบรับรอง" — ใบขอจากหน้า SSL ที่มีอยู่แล้วเหมือนใบของเว็บทุกใบ ·
     * ที่นี่คือขั้นที่เหลือ: บอกเดมอนเมลว่าให้ใช้ใบนั้น · งานตามเวลาทำให้เองทุกวันอยู่แล้ว
     * ปุ่มมีไว้ให้ไม่ต้องรอถึงพรุ่งนี้เช้าหลังเพิ่งขอใบมาสด ๆ
     */
    public function certificate(Request $request): Response
    {
        $result = $this->agent()->data('mail.cert', [], $this->ctx->actor($request));
        $message = (string) ($result['message'] ?? 'Mail certificate checked');

        return $this->done(
            $message,
            [
                // ยังไม่มีใบให้ผูกไม่ใช่ข้อผิดพลาด แต่ก็ไม่ใช่ความสำเร็จที่ควรฉลอง —
                // ข้อความบอกวิธีขอใบ ซึ่งผู้ใช้ต้องอ่านจริง ๆ ไม่ใช่แค่เห็นแวบเดียว
                ['type' => 'notification',
                    'level' => ($result['found'] ?? false) ? 'success' : 'warning',
                    'message' => $message],
                // ตารางความพร้อมผูกกับข้อมูลของการ์ดนี้ · ปุ่มมี data-refresh-table
                // สั่งโหลดใหม่ให้อยู่แล้ว ที่นี่จึงบอกผลอย่างเดียว
            ],
            is_array($result) ? $result : [],
        );
    }

    /**
     * คำสั่งให้หน้าจอแสดงรหัสผ่านที่ระบบสุ่มให้
     *
     * รหัสนี้ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย — ระบบเก็บแค่แฮช · ต้องแสดงเป็นกล่อง
     * ที่ผู้ใช้ต้องกดปิดเอง ไม่ใช่ข้อความแจ้งเตือนที่หายไปเองใน 5 วินาที
     *
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    private function passwordActions(array $result, string $title, string $message = ''): array
    {
        $password = (string) ($result['password'] ?? '');

        // ปิดฟอร์มก่อนเสมอ — แล้วค่อยเปิดหน้าต่างรหัสผ่านทับ ถ้ามีรหัสให้แสดง
        $actions = [['type' => 'modal', 'action' => 'close']];

        /*
         * แถบแจ้งผล — เดิมขาดไปทั้งที่ปิด Modal และโหลดตารางใหม่ครบแล้ว
         *
         * ผลคือกดบันทึกแล้ว Modal หายไปเฉย ๆ แถวใหม่โผล่ในตารางโดยไม่มีอะไรยืนยันว่า
         * "สำเร็จ" — ผู้ดูแลที่ไม่ได้จ้องตารางอยู่จึงไม่รู้ว่าคำสั่งผ่านหรือเงียบหายไป
         * และมักกดซ้ำ · หน้าอื่นในระบบใช้ `saved()` ซึ่งมีแถบนี้ให้อยู่แล้ว
         *
         * ต้องมาก่อนหน้าต่างรหัสผ่าน เพื่อไม่ให้ถูกบังตอนที่มีรหัสให้แสดง
         */
        if ($message !== '') {
            $actions[] = ['type' => 'notification', 'level' => 'success', 'message' => $message];
        }

        if ($password !== '' && ($result['password_generated'] ?? false)) {
            $actions[] = [
                'type' => 'modal',
                'action' => 'show',
                'title' => $this->t($title),
                'titleClass' => 'icon-lock',
                'html' => '<p>' . $this->t('Copy this password before closing — it is not stored anywhere')
                    . '</p><p class="mono selectable">' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>',
            ];
        }

        /*
         * **สั่งโหลดตารางด้วยชนิดที่เฟรมเวิร์กมีตัวรับจริง** — `{type:"event"}` ไม่มี
         * ตัวรับเลย `ResponseHandler` เตือนใน console แล้วทิ้ง · ตารางกล่องจดหมายดึง
         * ข้อมูลเองแล้ว (`data-source`) จึงสั่งให้มันโหลดใหม่ได้ตรง ๆ
         */
        $actions[] = ['type' => 'redirect', 'url' => 'reload', 'target' => 'mailboxes'];

        return $actions;
    }

    /** @return list<array<string,mixed>> */
    private function modal(string $template, string $title, string $icon): array
    {
        return [[
            'type' => 'modal',
            'action' => 'show',
            'template' => $template,
            'title' => $title,
            'titleClass' => $icon,
        ]];
    }

    /**
     * โหลดกล่องพร้อมตรวจว่าผู้เรียกมีสิทธิ์เห็นมันจริง
     *
     * คืน null ทั้งกรณี "ไม่มีอยู่" และ "ไม่ใช่ของคุณ" โดยตั้งใจ — การแยกสองอย่างนี้
     * ออกจากกันบอกคนนอกได้ว่ากล่องไหนมีอยู่จริงบนเครื่อง
     *
     * @return array<string,mixed>|null
     */
    private function mailboxOrNull(int $id): ?array
    {
        foreach ($this->repository()->listMailboxes($this->scopeOwner()) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function repository(): MailboxRepository
    {
        return new MailboxRepository($this->app->db());
    }

    /** 0 = เห็นทั้งเครื่อง (ผู้ดูแล) · ค่าอื่น = เห็นเฉพาะของตัวเอง */
    private function scopeOwner(): int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : 0;
    }
}
