<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\CronJobRepository;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\CronJobResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Per-website scheduled jobs — `/api/v2/cron-jobs`
 *
 * Different from other resources in that the data lives in the panel's own
 * database first, and the `/etc/cron.d` file is generated from it afterward ·
 * every endpoint that changes anything must therefore always finish with
 * `cron.sync`, and **if the sync fails, the database edit must be rolled
 * back** — otherwise the screen would say a job is running when cron doesn't
 * know about it at all — that logic lives in `CronJobRepository::applyThenSync()`,
 * shared between the old web page and the API
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

        // The enable/disable/delete buttons' conditions in the table can only read values in the same row — so permission must travel with the row
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
         * One form does both create and edit, so it always fires at the same
         * endpoint, letting the hidden `id` in the form decide — the same way
         * other systems on this framework do it
         *
         * Deliberately lets POST also act as an edit, trading that for
         * genuinely having just one form · `PATCH /cron-jobs/{id}` still exists
         * unchanged for a machine caller
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

        // Failing to write the cron file = delete the row just created, never leave behind a job cron doesn't know about
        $id = $repository->applyThenSync(
            static fn(): int => $repository->create($siteId, $data),
            fn(): mixed => $this->sync($request, $siteId),
            static function (int $created) use ($repository): void {
                $repository->delete($created);
            },
        );

        return $this->saved('Cron job added', 'cronJobs', ['cron_job_id' => $id], 201)
            ->withHeader('Location', '/api/v2/cron-jobs/'.$id);
    }

    /**
     * A single job
     *
     * This route didn't exist before, even though `PATCH /cron-jobs/{id}` did —
     * editing a resource with no way to read that one resource was an
     * asymmetry that left the edit form unable to load existing values, having
     * to fetch the whole list and filter it itself, meaning other websites'
     * jobs were sent out unnecessarily
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        /*
         * **id = 0 means "a new one," not a 404**
         *
         * The create form and edit form are the same file (FRAMEWORK_GUIDE
         * Pattern 3), so both paths ask for their data here the same way — the
         * only difference is getting an empty shell versus existing values —
         * if this answered 404 for id=0, the frontend would need a special path
         * for "create new," meaning two routes that must forever be kept in sync
         *
         * Must have create permission before even getting the empty shell —
         * otherwise a view-only user would open the form, fill it all in, and
         * only then get rejected when clicking save
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
     * The command telling the page to open the form in a modal
     *
     * The server decides which template to use and what the title says — the
     * page just fires the request and hands the whole response to
     * `ResponseHandler`, never needing to know the template's filename at all
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

        // PATCH = edit only what's sent · a value not sent must stay unchanged
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
            // Rolling back = insert the same row again (a new id, but every field's value the same as before)
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

    /** Rewrite the cron file — throws AgentException on failure, for the caller to roll back */
    private function sync(Request $request, int $siteId): mixed
    {
        return $this->agent()->data('cron.sync', ['site_id' => $siteId], $this->ctx->actor($request));
    }

    /**
     * Load a job the caller has permission to see
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
