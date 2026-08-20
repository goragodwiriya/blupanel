<?php

declare (strict_types = 1);

namespace Phpcp\Http;

use Phpcp\Agent\AgentException;
use Phpcp\Controller\Controller;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The base of every controller in REST API v2 — PLAN-V2 §4
 *
 * The one rule everything here must follow: **not one byte of HTML ever leaves
 * here** — success, failure, or blowing up mid-request · no redirects · no
 * results sent through a query string
 *
 * Inherits from the old Controller because HttpKernel constructs every
 * controller with the same signature, but view()/redirect() inherited from it
 * must never be called — those two methods belong to the HTML UI, which is
 * being retired in phase D
 */
abstract class ApiController extends Controller
{
    /** Pagination's default and ceiling — §4.5 */
    protected const PER_PAGE_DEFAULT = 50;
    protected const PER_PAGE_MAX = 200;

    /**
     * The event name a detail page uses to tell itself to reload its data
     *
     * One single name across the whole system, so every template writes the same
     * `data-refresh-event` value and no controller needs to know which page named what
     */
    protected const RELOAD_EVENT = 'phpcp:reload';

    /**
     * Success, with data
     *
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>             $meta
     * @param array<string,mixed>            $actions
     * @param array<string,mixed>             $filters
     * @param array<string,mixed>             $options
     */
    protected function ok(array $data = [], array $meta = [], array $actions = [], array $filters = [], array $options = []): Response
    {
        $body = ['ok' => true, 'data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        // Commands the screen from the server side — Now.js's `ResponseHandler` reads this key itself
        //
        // Used for a result whose **content the user must see in the response**,
        // not just that it succeeded — such as a system-generated random password,
        // which has no other place it can ever be viewed again · so the screen
        // never needs bespoke code for any given endpoint
        //
        // Valid types: notification · alert · modal · redirect · update · render ·
        // remove · class · attribute · focus · scroll · clipboard · download · event
        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        // The filters the caller asked for — `filters` per §4.5's contract
        // ['status' => [['value'=> '1', 'text' => 'Active'], ['value'=> '0', 'text' => 'Inactive']],
        // 'department' => [['value'=> '1', 'text' => 'Department 1'], ['value'=> '2', 'text' => 'Department 2']]]
        if ($filters !== []) {
            $body['filters'] = $filters;
        }

        // The options the caller asked for — `options` per §4.5's contract
        // ['status' => [['value'=> '1', 'text' => 'Active'], ['value'=> '0', 'text' => 'Inactive']],
        // 'department' => [['value'=> '1', 'text' => 'Department 1'], ['value'=> '2', 'text' => 'Department 2']]]
        if ($filters !== []) {
            $body['filters'] = $filters;
        }
        if ($options !== []) {
            $body['options'] = $options;
        }

        return Response::json($body);
    }

    /**
     * A command's response — **must never have a `data` key**
     *
     * Now.js unwraps a response with `response.data.data ?? response.data` · the
     * rule that makes that work is **one response does one job**: a *read*
     * response has `data` to bind to the screen, a *command* response has
     * `actions` for the framework to carry out · sending both at once means it
     * picks the inner layer and never sees `actions` at all — the result the user
     * was supposed to read just silently vanishes
     *
     * Values the caller genuinely needs (a system-generated password · an
     * exported file's path) go through `$extra`, which lands at the **top level
     * of the response**, not inside the `data` layer
     *
     * @param array<string,mixed> $extra
     * @param list<array<string,mixed>> $actions
     */
    protected function done(string $message, array $actions = [], array $extra = [], int $status = 200): Response
    {
        $message = $this->t($message);
        $actions = $this->translateActions($actions);

        $body = ['ok' => true, 'message' => $message] + $extra;

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        return Response::json($body, $status);
    }

    /**
     * Report success and bring the screen back up to date — `done()`'s most common shape
     *
     * @param string $table the `data-table` name(s) to reload, comma-separated · empty = only signal the page
     * @param array<string,mixed> $extra
     */
    protected function completed(string $message, string $table = '', array $extra = [], int $status = 200, array $notices = []): Response
    {
        return $this->done($message, [
            ['type' => 'notification', 'level' => 'success', 'message' => $message],
            ...self::noticeActions($notices),
            self::refreshAction($table)
        ], $extra, $status);
    }

    /**
     * The one command that says "what's on screen is now out of date" — every helper here ends with it
     *
     * **Never `{"type":"redirect","url":"reload"}` again.** That is
     * `ResponseHandler`'s own action, and when the named table isn't on the
     * page the user is actually looking at, it falls through to
     * `window.location.reload()` — a full browser reload · that isn't a rare
     * corner: one endpoint serves several screens (adding a domain answers
     * `sites` but is pressed from the site's own page; enabling SFTP is
     * pressed from both the SFTP page and a user's page), and a full reload
     * throws away a dialog that was just opened to show a generated password,
     * which then exists nowhere at all.
     *
     * The handler for this type lives in `js/ui.js`: it reloads the named
     * table **when the page genuinely has one**, and always emits
     * `phpcp:reload` for pages whose tables are bound to page data
     * (`data-attr="data:..."`) and so can't be told to reload by name.
     *
     * @param string $table the `data-table` name(s), comma-separated · empty = signal the page only
     * @return array<string,mixed>
     */
    private static function refreshAction(string $table): array
    {
        return ['type' => 'refresh', 'target' => $table];
    }

    /**
     * A modal form saved successfully — **close the modal, then reload the table**
     *
     * A form opened in a modal was opened by a server command (`$this->modal(...)`),
     * so closing it must be a server command too · without this line the modal
     * would stay stuck on screen even though the save already succeeded — the user
     * sees the same form with a success message and often clicks save again,
     * thinking it didn't go through, creating a duplicate record
     *
     * **Order matters:** close the modal before refreshing · swap the order and
     * the table gets redrawn underneath a modal that's still open, where the
     * user can't see the change happen anyway
     *
     * Use {@see revealed()} instead when the response carries something the
     * user gets one chance to read — closing and re-opening in the same breath
     * is what wipes a password dialog blank.
     *
     * @param string $table the `data-table` name(s) to reload, comma-separated · empty = only signal the page
     * @param array<string,mixed> $extra
     */
    protected function saved(string $message, string $table = '', array $extra = [], int $status = 200, array $notices = []): Response
    {
        return $this->done($message, [
            ['type' => 'modal', 'action' => 'close'],
            ['type' => 'notification', 'level' => 'success', 'message' => $message],
            ...self::noticeActions($notices),
            self::refreshAction($table)
        ], $extra, $status);
    }

    /**
     * Things that went wrong **without failing the command** — a warning bar each
     *
     * A command can half-succeed in ways the caller must still be told about:
     * the account was created but its first website wasn't, the DNS record was
     * saved but BIND wouldn't reload · answering with an error would be wrong
     * (the main thing genuinely happened, and retrying would collide with it),
     * and staying silent is worse — so they ride along beside the success bar.
     *
     * Longer on screen than a success message on purpose: this is the one that
     * has to actually be read.
     *
     * @param list<array<string,string>> $notices each `['level' => 'warning', 'message' => '...']`
     * @return list<array<string,mixed>>
     */
    private static function noticeActions(array $notices): array
    {
        $out = [];

        foreach ($notices as $notice) {
            $message = trim((string) ($notice['message'] ?? ''));

            if ($message === '') {
                continue;
            }

            $out[] = [
                'type' => 'notification',
                'level' => (string) ($notice['level'] ?? 'warning'),
                'duration' => (int) ($notice['duration'] ?? 15000),
                'message' => $message
            ];
        }

        return $out;
    }

    /**
     * Saved, **and there is something in the answer the user gets exactly one chance to read**
     *
     * The whole reason this is separate from `saved()`: a generated password
     * is stored nowhere — not by the panel, not by MariaDB, not by Dovecot,
     * which all keep a hash — so if it isn't copied off this one screen it is
     * gone, and the only way back is to generate another one.
     *
     * **The modal is never closed first.** Closing and immediately re-opening
     * looks like one dialog replacing another, but `Modal.hide()` schedules
     * `body.innerHTML = ''` 150ms later to clear itself after the fade — and
     * that timer lands *after* the new content has already been put in, so the
     * dialog ends up on screen **empty**, with the password wiped out of it.
     * That is exactly what "create database showed nothing" was. Showing the
     * dialog on its own swaps the content of the same one instead, which is
     * both correct and what it looked like it was meant to do.
     *
     * Order is therefore: notification → the dialog (replacing the form) →
     * refresh · the refresh runs underneath the open dialog, so the new row is
     * already in the table by the time it's closed.
     *
     * **Empty `$secrets` = an ordinary `saved()`.** The caller decides, because
     * only the caller knows whether the password in its hand was generated or
     * typed in by the admin — one has to be shown, the other is already in
     * their hands, and showing it back teaches them to dismiss this dialog
     * without reading it · rows that only give context (which database, which
     * mailbox) belong in `$secrets` too, but they are **never a reason on their
     * own** to interrupt with a dialog, so leave the whole array out when there
     * is nothing that can only be read once.
     *
     * @param array<string,string> $secrets label => value, in the order they should be read · empty = nothing to reveal
     * @param string $note extra sentence under the values (what else happened, a caveat) — plain text, and only ever shown when there is a dialog to put it in
     * @param string $goAfterClose route to move to once the user closes the dialog · empty = stay put
     * @param array<string,mixed> $extra
     */
    protected function revealed(
        string $message,
        string $table = '',
        string $title = 'Copy this before closing',
        array $secrets = [],
        string $note = '',
        string $goAfterClose = '',
        array $extra = [],
        int $status = 200,
        array $notices = [],
    ): Response {
        // Nothing to reveal = an ordinary save · never an empty dialog the
        // user has to dismiss to find out it had nothing in it
        $secrets = array_filter($secrets, static fn($value): bool => trim((string) $value) !== '');

        $actions = [['type' => 'notification', 'level' => 'success', 'message' => $message]];
        $actions = array_merge($actions, self::noticeActions($notices));

        if ($secrets === []) {
            /*
             * Nothing to keep on screen, so this is an ordinary save: close the
             * form, and take the trip that was only ever postponed for the
             * dialog's sake · leaving the admin on a create form they have
             * already submitted is how the same account gets created twice
             */
            array_unshift($actions, ['type' => 'modal', 'action' => 'close']);
            $actions[] = self::refreshAction($table);

            if ($goAfterClose !== '') {
                $actions[] = ['type' => 'redirect', 'url' => $goAfterClose, 'delay' => 1200];
            }

            return $this->done($message, $actions, $extra, $status);
        }

        // With a dialog open, the trip rides on its Close button instead
        // (`data-go`) — navigating now would take the page and the dialog with it
        $actions[] = $this->secretDialog($title, $secrets, $note, $goAfterClose);
        $actions[] = self::refreshAction($table);

        return $this->done($message, $actions, $extra, $status);
    }

    /**
     * The dialog that shows a value which exists nowhere else
     *
     * Assembled here rather than in each controller because every part of it
     * is a rule that was got wrong somewhere before:
     *
     *   - **`html`, not `content`** — `ResponseHandler` reads `html` directly;
     *     `content` is put through the template engine first, so text with a
     *     `{` in it (a generated password genuinely can hold one) comes out
     *     mangled or empty
     *   - **a labelled Close button**, not only the corner X — closing is the
     *     step that means "copied", and `.modal-close` (the class Modal binds
     *     on its own) is styled as a 32px icon that can't carry a label, so
     *     the button uses the panel's own `closeModal` action instead
     *   - **a Copy button per value** — selecting a 20-character random string
     *     by hand is where it gets truncated, and a truncated password looks
     *     exactly like a wrong one
     *   - **every value escaped**, including inside `data-copy-value` — the
     *     text here is generated, but the labels beside it carry a domain or a
     *     mailbox address the customer chose
     *
     * @param array<string,string> $secrets
     * @return array<string,mixed>
     */
    private function secretDialog(string $title, array $secrets, string $note, string $goAfterClose): array
    {
        $esc = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $rows = '';

        foreach ($secrets as $label => $value) {
            $value = (string) $value;

            $rows .= sprintf(
                '<dt>%s</dt><dd><code class="mono selectable">%s</code>'
                .'<button type="button" class="btn small icon-copy" data-action="click.prevent:copyToClipboard"'
                .' data-copy-value="%s">%s</button></dd>',
                $esc($this->t((string) $label)),
                $esc($value),
                $esc($value),
                $esc($this->t('Copy')),
            );
        }

        return [
            'type' => 'modal',
            'action' => 'show',
            'title' => $this->t($title),
            'titleClass' => 'icon-lock',
            'html' => sprintf(
                '<div class="secret-reveal"><p>%s</p><dl>%s</dl>%s<div class="secret-actions">'
                .'<button type="button" class="btn btn-primary icon-valid" data-action="click.prevent:closeModal"%s>%s</button>'
                .'</div></div>',
                $esc($this->t('Copy this password before closing — it is not stored anywhere')),
                $rows,
                $note === '' ? '' : '<p class="muted">'.$esc($note).'</p>',
                $goAfterClose === '' ? '' : sprintf(' data-go="%s"', $esc($goAfterClose)),
                $esc($this->t('Close')),
            )
        ];
    }

    /**
     * Report success and tell a detail page to reload itself
     *
     * Used instead of `completed()` when what needs updating isn't a
     * self-fetching table but the `data-component="api"` wrapping the whole page
     * (a single website's page, for example) — its child tables receive data via
     * `data-attr="data:domains"`, so they can't be told to reload directly
     *
     * The event name must match that page's `data-refresh-event`
     *
     * @param array<string,mixed> $extra
     */
    protected function refreshed(string $message, string $target = '', array $extra = []): Response
    {
        return $this->done($message, [
            ['type' => 'notification', 'level' => 'success', 'message' => $message],
            self::refreshAction($target)
        ], $extra);
    }

    /**
     * Created successfully — must always attach the Location of the resource that was just created
     *
     * @param array<string,mixed> $data
     */
    protected function created(array $data, string $location, array $actions = []): Response
    {
        $body = ['ok' => true, 'data' => $data];

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        return Response::json($body, 201)
            ->withHeader('Location', $location);
    }

    /**
     * The job has been queued — used for long-running work whose result isn't ready yet (a backup, for example)
     *
     * @param array<string,mixed> $data
     */
    protected function accepted(array $data = []): Response
    {
        return Response::json(['ok' => true, 'data' => $data], 202);
    }

    /**
     * Success, nothing to answer back with
     *
     * The body must genuinely be empty per the HTTP standard (204 must have no
     * content), but Content-Type is still set to JSON so every kind of v2 response
     * shares the same Content-Type — the SPA can then check the response type in
     * one place without treating 204 as a special case
     */
    protected function noContent(): Response
    {
        return Response::noContent()->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * The permissions the caller has on the resource currently being answered
     *
     * Attached to a single resource under the `can` key, so a screen can write
     * `data-if="can['delete']"` directly · **it's a server-side answer, not a
     * calculation duplicated on the screen** — the screen never needs to know
     * which role can do what, which is exactly the kind of duplicated knowledge
     * that drifts out of sync easiest
     *
     * The result is always a map (every key has a true/false value), never a list
     * of only the allowed ones — Now.js's `data-if` reveals an element when it
     * hits an undefined value, so sending only the allowed keys would make a
     * button that should be hidden show up instead · same reasoning as
     * /api/v2/session's permission map
     *
     * @param array<string,string> $map the screen's name for it => the permission it requires
     * @return array<string,bool>
     */
    protected function can(array $map): array
    {
        return array_map(fn(string $permission): bool => $this->ctx->can($permission), $map);
    }

    /**
     * A paginated response — meta must always carry all four values so the SPA can compute the pager
     *
     * @param list<mixed> $items
     */
    protected function paginate(array $items, int $total, int $page, int $perPage): Response
    {
        return $this->ok($items, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0
        ]);
    }

    /** @param array<string,string> $fields */
    protected function problem(ApiProblem $problem, string $message, array $fields = []): Response
    {
        return $problem->response(
            $this->t($message),
            array_map(fn(string $text): string => $this->t($text), $fields),
        );
    }

    /**
     * Translate a message before it goes out to be read
     *
     * **Code is always written in English** — the English text is itself the
     * catalogue's key, the same rule the frontend follows (`public/assets/spa/lang/th.json`
     * is the very same file) · a key with no translation yet goes out in English,
     * which reads fine, not a meaningless code
     *
     * @param array<string,string|int|float> $params values that replace `{name}` in the message
     */
    protected function t(string $key, array $params = []): string
    {
        return $this->app->t($key, $params);
    }

    /**
     * Translate the message inside an action sent to the screen to act on
     *
     * `notification` and `alert` carry a message the user reads · other types
     * (redirect, event, modal) don't · a modal's title uses the `{LNG_...}` shape,
     * which the frontend already translates on its own
     *
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>
     */
    private function translateActions(array $actions): array
    {
        foreach ($actions as $index => $action) {
            if (is_array($action) && isset($action['message']) && is_string($action['message'])) {
                $actions[$index]['message'] = $this->t($action['message']);
            }
        }

        return $actions;
    }

    /**
     * The PHP versions actually installed, from the agent, sorted "usable first, then newest"
     *
     * The first entry after sorting is always what should be the `<select>`'s
     * default — both PhpVersionsController (the list page) and SitesController
     * (the website create/edit form) share this, so a new website never gets a
     * default whose FPM isn't actually running from the two pages sorting differently
     *
     * Doesn't check `isAvailable()` for the caller — the caller decides whether to
     * accept an empty list back if the agent is down, or let AgentException propagate
     *
     * @return array<string,mixed> the agent's original shape, but `versions` sorted into a list
     */
    protected function fetchPhpVersions(Request $request): array
    {
        $data = $this->agent()->data('php.list', [], $this->ctx->actor($request));

        // The agent returns a map keyed by version number — REST needs an ordered
        // array, or the JSON becomes an object the client iterates in an unpredictable order
        $versions = array_values($data['versions'] ?? []);

        /*
         * Installed first, then running, then newest
         *
         * The installed test leads because the list now includes versions that
         * are merely installable ({@see \Phpcp\Agent\Capability\PhpList}) ·
         * without it, a version nobody has installed would sort above an
         * installed-but-stopped one purely for being a higher number, and the
         * top of the table would be things the machine does not have
         */
        usort($versions, static function (array $a, array $b): int {
            $present = (int) (bool) ($b['installed'] ?? false) <=> (int) (bool) ($a['installed'] ?? false);

            if ($present !== 0) {
                return $present;
            }

            $runnable = (int) (bool) ($b['fpm_running'] ?? false) <=> (int) (bool) ($a['fpm_running'] ?? false);

            return $runnable !== 0
                ? $runnable
                : version_compare((string) $b['version'], (string) $a['version']);
        });

        $data['versions'] = $versions;

        return $data;
    }

    /**
     * Convert an agent error into a contract-shaped response
     *
     * Used when the controller must do something else after the agent fails
     * (rolling back what was already done, for example) · the common case never
     * needs to call this itself — let the exception propagate up for HttpKernel
     * to handle, which produces exactly the same result and also logs it
     */
    protected function failFromAgent(AgentException $e): Response
    {
        return ApiProblem::fromAgentException($e)->response($this->t($e->getMessage()));
    }

    /**
     * Pagination parameters, already clamped into range — §4.5
     *
     * @return array{page:int,per_page:int,offset:int}
     */
    protected function pagination(Request $request): array
    {
        $page = max(1, $request->queryInt('page', 1));
        // `per_page` is §4.5's contract name · `pageSize` is what Now.js's table sends
        // Both names are accepted right here in one place, so every list endpoint
        // speaks the same language as the screen with no parameter-converting JS
        // and no framework change needed
        $perPage = $request->get('per_page') !== ''
            ? $request->queryInt('per_page', self::PER_PAGE_DEFAULT)
            : $request->queryInt('pageSize', self::PER_PAGE_DEFAULT);
        $perPage = max(1, min($perPage, self::PER_PAGE_MAX));

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * The search term the caller asked for — `q` per the contract, or `search` as sent by Now.js's table
     */
    protected function searchTerm(Request $request): string
    {
        $term = trim($request->get('q'));

        return $term !== '' ? $term : trim($request->get('search'));
    }

    /**
     * The sort order the caller asked for
     *
     * Accepts two shapes: `-field` per §4.5's contract, and `field desc` as sent
     * by Now.js's table (a table can send multiple comma-separated pairs — only
     * the first is used, since every endpoint sorts on a single level)
     *
     * The field name is always constrained by the caller's own allowlist, because
     * this value is appended to ORDER BY — a raw user value must never reach SQL,
     * under any circumstance
     *
     * @param list<string> $allowed
     * @return array{field:string,desc:bool}
     */
    protected function sort(Request $request, array $allowed, string $default): array
    {
        $raw = trim(explode(',', $request->get('sort'))[0]);

        $desc = str_starts_with($raw, '-');
        $field = ltrim($raw, '-');

        if (str_contains($field, ' ')) {
            [$field, $direction] = explode(' ', $field, 2);
            $desc = strtolower(trim($direction)) === 'desc';
        }

        return [
            'field' => in_array($field, $allowed, true) ? $field : $default,
            'desc' => $desc
        ];
    }
}
