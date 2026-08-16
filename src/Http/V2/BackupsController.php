<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\Resource\BackupResource;
use Phpcp\Kernel\Paths;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Backup files — `/api/v2/backups`
 *
 * **The list comes from the real folder in the customer's home directory, not
 * a table** (PLAN-BACKUP-V2 item B4) · `<home>/backup` is deliberately opened
 * to the customer via SFTP, and they can delete their own files at any time —
 * so a table row recorded at creation time is a lie waiting to happen ·
 * that's why this always asks the agent to read the folder fresh
 * (`backup.list`) instead of a SELECT
 *
 * A file is referenced by **account + filename**, not a row id · so REST's
 * path is `/api/v2/backups/{user}/{file}`, which refers to the real file
 * itself, present or not, unlike a row id pointing at a record that may no
 * longer match reality
 */
final class BackupsController extends HostingController
{
    public function index(Request $request): Response
    {
        $result = $this->agent()->data('backup.list', [
            'user_id' => $request->queryInt('user_id', 0),
        ], $this->ctx->actor($request));

        $files = is_array($result['files'] ?? null) ? $result['files'] : [];

        $type = $request->get('type');
        if ($type !== '') {
            $files = array_values(array_filter($files, static fn (array $f): bool => $f['type'] === $type));
        }

        $domain = $request->get('domain');
        if ($domain !== '') {
            $files = array_values(array_filter($files, static fn (array $f): bool => $f['domain'] === $domain));
        }

        $page = $this->pagination($request);
        $slice = array_slice($files, $page['offset'], $page['per_page']);

        // A button's condition in the table can only read values in the same row
        // — so permission must travel with the row (flat names like
        // can_manage/can_restore/can_offsite, not a nested can.manage, since
        // data-row-actions' condition isn't confirmed to support nested property paths)
        $canManage = $this->ctx->can('backup.manage');
        $canRestore = $this->ctx->can('backup.restore');
        $canOffsite = $this->ctx->can('backup.offsite');
        $sites = $this->domainToSiteId();

        $items = array_map(
            static fn (array $row): array => BackupResource::one($row + [
                'site_id' => $sites[$row['domain']] ?? 0,
            ]) + [
                'can_manage' => $canManage,
                'can_restore' => $canRestore && $row['restorable'],
                'can_offsite' => $canOffsite,
            ],
            $slice,
        );

        return $this->paginate($items, count($files), $page['page'], $page['per_page'])
            ->withHeader('X-Total-Size', (string) array_sum(array_column($files, 'bytes')));
    }

    /**
     * Where backup files are stored — **the user needs to know exactly where their own files really live**
     *
     * The answer now means something directly to a customer, not just an admin:
     * the path returned is the real path they can `cd` into via SFTP and
     * download files from themselves
     *
     * **A separate endpoint of its own, not the list's `meta`** — `meta.*` can't
     * be bound by Now.js's `data-text`/`data-if` (the component only sees the
     * `data` layer), so a label bound to meta never once showed up · this
     * already cost one round of debugging time on the Mailboxes page
     */
    public function storage(Request $request): Response
    {
        $result = $this->agent()->data('backup.list', [
            'user_id' => $request->queryInt('user_id', 0),
        ], $this->ctx->actor($request));

        $files = is_array($result['files'] ?? null) ? $result['files'] : [];
        $owner = $this->scopeOwner();

        // A customer sees only their own path · an admin sees several accounts, so this states the pattern instead
        $path = $owner === null
            ? Paths::usersDir() . '/<account>/backup'
            : (string) ($this->app->db()->value(
                'SELECT COALESCE(system_user, username) FROM users WHERE id = :id',
                ['id' => $owner],
                '',
            ));

        if ($owner !== null) {
            $path = Paths::usersDir() . '/' . $path . '/backup';
        }

        return $this->ok([
            'path' => $path,
            'files' => count($files),
            'bytes' => (int) ($result['bytes'] ?? 0),
            // The files already sit in the customer's own home directory, so
            // they're viewable through a file scope they already have — no
            // special panel scope needed anymore (removed in PLAN-BACKUP-V2 §4.1)
            'scope' => '',
        ]);
    }

    /**
     * The empty shell of the create-backup form, with the command to open its modal
     *
     * A backup can't be edited (only created · restored · deleted), so there's only a form for a new one
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            ['type' => 'site', 'site_id' => 0, 'database' => ''],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'backup-form.html',
                'title' => '{LNG_Create backup}',
                'titleClass' => 'icon-stack',
            ]],
        );
    }

    /**
     * Create a backup file
     *
     * Answers 201, not 202, because the capability genuinely runs
     * synchronously — the file is already usable by the time the response
     * reaches the caller
     */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('backup.create', [
            'type' => $request->payloadString('type'),
            'site_id' => $siteId,
            'database' => trim($request->payloadString('database')),
        ], $this->ctx->actor($request));

        return $this->done(
            (string) ($result['message'] ?? 'Backup created'),
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success',
                 'message' => (string) ($result['message'] ?? 'Backup created')],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'backups'],
            ],
            is_array($result) ? $result : [],
            201,
        );
    }

    /**
     * Restore from a backup file — requires confirming with the domain name
     *
     * The capability always checks the checksum first, reads the manifest
     * confirming this file genuinely belongs to this website, and creates a
     * safety backup of the current state before overwriting anything
     */
    public function restore(Request $request): Response
    {
        $result = $this->agent()->data('backup.restore', [
            'site_id' => (int) $request->payload('site_id', 0),
            'file' => self::fileParam($request),
            'confirm' => trim($request->payloadString('confirm')),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup restored'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * Send a backup file to an offsite destination
     *
     * A sub-resource named `offsite-copy` per §4.1 (a noun, not a verb) — what's
     * being created is "the offsite copy" of that backup file
     */
    public function pushOffsite(Request $request): Response
    {
        $result = $this->agent()->data('backup.push', [
            'user_id' => $request->paramInt('user'),
            'file' => self::fileParam($request),
            'destination_id' => (int) $request->payload('destination_id', 0),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup copied offsite'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    public function destroy(Request $request): Response
    {
        $result = $this->agent()->data('backup.delete', [
            'user_id' => $request->paramInt('user'),
            'file' => self::fileParam($request),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup deleted'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * The filename from the path — always URL-decoded first
     *
     * The router matches against the part of the path that's still
     * **URL-encoded** and doesn't decode it · a filename the system generated
     * itself is already URL-safe, but this folder belongs to the customer, who
     * can rename a file with non-ASCII characters or spaces in it · not
     * decoding means the delete button for those files answers "file not found"
     *
     * A decoded `%2F` becoming `/` is still rejected by
     * `BackupFiles::assertName()` — decoding doesn't open a path to climb out of the folder
     */
    private static function fileParam(Request $request): string
    {
        return rawurldecode($request->param('file'));
    }

    /**
     * Domain → the website id the caller has permission to see
     *
     * The restore button must send `site_id` along, but the list is read from
     * filenames, which only know the domain name · converted once here instead
     * of querying per row
     *
     * @return array<string,int>
     */
    private function domainToSiteId(): array
    {
        $owner = $this->scopeOwner();
        $rows = $this->app->db()->all(
            'SELECT id, primary_domain FROM sites'
            . ($owner === null ? '' : ' WHERE owner_user_id = :owner'),
            $owner === null ? [] : ['owner' => $owner],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['primary_domain']] = (int) $row['id'];
        }

        return $map;
    }
}
