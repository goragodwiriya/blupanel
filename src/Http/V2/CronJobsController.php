<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\CronJobRepository;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\CronJobResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * งานอัตโนมัติต่อเว็บไซต์ — `/api/v2/cron-jobs`
 *
 * ต่างจากทรัพยากรอื่นตรงที่ข้อมูลอยู่ในฐานข้อมูลของ panel แล้วค่อย generate ไฟล์
 * `/etc/cron.d` ตามทีหลัง · ทุก endpoint ที่เปลี่ยนแปลงจึงต้องจบด้วย `cron.sync` เสมอ
 * และ **ถ้า sync ล้มต้องย้อนการแก้ไขในฐานข้อมูลกลับ** ไม่งั้นหน้าจอจะบอกว่างานทำงานอยู่
 * ทั้งที่ cron ไม่รู้จักมันเลย — ตรรกะนั้นอยู่ใน `CronJobRepository::applyThenSync()`
 * ที่หน้าเว็บเดิมกับ API ใช้ร่วมกัน
 */
final class CronJobsController extends HostingController
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): Response
    {
        $jobs = $this->repository()->listWithSite($this->scopeOwner());

        $siteId = (int) $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $jobs = array_values(array_filter($jobs, static fn(array $j): bool => (int) $j['site_id'] === $siteId));
        }

        $page = $this->pagination($request);
        $slice = array_slice($jobs, $page['offset'], $page['per_page']);

        // เงื่อนไขปุ่มเปิด/ปิด/ลบในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        $manage = $this->ctx->can('cron.manage');
        $rows = array_map(
            static fn(array $row): array=> $row + ['can_manage' => $manage],
            CronJobResource::collection($slice),
        );

        return $this->paginate($rows, count($jobs), $page['page'], $page['per_page']);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request): Response
    {
        /*
         * ฟอร์มเดียวทำทั้งสร้างและแก้ไข จึงยิงมาที่ปลายทางเดียวเสมอ แล้วให้ `id`
         * ที่ซ่อนอยู่ในฟอร์มเป็นตัวตัดสิน — แบบเดียวกับที่ระบบอื่นบนเฟรมเวิร์กนี้ทำ
         *
         * ยอมให้ POST ทำหน้าที่แก้ไขด้วยโดยตั้งใจ แลกกับการมีฟอร์มชุดเดียวจริง ๆ
         * `PATCH /cron-jobs/{id}` ยังอยู่เหมือนเดิมสำหรับผู้เรียกที่เป็นเครื่อง
         */
        $id = (int) $request->payload('id', 0);

        if ($id > 0) {
            return $this->update($request->withParams(['id' => $id]));
        }

        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $repository = $this->repository();
        $data = CronJobRepository::validate([
            'name' => $request->payloadString('name'),
            'schedule' => $request->payloadString('schedule'),
            'command' => $request->payloadString('command'),
            'enabled' => $request->payload('enabled', true)
        ]);

        // เขียนไฟล์ cron ไม่สำเร็จ = ลบแถวที่เพิ่งสร้างทิ้ง ไม่ปล่อยให้เหลืองานที่ cron ไม่รู้จัก
        $id = $repository->applyThenSync(
            static fn(): int => $repository->create($siteId, $data),
            fn(): mixed => $this->sync($request, $siteId),
            static function (int $created) use ($repository): void {
                $repository->delete($created);
            },
        );

        return $this->done(
            'Cron job added',
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success', 'message' => 'Cron job added'],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'cronJobs']
            ],
            ['cron_job_id' => $id],
            201,
        )->withHeader('Location', '/api/v2/cron-jobs/'.$id);
    }

    /**
     * งานเดียว
     *
     * เดิมไม่มีเส้นทางนี้ ทั้งที่ `PATCH /cron-jobs/{id}` มีอยู่ — แก้ทรัพยากรที่อ่านตัวเดียว
     * ไม่ได้เป็นความไม่สมมาตรที่ทำให้ฟอร์มแก้ไขโหลดค่าเดิมมาไม่ได้ ต้องไปดึงทั้งรายการ
     * มากรองเอง ซึ่งแปลว่างานของเว็บไซต์อื่นถูกส่งออกไปโดยไม่จำเป็น
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        /*
         * **id = 0 คือ "ของใหม่" ไม่ใช่ 404**
         *
         * ฟอร์มสร้างกับฟอร์มแก้ไขเป็นไฟล์เดียวกัน (FRAMEWORK_GUIDE Pattern 3) ทั้งสอง
         * ทางจึงถามข้อมูลจากที่นี่เหมือนกัน ต่างกันแค่ได้โครงว่างหรือได้ค่าเดิม —
         * ถ้าที่นี่ตอบ 404 ให้ id=0 ฝั่งหน้าเว็บจะต้องมีทางพิเศษสำหรับ "สร้างใหม่"
         * ซึ่งแปลว่ามีสองเส้นทางที่ต้องดูแลให้เหมือนกันตลอดไป
         *
         * ต้องมีสิทธิ์สร้างก่อนถึงจะได้โครงเปล่า — ไม่งั้นคนที่ดูได้อย่างเดียวจะเปิด
         * ฟอร์มขึ้นมากรอกจนเสร็จแล้วค่อยโดนปฏิเสธตอนกดบันทึก
         */
        if ($id === 0) {
            if (!$this->ctx->can('cron.manage')) {
                return $this->problem(ApiProblem::Forbidden, 'You may not create cron jobs');
            }

            return $this->ok(
                CronJobResource::blank() + ['can' => $this->can(['manage' => 'cron.manage'])],
                [],
                $this->formActions('{LNG_Add cron job}'),
            );
        }

        $job = $this->findJob($id);

        if ($job === null) {
            return $this->problem(ApiProblem::NotFound, 'Cron job not found');
        }

        return $this->ok(
            CronJobResource::one($job) + ['can' => $this->can(['manage' => 'cron.manage'])],
            [],
            $this->formActions('{LNG_Edit cron job}'),
        );
    }

    /**
     * คำสั่งให้หน้าเว็บเปิดฟอร์มใน modal
     *
     * เซิร์ฟเวอร์เป็นคนบอกว่าใช้เทมเพลตไหนและหัวข้อว่าอะไร — หน้าเว็บแค่ยิงคำขอแล้ว
     * ส่งคำตอบต่อให้ `ResponseHandler` ทั้งก้อน ไม่ต้องรู้จักชื่อไฟล์เทมเพลตเลย
     *
     * @return list<array<string,mixed>>
     */
    private function formActions(string $title): array
    {
        return [[
            'type' => 'modal',
            'action' => 'show',
            'template' => 'cron-job-form.html',
            'title' => $title,
            'titleClass' => 'icon-clock'
        ]];
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function update(Request $request): Response
    {
        $repository = $this->repository();
        $job = $this->findJob($request->paramInt('id'));

        if ($job === null) {
            return $this->problem(ApiProblem::NotFound, 'Cron job not found');
        }

        // PATCH = แก้เฉพาะที่ส่งมา · ค่าที่ไม่ส่งมาต้องคงเดิม
        $data = CronJobRepository::validate([
            'name' => $request->payload('name', $job['name']),
            'schedule' => $request->payload('schedule', $job['schedule']),
            'command' => $request->payload('command', $job['command']),
            'enabled' => $request->payload('enabled', (int) $job['enabled'] === 1)
        ]);

        $previous = CronJobRepository::restorable($job);
        $id = (int) $job['id'];

        $repository->applyThenSync(
            static function () use ($repository, $id, $data): void {
                $repository->update($id, $data);
            },
            fn(): mixed => $this->sync($request, (int) $job['site_id']),
            static function () use ($repository, $id, $previous): void {
                $repository->update($id, $previous);
            },
        );

        return $this->saved('Cron job saved', 'cronJobs', ['cron_job_id' => $id]);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function destroy(Request $request): Response
    {
        $repository = $this->repository();
        $job = $this->findJob($request->paramInt('id'));

        if ($job === null) {
            return $this->problem(ApiProblem::NotFound, 'Cron job not found');
        }

        $id = (int) $job['id'];

        $repository->applyThenSync(
            static function () use ($repository, $id): void {
                $repository->delete($id);
            },
            fn(): mixed => $this->sync($request, (int) $job['site_id']),
            // ย้อนกลับ = ใส่แถวเดิมคืน (id ใหม่ แต่ค่าเหมือนเดิมทุกฟิลด์)
            static function () use ($repository, $job): void {
                $repository->create((int) $job['site_id'], CronJobRepository::restorable($job));
            },
        );

        return $this->completed('Cron job deleted', 'cronJobs', ['cron_job_id' => $id]);
    }

    private function repository(): CronJobRepository
    {
        return new CronJobRepository($this->app->db());
    }

    /** เขียนไฟล์ cron ใหม่ — โยน AgentException เมื่อล้ม ให้ผู้เรียกย้อนกลับ */
    private function sync(Request $request, int $siteId): mixed
    {
        return $this->agent()->data('cron.sync', ['site_id' => $siteId], $this->ctx->actor($request));
    }

    /**
     * โหลดงานที่ผู้เรียกมีสิทธิ์เห็น
     *
     * @return array<string,mixed>|null
     */
    private function findJob(int $id): ?array
    {
        $job = $this->repository()->find($id);

        if ($job === null || !$this->mayAccessSite((int) $job['site_id'])) {
            return null;
        }

        return $job;
    }
}
