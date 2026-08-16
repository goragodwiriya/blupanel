<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Template;

/**
 * nginx as the front layer + Apache as the backend — the only mode where `.htaccess` genuinely works on nginx
 *
 * **Why this mode exists:** nginx doesn't support `.htaccess` and never
 * will — it reads config exactly once at start, with no per-directory
 * config mechanism at all · nearly every site migrated from another host
 * relies on `.htaccess` (WordPress, Laravel, folder-blocking rules,
 * redirects a customer set up themselves) — automatically translating
 * those rules into nginx config would mean taking text a customer wrote
 * and assembling it into a file root loads, a privilege-escalation path far too broad to accept.
 *
 * This mode solves it by translating nothing at all: Apache, which already
 * reads `.htaccess` as its own native feature, answers every request, while
 * nginx does what it's better at — terminating TLS, absorbing slow-network
 * clients instead of Apache, limiting body size, and being the single place rate limiting is enforced.
 *
 * ```
 *   visitor ──► nginx :80/:443 ──► Apache 127.0.0.1:8080 ──► PHP-FPM (customer's own uid)
 *              TLS ends here          .htaccess is read here
 * ```
 *
 * **What's different from pure Apache mode, and what to watch for:**
 *
 *   1. Apache no longer listens on 80/443 at all — `backend-ports.conf.tpl`
 *      overwrites `/etc/apache2/ports.conf` to listen on loopback only ·
 *      the original file is backed up by ConfigTransaction like every other file.
 *   2. A visitor's genuine address only ever reaches Apache through
 *      `X-Forwarded-For` + `mod_remoteip` — if this module is ever missing,
 *      logs show 127.0.0.1 for everything and fail2ban can never ban
 *      anyone, so it lives in REQUIRED_MODULES rather than wrapped in an IfModule.
 *   3. HTTPS is forced **at the front layer only** — if the backend also
 *      redirected, it would loop forever, since the request nginx sends it is always http.
 */
final class NginxProxyDriver implements WebServerDriver
{
    private const NGINX_ROOT = '/etc/nginx';
    private const NGINX_SITES_DIR = self::NGINX_ROOT . '/conf.d';
    private const NGINX_MAP_FILE = self::NGINX_SITES_DIR . '/00-phpcp-proxy.conf';

    /** The front layer's http://localhost — the backend shares the same file apache mode uses */
    private const NGINX_LOCALHOST_FILE = NginxDriver::LOCALHOST_FILE;

    /** Matches an ordinary site's default — the dev folder has no upload quota configured */
    private const LOCALHOST_UPLOAD_LIMIT_MB = 64;

    private const APACHE_ROOT = '/etc/apache2';
    private const APACHE_SITES_DIR = self::APACHE_ROOT . '/sites-enabled';
    private const APACHE_PORTS_FILE = self::APACHE_ROOT . '/ports.conf';

    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    /** The backend's port — bound to loopback only, never exposed outside the machine */
    public const BACKEND_PORT = 8080;
    public const BACKEND = '127.0.0.1:' . self::BACKEND_PORT;

    /**
     * `remoteip` isn't optional extra — see point 2 in the class description
     *
     * proxy/proxy_http aren't needed, since Apache is the one being proxied
     * here, never the one doing the proxying · but proxy_fcgi is still
     * required, since PHP still goes through the FPM socket exactly the same way.
     */
    private const REQUIRED_MODULES = ['headers', 'rewrite', 'proxy', 'proxy_fcgi', 'remoteip'];

    private readonly ApacheDriver $apache;

    /**
     * @param bool $staticByNginx let nginx serve static files itself — the value comes from `webserver.static_by_nginx`
     */
    public function __construct(
        private readonly Template $templates,
        private readonly bool $staticByNginx = true,
        /** null = don't enable http://localhost (the default for a real production machine) */
        private readonly ?LocalhostSite $localhost = null,
    ) {
        $this->apache = new ApacheDriver($templates, $localhost);
    }

    public function name(): string
    {
        return 'nginx-proxy';
    }

    /**
     * The unit that receives requests from the internet — a caller wanting the backend uses backendUnit() instead
     */
    public function unit(): string
    {
        return 'nginx';
    }

    public function backendUnit(): string
    {
        return 'apache2';
    }

    /**
     * A website's files must belong to the group **Apache** can read, never nginx
     *
     * nginx never touches a site's files at all in this mode (it forwards
     * every request) — setting it to nginx would leave Apache unable to
     * read a customer's files, and every site would answer 403.
     */
    public function runAsUser(): string
    {
        return $this->apache->runAsUser();
    }

