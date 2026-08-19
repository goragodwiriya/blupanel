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
 * A backup file's destination — `/api/v2/backup-destinations` (PLAN-V2 phase E1)
 *
 * **A secret travels in only, never back out** · the ssh key sent when creating
 * or editing is encrypted and stored immediately, and no endpoint ever returns
 * it back in any shape · the screen only knows `has_secret` — whether one is
 * already stored — which is enough for display and not enough to steal
 *
 * **Sending an empty secret field = don't change it, not clear it** — the edit
 * screen sends the whole form back with that field always empty · interpreting
 * that as "clear it" would break the destination every single time an admin
 * edits its name
 *
 * This whole resource belongs to the SERVER tier — `backup.manage`, which a webadmin doesn't have
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
     * The destination — **this server can only have one**, so it's always "the one"
     *
     * `id = 0` means "give me this server's destination," not "a blank form for
     * a new one": one already exists → get its values to edit · none yet → get
     * an empty shell to create one
     *
     * **This is why the button on the Backups page can always point at `?id=0`**
     * · before this, `id = 0` always meant "a new one," so that one button led
     * to creating a second set every time, colliding with the "only one allowed"
     * rule `store()` enforces — clicking it just got a 409
     *
     * The add form and the edit form are the same file (`backup-destination.html`),
     * so both paths ask for their data here the same way — the only difference
     * is getting an empty shell versus the existing values
     *
     * This doesn't command a modal to open like other forms do, because there
     * are too many fields to fit comfortably in one (over a dozen, including the
     * secret key's textarea) — FRAMEWORK_GUIDE says to use the page's own screen
     * for a case like this
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            $existing = $this->repository()->all();

            return $this->ok(
                $existing === []
                    ? $this->blank() + ['can_manage' => $this->ctx->can('backup.offsite')]
                    : $this->present($existing[0], $this->ctx->can('backup.offsite')),
            );
        }

        $row = $this->repository()->find($id);

        return $row === null
            ? $this->problem(ApiProblem::NotFound, 'Destination not found')
            : $this->ok($this->present($row, $this->ctx->can('backup.offsite')));
    }

    /**
     * An empty shell carrying the same keys as a real one — the same form can bind to either case
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
     * Fill in ready-computed values for the table — conditional composed
     * strings (host:port:path shaped differently per driver, a retention policy
     * combining two conditions) can't be done in a Now.js template because
     * `data-template` only substitutes `${key}` directly, with no conditional support
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

        // A destination that's silently failing is just as dangerous as having
        // none at all — "never checked yet" is kept clearly distinct from
        // "checked and failed," not just "no last-run time"
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
         * One form does both add and edit, so it always fires at the same
         * endpoint, letting the `id` hidden in the form decide — the screen
         * never has to switch method itself based on state
         */
        $id = (int) $request->payload('id', 0);

        if ($id > 0) {
            return $this->update($request->withParams(['id' => $id]));
        }

        $repository = $this->repository();

        /*
         * **Only one destination is allowed** (PLAN-BACKUP-V2 §4.2)
         *
         * Multiple destinations sound flexible, but it creates questions nobody
         * has answered: which account's files go where · who chooses · a file
         * sent to one destination whose retention policy at another destination
         * deletes it — does that still count as having a copy · the automatic
         * backup round is one single machine-wide process (item B6), so it needs
         * one single answer to "where is the offsite copy"
         *
         * Editing the existing one is always allowed — what's refused is
         * **adding a second one**, not changing the destination
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
                // The form lives on a different page from the table — send back to
                // the list page, not reload a table that isn't on the same page
                ['type' => 'redirect', 'url' => '/app/backups', 'delay' => 800],
                // The destinations table itself already reloads from the target
                // above, but the destination `<select>` in the schedule form /
                // export form (backups.html) binds to this event separately,
                // since it isn't a table with an id for target to refer to
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

        // Sending config partially = merge with the existing values · a screen editing only the port doesn't need to send every field
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

        // Files already sent stay at the destination — we just stop tracking it
        //
        // Never follows through and deletes them, because removing a
        // destination from the list and destroying stored backup files are
        // completely different intentions, and the latter can't be undone
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

        return $this->completed($message, 'destinations', is_array($result) ? $result : []);
    }

    /**
     * Verify the destination genuinely works — writes a real file and reads it back
     *
     * A sub-resource named `verification` per §4.1 · what's being created is "the verification result"
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
     * Read the destination machine's host key to fill into the form
     *
     * Runs from this machine, the one that will genuinely send backup files —
     * so the key returned belongs to the machine it will actually talk to, not
     * whichever machine the admin happens to be sitting at (which might see a
     * different IP behind NAT, or with separate internal/external DNS)
     *
     * **Returns plain data, no `actions`** — the screen uses `readHostKey` in
     * `js/ui.js`, which fills the field in itself · sending `actions` would be
     * useless since this route isn't called through `ResponseHandler` (see the
     * comment at `readHostKey`)
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
     * A driver's non-secret settings
     *
     * Accepted as flat top-level fields, not a nested `config` blob — the
     * screen's form already sends flat fields, and forcing it to wrap them into
     * an object would mean writing extra JS for no benefit
     *
     * @return array<string,mixed>
     */
    private function configFrom(Request $request): array
    {
        $config = [];

        // `known_hosts` stores the **content** of ssh-keyscan, not a file path —
        // it's public data (the host's public key), so it belongs in config, not an encrypted secret field
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
     * Check that every field this driver requires is present
     *
     * Checked here instead of leaving the driver's constructor to throw, so the
     * user gets a 422 with **the exact missing field names**, not a bundled
     * message they have to guess the field from
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
            $missing['secret'] = 'A private key to reach the destination machine is required';
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
