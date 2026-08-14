<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Secret;

/**
 * ปลายทางของไฟล์สำรอง — `/api/v2/backup-destinations` (PLAN-V2 เฟส E1)
 *
 * **ความลับเดินทางเข้าอย่างเดียว ไม่เคยเดินออก** · กุญแจ ssh ที่ส่งมาตอนสร้างหรือแก้ไข
 * ถูกเข้ารหัสเก็บทันที และไม่มี endpoint ไหนคืนมันกลับมาไม่ว่ารูปแบบใด · หน้าจอรู้แค่
 * `has_secret` ว่ามีเก็บไว้แล้วหรือยัง ซึ่งพอสำหรับการแสดงผลและไม่พอสำหรับการขโมย
 *
 * **ส่งฟิลด์กุญแจว่างมา = ไม่เปลี่ยน ไม่ใช่ล้างทิ้ง** — หน้าจอแก้ไขส่งฟอร์มทั้งชุด
 * กลับมาโดยที่ช่องนั้นว่างเสมอ ถ้าตีความว่าล้าง ผู้ดูแลจะทำปลายทางพังทุกครั้งที่แก้ชื่อ
 *
 * ทั้งทรัพยากรนี้เป็นของชั้น SERVER — `backup.manage` ซึ่ง webadmin ไม่มี
 */
final class BackupDestinationsController extends ApiController
{
    public function index(Request $request): Response
    {
        $canManage = $this->ctx->can('backup.offsite');

        return $this->ok(
            array_map(fn (array $row): array => $this->present($row, $canManage), $this->repository()->all()),
            [
                'drivers' => BackupDestinationRepository::DRIVERS,
                'required_fields' => DestinationFactory::requiredFields(),
            ],
        );
    }

