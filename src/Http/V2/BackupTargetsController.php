<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Paths;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Which accounts get automatic backups — `/api/v2/backup-targets` (items B3, B6, B8)
 *
 * ## Why this is a table of "accounts," not "websites"
 *
 * Backup files live in an account's home directory and count against the
 * account's quota · turning it on/off per website would mean an admin has to
 * come back and reconfigure it every time a customer adds a website, exactly
 * the moment it's easiest to forget — and the result of forgetting is a
 * website with no copies at all, with nothing to raise the alarm · turning it
 * on at the account level means a new website under that account is
 * immediately included in the round on its own
 *
 * ## Why two switches, not one
 *
 * A static website has no database, and some accounts already keep their
 * website files in git and want only the database · a single switch would
 * mean both groups pay for space toward something they don't want (item B8)
 *
 * ## Why this writes straight to the database, not through the agent
 *
 * This value never touches the machine at all — no file, no service, no
 * system privilege involved · what it changes is "who the next round picks
 * up," which lives in the panel's own database · the same pattern as
 * `BackupSchedulesController` · the audit entry is still written by hand here
 * too, since this doesn't go through the Dispatcher
 */
final class BackupTargetsController extends ApiController
{
    public function index(Request $request): Response
    {
        /*
         * **Every hosting account, ready or not**
         *
         * This used to filter out `system_user IS NOT NULL`, meaning an
         * account that's never had a website silently disappeared from the
         * table · an admin opening this page looking for that customer
         * wouldn't find their name and would conclude the system was broken —
         * or worse, think backups were already turned on for them
         *
         * An account with no home directory yet still can't be checked (no
         * folder to write to), but it must **be seen, with a reason**, not just vanish
         */
        $rows = $this->app->db()->all(
            "SELECT u.id, u.username, u.system_user, u.backup_files, u.backup_database,
                    u.disk_quota_mb, u.disk_used_mb, u.service_status,
                    (SELECT COUNT(*) FROM sites s WHERE s.owner_user_id = u.id) AS sites,
                    (SELECT COUNT(*) FROM databases_ d
                       JOIN sites s2 ON s2.id = d.site_id
                      WHERE s2.owner_user_id = u.id) AS databases
               FROM users u
              WHERE u.role = :role
              ORDER BY u.username",
            ['role' => Permissions::WEBADMIN],
        );

        $canManage = $this->ctx->can('backup.offsite');

        $items = array_map(
            function (array $row) use ($canManage): array {
                $home = (string) ($row['system_user'] ?? '');
                $ready = $home !== '';

                return [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    // No system account yet = no home directory yet · stating that plainly beats showing a path that doesn't exist
                    'backup_dir' => $ready ? Paths::usersDir() . '/' . $home . '/backup' : '—',
                    'backup_files' => (int) $row['backup_files'] === 1,
                    'backup_database' => (int) $row['backup_database'] === 1,
                    'sites' => (int) $row['sites'],
                    'databases' => (int) $row['databases'],
                    // Remaining quota — backup files count against it too, so an admin must see this before turning it on
                    'disk_quota_mb' => (int) ($row['disk_quota_mb'] ?? -1),
                    'disk_used_mb' => (int) ($row['disk_used_mb'] ?? 0),
                    'ready' => $ready,
                    'reason' => $ready ? '' : $this->t('This account has no home folder yet — create its first website first'),
                    'can_manage' => $canManage && $ready,
                ];
            },
            $rows,
        );

        return $this->ok($items);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->app->db()->first(
            'SELECT id, username, system_user, backup_files, backup_database FROM users WHERE id = :id AND role = :role',
            ['id' => $id, 'role' => Permissions::WEBADMIN],
        );

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Hosting account not found');
        }

        // An account with no home directory yet can't have backups turned on — the round would just fail every night with no way to succeed
        if ((string) ($row['system_user'] ?? '') === '') {
            return $this->problem(
                ApiProblem::ValidationError,
                'This account has no home folder yet — create its first website first',
            );
        }

        $fields = [];

        foreach (['backup_files', 'backup_database'] as $column) {
            if ($request->payload($column) !== null) {
                $fields[$column] = $this->flag($request->payload($column));
            }
        }

        if ($fields === []) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        $this->app->db()->update('users', $fields + ['updated_at' => time()], ['id' => $id]);
        // This resource doesn't go through the Dispatcher, so nothing writes the audit entry automatically
        $this->app->audit()->write($this->ctx->actor($request), 'backup.target_set', (string) $row['username'], 'ok', $fields);

        return $this->saved('Saved', 'backup-targets', [
            'id' => $id,
            'backup_files' => (int) ($fields['backup_files'] ?? $row['backup_files']) === 1,
            'backup_database' => (int) ($fields['backup_database'] ?? $row['backup_database']) === 1,
        ]);
    }

    /**
     * A screen checkbox's value → 0/1
     *
     * Accepts true/1/"1"/"on" all together, because a checkbox not sent
     * through JSON sends "on," and accepting only one shape would mean a form
     * submitted the other way "saves successfully" while changing nothing
     */
    private function flag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }
}