    public function runAsGroup(): string
    {
        return $this->apache->runAsGroup();
    }

    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        $enabledDir = $executor->path(self::APACHE_ROOT . '/mods-enabled');
        $enabled = [];

        // The backend no longer needs mod_ssl at all — TLS terminates at nginx · false is always passed, deliberately
        foreach (self::REQUIRED_MODULES as $module) {
            if ($executor->exists($enabledDir . '/' . $module . '.load')) {
                continue;
            }

            $result = $executor->exec([$executor->path('/usr/sbin/a2enmod'), '-q', $module], timeout: 30);

            if ($result->ok()) {
                $enabled[] = $module;
            }
        }

        return $enabled;
    }

    /**
     * A file the whole machine shares, not belonging to any one website
     *
     * Returned as "path => content" for the caller to add into the same
     * ConfigTransaction as the vhost, so the configtest that follows checks
     * everything together, and rolls everything back together if it fails.
     *
     * @return array<string,string>
     */
    public function globalFiles(Executor $executor): array
    {
        $files = [
            self::NGINX_MAP_FILE => $this->templates->render('nginx/proxy-map.conf.tpl', []),
            self::APACHE_PORTS_FILE => $this->templates->render('apache/backend-ports.conf.tpl', [
                'BACKEND_PORT' => self::BACKEND_PORT,
                // NOTE: kept Thai — apache/backend-ports.conf.tpl's surrounding comment is still fully Thai (out of this src/ sweep's scope)
                'BACKUP_NOTE' => 'ถังพักของ ConfigTransaction ตอนเปลี่ยนโหมด',
            ]),
        ];

        // http://localhost needs the same two files as any other site in
        // this mode — the front layer at nginx and the backend at Apache ·
        // missing the backend file gets a 502, missing the front layer file means nobody answers at all
        if ($this->localhost !== null) {
            $files[self::NGINX_LOCALHOST_FILE] = $this->templates->render('nginx/localhost.conf.tpl', [
                'HTTP_PORT' => self::HTTP_PORT,
                'BACKEND' => self::BACKEND,
                'ERROR_LOG' => $executor->path($this->localhost->errorLog()),
                'ACCESS_LOG' => $executor->path($this->localhost->accessLog()),
                'UPLOAD_LIMIT' => self::LOCALHOST_UPLOAD_LIMIT_MB
            ]);
            $files[ApacheDriver::LOCALHOST_FILE] = $this->apache->renderLocalhost($executor, self::BACKEND);
        }

        return $files;
    }

    public function vhostPath(Site $site): string
    {
        return self::NGINX_SITES_DIR . '/phpcp-' . $this->prefix($site) . $site->domain . '.conf';
    }

    /** The backend's own vhost path — a different directory and a different shape than the front layer */
    public function backendVhostPath(Site $site): string
    {
        return self::APACHE_SITES_DIR . '/phpcp-' . $this->prefix($site) . $site->domain . '.conf';
    }

    /** @return list<string> */
    public function vhostPaths(Site $site): array
    {
        return [$this->vhostPath($site), $this->backendVhostPath($site)];
    }

    /**
     * This site's files, **plus this mode's shared files**
     *
     * The shared files are deliberately rewritten every time any site
     * changes at all — the content is fixed, so overwriting it has no side
     * effect, but it means a machine whose shared file went missing (an
     * admin deleted it, or an apache2 package upgrade replaced
     * ports.conf) self-corrects on the very next change, instead of
     * staying broken until someone notices · and the whole set passes the same configtest before reloading.
     *
     * Not included in vhostPaths(), because deleting one website must never delete a file the whole machine shares.
     *
     * @return array<string,string>
     */
    public function vhostFiles(Site $site, Executor $executor): array
    {
        return $this->globalFiles($executor) + [
            $this->vhostPath($site) => $this->renderVhost($site, $executor),
            $this->backendVhostPath($site) => $this->renderBackendVhost($site, $executor),
        ];
    }

    /**
     * A wildcard's vhost must be read last on both layers (PLAN-V2 phase E7)
     *
     * nginx picks a server block from server_name by specificity, so it
     * doesn't depend on filename at all, but Apache reads in alphabetical
     * order and the first match wins — the same prefix is used for both,
     * so nobody has to remember which layer needs what.
     */
    private function prefix(Site $site): string
    {
        return $site->hasWildcard() ? 'zz-wildcard-' : '';
    }

    public function renderVhost(Site $site, Executor $executor): string
    {
        // nginx puts every domain on a single server_name line, unlike Apache's ServerAlias
        $aliases = new SafeBlock(
            $site->aliases === [] ? '' : ' ' . implode(' ', array_map(
                static fn (string $d): string => Template::assertValue('server_name', $d),
                $site->aliases,
            )),
        );

        $common = [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'BACKEND' => self::BACKEND,
            'HTTP_PORT' => self::HTTP_PORT,
            'UPLOAD_LIMIT' => $site->uploadLimitMb,
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ];

        if ($site->sslMode === 'off') {
            return $this->templates->render('nginx/proxy-vhost.conf.tpl', $common + [
                'PROXY_BODY' => $this->proxyBody('http', $site, $executor),
            ]);
        }

        $certificate = $this->apache->certificatePath($site, $executor);

        return $this->templates->render('nginx/proxy-vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'Forced HTTPS' : 'Enabled',
            'PROXY_BODY' => $this->proxyBody('https', $site, $executor),
            'HTTP_SECTION' => $this->httpSection($site, $executor),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /**
     * The request-forwarding block, plus the section that lets nginx serve
     * static files itself when it's genuinely safe to
     *
     * The order of `location` blocks matters: a folder forced through
     * Apache uses `^~`, which always wins over a static file's regex
     * location, so it has to be declared first — swap the order, and a
     * static file inside a protected folder gets answered by nginx without ever passing through the customer's own rule.
     */
    private function proxyBody(string $scheme, Site $site, Executor $executor): SafeBlock
    {
        $scan = $this->staticPolicy($executor, $site);

        return new SafeBlock($this->templates->render('nginx/proxy-body.conf.tpl', [
            'BACKEND' => self::BACKEND,
            'PROBE_DENY' => new SafeBlock(ProbeBlocklist::nginx()),
            'SCHEME' => $scheme,
            'FORCE_PROXY_DIRS' => $this->forceProxyDirs($scan['proxy_dirs']),
            'STATIC_SECTION' => $scan['static_ok']
                ? new SafeBlock($this->templates->render('nginx/proxy-static.conf.tpl', [
                    'DOCROOT' => $executor->path($site->docroot()),
                ]))
                : new SafeBlock(
                    "\n    # This site lets Apache answer everything, static files included, because .htaccess at the site root\n"
                    . "    # has a rule nginx can't stand in for (access control or a response header change)\n"
                    . '    # — see Phpcp\\Driver\\WebServer\\HtaccessScan',
                ),
        ]));
    }

    /**
     * Should nginx serve static files itself, and which folders must be forced through Apache?
     *
     * @return array{static_ok:bool,proxy_dirs:list<string>}
     */
    private function staticPolicy(Executor $executor, Site $site): array
    {
        if (!$this->staticByNginx) {
            return ['static_ok' => false, 'proxy_dirs' => []];
        }

        return HtaccessScan::inspect($executor, $site->docroot());
    }

    /**
     * A `location` for a folder whose `.htaccess` has something nginx can't stand in for
     *
     * `^~` tells nginx "stop once this prefix matches, don't try a regex
     * location after this" — the one thing that stops a static file's
     * `location` from grabbing a request in this folder and answering it directly.
     *
     * @param list<string> $dirs
     */
    private function forceProxyDirs(array $dirs): SafeBlock
    {
        if ($dirs === []) {
            return new SafeBlock('');
        }

        $out = "\n    # Folders with an .htaccess rule nginx can't stand in for — every request must go through Apache\n";

        foreach ($dirs as $dir) {
            $out .= sprintf(
                // nginx's own variables must stay inside a single-quoted
                // string, or PHP substitutes them as empty, producing a
                // config that sends an empty Host to Apache — every request would fall through to the first vhost
                "    location ^~ %s {\n"
                . "        proxy_pass http://%s;\n"
                . '        proxy_set_header Host            $host;' . "\n"
                . '        proxy_set_header X-Real-IP       $remote_addr;' . "\n"
                . '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;' . "\n"
                . "    }\n",
                Template::assertValue('location', $dir),
                self::BACKEND,
            );
        }

        return new SafeBlock($out);
    }

    /**
     * The :80 block when HTTPS is forced — except for the acme path already declared above
     *
     * A `location /` here can't overlap with the same location twice, so
     * one or the other has to be chosen: forced mode = redirect · enabled-only mode = forward to the backend normally.
     */
    private function httpSection(Site $site, Executor $executor): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $this->proxyBody('http', $site, $executor);
        }

        return new SafeBlock(
            "\n    # Forced HTTPS — except Let's Encrypt's validation path above\n"
            . "    location / {\n"
            . "        return 301 https://\$host\$request_uri;\n"
            . '    }',
        );
    }

    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        // The reasoning for 6 months and never including preload is exactly the same as ApacheDriver
        return new SafeBlock(
            "\n    add_header Strict-Transport-Security \"max-age=15768000\" always;",
        );
    }

    /**
     * The backend's own vhost — uses the exact same body as pure apache mode
     *
     * Deliberately reused: the rule blocking `.env`/`.git` files,
     * `AllowOverride All`, and sending PHP into a customer's FPM pool all
     * have to be identical across every mode — if it were copied into a
     * separate file, one day it gets edited in one place and forgotten in
     * the other, becoming a vulnerability that only exists in some modes.
     */
    public function renderBackendVhost(Site $site, Executor $executor): string
    {
        $aliases = Template::lines('ServerAlias', $site->aliases);

        if (!$site->isActive()) {
            return $this->templates->render('apache/vhost-suspended.conf.tpl', [
                'DOMAIN' => $site->domain,
                'SERVER_ALIASES' => $aliases,
                'DOCROOT' => $executor->path($site->docroot()),
                'SUSPENDED_PAGE' => $executor->path($site->suspendedPage()),
                'ERROR_LOG' => $executor->path($site->errorLog()),
                'ACCESS_LOG' => $executor->path($site->accessLog()),
                'HTTP_PORT' => self::BACKEND,
            ]);
        }

        $body = new SafeBlock($this->templates->render('apache/vhost-body.conf.tpl', [
            // The admin's own directory — read last by the vhost, so its values win over the defaults
            'PROBE_DENY' => new SafeBlock(ProbeBlocklist::apache()),
            'CUSTOM_DIR' => $executor->path(CustomConfig::siteDirectory('apache', $site->domain)),
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
        ]));

        return $this->templates->render('apache/backend-vhost.conf.tpl', [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_BODY' => $body,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'BACKEND' => self::BACKEND,
            'BACKEND_PORT' => self::BACKEND_PORT,
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Must pass on **both layers** before this counts as passing at all
     *
     * Apache is validated first, since it's the layer genuinely serving
     * content · letting nginx pass and reload while the backend is broken
     * would leave every site answering 502, which looks like nginx's own problem.
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        [$apacheOk, $apacheOut] = $this->apache->testConfig($executor);

        if (!$apacheOk) {
            return [false, "The backend (Apache) failed validation:\n" . $apacheOut];
        }

        $result = $executor->exec([$executor->path('/usr/sbin/nginx'), '-t'], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        if (!$result->ok() && !self::isOnlyBindFailure($output)) {
            return [false, "The front layer (nginx) failed validation:\n" . $output];
        }

        return [true, trim($apacheOut . "\n" . $output)];
    }

    /**
     * Did `nginx -t` fail only because it couldn't bind a port, not because a file is wrong?
     *
     * **nginx's own config validation genuinely opens a listening socket**
     * — it doesn't just read syntax · while switching modes, Apache still
     * holds ports 80/443 until it's restarted, so validation fails with
     * EADDRINUSE even though nginx just said "syntax is ok" itself — if
     * that were treated as a failure, the transaction would roll back
     * every single time, and switching modes could never succeed even once.
     *
     * It's safe to let this pass through, since the genuine bind happens
     * at start time anyway, and if the port still can't be claimed at that
     * point, `ServiceAction` reports who's holding the port along with how
     * to fix it (not systemd's own raw message).
     *
     * The condition is deliberately strict: "syntax is ok" must be present,
     * and every emerg line must be about binding only — even a single
     * unrelated error still counts as a failure normally.
     */
    public static function isOnlyBindFailure(string $output): bool
    {
        if (!str_contains($output, 'syntax is ok')) {
            return false;
        }

        $emergencies = 0;

        foreach (explode("\n", $output) as $line) {
            if (!str_contains($line, '[emerg]')) {
                continue;
            }

            $emergencies++;

            if (!str_contains($line, 'bind() to') && !str_contains($line, 'still could not bind')) {
                return false;
            }
        }

        return $emergencies > 0;
    }

    /**
     * Always reloads the backend before the front layer
     *
     * While Apache is loading its new config, nginx, still holding the old
     * config, retries on its own · but reversing the order would have
     * nginx forward a request to a vhost that doesn't genuinely exist yet, answering 502 to a visitor.
     */
    public function reload(Executor $executor): void
    {
        $this->reloadUnit($executor, $this->backendUnit());
        $this->reloadUnit($executor, $this->unit());
    }

    private function reloadUnit(Executor $executor, string $unit): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $unit], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "The configuration was written successfully, but reloading %s failed — the new configuration will have no effect until the reload succeeds\n\n%s",
                $unit,
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    /** Both must be present — this mode cannot function at all if either one is missing */
    public function isInstalled(Executor $executor): bool
    {
        return $this->apache->isInstalled($executor)
            && $executor->exists($executor->path('/usr/sbin/nginx'));
    }
}
