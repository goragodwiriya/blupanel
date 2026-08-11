<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * แดชบอร์ด — `GET /api/v2/dashboard` (PLAN-V2 §4.6 แถว "Dashboard")
 *
 * รวมทุกอย่างที่หน้าแรกต้องใช้ไว้ในคำขอเดียว: ตัวเลขทรัพยากรของเครื่อง สถานะบริการ
 * จำนวนทรัพยากรที่มี และกิจกรรมล่าสุด · เหตุผลที่ไม่ให้ SPA ยิงสี่คำขอเองคือหน้าแรก
 * คือหน้าที่ถูกเปิดบ่อยที่สุด และแต่ละคำขอต้องเดิน middleware เจ็ดตัวใหม่ทั้งชุด
 *
 * **agent ล่มแล้วหน้านี้ต้องยังขึ้นได้** — ตัวเลขจากฐานข้อมูลของ panel เองยังถูกต้อง
 * และ audit log ก็ยังอ่านได้ · ถ้าปล่อยให้ `AgentException` ลอยขึ้นไปเป็น 503 ผู้ดูแล
 * จะเห็นหน้าเปล่าในจังหวะที่ต้องการรู้ที่สุดว่าเกิดอะไรขึ้น จึงกลืนไว้แล้วบอกผ่าน
 * `agent.available` แทน (นี่คือ **ที่เดียว** ในทั้ง v2 ที่ทำแบบนี้ — เส้นทางที่สั่งงาน
 * ทุกเส้นยังต้องล้มเสียงดังเหมือนเดิม)
 */
final class DashboardController extends HostingController
{
    public function index(Request $request): Response
    {
        $actor = $this->ctx->actor($request);
        $agentError = '';
        $metrics = [];
        $services = [];

        try {
            $metrics = $this->agent()->data('system.metrics', [], $actor);

            // ลูกค้าไม่มีสิทธิ์ดูบริการของเครื่อง — หน้าเดียวกันจึงแสดงต่างกันตามบทบาท
            if ($this->ctx->can('service.view')) {
                $result = $this->agent()->data(
                    'service.status',
                    ['services' => ServiceCatalog::dashboardUnits()],
                    $actor,
                );
                $services = $result['services'] ?? [];
            }
        } catch (AgentException $e) {
            $agentError = $e->getMessage();
        }

        // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
        $services = array_map(static function (array $service): array {
            $service['status_tone'] = match ($service['status'] ?? '') {
                'running' => 'ok',
                'failed' => 'danger',
                'transitioning' => 'warn',
                default => 'muted',
            };

            return $service;
        }, $services);

        return $this->ok([
            'agent' => [
                'available' => $agentError === '',
                'error' => $agentError,
            ],
            'metrics' => $metrics,
            'services' => array_values($services),
            'counts' => $this->counts(),
            // ลูกค้าไม่มีสิทธิ์ audit.view — ส่งรายการว่างแทนการซ่อนคีย์ เพื่อให้หน้าจอ
            // ไม่ต้องแยกกรณี "ไม่มีคีย์" กับ "ไม่มีรายการ" ซึ่งเป็นบ่อเกิดของ undefined
            'activity' => $this->ctx->can('audit.view') ? $this->activity() : [],
        ]);
    }

    /**
     * กิจกรรมล่าสุดที่ตัดให้เหลือเฉพาะฟิลด์ที่หน้าจอใช้
     *
     * ตั้งใจไม่ส่งทั้งแถว: `prev_hash`/`hash` เป็นกลไกภายในของ hash-chain และ
     * `detail_json` เก็บ argument ดิบของทุกคำสั่ง (ผ่าน `Dispatcher::redact()` มาแล้ว
     * แต่ก็ยังเป็นข้อมูลที่ไม่มีเหตุผลต้องส่งไปแสดงบนหน้าแรก)
     *
     * @return list<array<string,mixed>>
     */
    private function activity(): array
    {
        return array_map(
            static function (array $row): array {
                $result = (string) $row['result'];

                return [
                    'ts' => (int) $row['ts'],
                    'actor_name' => (string) $row['actor_name'],
                    'action' => (string) $row['action'],
                    'target' => (string) $row['target'],
                    'result' => $result,
                    // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${result_tone}` ได้ตรง ๆ
                    'result_tone' => match ($result) {
                        'ok' => 'ok',
                        'denied', 'error' => 'danger',
                        default => 'muted',
                    },
                ];
            },
            $this->app->audit()->recent(8),
        );
    }

    /**
     * จำนวนทรัพยากรที่ผู้เรียกเห็นได้จริง
     *
     * ลูกค้าเห็นเฉพาะของตัวเอง — นับที่ระดับ query เหมือนทุกที่ ไม่ใช่ดึงมาทั้งหมด
     * แล้วค่อยกรอง เพราะตัวเลขรวมของทั้งเครื่องก็เป็นข้อมูลที่ไม่ควรรั่วเช่นกัน
     *
     * @return array<string,int>
     */
    private function counts(): array
    {
        $db = $this->app->db();
        $owner = $this->scopeOwner();

        if ($owner === null) {
            return [
                'sites' => (int) $db->value('SELECT count(*) FROM sites', [], 0),
                'domains' => (int) $db->value('SELECT count(*) FROM domains', [], 0),
                'databases' => (int) $db->value('SELECT count(*) FROM databases_', [], 0),
                'certificates' => (int) $db->value('SELECT count(*) FROM certificates', [], 0),
                'php_versions' => (int) $db->value('SELECT count(DISTINCT php_version) FROM sites', [], 0),
                'backups' => (int) $db->value('SELECT count(*) FROM backups', [], 0),
                'users' => (int) $db->value('SELECT count(*) FROM users', [], 0),
            ];
        }

        $scoped = ' WHERE site_id IN (SELECT id FROM sites WHERE owner_user_id = :o)';

        // `certificates` ไม่มีคอลัมน์ site_id — มันผูกกับ **ชื่อโดเมน** ไม่ใช่กับเว็บไซต์
        // (ดู migration 0001) จึงต้องไล่ผ่านตาราง domains ไม่ใช่กรองด้วย site_id ตรง ๆ
        $certScope = ' WHERE domain IN (SELECT domain FROM domains'.$scoped.')';

        return [
            'sites' => (int) $db->value('SELECT count(*) FROM sites WHERE owner_user_id = :o', ['o' => $owner], 0),
            'domains' => (int) $db->value('SELECT count(*) FROM domains'.$scoped, ['o' => $owner], 0),
            'databases' => (int) $db->value('SELECT count(*) FROM databases_'.$scoped, ['o' => $owner], 0),
            'certificates' => (int) $db->value('SELECT count(*) FROM certificates'.$certScope, ['o' => $owner], 0),
            'php_versions' => (int) $db->value(
                'SELECT count(DISTINCT php_version) FROM sites WHERE owner_user_id = :o',
                ['o' => $owner],
                0,
            ),
            'backups' => (int) $db->value('SELECT count(*) FROM backups'.$scoped, ['o' => $owner], 0),
            'users' => 0,
        ];
    }
}
