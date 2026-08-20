<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
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
                /*
                 * A strong password, already filled in — the admin only has to
                 * touch this field when they want a different one
                 *
                 * **Only on the create form.** The edit form leaves it blank on
                 * purpose: an empty password there means "leave it as it is"
                 * ({@see \Phpcp\Agent\Capability\MailBoxUpdate}), so prefilling
                 * it would silently reset the customer's mailbox password every
                 * time somebody edited the quota — and the customer would find
                 * out when their phone stopped collecting mail.
                 */
                'suggested_password' => Password::random(PasswordsController::suggestedLength()),
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
                // Deliberately empty on the edit form — see form() · the key is
                // still present so the field's binding has something to read
                // rather than rendering the literal string "undefined"
                'suggested_password' => '',
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

        return $this->mailboxSaved($message, 'Mailbox created', $result, 201);
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

        return $this->mailboxSaved($message, 'New password', $result);
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
     * How to connect a mail client — the half of this page a customer can act on
     *
     * The readiness table beside it is the machine's own business (certificate
     * paths, PTR, whether the provider blocks port 25) and is gated on
     * `settings.manage`. This is the opposite: nothing here is about the
     * machine's internals, and every value is one the customer has to type into
     * Outlook or their phone before mail works at all — until this existed, the
     * panel let a customer create a mailbox and then told them nothing about
     * where to collect its mail from.
     *
     * The DNS rows come from what the panel already wrote when mail was turned
     * on for the domain, which matters most for a customer whose DNS is hosted
     * somewhere else — they have to copy these by hand, and had no way to read them.
     */
    public function connection(Request $request): Response
    {
        $settings = new SettingsRepository($this->app->db());
        $host = trim($settings->get('mail.hostname'));

        // No mail hostname set = the machine has no name to hand out yet ·
        // saying so plainly beats printing rows with an empty server name in them
        $rows = $host === '' ? [] : [
            ['service' => 'IMAP', 'host' => $host, 'port' => 993, 'security' => 'SSL/TLS',
                'note' => $this->t('Recommended — keeps mail on the server')],
            ['service' => 'IMAP', 'host' => $host, 'port' => 143, 'security' => 'STARTTLS', 'note' => ''],
            ['service' => 'POP3', 'host' => $host, 'port' => 995, 'security' => 'SSL/TLS',
                'note' => $this->t('Downloads and removes mail from the server')],
            ['service' => 'SMTP', 'host' => $host, 'port' => 587, 'security' => 'STARTTLS',
                'note' => $this->t('Sending — authentication required')],
            ['service' => 'SMTP', 'host' => $host, 'port' => 465, 'security' => 'SSL/TLS',
                'note' => $this->t('Sending — use this one if 587 is blocked')],
        ];

        $dns = array_map(
            static fn (array $row): array => [
                'domain' => (string) $row['domain'],
                'type' => (string) $row['type'],
                // A record's name is relative to its own zone — spelling out the
                // full name is what a customer has to paste into somebody else's
                // DNS panel, and "@" means nothing there
                'name' => $row['name'] === '@'
                    ? (string) $row['domain']
                    : $row['name'] . '.' . $row['domain'],
                'value' => $row['priority'] === null
                    ? (string) $row['value']
                    : $row['priority'] . ' ' . $row['value'],
            ],
            $this->repository()->mailDnsRecords($this->scopeOwner()),
        );

        return $this->ok([
            'configured' => $host !== '',
            'host' => $host,
            'servers' => $rows,
            'dns' => $dns,
            // The "no domain has mail turned on yet" hint used to sit on the
            // readiness card · that card is admin-only now, so the count travels
            // here instead — the customer is exactly who needs to be told
            'domains' => count($this->repository()->selectableDomains($this->scopeOwner())),
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
     * A mailbox form saved — showing the generated password when there is one
     *
     * The password only exists in this one response: Dovecot stores a hash and
     * so does the panel · a password the admin typed in themselves is never
     * shown back (they already have it), which is what `password_generated`
     * from the capability distinguishes.
     *
     * Everything about *how* it is shown — no `modal close` before the dialog
     * (that wipes it blank 150ms later), a labelled Close button, a Copy button
     * per value, the table refresh underneath — is `ApiController::revealed()`'s
     * job, shared with every other screen that hands out a credential.
     *
     * @param array<string,mixed> $result
     */
    private function mailboxSaved(string $message, string $title, array $result, int $status = 200): Response
    {
        $generated = ($result['password_generated'] ?? false)
            ? (string) ($result['password'] ?? '')
            : '';

        return $this->revealed(
            $message,
            'mailboxes',
            $title,
            // The address alone is no reason to open a dialog — it is right
            // there in the table · only a password the system generated is
            $generated === '' ? [] : [
                'Mailbox' => (string) ($result['address'] ?? ''),
                'Password' => $generated,
            ],
            extra: is_array($result) ? $result : [],
            status: $status,
        );
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