    /**
     * ปลายทางหนึ่งที่ — **id = 0 คือ "ของใหม่" ไม่ใช่ 404**
     *
     * ฟอร์มเพิ่มกับฟอร์มแก้ไขเป็นไฟล์เดียวกัน (`backup-destination.html`) ทั้งสองทาง
     * จึงถามข้อมูลจากที่นี่เหมือนกัน ต่างกันแค่ได้โครงว่างหรือได้ค่าเดิม
     *
     * ที่นี่ไม่สั่งเปิด modal เหมือนฟอร์มอื่น เพราะช่องกรอกเยอะเกินกว่าจะอยู่ใน modal
     * ได้อย่างสบายตา (สิบกว่าช่อง รวม textarea ของกุญแจลับ) — FRAMEWORK_GUIDE ให้
     * ใช้หน้าเว็บของตัวเองในกรณีแบบนี้
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            return $this->ok($this->blank() + ['can_manage' => $this->ctx->can('backup.offsite')]);
        }

        $row = $this->repository()->find($id);

        return $row === null
            ? $this->problem(ApiProblem::NotFound, 'Destination not found')
            : $this->ok($this->present($row, $this->ctx->can('backup.offsite')));
    }

    /**
     * โครงเปล่าที่มีคีย์ครบเท่ากับของจริง — ฟอร์มเดียวกันผูกค่าได้ทั้งสองกรณี
     *
     * @return array<string,mixed>
     */
    private function blank(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'driver' => 'local',
            'enabled' => 1,
            'retention_days' => 30,
            'retention_count' => 7,
            'config' => [
                'host' => '',
                'port' => 22,
                'user' => '',
                'path' => '',
                'bucket' => '',
                'region' => '',
                'access_key' => '',
                'endpoint' => '',
                'path_style' => 0,
            ],
        ];
    }

    /**
     * เติมค่าที่คำนวณสำเร็จรูปให้ตาราง — string ประกอบที่มีเงื่อนไข (host:port:path
     * ต่างรูปกันตาม driver, นโยบายเก็บย้อนหลังสองเงื่อนไขร่วมกัน) ทำในเทมเพลตของ
     * Now.js ไม่ได้เพราะ `data-template` ทดแทนได้แค่ `${key}` ตรง ๆ ไม่รองรับเงื่อนไข
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, bool $canManage): array
    {
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        $driver = (string) ($row['driver'] ?? '');

        $row['target_display'] = match (true) {
            $driver === 'local' => (string) ($config['path'] ?? '') ?: '—',
            $driver === 's3' => sprintf(
                's3://%s/%s',
                (string) ($config['bucket'] ?? '?'),
                trim((string) ($config['path'] ?? ''), '/'),
            ),
            default => sprintf(
                '%s@%s%s:%s',
                (string) ($config['user'] ?? '?'),
                (string) ($config['host'] ?? '?'),
                (int) ($config['port'] ?? 0) > 0 && (int) $config['port'] !== 22 ? ':' . (int) $config['port'] : '',
                (string) ($config['path'] ?? '?'),
            ),
        };

        $days = (int) ($row['retention_days'] ?? 0);
        $count = (int) ($row['retention_count'] ?? 0);
        $parts = [];
        if ($days > 0) {
            $parts[] = $this->t('{days} days', ['days' => $days]);
        }
        if ($count > 0) {
            $parts[] = $this->t('Keep at least {count} copies', ['count' => $count]);
        }
        $row['retention_label'] = $parts === [] ? $this->t('Unlimited') : implode(' · ', $parts);

        // ปลายทางที่ล้มเงียบอันตรายพอ ๆ กับไม่มีปลายทางเลย — แยก "ยังไม่เคยตรวจ"
        // ออกจาก "ตรวจแล้วล้ม" ให้ชัดเจน ไม่ใช่แค่ "ไม่มีเวลาล่าสุด"
        $row['health_status'] = match (true) {
            !empty($row['last_error']) => 'failed',
            empty($row['last_ok_at']) => 'never',
            default => 'ok',
        };
        $row['health_tone'] = match ($row['health_status']) {
            'ok' => 'ok',
            'failed' => 'danger',
            default => 'muted',
        };

        $row['can_manage'] = $canManage;

        return $row;
    }

    public function store(Request $request): Response
    {
        /*
         * ฟอร์มเดียวทำทั้งเพิ่มและแก้ไข จึงยิงมาที่ปลายทางเดียวเสมอ แล้วให้ `id`
         * ที่ซ่อนอยู่ในฟอร์มเป็นตัวตัดสิน — หน้าเว็บไม่ต้องสลับ method เองตามสถานะ
         */
        $id = (int) $request->payload('id', 0);

        if ($id > 0) {
            return $this->update($request->withParams(['id' => $id]));
        }

        $repository = $this->repository();

        /*
         * **ปลายทางมีได้ชุดเดียว** (PLAN-BACKUP-V2 §4.2)
         *
         * ปลายทางหลายชุดฟังดูยืดหยุ่น แต่มันสร้างคำถามที่ไม่มีใครตอบไว้: ไฟล์ของบัญชี
         * ไหนไปที่ไหน · ใครเป็นคนเลือก · ไฟล์ที่ส่งไปที่หนึ่งแล้วนโยบายเก็บของอีกที่หนึ่ง
         * ลบทิ้ง นับว่ามีสำเนาอยู่ไหม · รอบสำรองอัตโนมัติเป็นกระบวนการเดียวทั้งเครื่อง
         * (ข้อ B6) จึงต้องมีคำตอบเดียวว่า "สำเนานอกเครื่องอยู่ที่ไหน"
         *
         * แก้ของเดิมได้เสมอ — ที่ปฏิเสธคือการ**เพิ่มชุดที่สอง** ไม่ใช่การเปลี่ยนปลายทาง
         */
        $existing = $repository->all();

        if ($existing !== []) {
            return $this->problem(
                ApiProblem::Conflict,
                'This server already has an offsite destination — edit it instead of adding a second one',
            );
        }

        $driver = $request->payloadString('driver');

        $error = $this->validateDriver($repository, $driver, $this->configFrom($request), $request->payloadString('secret'));

        if ($error !== null) {
            return $error;
        }

        $id = $repository->create(
            $request->payloadString('name'),
            $driver,
            $this->configFrom($request),
            $request->payloadString('secret'),
            (int) $request->payload('retention_days', 30),
            (int) $request->payload('retention_count', 7),
        );

        return $this->done(
            'Destination added',
            [
                ['type' => 'notification', 'level' => 'success', 'message' => 'Destination added'],
                // ฟอร์มอยู่คนละหน้ากับตารางแล้ว — พากลับไปหน้ารายการ ไม่ใช่รีโหลด
                // ตารางที่ไม่ได้อยู่ในหน้าเดียวกัน
                ['type' => 'redirect', 'url' => '/app/backups', 'delay' => 800],
                // ตารางปลายทางเองรีโหลดจาก target ด้านบนแล้ว แต่ <select> ตัวเลือกปลายทาง
                // ในฟอร์มตั้งเวลา/ฟอร์มส่งออก (backups.html) ผูกกับเหตุการณ์นี้ต่างหาก
                // เพราะไม่ใช่ตารางที่มี id ให้ target อ้างถึง
            ],
            ['destination_id' => $id],
            201,
        )->withHeader('Location', '/api/v2/backup-destinations/' . $id);
    }

    public function update(Request $request): Response
    {
        $repository = $this->repository();
        $id = $request->paramInt('id');
        $row = $repository->find($id);

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Destination not found');
        }

        $changes = [];

        foreach (['name' => 'string', 'retention_days' => 'int', 'retention_count' => 'int', 'enabled' => 'int'] as $key => $type) {
            if ($request->payload($key) !== null) {
                $changes[$key] = $type === 'int' ? (int) $request->payload($key) : $request->payloadString($key);
            }
        }

        // ส่ง config มาบางส่วน = ผสมกับของเดิม · หน้าจอที่แก้แค่พอร์ตไม่ต้องส่งทุกฟิลด์
        if ($request->payload('config') !== null || $request->payload('host') !== null || $request->payload('path') !== null) {
            $changes['config'] = array_merge($row['config'], $this->configFrom($request));
        }

        if ($request->payloadString('secret') !== '') {
            $changes['secret'] = $request->payloadString('secret');
        }

        if ($changes === []) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        $repository->update($id, $changes);

        $result = $repository->find($id) ?? [];

        return $this->done(
            'Destination saved',
            [
                ['type' => 'notification', 'level' => 'success', 'message' => 'Destination saved'],
                ['type' => 'redirect', 'url' => '/app/backups', 'delay' => 800],
            ],
            is_array($result) ? $result : [],
        );
    }

    public function destroy(Request $request): Response
    {
        $repository = $this->repository();
        $id = $request->paramInt('id');

        if ($repository->find($id) === null) {
            return $this->problem(ApiProblem::NotFound, 'Destination not found');
        }

        // ไฟล์ที่ส่งไปแล้วยังอยู่ที่ปลายทาง — เราแค่เลิกรู้จักมัน
        //
        // ไม่ตามไปลบให้ เพราะการลบปลายทางออกจากรายการกับการทำลายไฟล์สำรองที่เก็บไว้
        // เป็นคนละเจตนากันสิ้นเชิง และอย่างหลังย้อนคืนไม่ได้
        $orphans = (int) $this->app->db()->value(
            'SELECT count(*) FROM backups WHERE destination_id = :id AND offsite_status = :ok',
            ['id' => $id, 'ok' => 'ok'],
        );

        $repository->delete($id);

        $result = [
            'deleted' => true,
            'remote_files_left' => $orphans,
            'message' => $orphans > 0
                ? $this->t('Destination removed — the {count} files already copied there are left alone, delete them yourself if you do not want them', ['count' => $orphans])
                : 'Destination removed',
        ];

        $message = (string) ($result['message'] ?? 'Destination removed');

        return $this->done(
            $message,
            [
                ['type' => 'notification', 'level' => 'success', 'message' => $message],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'destinations'],
            ],
            is_array($result) ? $result : [],
        );
    }

    /**
     * ทดสอบว่าปลายทางใช้งานได้จริง — เขียนไฟล์จริงแล้วอ่านกลับ
     *
     * เป็น sub-resource ชื่อ `verification` ตาม §4.1 · สิ่งที่ถูกสร้างคือ "ผลการยืนยัน"
     */
    public function verify(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($this->repository()->find($id) === null) {
            return $this->problem(ApiProblem::NotFound, 'Destination not found');
        }

        $result = $this->agent()->data('backup.destination_test', [
            'destination_id' => $id,
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'The destination works'), 'destinations', is_array($result) ? $result : []);
    }

    /**
     * อ่าน host key ของเครื่องปลายทางมาเติมให้ในฟอร์ม
     *
     * รันจากเครื่องนี้ ซึ่งเป็นเครื่องที่จะส่งไฟล์สำรองจริง — กุญแจที่ได้จึงเป็นของ
     * เครื่องที่มันจะคุยด้วยจริง ๆ ไม่ใช่ของเครื่องที่ผู้ดูแลบังเอิญนั่งอยู่ (ซึ่งอาจ
     * มองเห็นคนละ IP เมื่อมี NAT หรือ DNS แยกวงใน/วงนอก)
     *
     * **คืนข้อมูลล้วน ไม่มี `actions`** — ฝั่งหน้าจอใช้ `readHostKey` ใน `js/ui.js`
     * ซึ่งเอาค่าไปเติมช่องเอง · ส่ง `actions` มาก็เปล่าประโยชน์เพราะเส้นทางนี้ไม่ได้
     * ถูกเรียกผ่าน `ResponseHandler` (ดูคอมเมนต์ที่ `readHostKey`)
     */
    public function hostKey(Request $request): Response
    {
        $result = $this->agent()->data('backup.host_key_scan', [
            'host' => trim($request->payloadString('host')),
            'port' => (int) $request->payload('port', 22),
        ], $this->ctx->actor($request));

        return $this->done((string) ($result['message'] ?? 'Host key read'), [], is_array($result) ? $result : []);
    }

    /**
     * ค่าตั้งที่ไม่ใช่ความลับของแต่ละ driver
     *
     * รับเป็นฟิลด์แบน ๆ ระดับบนสุด ไม่ใช่ก้อน `config` ซ้อน — ฟอร์มฝั่งหน้าจอส่ง
     * ฟิลด์แบนอยู่แล้ว และการบังคับให้ห่อเป็น object ทำให้ต้องเขียน JS เพิ่มโดยไม่ได้อะไร
     *
     * @return array<string,mixed>
     */
    private function configFrom(Request $request): array
    {
        $config = [];

        // `known_hosts` เก็บ **เนื้อหา** ของ ssh-keyscan ไม่ใช่เส้นทางไฟล์ — เป็นข้อมูล
        // สาธารณะ (กุญแจสาธารณะของโฮสต์) จึงอยู่ใน config ไม่ใช่ช่องความลับที่เข้ารหัส
        foreach (['host', 'user', 'path', 'known_hosts', 'bucket', 'region', 'endpoint', 'access_key'] as $key) {
            $value = trim($request->payloadString($key));

            if ($value !== '') {
                $config[$key] = $value;
            }
        }

        $port = (int) $request->payload('port', 0);

        if ($port > 0) {
            $config['port'] = $port;
        }

        if ($request->payload('path_style') !== null) {
            $config['path_style'] = (bool) $request->payload('path_style');
        }

        return $config;
    }

    /**
     * ตรวจว่าฟิลด์ที่ driver นั้นต้องการมาครบ
     *
     * ตรวจที่นี่แทนที่จะปล่อยให้ constructor ของ driver โยน เพื่อให้ผู้ใช้ได้ 422 พร้อม
     * **ชื่อฟิลด์ที่ขาด** ไม่ใช่ข้อความรวม ๆ ที่ต้องเดาเองว่าช่องไหน
     *
     * @param array<string,mixed> $config
     */
    private function validateDriver(BackupDestinationRepository $repository, string $driver, array $config, string $secret): ?Response
    {
        $repository->assertDriver($driver);

        $missing = [];

        foreach (DestinationFactory::requiredFields()[$driver] ?? [] as $field) {
            if (($config[$field] ?? '') === '') {
                $missing[$field] = 'Required';
            }
        }

        if (DestinationFactory::needsSecret($driver) && $secret === '') {
            $missing['secret'] = 'ต้องใส่กุญแจส่วนตัวสำหรับเข้าเครื่องปลายทาง';
        }

        return $missing === []
            ? null
            : $this->problem(ApiProblem::ValidationError, 'The destination details are incomplete', $missing);
    }

    private function repository(): BackupDestinationRepository
    {
        return new BackupDestinationRepository($this->app->db(), new Secret($this->app->config->secretKey()));
    }
}
