<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Capability\FirewallRuleDelete;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Firewall — `/api/v2/firewall`
 *
 * **ทุกคำสั่งที่ทำให้เข้าถึงเครื่องได้แคบลงต้องยืนยันภายในเวลา** ไม่งั้นระบบคืนค่าเดิมให้เอง
 * (ARCHITECTURE §5.4) · คำตอบจึงแนบ `pending_rollback` กลับไปเสมอ — ถ้าไม่แนบ
 * ฝั่ง SPA จะไม่รู้ว่าต้องขึ้นตัวนับถอยหลังให้ผู้ใช้กดยืนยัน แล้วผู้ใช้จะเสียกฎที่เพิ่งตั้ง
 * ไปเงียบ ๆ เมื่อครบเวลาโดยไม่เข้าใจว่าทำไม
 *
 * `signature` ของแต่ละกฎถูกส่งไปพร้อมรายการและต้องส่งกลับมาตอนลบ — กันกรณีหมายเลขกฎ
 * เลื่อนเพราะมีคนแก้จากอีกหน้าต่างหนึ่งระหว่างที่หน้านี้เปิดค้างอยู่ แล้วลบผิดกฎ
 */
final class FirewallController extends ApiController
{
    public function index(Request $request): Response
    {
        $status = $this->agent()->data('firewall.status', [], $this->ctx->actor($request));
        $canManage = $this->ctx->can('firewall.manage');

        foreach ($status['rules'] ?? [] as $index => $rule) {
            $status['rules'][$index]['signature'] = FirewallRuleDelete::signature($rule);
            // เงื่อนไขปุ่มลบในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
            $status['rules'][$index]['can_manage'] = $canManage;
        }

        return $this->ok($status, ['pending_rollback' => $this->pendingRollback()]);
    }

    /**
     * โครงเปล่าของฟอร์มเพิ่มกฎ พร้อมคำสั่งเปิด modal
     *
     * กฎของ ufw แก้ไม่ได้ (เพิ่มกับลบเท่านั้น — หมายเลขกฎเลื่อนเมื่อลบตัวก่อนหน้า)
     * จึงมีแต่ฟอร์มของใหม่ · เส้นทางยังเป็นรูปเดียวกับหน้าอื่นเพื่อให้หน้าเว็บทำ
     * สิ่งเดียวกันทุกหน้า: ยิงคำขอแล้วส่งคำตอบต่อให้ ResponseHandler
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            ['action' => 'allow', 'port' => '', 'protocol' => 'tcp', 'source' => ''],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'firewall-rule-form.html',
                'title' => '{LNG_Add rule}',
                'titleClass' => 'icon-fire',
            ]],
        );
    }

    /** เพิ่มกฎ — ต้องยืนยันภายใน `window` วินาที ไม่งั้นถูกถอนคืนอัตโนมัติ */
    public function addRule(Request $request): Response
    {
        $result = $this->agent()->data('firewall.rule_add', [
            'action' => $request->payloadString('action'),
            'port' => $request->payloadString('port'),
            'protocol' => $request->payloadString('protocol'),
            'source' => $request->payloadString('source'),
            'comment' => $request->payloadString('comment'),
            'window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'เพิ่มกฎแล้ว — ต้องกดยืนยันก่อนหมดเวลาคืนค่าอัตโนมัติ'),
            extra: $result + ['pending_rollback' => $this->pendingRollback()],
        )->withHeader('Location', '/api/v2/firewall');
    }

    /**
     * ลบกฎตามหมายเลข
     *
     * `expect` คือลายเซ็นของกฎที่ผู้ใช้เห็นตอนกดปุ่ม — agent เทียบก่อนลบเสมอ
     * เพื่อไม่ให้ลบผิดกฎเมื่อหมายเลขเลื่อน
     */
    public function deleteRule(Request $request): Response
    {
        $this->agent()->data('firewall.rule_delete', [
            'number' => $request->paramInt('number'),
            'expect' => $request->payloadString('expect') ?: $request->get('expect'),
            'window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            'ลบกฎแล้ว — ต้องกดยืนยันก่อนหมดเวลาคืนค่าอัตโนมัติ',
            extra: ['number' => $request->paramInt('number'), 'pending_rollback' => $this->pendingRollback()],
        );
    }

    /**
     * เปิดหรือปิด firewall ทั้งตัว
     *
     * เปิดครั้งแรกคือคำสั่งที่อันตรายที่สุดในหน้านี้ — ถ้ากฎยังไม่ครอบพอร์ต SSH
     * ผู้ดูแลจะหลุดจากเครื่องทันที · จึงต้องยืนยันภายในเวลาเช่นกัน
     * ส่วนการ**ปิด** ไม่ต้องยืนยันเพราะมันทำให้เข้าถึงได้กว้างขึ้น ไม่ใช่แคบลง
     */
    public function setEnabled(Request $request): Response
    {
        $enabled = $request->payload('enabled');
        $wantEnabled = in_array($enabled, [true, 1, '1', 'true'], true);

        $result = $this->agent()->data(
            $wantEnabled ? 'firewall.enable' : 'firewall.disable',
            $wantEnabled ? ['window' => (int) $request->payload('window', RollbackGuard::DEFAULT_WINDOW)] : [],
            $this->ctx->actor($request),
        );

        return $this->refreshed(
            (string) ($result['message'] ?? ($wantEnabled ? 'เปิดไฟร์วอลล์แล้ว' : 'ปิดไฟร์วอลล์แล้ว')),
            extra: $result + ['enabled' => $wantEnabled, 'pending_rollback' => $this->pendingRollback()],
        );
    }

    /**
     * รายการที่รอยืนยันอยู่ตอนนี้ พร้อมเวลาที่เหลือ
     *
     * @return array<string,mixed>|null
     */
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
