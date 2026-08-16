<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The outgoing mail queue — `/api/v2/mail/queue` (PLAN-MAIL §5)
 *
 * This layer only converts requests into capability arguments and converts
 * results back into JSON · deciding how dangerous a given command is belongs
 * to the capability, the only place that can touch the machine
 */
final class MailQueueController extends ApiController
{
    /** The queue's messages, with a summary the screen uses to decide whether action is needed */
    public function index(Request $request): Response
    {
        $result = $this->agent()->data('mail.queue', [], $this->ctx->actor($request));
        $now = time();

        $rows = array_map(
            fn (array $row): array => $row + [
                'row_id' => $row['id'],
                'recipient' => implode(', ', (array) ($row['recipients'] ?? [])),
                // How long it's been stuck is what says whether this is urgent yet — Postfix gives up at 5 days
                'age' => max(0, $now - (int) ($row['arrival_time'] ?? $now)),
                'tone' => ($row['queue'] ?? '') === 'deferred' ? 'danger' : 'ok',
                'reason_short' => mb_substr((string) ($row['reason'] ?? ''), 0, 120),
            ],
            (array) ($result['messages'] ?? []),
        );

        /*
         * **Returns a plain list, because the table fetches its own data (`data-source`)**
         *
         * The same pattern the databases page and websites page already use
         * successfully · only a table that fetches its own data can genuinely
         * be told to "reload" after a successful delete — a table bound to the
         * page's own data would need the whole page to reload, a path with no
         * mechanism for that yet
         *
         * Failing to read the queue = an error, not an empty queue · answering
         * with a problem response is better than showing an empty table that
         * reads as "no mail stuck" when the truth is "can't be viewed"
         */
        if (($result['available'] ?? false) !== true) {
            return $this->problem(
                ApiProblem::AgentUnavailable,
                'Cannot read the queue on this server.',
                ['postqueue' => (string) ($result['message'] ?? '')],
            );
        }

        return $this->ok($rows);
    }

    /** One queued message's content — opens in a modal, not a new page */
    public function show(Request $request): Response
    {
        $result = $this->agent()->data(
            'mail.message',
            ['id' => $request->param('id')],
            $this->ctx->actor($request),
        );

        return $this->done(
            'Queued message',
            [[
                'type' => 'modal',
                'action' => 'show',
                'title' => $this->t('Queued message') . ' ' . (string) ($result['id'] ?? ''),
                'titleClass' => 'icon-email',
                // Raw text — must be entirely HTML-escaped, since a message's content always comes from an outsider
                'html' => '<pre class="mono selectable scroll">'
                    . htmlspecialchars((string) ($result['content'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '</pre>',
            ]],
            is_array($result) ? $result : [],
        );
    }

    /** Retry sending the whole queue right now */
    public function flush(Request $request): Response
    {
        return $this->act($request, ['action' => 'flush']);
    }

    /** Delete one message */
    public function destroy(Request $request): Response
    {
        return $this->act($request, ['action' => 'delete', 'id' => $request->param('id')]);
    }

    /**
     * Clear the whole queue
     *
     * Deliberately a separate route from deleting a single message — there's
     * no way for a strange id value to turn into deleting the whole queue,
     * since these two never share a route at all
     */
    public function destroyAll(Request $request): Response
    {
        return $this->act($request, ['action' => 'delete_all']);
    }

    /**
     * @param array<string,mixed> $args
     *
     * **Tells the table to reload via `completed()`, not a page event**
     *
     * `completed($message, 'tableName')` sends back a `{type:"redirect",
     * url:"reload"}` command, a type the framework already has a handler for —
     * `TableManager` supplies a `reloadTable` function when
     * `ResponseHandler.process()` runs after a row button fires — the same
     * path the databases page has always used to refresh its table correctly after a delete
     *
     * What used to be sent, `{type:"event"}`, **has no handler for that type at
     * all** · `ResponseHandler.executeAction` finds nothing, warns in the
     * console, and moves on silently — the result was a genuinely successful
     * server-side delete, while the table kept showing the same row as if nothing happened
     */
    private function act(Request $request, array $args): Response
    {
        $result = $this->agent()->data('mail.queue_action', $args, $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Queue updated'),
            'mailQueue',
            is_array($result) ? $result : [],
        );
    }
}
