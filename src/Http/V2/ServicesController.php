<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\ServiceRelations;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * บริการของระบบ — `/api/v2/services`
 *
 * รายการ unit ที่จัดการได้เป็น **allowlist ตายตัว** ใน `ServiceCatalog` และบริการของ
 * panel เองถูกกรองออกไปแล้วตั้งแต่ชั้นนั้น (SelfProtection) — ไม่ใช่แค่ซ่อนปุ่มบนหน้าจอ
 * ผู้ใช้จึงยิง API ตรงเพื่อสั่งหยุด phpcp-agentd ไม่ได้ไม่ว่าจะพยายามอย่างไร
 *
 * `POST /services/{unit}/actions` เป็นคำนาม (`actions`) ตาม §4.1 ไม่ใช่ `/restart`
 * — คำสั่งอยู่ใน body ทำให้เพิ่มคำสั่งใหม่ได้โดยไม่ต้องเพิ่มเส้นทาง และ audit log
 * บันทึกทุกคำสั่งด้วยรูปแบบเดียวกัน
 */
final class ServicesController extends ApiController
{
    /** คำสั่งที่ยอมรับ — ตรงกับ capability service.<action> ที่มีอยู่จริงเท่านั้น */
    private const ACTIONS = ['start', 'stop', 'restart', 'reload'];

    public function index(Request $request): Response
    {
        $units = ServiceCatalog::units();
        $data = $this->agent()->data('service.status', ['services' => $units], $this->ctx->actor($request));
        $services = $data['services'] ?? [];

        // ความสัมพันธ์กับเว็บไซต์ — ผู้ดูแลต้องเห็นว่า "หยุดตัวนี้แล้วเว็บไหนดับบ้าง"
        // ก่อนกด ไม่ใช่รู้ตอนที่ลูกค้าโทรมาแจ้ง
        $relations = (new ServiceRelations($this->app->db()))->forUnits($units);

        $canControl = $this->ctx->can('service.control');
        $rows = [];

        foreach ($units as $unit) {
            $service = $services[$unit] ?? null;

            if ($service === null) {
                continue;
            }

            $affects = $relations[$unit] ?? ['items' => []];

            if (isset($affects['label'])) {
                $affects['label'] = $this->t((string) $affects['label']);
            }

            $affects['items'] = array_map(
                fn (array $item): array => ['detail' => $this->t((string) ($item['detail'] ?? ''))] + $item,
                $affects['items'] ?? [],
            );

            $affectedNames = array_column($affects['items'] ?? [], 'name');
            $status = (string) ($service['status'] ?? '');

            $rows[] = $service + [
                'unit' => $unit,
                'kind' => ServiceCatalog::kind($unit),
                'label' => ServiceCatalog::label($unit),
                'critical' => ServiceCatalog::isCritical($unit),
                'affects' => $affects,
                // ข้อความรวมสำเร็จรูป — data-template ของ Now.js ต่อรายการเป็นข้อความ
                // อ่านง่ายให้เองไม่ได้ ทำได้แค่แทน ${key} ตรง ๆ
                //
                // ก่อนหน้านี้คอลัมน์นี้แสดง "—" เสมอไม่ว่าจะมีเว็บไซต์ได้รับผลกระทบจริง
                // กี่แห่ง เพราะ formatList (js/formatters.js เดิม) เช็ค Array.isArray()
                // แต่ affects เป็น object {kind,label,items,total} มาตั้งแต่แรก ไม่ใช่ array
                'affects_label' => $affectedNames === [] ? '—' : implode(', ', $affectedNames),
                // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
                'status_tone' => match ($status) {
                    'running' => 'ok',
                    'failed' => 'danger',
                    'transitioning' => 'warn',
                    default => 'muted',
                },
                'can_manage' => $canControl,
            ];
        }

        return $this->ok($rows, [
            'total' => count($rows),
            'actions' => self::ACTIONS,
        ]);
    }

    /** สั่งงานบริการหนึ่งตัว */
    public function action(Request $request): Response
    {
        $action = $request->payloadString('action');

        // ตรวจที่นี่ด้วยแม้ agent จะตรวจอีกรอบ — คำสั่งที่ไม่รู้จักต้องไม่ถูกส่งออกไปเลย
        // (ถ้าปล่อยไป ชื่อ capability จะถูกประกอบจากค่าที่ผู้ใช้ส่งมา ซึ่งเป็นรูปแบบที่ไม่ควรมี)
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->problem(
                ApiProblem::ValidationError,
                'Invalid command',
                ['action' => 'Allowed: ' . implode(', ', self::ACTIONS)],
            );
        }

        $result = $this->agent()->data(
            'service.' . $action,
            ['service' => $request->param('unit')],
            $this->ctx->actor($request),
        );

        return $this->completed(
            (string) ($result['message'] ?? $this->t('{action} sent to {unit}', ['action' => $action, 'unit' => $request->param('unit')])),
            'services',
            $result + ['unit' => $request->param('unit'), 'action' => $action],
        );
    }
}
