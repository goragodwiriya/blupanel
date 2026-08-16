<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\MailboxRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Mailboxes and forwarding addresses — `/api/v2/mailboxes` (PLAN-MAIL phase M2)
 *
 * Every command that changes anything goes through the agent · this layer's
 * only job is turning HTTP requests into capability arguments and turning
 * results back into JSON
 *
 * **Visibility is constrained at the query, not filtered afterward** — a
 * website owner sees only their own domains' mailboxes, with another
 * customer's mailboxes never even read into memory in the first place
 */
final class MailboxesController extends ApiController
{
    /**
     * The mailbox list, with forwarding addresses and selectable domains in meta
     */
    public function index(Request $request): Response
    {
        $repository = $this->repository();
        $scope = $this->scopeOwner();
        $canManage = $this->ctx->can('mail.manage');

        $rows = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'row_id' => (int) $row['id'],
                'address' => $row['local_part'] . '@' . $row['domain'],
                'local_part' => (string) $row['local_part'],
                'domain' => (string) $row['domain'],
                'quota_mb' => (int) $row['quota_mb'],
                'enabled' => (int) $row['enabled'] === 1,
                'created_at' => (int) $row['created_at'],
                'can_manage' => $canManage,
            ],
            $repository->listMailboxes($scope),
        );

        /*
         * **Returns a plain list, because the table fetches its own data (`data-source`)**
         *
         * Only a table that fetches its own data can genuinely be told to
         * "reload" after a successful delete · when bound to the page's own
         * data instead, the delete worked correctly on the server but the table
         * still showed the old row stuck in place — forwarding addresses got
         * their own endpoint for the same reason
         */
        return $this->ok($rows);
    }

    /**
     * The empty shell of the create-mailbox form, with the command to open its modal
     *
     * A mailbox can be edited (password and quota), but its address can't be
     * changed — changing the address means moving the whole mailbox's mail to a
     * new one, a completely different operation from editing a value · the edit
     * form's address field is therefore read-only text
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            [
                'id' => 0,
                'local_part' => '',
                'domain' => '',
                'quota_mb' => 1024,
                'domains' => $this->repository()->selectableDomains($this->scopeOwner()),
            ],
            [],
            $this->modal('mailbox-form.html', '{LNG_Add mailbox}', 'icon-email'),
        );
    }

    /** The edit form for an existing mailbox — the address can't be changed, only the password and quota */
    public function show(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        return $this->ok(
            [
                'id' => (int) $row['id'],
                'address' => $row['local_part'] . '@' . $row['domain'],
                'local_part' => (string) $row['local_part'],
                'domain' => (string) $row['domain'],
                'quota_mb' => (int) $row['quota_mb'],
                'domains' => [],
            ],
            [],
            $this->modal('mailbox-form.html', '{LNG_Edit mailbox}', 'icon-email'),
        );
    }

    public function store(Request $request): Response
    {
        // One form does both create and edit — the hidden `id` decides, same as other pages
        $id = (int) $request->payload('id', 0);

        if ($id > 0) {
            return $this->update($request->withParams(['id' => $id]));
        }

        $result = $this->agent()->data('mail.box_create', [
            'address' => trim($request->payloadString('local_part')) . '@' . trim($request->payloadString('domain')),
            'quota_mb' => (int) $request->payload('quota_mb', 1024),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        $message = (string) ($result['message'] ?? 'Mailbox created');

        return $this->done($message, $this->passwordActions($result, 'Mailbox created', $message), $result, 201);
    }

    public function update(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        $result = $this->agent()->data('mail.box_update', [
            'address' => $row['local_part'] . '@' . $row['domain'],
            'quota_mb' => (int) $request->payload('quota_mb', 0),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        $message = (string) ($result['message'] ?? 'Mailbox saved');

        return $this->done($message, $this->passwordActions($result, 'New password', $message), $result);
    }

    public function destroy(Request $request): Response
    {
        $row = $this->mailboxOrNull($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Mailbox not found');
        }

        $result = $this->agent()->data('mail.box_delete', [
            'address' => $row['local_part'] . '@' . $row['domain'],
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Mailbox deleted'),
            'mailboxes',
            is_array($result) ? $result : [],
        );
    }

    /**
     * Mail's six readiness checks — PLAN-MAIL §7
     *
     * Fills in `fix_label` so the screen never needs to know the internal
     * codes, and fills in each check's description server-side, because that's
     * knowledge about mail, not about display
     */
    public function readiness(Request $request): Response
    {
        $result = $this->agent()->data('mail.readiness', [], $this->ctx->actor($request));

        $labels = [
            'hostname' => 'Mail hostname',
            'listening' => 'Listening for incoming mail',
            'outbound' => 'Outgoing port 25',
            'rdns' => 'Reverse DNS (PTR)',
            'dkim' => 'DKIM signing key',
            'tls' => 'TLS certificate',
        ];

        $rows = array_map(
            fn (array $check): array => $check + [
                'label' => $this->t($labels[$check['key']] ?? $check['key']),
                'tone' => $check['ok'] ? 'ok' : 'danger',
                'status' => $check['ok'] ? $this->t('Ready') : $this->t('Not ready'),
                // An item the panel can't fix itself must clearly say where to go fix it
                'fix_label' => $check['fix'] === 'provider'
                    ? $this->t('Set this at your VPS provider — the panel cannot do it')
                    : $this->t('The panel can fix this'),
            ],
            (array) ($result['checks'] ?? []),
        );

        // Lives in `data` for the same reason as index() — a summary bound to `meta` never once showed up
        return $this->ok([
            'ready' => (bool) ($result['ready'] ?? false),
            'failed' => (int) ($result['failed'] ?? 0),
            'domains' => (array) ($result['domains'] ?? []),
            'checks' => $rows,
        ]);
    }

    /**
     * Bind the mail hostname's certificate into Postfix and Dovecot — PLAN-MAIL phase M3
     *
     * This button doesn't "request a certificate" — it's requested from the
     * existing SSL page, the same as every website's certificate · this is the
     * remaining step: telling the mail daemons to use that certificate · the
     * scheduled job already does this every day on its own — the button exists
     * so nobody has to wait until tomorrow morning right after requesting a fresh one
     */
    public function certificate(Request $request): Response
    {
        $result = $this->agent()->data('mail.cert', [], $this->ctx->actor($request));
        $message = (string) ($result['message'] ?? 'Mail certificate checked');

        return $this->done(
            $message,
            [
                // No certificate to bind yet isn't an error, but it's not a
                // success worth celebrating either — the message explains how
                // to request one, and the user genuinely needs to read it, not just glimpse it
                ['type' => 'notification',
                    'level' => ($result['found'] ?? false) ? 'success' : 'warning',
                    'message' => $message],
                // The readiness table is bound to this card's own data · the
                // button already has data-refresh-table telling it to reload, so
                // this just reports the result
            ],
            is_array($result) ? $result : [],
        );
    }

    /**
     * The command telling the screen to show a system-generated password
     *
     * This password has no other place it can ever be viewed again — the system
     * only stores a hash · it must be shown as a box the user closes themselves,
     * not a notification that vanishes on its own after 5 seconds
     *
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    private function passwordActions(array $result, string $title, string $message = ''): array
    {
        $password = (string) ($result['password'] ?? '');

        // Always closes the form first — then opens the password dialog on top of it, if there's a password to show
        $actions = [['type' => 'modal', 'action' => 'close']];

        /*
         * The notification bar — this used to be missing even though the modal
         * closed and the table reloaded correctly
         *
         * The result was clicking save just made the modal disappear, a new row
         * appeared in the table with nothing confirming "success" — an admin not
         * watching the table closely couldn't tell whether it went through or
         * silently vanished, and would often click again · other pages in the
         * system use `saved()`, which already includes this bar
         *
         * Must come before the password dialog, so it isn't hidden behind it when there's a password to show
         */
        if ($message !== '') {
            $actions[] = ['type' => 'notification', 'level' => 'success', 'message' => $message];
        }

        if ($password !== '' && ($result['password_generated'] ?? false)) {
            $actions[] = [
                'type' => 'modal',
                'action' => 'show',
                'title' => $this->t($title),
                'titleClass' => 'icon-lock',
                'html' => '<p>' . $this->t('Copy this password before closing — it is not stored anywhere')
                    . '</p><p class="mono selectable">' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</p>',
            ];
        }

        /*
         * **Tells the table to reload using a type the framework genuinely
         * handles** — `{type:"event"}` has no handler at all; `ResponseHandler`
         * warns in the console and drops it · the mailbox table already fetches
         * its own data (`data-source`), so it can be told to reload directly
         */
        $actions[] = ['type' => 'redirect', 'url' => 'reload', 'target' => 'mailboxes'];

        return $actions;
    }

    /** @return list<array<string,mixed>> */
    private function modal(string $template, string $title, string $icon): array
    {
        return [[
            'type' => 'modal',
            'action' => 'show',
            'template' => $template,
            'title' => $title,
            'titleClass' => $icon,
        ]];
    }

    /**
     * Load a mailbox while checking the caller genuinely has permission to see it
     *
     * Deliberately returns null for both "doesn't exist" and "isn't yours" —
     * telling these two apart would let an outsider learn which mailboxes genuinely exist on the machine
     *
     * @return array<string,mixed>|null
     */
    private function mailboxOrNull(int $id): ?array
    {
        foreach ($this->repository()->listMailboxes($this->scopeOwner()) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function repository(): MailboxRepository
    {
        return new MailboxRepository($this->app->db());
    }

    /** 0 = sees the whole machine (an admin) · anything else = sees only their own */
    private function scopeOwner(): int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : 0;
    }
}
