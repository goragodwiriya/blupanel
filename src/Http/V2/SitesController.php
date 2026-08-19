<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\DomainResource;
use Phpcp\Http\Resource\SiteResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Websites — `/api/v2/sites` (PLAN-V2 §4.6)
 *
 * Every command that changes anything goes through the agent · this controller's
 * only job is turning HTTP requests into capability arguments and turning
 * results back into JSON — not one line of website create/delete logic lives here
 *
 * `AgentException` never needs catching: HttpKernel already converts it to the
 * right status code (ValidationError→422, PermissionDenied→403, TransportError→503)
 */
final class SitesController extends HostingController
{
    /** Fields sorting is allowed on — this value is appended to ORDER BY, so it must be an allowlist */
    private const SORTABLE = ['primary_domain', 'created_at', 'disk_used', 'php_version', 'status'];

    /**
     * The API's field name → the database's column name
     *
     * Only one entry, because this is the one place the API's unit (bytes) doesn't
     * match the stored unit (MB) — sorting gives the same result in either unit,
     * so the raw column can be sorted on directly
     */
    private const SORT_COLUMN = ['disk_used' => 'disk_used_mb'];

    /**
     * The website list — supports search, status filtering, sorting, and pagination per §4.5
     *
     * Filtering by owner is always done at the query level, never after the fact —
     * another customer's data must never even be read into memory in the first place
     */
    public function index(Request $request): Response
    {
        $rows = $this->sites()->listWithCounts($this->scopeOwner());

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => str_contains(mb_strtolower((string) $row['primary_domain']), $needle),
            ));
        }

        $status = $request->get('status');
        if (in_array($status, ['active', 'suspended'], true)) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === $status));
        }

        $phpVersion = $request->get('php_version');
        if ($phpVersion !== '') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['php_version'] === $phpVersion));
        }

        $sort = $this->sort($request, self::SORTABLE, 'primary_domain');
        $column = self::SORT_COLUMN[$sort['field']] ?? $sort['field'];
        usort($rows, static function (array $a, array $b) use ($sort, $column): int {
            $left = $a[$column] ?? '';
            $right = $b[$column] ?? '';
            $result = is_numeric($left) && is_numeric($right)
                ? $left <=> $right
                : strcasecmp((string) $left, (string) $right);

            return $sort['desc'] ? -$result : $result;
        });

        $page = $this->pagination($request);
        $total = count($rows);
        $slice = array_slice($rows, $page['offset'], $page['per_page']);

        return $this->paginate(SiteResource::collection($slice), $total, $page['page'], $page['per_page']);
    }

    /** Create a new website */
    public function store(Request $request): Response
    {
        $aliases = $request->payload('aliases', []);

        // Accepts either an array (JSON) or space/comma-separated text (forms and curl)
        if (is_string($aliases)) {
            $aliases = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $aliases) ?: [])));
        }

        $result = $this->agent()->data('site.create', [
            'domain' => trim($request->payloadString('domain')),
            'php_version' => $request->payloadString('php_version'),
            'aliases' => is_array($aliases) ? array_values($aliases) : [],
            'docroot' => trim($request->payloadString('docroot')),
            'pointer_root' => trim($request->payloadString('pointer_root')),
            // A customer can only create their own website — never allowed to name another owner
            'owner_user_id' => $this->ctx->role() === Permissions::WEBADMIN
                ? $this->ctx->userId()
                : (int) $request->payload('owner_user_id', 0)
        ], $this->ctx->actor($request));

        $siteId = (int) $result['site_id'];
        $site = $this->sites()->find($siteId);

        return $this->done(
            $this->t('Website {domain} created', ['domain' => (string) ($site['primary_domain'] ?? $result['domain'] ?? '')]),
            [
                ['type' => 'notification', 'level' => 'success',
                    'message' => $this->t('Website {domain} created', ['domain' => (string) ($site['primary_domain'] ?? '')])],
                ['type' => 'redirect', 'url' => '/app/sites', 'delay' => 800]
            ],
            ['site_id' => $siteId, 'domain' => (string) ($site['primary_domain'] ?? '')],
            201,
        )->withHeader('Location', '/api/v2/sites/'.$siteId);
    }

    /**
     * A single website's details, with all of its domains
     *
     * `id=0` is a special reserved value meaning "this website doesn't exist yet —
     * give me the defaults for creating one" instead of a 404 · this lets the
     * website-create form (site-create.html) load its data from the same endpoint
     * as the edit page (site.html) — no separate endpoint doing nearly the same
     * job just for the new-website case
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            $pointerRoots = [];
            foreach ($this->app->config->list('sites.pointer_roots') as $root) {
                $pointerRoots[] = ['value' => $root, 'text' => $root];
            }

            // Must always answer 200 even if the agent is down — this page only holds
            // form defaults, not a command that genuinely depends on the agent (see
            // SystemController::health) · only the PHP version choice is unavailable
            // for that moment, not the whole page
            $phpVersions = [];

            if ($this->agent()->isAvailable()) {
                foreach ($this->fetchPhpVersions($request)['versions'] as $version) {
                    $phpVersions[] = ['value' => $version['version'], 'text' => $version['version']];
                }
            }

            return $this->ok([
                // The owner select's default — an admin can change it, a customer can't,
                // because ownerOptions() returns only themselves as a choice
                'owner_user_id' => $this->ctx->userId(),
                'has_pointer_roots' => $pointerRoots !== [],
                'options' => [
                    'php_version' => $phpVersions,
                    'pointer_root' => $pointerRoots,
                    'owner_user_id' => $this->ownerOptions()
                ]
            ]);
        }

        $site = $this->findSite($id);

        if ($site === null) {
            return $this->siteNotFound();
        }

        $domains = $this->app->db()->all(
            'SELECT * FROM domains WHERE site_id = :id ORDER BY type, domain',
            ['id' => $site['id']],
        );

        return $this->ok(SiteResource::one($site) + [
            /*
             * Each domain states its own serving location — not left for the admin
             * to infer from the layout
             *
             * Two websites on the same account can live in different places (the
             * primary domain gets `public_html`, one added later gets a folder
             * named after itself), and a subdomain can point at a sub-path on top
             * of that · showing the account's overall shape can't answer the
             * question an admin actually asks, which is "where does this name
             * lead on disk"
             */
            'domains' => array_map(
                fn (array $row): array => DomainResource::one($row) + [
                    'docroot' => $this->domainDocroot($site, $row),
                ],
                $domains,
            ),
            'aliases' => $this->sites()->aliasesOf((int) $site['id']),
            // The screen's buttons ask `can[...]` from this response — never guessed from the role
            'can' => $this->can([
                'edit' => 'site.edit',
                'suspend' => 'site.suspend',
                'delete' => 'site.delete',
                'manage_domains' => 'domain.manage',
                // The extra config-file screen belongs to the machine's admin, not the website owner
                'edit_config' => 'settings.manage'
            ])
        ]);
    }

    /**
     * The choice of a new website's owner — the same shape as the php_version
     * choice, a list of {value, text} for the SPA's select to use directly
     *
     * An admin (any role other than webadmin) can hand a website to any hosting
     * account, so they see every role=webadmin account · a customer (webadmin)
     * can only create their own website — they see only their own name as a
     * choice, matching the same rule store() already enforces
     *
     * @return list<array{value:int,text:string}>
     */
    private function ownerOptions(): array
    {
        if ($this->ctx->role() === Permissions::WEBADMIN) {
            return [['value' => $this->ctx->userId(), 'text' => $this->ctx->displayName()]];
        }

        return array_map(
            static fn(array $user): array=> [
                'value' => (int) $user['id'],
                'text' => (string) $user['username']
            ],
            (new UserRepository($this->app->db()))->hostingAccounts(),
        );
    }

    /**
     * A partial edit
     *
     * Only `php_version` is supported right now, because it's the only website
     * field with a capability that actually backs it · renaming or changing
     * docroot has to wait for its own capability — this layer won't write the
     * database directly for convenience, because that skips layer 2 and leaves
     * that change out of the audit log
     */
    public function update(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $phpVersion = trim($request->payloadString('php_version'));

        if ($phpVersion === '') {
            return $this->problem(
                ApiProblem::ValidationError,
                'php_version is the only field that can be changed here',
                ['php_version' => 'The PHP version to switch to is required'],
            );
        }

        return $this->applyPhpVersion($request, (int) $site['id'], $phpVersion);
    }

    /** Change PHP version — replaces the whole value, so it's a PUT */
    public function setPhpVersion(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        return $this->applyPhpVersion($request, (int) $site['id'], trim($request->payloadString('php_version')));
    }

    /**
     * Suspend or resume a website
     *
     * A `PUT` on a noun resource (`suspension`) per §4.1, not `POST /suspend` — the
     * real benefit is that it's safe to repeat: a screen where a slow connection
     * causes a double-click doesn't get a strange result
     */
    public function setSuspension(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $suspended = $request->payload('suspended');

        if (!is_bool($suspended) && !in_array($suspended, ['1', '0', 'true', 'false', 1, 0], true)) {
            return $this->problem(
                ApiProblem::ValidationError,
                'The wanted status is required',
                ['suspended' => 'Must be true (suspend) or false (resume)'],
            );
        }

        $wantSuspended = in_array($suspended, [true, '1', 'true', 1], true);

        $result = $this->agent()->data(
            $wantSuspended ? 'site.suspend' : 'site.resume',
            ['site_id' => (int) $site['id']],
            $this->ctx->actor($request),
        );

        return $this->refreshed(
            (string) ($result['message'] ?? ($wantSuspended ? 'Website suspended' : 'Website resumed')),
            extra: ['site_id' => (int) $site['id'], 'suspended' => $wantSuspended],
        );
    }

    /**
     * Read the rate-limit setting, along with real status from fail2ban
     *
     * `status` comes from fail2ban itself, not the panel's database, because the
     * two can disagree: an admin might run `fail2ban-client` directly from the
     * command line, or fail2ban might have failed to load the jail because of a
     * bad file · the screen must state what's actually true on the machine, not
     * what we think was configured
     */
    public function rateLimit(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $row = $this->app->db()->first(
            'SELECT * FROM site_rate_limits WHERE site_id = :id',
            ['id' => (int) $site['id']],
        );

        // Never configured = send form defaults to prefill, not an empty value the user has to guess
        $data = [
            'site_id' => (int) $site['id'],
            'domain' => (string) $site['primary_domain'],
            'enabled' => (bool) ($row['enabled'] ?? false),
            'max_requests' => (int) ($row['max_requests'] ?? 300),
            'window_seconds' => (int) ($row['window_seconds'] ?? 60),
            'ban_seconds' => (int) ($row['ban_seconds'] ?? 600),
            'ignore_ips' => (string) ($row['ignore_ips'] ?? ''),
        ];

        // Only asks for real status when it's turned on — calling fail2ban-client every
        // time a website that doesn't use this feature opens its page is wasted work
        // that slows the page down for no reason
        if ($data['enabled']) {
            $result = $this->agent()->data(
                'site.rate_limit_status',
                ['site_id' => (int) $site['id']],
                $this->ctx->actor($request),
            );

            $data['status'] = $result['status'] ?? null;
            $data['banned_ips'] = $result['banned_ips'] ?? [];
        }

        return $this->ok($data, [
            // The trade-off an admin needs to know before turning this on — the screen shows it as a warning
            'ban_scope' => $this->t('the machine'),
            'notice' => $this->t('A ban applies to the whole machine, not just this website — a banned IP is also locked out of other websites on the same machine'),
        ]);
    }

    /**
     * The list of currently banned IPs — a separate endpoint because the SPA's
     * table needs `data` to be an array
     *
     * `GET /rate-limit` returns `data` as an object (the form's settings), which
     * `data-table` can't bind to · cramming both shapes into one endpoint and
     * letting the screen pick would mean writing page-specific JS, which not one
     * single page in this whole SPA has
     */
    public function rateLimitBans(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data(
            'site.rate_limit_status',
            ['site_id' => (int) $site['id']],
            $this->ctx->actor($request),
        );

        $rows = array_map(
            static fn (string $ip): array => ['ip' => $ip],
            is_array($result['banned_ips'] ?? null) ? $result['banned_ips'] : [],
        );

        return $this->ok($rows, [
            'total' => count($rows),
            'jail' => (string) ($result['jail'] ?? ''),
            'active' => (bool) ($result['status']['active'] ?? false),
        ]);
    }

    /** Turn the rate limit on, off, or adjust its settings */
    public function setRateLimit(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.rate_limit_set', [
            'site_id' => (int) $site['id'],
            'enabled' => $request->payload('enabled'),
            'max_requests' => $request->payload('max_requests'),
            'window_seconds' => $request->payload('window_seconds'),
            'ban_seconds' => $request->payload('ban_seconds'),
            'ignore_ips' => $request->payloadString('ignore_ips'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Rate limit saved'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * Unban one IP
     *
     * Needed because a ban is issued at the firewall, which knows nothing about
     * vhosts — an admin who tests their own website a bit too hard and gets
     * banned would be locked out of the panel entirely with no other way to lift it
     */
    public function unbanIp(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.rate_limit_unban', [
            'site_id' => (int) $site['id'],
            'ip' => $request->payloadString('ip'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Ban removed'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * Delete a website — always requires confirming with the domain name
     *
     * The domain name is accepted via either body or query, because some clients
     * still strip the body from a `DELETE` — forcing only one way to send it would
     * make deleting via curl impossible on some machines
     */
    public function destroy(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $confirm = trim($request->payloadString('confirm_domain')) ?: trim($request->get('confirm_domain'));

        $this->agent()->data('site.delete', [
            'site_id' => (int) $site['id'],
            'confirm_domain' => $confirm
        ], $this->ctx->actor($request));

        // Always goes back to the list page — deleting from the detail page and
        // staying there would get a 404 · deleting from the list page and going to
        // the same route just makes the table reload correctly
        return $this->completed(
            $this->t('Website {domain} deleted', ['domain' => (string) $site['primary_domain']]),
            'sites',
            ['site_id' => (int) $site['id']],
        );
    }

    /** Reset a website's file ownership back to correct */
    public function resetOwner(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.reset_owner', [
            'site_id' => (int) $site['id'],
            'fix_permissions' => (bool) $request->payload('fix_permissions', false)
        ], $this->ctx->actor($request));

        return $this->refreshed((string) ($result['message'] ?? 'File ownership reset'), extra: $result);
    }

    /** Every domain belonging to this website */
    public function domains(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $rows = $this->app->db()->all(
            'SELECT * FROM domains WHERE site_id = :id ORDER BY type, domain',
            ['id' => $site['id']],
        );
        $filter = [
            'status' => [
                ['value' => 'active', 'text' => 'Active'],
                ['value' => 'suspended', 'text' => 'Suspended']
            ]
        ];

        return $this->ok(
            DomainResource::collection($rows),
            [],
            [],
            $filter
        );
    }

    /** Add a subdomain or alias to this website */
    /**
     * The empty shell of the add-domain form for this website, with the command to open its modal
     *
     * The form's destination depends on the website, so `form_action` is sent
     * ready to bind straight to the action attribute — the modal's template is
     * loaded later, so it never goes through RouterManager's `{id}` substitution
     * the way a page's own HTML does
     */
    public function domainForm(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        return $this->ok(
            [
                'form_action' => '/api/v2/sites/' . (int) $site['id'] . '/domains',
                'host' => '',
                'path' => '',
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'site-domain-form.html',
                'title' => '{LNG_Add domain}',
                'titleClass' => 'icon-link',
            ]],
        );
    }

    public function addDomain(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.add_domain', [
            'site_id' => (int) $site['id'],
            'host' => trim($request->payloadString('host')),
            'path' => trim($request->payloadString('path'))
        ], $this->ctx->actor($request));

        $domain = (string) ($result['domain'] ?? '');
        $row = $this->app->db()->first(
            'SELECT * FROM domains WHERE site_id = :s AND domain = :d',
            ['s' => $site['id'], 'd' => $domain],
        );

        /*
         * `sites` names the list page's table, but this form is opened from the
         * site's **own** page, where that table doesn't exist — the refresh
         * action reloads it when it's there and otherwise just signals the page,
         * whose `data-refresh-event` reloads the domain list bound to it · this
         * used to fall through to a full browser reload every single time
         */
        return $this->saved(
            $this->t('Domain {domain} added', ['domain' => $domain]),
            'sites,siteDomains',
            ['site_id' => (int) $site['id'], 'domain' => $domain],
            201,
        )->withHeader('Location', '/api/v2/sites/'.$site['id'].'/domains');
    }

    /**
     * Replace the whole domain list for a given type
     *
     * Used when editing the whole list from a single screen — the capability
     * works out on its own what needs adding or removing
     */
    public function setDomains(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $domains = $request->payload('domains', []);

        if (is_string($domains)) {
            $domains = array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $domains) ?: [])));
        }

        $result = $this->agent()->data('site.set_domains', [
            'site_id' => (int) $site['id'],
            'domains' => is_array($domains) ? array_values($domains) : [],
            'type' => $request->payloadString('type') === 'subdomain' ? 'subdomain' : 'alias'
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'Domain list saved'),
            extra: $result,
        );
    }

    /** Remove a subdomain or alias from a website */
    public function removeDomain(Request $request): Response
    {
        $site = $this->findSite($request->paramInt('id'));

        if ($site === null) {
            return $this->siteNotFound();
        }

        $this->agent()->data('site.remove_domain', [
            'site_id' => (int) $site['id'],
            // The domain name comes from the path, so it must be urldecoded first (dots and hyphens aren't encoded, but this guards against it anyway)
            'domain' => rawurldecode($request->param('domain'))
        ], $this->ctx->actor($request));

        return $this->refreshed($this->t('Domain {domain} removed', ['domain' => rawurldecode($request->param('domain'))]), 'sites');
    }

    /** Send the change-PHP-version command to the agent — shared between PATCH and PUT */
    private function applyPhpVersion(Request $request, int $siteId, string $phpVersion): Response
    {
        $result = $this->agent()->data('site.set_php', [
            'site_id' => $siteId,
            'php_version' => $phpVersion
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'PHP version changed'),
            extra: ['site_id' => $siteId, 'php_version' => $phpVersion],
        );
    }
    /**
     * Which directory this domain serves files from
     *
     * A subdomain bound to a sub-path (`redirect_target`) points there · everything
     * else uses the website's docroot, which comes from the owner's layout or from
     * a configured Domain Pointer
     *
     * Always computed from the real `Site`, never assembled by hand here — this is
     * the web layer, and re-assembling the path here is exactly how the screen and
     * the truth on disk start to disagree
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $domain
     */
    private function domainDocroot(array $site, array $domain): string
    {
        $target = trim((string) ($domain['redirect_target'] ?? ''));

        if (($domain['type'] ?? '') === 'subdomain' && $target !== '') {
            return $target;
        }

        $loaded = $this->sites()->load((int) $site['id']);

        return $loaded === null ? '' : $loaded->docroot();
    }
}