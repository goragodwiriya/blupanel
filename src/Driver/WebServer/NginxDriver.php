<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\Template;

/**
 * nginx — the second web server option (ARCHITECTURE §10)
 *
 * Deliberately structured identically to ApacheDriver: one file per
 * website, named with a `phpcp-` prefix, a shared config section built once
 * and used by both the HTTP and HTTPS blocks, and configtest always
 * required to pass before a reload — someone reading these two files
 * side by side should see the same thing written in two languages, not two
 * separate systems that each need learning on their own.
 *
 * The important differences from Apache:
 *   - No runtime enable/disable module system — everything is already
 *     compiled in, so ensureModules() just returns []
 *   - No .htaccess — a site that depends on .htaccess won't behave the same
 *     way, and its rules have to be translated by hand
 *   - Has to guard against fastcgi's forged path info itself (see the explanation in the template)
 */
final class NginxDriver implements WebServerDriver
{
    private const CONFIG_ROOT = '/etc/nginx';
    private const SITES_DIR = self::CONFIG_ROOT . '/conf.d';

    /** A machine-level file, not belonging to any one website — same name across every mode */
    public const LOCALHOST_FILE = self::SITES_DIR . '/phpcp-000-localhost.conf';

    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    /** Matches an ordinary site's default — the dev folder has no upload quota configured */
    private const UPLOAD_LIMIT_MB = 64;

    public function __construct(
        private readonly Template $templates,
        /** null = don't enable http://localhost (the default for a real production machine) */
        private readonly ?LocalhostSite $localhost = null,
    ) {
    }

    public function name(): string
    {
        return 'nginx';
    }

    public function unit(): string
    {
        return 'nginx';
    }

    public function runAsUser(): string
    {
        return 'www-data';
    }

    public function runAsGroup(): string
    {
        return 'www-data';
    }

    /**
     * nginx binds its modules in at compile time — nothing to turn on at runtime
     *
     * If a machine is running an nginx that wasn't compiled with
     * ngx_http_ssl_module, that surfaces at configtest — a safe failure,
     * since ConfigTransaction already restores the original file.
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        return [];
    }

    public function vhostPath(Site $site): string
    {
        // The filename comes from a domain that already passed
        // Validator::domain(), so it only ever contains [a-z0-9.-]
        //
        // **A wildcard-accepting site's vhost must always be read last**
        // (PLAN-V2 phase E7) — nginx reads files in alphabetical order, and
        // if `*.example.com` gets read before a vhost that fully specifies
        // `blog.example.com`, a request for blog falls through to the
        // wildcard site instead — a cross-site leak between two different customers.
        //
        // The `zz-` prefix always pushes it to the end without relying on
        // any coincidence of domain naming · `$site->domain` is still the
        // genuine, already-validated name, with no `*` mixed in.
        return self::SITES_DIR . '/phpcp-' . ($site->hasWildcard() ? 'zz-wildcard-' : '') . $site->domain . '.conf';
    }

    /** @return list<string> */
    public function vhostPaths(Site $site): array
    {
        return [$this->vhostPath($site)];
    }

    /** @return array<string,string> */
    /**
     * Deliberately never touches Apache's `ports.conf` — this mode doesn't
     * use Apache at all, and nginx holds port 80 · writing Apache back to
     * listen on 80 would only make Apache (if it's still enabled) fail to start due to a port collision.
     *
     * @return array<string,string>
     */
    public function globalFiles(Executor $executor): array
    {
        if ($this->localhost === null) {
            return [];
        }

        return [
            self::LOCALHOST_FILE => $this->templates->render('nginx/localhost-standalone.conf.tpl', [
                'HTTP_PORT' => self::HTTP_PORT,
                'DOCROOT' => $executor->path($this->localhost->docroot),
                'FPM_SOCKET' => $executor->path($this->localhost->fpmSocket()),
                'ERROR_LOG' => $executor->path($this->localhost->errorLog()),
                'ACCESS_LOG' => $executor->path($this->localhost->accessLog()),
                'UPLOAD_LIMIT' => self::UPLOAD_LIMIT_MB
            ])
        ];
    }

