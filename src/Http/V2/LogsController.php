<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Log viewer — `/api/v2/logs`
 *
 * A caller picks **a "source" from a list**, never names a file path directly — the
 * real paths live only in `LogCatalog` on the server. This is why the API can't read
 * any file on the machine no matter what's attempted: what's sent is a key, not a path.
 *
 * The list holds both machine-level logs and each website's own logs. Both sets are
 * filtered by permission and ownership before reaching the screen, then re-checked
 * again on the agent side.
 */
final class LogsController extends ApiController
{
    private const DEFAULT_LINES = 20;
    private const MIN_LINES = 10;
    private const MAX_LINES = 2000;

    /** Log sources the caller may read */
    public function sources(Request $request): Response
    {
        $sources = [];

        foreach ($this->availableSources() as $key => $source) {
            $sources[] = ['key' => $key] + $source;
        }

        return $this->ok($sources, [
            'levels' => LogCatalog::levels(),
            'default_lines' => self::DEFAULT_LINES,
            'max_lines' => self::MAX_LINES
        ]);
    }

    /** Reads the last lines of the chosen source, with text and level filtering */
    public function tail(Request $request): Response
    {
        $available = $this->availableSources();
        $source = $request->get('source');

        if ($source === '' && $available !== []) {
            $source = (string) array_key_first($available);
        }

        if (!isset($available[$source])) {
            // 403, not 404 — a log source key isn't a secret (it's in the docs). What
            // differs is permission, so the admin should know to go fix that, not the URL
            return $this->problem(ApiProblem::Forbidden, 'Unknown log source, or you may not read it');
        }

        $lines = max(self::MIN_LINES, min(self::MAX_LINES, $request->queryInt('per_page', self::DEFAULT_LINES)));

        $data = $this->agent()->data('system.logs_tail', [
            'source' => $source,
            'lines' => $lines,
            'search' => $this->searchTerm($request),
            'level' => $request->get('level')
        ], $this->ctx->actor($request));

        // Pill colour comes from the server, so the template can write `pill-${level_tone}` directly
        $data['lines'] = array_map(static function (array $line): array {
            $level = (string) ($line['level'] ?? '');

            $line['level_tone'] = match ($level) {
                'error' => 'danger',
                'warn' => 'warn',
                'ok' => 'ok',
                default => 'muted',
            };
            $line['level_label'] = $level === '' ? 'line' : $level;

            return $line;
        }, $data['lines'] ?? []);

        return $this->ok($data['lines'] ?? [], [
            'source' => $source,
            'page' => 1,
            'per_page' => $lines,
            'total' => $data['total'] ?? 0,
            'total_pages' => 1
        ]);
    }

    /**
     * Every source the caller may read — machine level first, then per-site
     *
     * **This is both the displayed list and the read-time allowlist** — the two have
     * to come from the same place, or one day a source stops showing up in the list
     * while still being callable directly.
     *
     * Machine level comes first on purpose: `tail()` uses the first entry as the
     * default when no source is specified, and that has to be a stable value, not
     * whichever customer's site happened to land first.
     *
     * @return array<string,array{label:string,group:string,format:string}>
     */
    private function availableSources(): array
    {
        $sources = [];

        foreach (LogCatalog::forRole($this->ctx->role()) as $key => $source) {
            $sources[$key] = [
                'label' => $this->t((string) ($source['label'] ?? $key)),
                'group' => $this->t((string) ($source['group'] ?? '')),
                'format' => $source['format'] ?? ''
            ];
        }

        if (!$this->ctx->can(LogCatalog::SITE_PERMISSION)) {
            return $sources;
        }

        // Anyone who doesn't see everyone's sites only sees their own — the same
        // filter LogTail re-checks on the agent side; done here too so the list never
        // shows something that would just be rejected on click
        $ownerId = Permissions::seesAllSites($this->ctx->role()) ? null : $this->ctx->userId();

        foreach ((new SiteRepository($this->app->db()))->listBrief($ownerId) as $site) {
            foreach (LogCatalog::siteKinds() as $kind => $meta) {
                $sources[LogCatalog::siteKey($site['id'], $kind)] = [
                    /*
                     * The owner's name lives in the **label**, not only in `group`,
                     * because the log page's `<select>` is still a flat list — a
                     * value in `group` is never rendered at all. The list is already
                     * sorted by owner, so a repeated prefix reads as a visible block,
                     * about as close to an optgroup as is possible right now.
                     */
                    'label' => $this->t('{owner} · {domain} · {kind}', [
                        'owner' => $site['owner'],
                        'domain' => $site['domain'],
                        'kind' => $this->t($meta['label']),
                    ]),
                    // Kept for a future screen that can group properly — the
                    // question this page answers is "what is this customer's site
                    // seeing", not "what is the whole machine seeing"
                    'group' => $this->t('Websites of {owner}', ['owner' => $site['owner']]),
                    'format' => $meta['format']
                ];
            }
        }

        return $sources;
    }
}
