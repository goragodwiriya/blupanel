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
 * Apache 2.4 — matches the web server on the target machine
 *
 * vhost files are written to /etc/apache2/sites-enabled/, every filename
 * starting with phpcp-, so it's clearly visible which files the panel
 * created, and so it never overwrites a vhost an admin wrote by hand beforehand.
 */
final class ApacheDriver implements WebServerDriver
{
    private const CONFIG_ROOT = '/etc/apache2';
    private const SITES_DIR = self::CONFIG_ROOT . '/sites-enabled';
    private const PORTS_FILE = self::CONFIG_ROOT . '/ports.conf';

    /**
     * Named with a `phpcp-000-` prefix so it's read before any real
     * website's vhost — has no effect on which vhost is chosen (Apache
     * chooses from ServerName), but keeps machine-level files grouped at
     * the top when browsing the directory.
     */
    public const LOCALHOST_FILE = self::SITES_DIR . '/phpcp-000-localhost.conf';

    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    public function __construct(
        private readonly Template $templates,
        /** null = don't enable http://localhost (the default for a real production machine) */
        private readonly ?LocalhostSite $localhost = null,
    ) {
    }

    public function name(): string
    {
        return 'apache';
    }

    public function unit(): string
    {
        return 'apache2';
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
     * The modules the system's generated vhost files "must" have, or the config fails to pass at all
     *
     * Deliberately not wrapped in <IfModule>, even though that would be
     * easier — because <IfModule> silently skips a missing directive,
     * which for HSTS means a site configured for "force HTTPS" would
     * never actually get HSTS with nobody knowing · letting configtest
     * fail and having ConfigTransaction restore the previous state is the safer failure.
     */
    private const REQUIRED_MODULES = ['headers', 'rewrite', 'proxy', 'proxy_fcgi'];

    /** Added when any website turns on HTTPS */
    private const SSL_MODULES = ['ssl'];

    /**
     * Turns on the required modules if they aren't on yet
     *
     * The panel already owns this Apache stack — turning on a module its
     * own config needs isn't interfering with an admin's setup, it's making
     * what it just wrote actually work.
     *
     * @return list<string> the modules just turned on in this pass
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        $needed = $withSsl ? [...self::REQUIRED_MODULES, ...self::SSL_MODULES] : self::REQUIRED_MODULES;
        $enabledDir = $executor->path(self::CONFIG_ROOT . '/mods-enabled');
        $enabled = [];

        foreach ($needed as $module) {
            if ($executor->exists($enabledDir . '/' . $module . '.load')) {
                continue;
            }

            // a2enmod only exists on Debian/Ubuntu, which is what v1 supports
            $result = $executor->exec([$executor->path('/usr/sbin/a2enmod'), '-q', $module], timeout: 30);

            if ($result->ok()) {
                $enabled[] = $module;
            }
        }

        return $enabled;
    }

    public function vhostPath(Site $site): string
    {
        // The filename comes from a domain that already passed
        // Validator::domain(), so it only ever contains [a-z0-9.-]
        //
        // **A wildcard-accepting site's vhost must always be read last**
        // (PLAN-V2 phase E7) — Apache reads files in alphabetical order,
        // and if `*.example.com` gets read before a vhost that fully
        // specifies `blog.example.com`, a request for blog falls through
        // to the wildcard site instead — a cross-site leak between two different customers.
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

    /**
     * A file the whole machine shares — the ports Apache listens on
     *
     * **Must always be rewritten, never only when switching modes**,
     * because nginx-proxy mode overwrites this same file to make Apache
     * fall back to listening on 127.0.0.1:8080 · a machine that once went
     * through that mode and switches back would be left with every vhost
     * declaring *:80 while nobody is actually listening on port 80.
     *
     * Genuinely found on a dev machine (2026-08-12): http://localhost
     * vanished for the whole machine, even though both apache2 and nginx
     * were up with no error anywhere — nginx had no vhost (already cleaned
     * up during the mode switch), while Apache was still stuck on 8080.
     *
     * @return array<string,string>
     */
    public function globalFiles(Executor $executor): array
    {
        $files = [
            self::PORTS_FILE => $this->templates->render('apache/standalone-ports.conf.tpl', [
                'HTTP_PORT' => self::HTTP_PORT,
                'HTTPS_PORT' => self::HTTPS_PORT
            ])
        ];

        if ($this->localhost !== null) {
            $files[self::LOCALHOST_FILE] = $this->renderLocalhost($executor, '*:' . self::HTTP_PORT);
        }

        return $files;
    }

    /**
     * The http://localhost vhost — also borrowed by nginx-proxy mode to serve as its backend layer
     *
     * @param string $listen the address this vhost binds (`*:80` or `127.0.0.1:8080`)
     */
    public function renderLocalhost(Executor $executor, string $listen): string
    {
        if ($this->localhost === null) {
            return '';
        }

        return $this->templates->render('apache/localhost.conf.tpl', [
            'LISTEN' => $listen,
            'DOCROOT' => $executor->path($this->localhost->docroot),
            'FPM_SOCKET' => $executor->path($this->localhost->fpmSocket()),
            'ERROR_LOG' => $executor->path($this->localhost->errorLog()),
            'ACCESS_LOG' => $executor->path($this->localhost->accessLog())
        ]);
    }

    /** @return array<string,string> */
    public function vhostFiles(Site $site, Executor $executor): array
    {
        return $this->globalFiles($executor) + [$this->vhostPath($site) => $this->renderVhost($site, $executor)];
    }

    public function renderVhost(Site $site, Executor $executor): string
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
                'HTTP_PORT' => self::HTTP_PORT,
            ]);
        }

        // The vhost's shared section is built once and used by both the :80
        // and :443 blocks. Letting the SSL template copy these rules on its
        // own would mean one day the rule blocking .env or .git files gets
        // edited in one place and forgotten in the other, turning into a
        // vulnerability that only exists over HTTPS
        $body = new SafeBlock($this->templates->render('apache/vhost-body.conf.tpl', [
            'PROBE_DENY' => new SafeBlock(ProbeBlocklist::apache()),
            // The admin's own directory — read last by the vhost, so its values win over the defaults
            'CUSTOM_DIR' => $executor->path(CustomConfig::siteDirectory('apache', $site->domain)),
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
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
            return $this->templates->render('apache/vhost.conf.tpl', $common);
        }

        $certificate = $this->certificatePath($site, $executor);

        return $this->templates->render('apache/vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'DOCROOT' => $executor->path($site->docroot()),
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'Forced HTTPS' : 'Enabled',
            'HTTP_SECTION' => $this->httpSection($site, $body),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /**
     * The :80 block differs by mode — forced HTTPS has to redirect everything that isn't acme
     */
    private function httpSection(Site $site, SafeBlock $body): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $body;
        }

        return new SafeBlock(
            "\n    # Forced HTTPS — except Let's Encrypt's validation path above\n"
            . "    RewriteEngine On\n"
            . "    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/\n"
            . '    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]',
        );
    }

    /**
     * HSTS is only ever added when HTTPS is forced
     *
     * A browser remembers this header and refuses that domain's HTTP for
     * the given duration · adding it while both are still allowed, then
     * having an admin later change their mind and turn SSL off, would leave
     * a returning visitor unable to reach the site at all until it expires — something a server can't fix on its own.
     */
    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        // 6 months, no preload — preload is hard to withdraw and requires submitting to a central list
        return new SafeBlock(
            "\n    Header always set Strict-Transport-Security \"max-age=15768000\"",
        );
    }

    /**
     * The genuinely-used certificate's location — a Let's Encrypt certificate wins over a self-signed one
     *
     * If neither exists, this still returns Let's Encrypt's path, and lets
     * `apache2 -t` be the one to say the file doesn't exist — better than
     * silently writing a config that points elsewhere, since
     * ConfigTransaction rolls back on its own when configtest fails.
     */
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
     * Validates the whole config — the point that stops a bad vhost from taking down every site on the machine
     *
     * Picks its command based on "which config tree is being validated",
     * not which mode is running:
     *   - the system's own tree → apache2ctl -t, which sources
     *     /etc/apache2/envvars first (necessary on Debian/Ubuntu, since
     *     apache2.conf references ${APACHE_LOG_DIR})
     *   - any other tree (sandbox) → apache2 -d <root> -f
     *     <root>/apache2.conf -t, which already uses a fully self-contained config, needing no envvars
     *
     * Both paths run the real apache binary — the part that breaks most
     * often is therefore validated with the genuine article, even in test mode (ARCHITECTURE §6.3).
     */
    public function testConfig(Executor $executor): array
    {
        $root = $executor->path(self::CONFIG_ROOT);

        $argv = $root === self::CONFIG_ROOT
            ? ['/usr/sbin/apache2ctl', '-t']
            : ['/usr/sbin/apache2', '-d', $root, '-f', $root . '/apache2.conf', '-t'];

        $result = $executor->exec($argv, timeout: 20);

        // apache2 -t writes its result to stderr even when it passes ("Syntax OK")
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
