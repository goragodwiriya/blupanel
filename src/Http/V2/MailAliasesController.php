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
 * Mail forwarders — `/api/v2/mail-aliases` (PLAN-MAIL phase M2)
 *
 * The list used to live in the mailboxes page's `meta.aliases`, so it had no
 * index of its own — a table bound to already-loaded data
 * (`data-attr="data:aliases"`) never fires a request of its own
 */
final class MailAliasesController extends ApiController
{
    /**
     * The list of forwarders — the table fetches this itself (`data-source`)
     *
     * **Needs its own endpoint, not bundled into `/api/v2/mailboxes`** · only
     * a table that fetches its own data can genuinely be told to "reload"
     * after a successful delete — when bound to the page's already-loaded
     * data, the delete succeeds correctly on the server but the table keeps showing the old row
     */
    public function index(Request $request): Response
    {
        $canManage = $this->ctx->can('mail.manage');

        return $this->ok(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'row_id' => (int) $row['id'],
                // Empty = catch-all · shown as `@domain` to match what Postfix writes
                'source' => ((string) $row['source'] === '' ? '' : $row['source']) . '@' . $row['domain'],
                'destination' => (string) $row['destination'],
                'domain' => (string) $row['domain'],
                'can_manage' => $canManage,
            ],
            $this->repository()->listAliases($this->scopeOwner()),
        ));
    }

    /** An empty form shape, with the command that opens the modal */
    public function form(Request $request): Response
    {
        return $this->ok(
            [
                'source' => '',
                'domain' => '',
                'destination' => '',
                'domains' => $this->repository()->selectableDomains($this->scopeOwner()),
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'mail-alias-form.html',
                'title' => '{LNG_Add forwarder}',
                'titleClass' => 'icon-link',
            ]],
        );
    }

    public function store(Request $request): Response
    {
        $result = $this->agent()->data('mail.alias_set', [
            'domain' => trim($request->payloadString('domain')),
            'source' => trim($request->payloadString('source')),
            'destination' => trim($request->payloadString('destination')),
        ], $this->ctx->actor($request));

        return $this->saved(
            (string) ($result['message'] ?? 'Forwarder saved'),
            'mailAliases',
            is_array($result) ? $result : [],
        );
    }

    public function destroy(Request $request): Response
    {
        $id = $request->paramInt('id');

        if (!$this->owns($id)) {
            return $this->problem(ApiProblem::NotFound, 'Forwarder not found');
        }

        $result = $this->agent()->data('mail.alias_delete', ['id' => $id], $this->ctx->actor($request));

        // Tells the table to reload, using an action type the framework already has a handler for
        return $this->completed(
            (string) ($result['message'] ?? 'Forwarder deleted'),
            'mailAliases',
            is_array($result) ? $result : [],
        );
    }

    /** Can the caller genuinely see this item? — checked against the already-scoped list */
    private function owns(int $id): bool
    {
        foreach ($this->repository()->listAliases($this->scopeOwner()) as $row) {
            if ((int) $row['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    private function repository(): MailboxRepository
    {
        return new MailboxRepository($this->app->db());
    }

    private function scopeOwner(): int
    {
        return $this->ctx->role() === Permissions::WEBADMIN ? $this->ctx->userId() : 0;
    }
}