    public function vhostFiles(Site $site, Executor $executor): array
    {
        return $this->globalFiles($executor) + [$this->vhostPath($site) => $this->renderVhost($site, $executor)];
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

        if (!$site->isActive()) {
            return $this->templates->render('nginx/vhost-suspended.conf.tpl', [
                'DOMAIN' => $site->domain,
                'SERVER_ALIASES' => $aliases,
                'SUSPENDED_PAGE' => $executor->path($site->suspendedPage()),
                'ERROR_LOG' => $executor->path($site->errorLog()),
                'ACCESS_LOG' => $executor->path($site->accessLog()),
                'HTTP_PORT' => self::HTTP_PORT,
            ]);
        }

        $body = new SafeBlock($this->templates->render('nginx/vhost-body.conf.tpl', [
            'PROBE_DENY' => new SafeBlock(ProbeBlocklist::nginx()),
            // The admin's own directory — read last by the vhost, so its values win over the defaults
            'CUSTOM_DIR' => $executor->path(CustomConfig::siteDirectory('nginx', $site->domain)),
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
            'UPLOAD_LIMIT' => $site->uploadLimitMb,
        ]));

        $common = [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_BODY' => $body,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'HTTP_PORT' => self::HTTP_PORT,
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ];

        if ($site->sslMode === 'off') {
            return $this->templates->render('nginx/vhost.conf.tpl', $common);
        }

        $certificate = $this->certificatePath($site, $executor);

        return $this->templates->render('nginx/vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'DOCROOT' => $executor->path($site->docroot()),
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'Forced HTTPS' : 'Enabled',
            'HTTP_SECTION' => $this->httpSection($site, $body),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /** The HTTP block differs by mode — forced HTTPS has to redirect everything that isn't acme */
    private function httpSection(Site $site, SafeBlock $body): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $body;
        }

        // The acme location ^~ above already takes priority over this location /,
        // so there's no need to write a duplicate exception the way Apache requires
        return new SafeBlock(
            "\n    # Forced HTTPS — Let's Encrypt's validation path above is already matched first via ^~\n"
            . "    location / {\n"
            . "        return 301 https://\$host\$request_uri;\n"
            . '    }',
        );
    }

    /**
     * HSTS is only ever added when HTTPS is forced — same reasoning as ApacheDriver
     */
    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        return new SafeBlock(
            "\n    add_header Strict-Transport-Security \"max-age=15768000\" always;",
        );
    }

    /** The genuinely-used certificate's location — a Let's Encrypt certificate wins over a self-signed one */
    public function certificatePath(Site $site, Executor $executor): string
    {
        $selfSigned = CertbotManager::SELF_SIGNED_DIR . '/' . $site->domain;

        if (!$executor->exists($executor->path(CertbotManager::LIVE_DIR . '/' . $site->domain . '/fullchain.pem'))
            && $executor->exists($executor->path($selfSigned . '/fullchain.pem'))) {
            return $selfSigned;
        }

        return CertbotManager::LIVE_DIR . '/' . $site->domain;
    }

    /**
     * Validates the whole config with the real nginx binary
     *
     * Picks its command based on which config tree is being validated, the
     * same as ApacheDriver: the system's own tree uses a bare `nginx -t`,
     * while any other tree (sandbox) has to point explicitly at the main config file.
     */
    public function testConfig(Executor $executor): array
    {
        $root = $executor->path(self::CONFIG_ROOT);

        $argv = $root === self::CONFIG_ROOT
            ? ['/usr/sbin/nginx', '-t']
            : ['/usr/sbin/nginx', '-t', '-p', $root, '-c', $root . '/nginx.conf'];

        $result = $executor->exec($argv, timeout: 20);

        // nginx -t writes its result to stderr even when it passes ("syntax is ok")
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /** The exit code is always checked — a reload that fails silently leaves new config with no effect, with nobody knowing */
    public function reload(Executor $executor): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $this->unit()], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "The configuration was written successfully, but reloading %s failed — the new configuration will have no effect until the reload succeeds\n\n%s",
                $this->unit(),
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->exists($executor->path(self::CONFIG_ROOT));
    }

    /** The directory holding vhosts — used when scaffolding for the first time */
    public static function sitesDir(): string
    {
        return self::SITES_DIR;
    }

    public static function configRoot(): string
    {
        return self::CONFIG_ROOT;
    }
}
