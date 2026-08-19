<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\DbAccountRepository;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * MariaDB databases — `/api/v2/databases`
 *
 * The identifier is the **database name**, not a numeric id, because the name is
 * what's genuinely unique on MariaDB and what an admin refers to it by
 * everywhere (in the mysql client, in a website's config files) — using the
 * panel table's id would make the API refer to something MariaDB doesn't know
 *
 * Scoping by owner already happens in the `db.list` capability (agent side) — a
 * webadmin gets back only their own website's databases, so this layer doesn't
 * need to filter again
 */
final class DatabasesController extends ApiController
{
    public function index(Request $request): Response
    {
        $data = $this->agent()->data('db.list', [], $this->ctx->actor($request));
        $all = $data['databases'] ?? [];

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $all = self::filter($all, $query);
        }

        // A direct field-name filter per §4.5 — the website detail page uses this
        // so it doesn't have to fetch every other customer's databases and filter them out client-side
        $siteId = $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $all = array_values(array_filter(
                $all,
                static fn (array $db): bool => (int) ($db['site_id'] ?? 0) === $siteId,
            ));
        }

        $page = $this->pagination($request);
        $total = count($all);
        $slice = array_slice($all, $page['offset'], $page['per_page']);

        // The delete button's condition in the table can only read values in the same row — so permission must travel with the row
        $manage = $this->ctx->can('db.manage');
        $slice = array_map(
            function (array $db) use ($manage): array {
                /*
                 * **A row the panel knows about but the machine doesn't have must be
                 * visible right in the table**
                 *
                 * The two sides can genuinely drift apart (deleted straight from the
                 * mysql client, or an older panel.db restored back) · before this,
                 * nothing said so at all — the user would only find out after
                 * clicking backup and getting a raw mysqldump error, or the nightly
                 * automatic round failing · `db.list` already knows this answer, it
                 * was just never sent out
                 */
                $exists = ($db['exists_on_server'] ?? true) === true;

                return $db + [
                    'can_manage' => $manage,
                    'status' => $exists ? 'ok' : 'missing',
                    'status_label' => $exists ? $this->t('OK') : $this->t('Missing on server'),
                    // The pill's color comes from the server, so the template can write `pill-${status_tone}` directly
                    'status_tone' => $exists ? 'ok' : 'danger',
                ];
            },
            $slice,
        );

        return $this->paginate(
            array_values($slice),
            $total,
            $page['page'],
            $page['per_page'],
        )->withHeader('X-Total-Size', (string) array_sum(array_map(
            static fn (array $db): int => (int) ($db['size'] ?? 0),
            $all,
        )));
    }

    /**
     * The empty shell of the create-database form, with the command to open its modal
     *
     * A database can't be edited (only created or deleted), so there's no `show`
     * the way cron-jobs has one — but it still uses the same route so every page
     * does the same thing: fire the request and hand the response to
     * ResponseHandler, with no need to know what the template is called
     */
    public function form(Request $request): Response
    {
        $owner = $this->scopeOwner();

        /*
         * The account and website lists travel in `data`, **not `meta`**
         *
         * `meta.*` can't be bound by Now.js's `data-for`/`data-text` — the
         * component only sees the `data` layer · a list placed in meta becomes an
         * empty `<select>` with no error shown at all (already cost one round of
         * debugging time on the Mailboxes page)
         */
        return $this->ok(
            [
                'name' => '',
                'privileges' => 'readwrite',
                'owner_user_id' => $owner ?? 0,
                'site_id' => 0,
                'accounts' => $this->selectableAccounts($owner),
                'sites' => $this->selectableSites($owner),
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'database-form.html',
                'title' => '{LNG_Add database}',
                'titleClass' => 'icon-database',
            ]],
        );
    }

    /** null = an admin who sees the whole machine · anything else = a customer who sees only their own */
    private function scopeOwner(): ?int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : null;
    }

    /**
     * The hosting accounts the caller may pick as a database's owner
     *
     * **A customer can only pick themselves**, so there's just the one entry ·
     * an admin sees every account that genuinely has a home directory (an
     * account with no system account yet has no prefix to use, and nowhere to
     * store anything yet)
     *
     * The displayed name carries its prefix, because that's what the user needs
     * to see before typing a database name — otherwise they'd type `shop` and be
     * surprised to get `alice_shop`
     *
     * @return list<array<string,mixed>>
     */
    private function selectableAccounts(?int $owner): array
    {
        $sql = 'SELECT id, username, COALESCE(system_user, username) AS system_user
                  FROM users
                 WHERE role = :role AND system_user IS NOT NULL';
        $params = ['role' => Permissions::WEBADMIN];

        if ($owner !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $owner;
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'prefix' => DbAccountRepository::prefixFor((string) $row['system_user']),
                'label' => sprintf('%s (%s…)', $row['username'], DbAccountRepository::prefixFor((string) $row['system_user'])),
            ],
            $this->app->db()->all($sql . ' ORDER BY username', $params),
        );
    }

    /**
     * The websites this database may be linked to · the first entry is "not linked to a website"
     *
     * The "not linked" choice comes from the server, not a static `<option>` in
     * the template, because `data-for` overwrites every child of the `<select>` —
     * an option written in the template disappears the moment data arrives
     *
     * **The website link has a real effect, not just a label** — the automatic
     * backup round finds a website's databases from this value, so a database
     * linked to no website at all doesn't get backed up
     *
     * @return list<array<string,mixed>>
     */
    private function selectableSites(?int $owner): array
    {
        $sql = 'SELECT id, primary_domain, owner_user_id FROM sites';
        $params = [];

        if ($owner !== null) {
            $sql .= ' WHERE owner_user_id = :owner';
            $params['owner'] = $owner;
        }

        $sites = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'label' => (string) $row['primary_domain'],
            ],
            $this->app->db()->all($sql . ' ORDER BY primary_domain', $params),
        );

        return array_merge(
            [['id' => 0, 'owner_user_id' => 0, 'label' => $this->t('Not linked to a website')]],
            $sites,
        );
    }

    /**
     * Create a database with its user
     *
     * The generated password is in the response **exactly once** — the panel
     * never stores it anywhere (the old version sent it via query string during a
     * redirect, which ended up in the web server's log and the browser's history
     * · sending it in the response body has no such problem)
     */
    public function store(Request $request): Response
    {
        $result = $this->agent()->data('db.create', [
            'name' => trim($request->payloadString('name')),
            // The database username is no longer accepted from the form — the
            // capability sets it from the prefixed database name instead (see
            // DbCreate::dedicatedUser), so two customers naming their database
            // the same thing never collide on the same user
            'host' => $request->payloadString('host') ?: 'localhost',
            'privileges' => $request->payloadString('privileges') ?: 'readwrite',
            'owner_user_id' => (int) $request->payload('owner_user_id', 0),
            'site_id' => (int) $request->payload('site_id', 0),
            'charset' => $request->payloadString('charset') ?: 'utf8mb4',
        ], $this->ctx->actor($request));

        return $this->databaseCreated(
            $result,
            $this->t('Database {name} created', ['name' => (string) $result['name']]),
        )->withHeader('Location', '/api/v2/databases/' . rawurlencode((string) $result['name']));
    }

    /**
     * The screen's answer after a database is created
     *
     * The order the whole panel uses after a modal form is saved —
     * notification, then the dialog holding what can only be read once, then
     * the table refresh underneath it (see `ApiController::revealed()`).
     *
     * Three things that were once wrong right here:
     *
     *   1. **The dialog was opened straight after a `modal close`** ·
     *      `Modal.hide()` clears its own body 150ms later, which landed after
     *      the new content was already in place — so the dialog opened, then
     *      blanked itself, and the password was gone with it. That is the
     *      "created a database and never saw the password" report.
     *   2. **No notification bar at all** — clicking save just made the modal
     *      disappear, and a user not watching the table closely couldn't tell
     *      whether it succeeded, so they'd often click again.
     *   3. The dialog sent `content`, which `ResponseHandler` puts through the
     *      template engine instead of showing as-is (it reads `html`).
     *
     * @param array<string,mixed> $result what `db.create` answered
     */
    private function databaseCreated(array $result, string $message): Response
    {
        // `db.create` generates the password itself (the form has no password
        // field at all), but the dialog is still tied to it genuinely being
        // there — the database's name and its user are in the table already,
        // and a dialog holding nothing new is just something to dismiss
        $password = (string) ($result['password'] ?? '');

        return $this->revealed(
            $message,
            'databases',
            'Database created',
            $password === '' ? [] : [
                'Database' => (string) ($result['name'] ?? ''),
                'User' => (string) ($result['username'] ?? ''),
                'Password' => $password,
            ],
            extra: $result,
            status: 201,
        );
    }

    /**
     * Delete a database — requires confirming with the name, and a choice of whether to also delete its user
     *
     * `drop_user` is never the default, because one database user can hold
     * privileges on several databases — deleting it automatically would
     * instantly cut off another website using the same user from its own database
     */
    public function destroy(Request $request): Response
    {
        $name = rawurldecode($request->param('name'));
        $confirm = trim($request->payloadString('confirm')) ?: trim($request->get('confirm'));
        $dropUser = $request->payload('drop_user', $request->get('drop_user'));

        $result = $this->agent()->data('db.drop', [
            'name' => $name,
            'confirm' => $confirm,
            'drop_user' => in_array($dropUser, [true, '1', 1, 'true'], true) ? '1' : '0',
        ], $this->ctx->actor($request));

        /*
         * The capability's own message takes priority over the default one — the
         * two cases are too different to phrase the same way: a real deletion
         * (already backed up under this file's name) versus **only removing the
         * leftover row in the panel's own database** because that database no
         * longer exists on the machine at all · saying "database deleted" in the
         * latter case would make the user believe real data was actually removed
         */
        return $this->completed(
            (string) ($result['message'] ?? $this->t('Database {name} deleted', ['name' => $name])),
            'databases',
            is_array($result) ? $result : ['name' => $name],
        );
    }

    /**
     * Set a new password for a database user
     *
     * A sub-resource of `database-users`, not `databases`, because one user can
     * work with several databases — nesting it under any single database would
     * be misleading
     */
    public function resetPassword(Request $request): Response
    {
        $result = $this->agent()->data('db.user_password', [
            'username' => rawurldecode($request->param('user')),
            'host' => $request->payloadString('host') ?: 'localhost',
        ], $this->ctx->actor($request));

        $password = (string) ($result['password'] ?? '');

        return $this->revealed(
            $this->t('New password set for {user}', ['user' => (string) ($result['username'] ?? '')]),
            'databases',
            'New password',
            $password === '' ? [] : [
                'User' => (string) ($result['username'] ?? ''),
                'Password' => $password,
            ],
            extra: $result,
        );
    }

    /**
     * Filter by database name, website name, or username
     *
     * @param list<array<string,mixed>> $databases
     * @return list<array<string,mixed>>
     */
    private static function filter(array $databases, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter($databases, static function (array $db) use ($needle): bool {
            if (str_contains(mb_strtolower((string) ($db['name'] ?? '')), $needle)) {
                return true;
            }

            if (str_contains(mb_strtolower((string) ($db['site'] ?? '')), $needle)) {
                return true;
            }

            foreach ($db['users'] ?? [] as $user) {
                if (str_contains(mb_strtolower((string) ($user['username'] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
