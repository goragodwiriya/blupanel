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
     * Report success and reload a table — `done()`'s most common shape
     *
     * @param string $table the `data-table` name to reload · empty = reload nothing
     * @param array<string,mixed> $extra
     */
    protected function completed(string $message, string $table = '', array $extra = []): Response
    {
        $actions = [['type' => 'notification', 'level' => 'success', 'message' => $message]];

        if ($table !== '') {
            $actions[] = ['type' => 'redirect', 'url' => 'reload', 'target' => $table];
        }

        return $this->done($message, $actions, $extra);
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
     * **Order matters:** close the modal before reloading the table · swap the
     * order and the table gets redrawn underneath a modal that's still open,
     * where the user can't see the change happen anyway
     *
     * @param string $table the `data-table` name to reload · empty = reload nothing
     * @param array<string,mixed> $extra
     */
    protected function saved(string $message, string $table = '', array $extra = [], int $status = 200): Response
    {
        $actions = [
            ['type' => 'modal', 'action' => 'close'],
            ['type' => 'notification', 'level' => 'success', 'message' => $message],
        ];

        if ($table !== '') {
            $actions[] = ['type' => 'redirect', 'url' => 'reload', 'target' => $table];
        }

        return $this->done($message, $actions, $extra, $status);
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
            ['type' => 'redirect', 'url' => 'reload', 'target' => $target]
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
            array_map(fn (string $text): string => $this->t($text), $fields),
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

        usort($versions, static function (array $a, array $b): int {
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
