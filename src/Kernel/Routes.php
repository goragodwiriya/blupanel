<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

use Phpcp\Http\V2\AlertsController;
use Phpcp\Http\V2\BackupDestinationsController;
use Phpcp\Http\V2\BackupSchedulesController;
use Phpcp\Http\V2\BackupsController;
use Phpcp\Http\V2\BackupTargetsController;
use Phpcp\Http\V2\CertificatesController;
use Phpcp\Http\V2\ConfigFilesController;
use Phpcp\Http\V2\CronJobsController;
use Phpcp\Http\V2\DashboardController as V2DashboardController;
use Phpcp\Http\V2\DatabasesController;
use Phpcp\Http\V2\DomainsController;
use Phpcp\Http\V2\FilesController;
use Phpcp\Http\V2\FirewallController as V2FirewallController;
use Phpcp\Http\V2\LogsController;
use Phpcp\Http\V2\MailAliasesController;
use Phpcp\Http\V2\MailboxesController;
use Phpcp\Http\V2\MailQueueController;
use Phpcp\Http\V2\MetricsController as V2MetricsController;
use Phpcp\Http\V2\RollbacksController;
use Phpcp\Http\V2\SecurityController as V2SecurityController;
use Phpcp\Http\V2\ServicesController;
use Phpcp\Http\V2\SshConfigController;
use Phpcp\Http\V2\SettingsController as V2SettingsController;
use Phpcp\Http\V2\SystemController;
use Phpcp\Http\V2\UsersController;
use Phpcp\Http\V2\MeController;
use Phpcp\Http\V2\PhpMyAdminController;
use Phpcp\Http\V2\PhpVersionsController;
use Phpcp\Http\V2\ScheduledJobsController;
use Phpcp\Http\V2\SessionController;
use Phpcp\Http\V2\SitesController;
use Phpcp\Http\V2\SqliteController;
use Phpcp\Http\SpaController;

/**
 * The system-wide route table — the single place that answers "which URL needs which permission"
 *
 * permission = null means a public route, and it must be set deliberately.
 * Forgetting to set permission means the route is closed, not open (safe default).
 *
 * **Only one page file is left in the whole system** — the SPA shell at `/app` ·
 * everything else is pure `/api/v2/*`. The server-side HTML UI (92 routes) and the
 * v1 API (8 routes) were deleted wholesale on 2026-08-08 once the SPA was fully
 * working — the system hadn't gone live yet, so there was no need for a transition
 * period. Full reasoning is in PLAN-V2, under "Retiring the HTML UI".
 */
final class Routes
{
    /**
     * @return mixed
     */
    public static function build(): Router
    {
        $router = new Router();

        // Visiting the root domain must land in the app, not a 404 · public route
        // because someone not logged in still needs to be taken to the SPA's login page
        $router->add(new Route('GET', '/', SpaController::class, 'root', null, 'root'));

        // --- SPA (Now.js) — Phase C ---
        //
        // Every route here always sends the same shell file; the browser decides the
        // sub-route of the actual screen. Public on purpose: the shell carries no
        // user data at all (see SpaController for the full reasoning).
        //
        // The real files under /app (js, css, vendor, templates, lang) never reach
        // here — Apache serves them directly because they exist on disk, so
        // FallbackResource never runs for them.
        //
        // **A real `public/app/` directory must never exist** — Apache's
        // `FallbackResource` skips any URL pointing at a file or directory that
        // actually exists. If that directory existed, `mod_dir` would handle it
        // first and end in Apache's own 404, with PHP never seeing the request at
        // all. So the SPA's static files live at `public/assets/spa/` instead (see
        // `Paths::spa()`).
        //
        // The trailing-`/` forms exist because users routinely type `/app/` · `php
        // -S` and Apache handle a trailing slash differently, so both forms must be
        // accepted identically in both places.
        $router->add(new Route('GET', '/app', SpaController::class, 'shell', null, 'app.shell'));
        $router->add(new Route('GET', '/app/', SpaController::class, 'shell', null, 'app.shell.slash'));
        $router->add(new Route('GET', '/app/{page}', SpaController::class, 'shell', null, 'app.page'));
        $router->add(new Route('GET', '/app/{page}/', SpaController::class, 'shell', null, 'app.page.slash'));

        self::apiV2($router);

        return $router;
    }

    /**
     * REST API v2 — PLAN-V2 Phase B
     *
     * Purely additive — not a single existing route was touched, so the HTML UI
     * kept working throughout this phase. And if v2 ran into trouble, rolling back
     * meant deleting this one block.
     *
     * Every route here always answers JSON — HttpKernel and all seven middleware
     * check `Request::isApiV2()` and adapt the response shape themselves, so the
     * controller never has to know about it.
     */
    private static function apiV2(Router $router): void
    {
        // --- Session: callable without logging in (the SPA's bootstrap point) ---
        $router->add(new Route('GET', '/api/v2/session', SessionController::class, 'show', null, 'api.v2.session.show'));
        $router->add(new Route('POST', '/api/v2/session', SessionController::class, 'create', null, 'api.v2.session.create'));
        $router->add(new Route('POST', '/api/v2/session/2fa', SessionController::class, 'verifyTwoFactor', null, 'api.v2.session.2fa'));
        // Logging out needs no permission at all — whoever holds the cookie must
        // always be able to discard it
        $router->add(new Route('DELETE', '/api/v2/session', SessionController::class, 'destroy', null, 'api.v2.session.destroy'));

        // --- Dashboard: everything the home page needs, in one request ---
        $router->add(new Route('GET', '/api/v2/dashboard', V2DashboardController::class, 'index', 'dashboard.view', 'api.v2.dashboard'));

        // --- Own account ---
        $router->add(new Route('GET', '/api/v2/me', MeController::class, 'show', 'dashboard.view', 'api.v2.me.show'));
        $router->add(new Route('PATCH', '/api/v2/me/password', MeController::class, 'changePassword', 'dashboard.view', 'api.v2.me.password'));

        // --- Websites ---
        $router->add(new Route('GET', '/api/v2/sites', SitesController::class, 'index', 'site.view', 'api.v2.sites.index'));
        $router->add(new Route('POST', '/api/v2/sites', SitesController::class, 'store', 'site.create', 'api.v2.sites.store'));
        $router->add(new Route('GET', '/api/v2/sites/{id}', SitesController::class, 'show', 'site.view', 'api.v2.sites.show'));
        $router->add(new Route('PATCH', '/api/v2/sites/{id}', SitesController::class, 'update', 'site.edit', 'api.v2.sites.update'));
        $router->add(new Route('DELETE', '/api/v2/sites/{id}', SitesController::class, 'destroy', 'site.delete', 'api.v2.sites.destroy'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/php-version', SitesController::class, 'setPhpVersion', 'site.edit', 'api.v2.sites.php'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/suspension', SitesController::class, 'setSuspension', 'site.suspend', 'api.v2.sites.suspension'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/owner-reset', SitesController::class, 'resetOwner', 'site.edit', 'api.v2.sites.owner_reset'));

        // Per-site request rate limiting — enforced through fail2ban (Phase E5)
        $router->add(new Route('GET', '/api/v2/sites/{id}/rate-limit', SitesController::class, 'rateLimit', 'site.view', 'api.v2.sites.rate_limit'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/rate-limit', SitesController::class, 'setRateLimit', 'site.edit', 'api.v2.sites.rate_limit.set'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/rate-limit/bans', SitesController::class, 'rateLimitBans', 'site.view', 'api.v2.sites.rate_limit.bans'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/rate-limit/unban', SitesController::class, 'unbanIp', 'site.edit', 'api.v2.sites.rate_limit.unban'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/domains', SitesController::class, 'domains', 'domain.view', 'api.v2.sites.domains'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/domains/form', SitesController::class, 'domainForm', 'domain.manage', 'api.v2.sites.domains.form'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/domains', SitesController::class, 'addDomain', 'domain.manage', 'api.v2.sites.domains.add'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/domains', SitesController::class, 'setDomains', 'domain.manage', 'api.v2.sites.domains.set'));
        $router->add(new Route('DELETE', '/api/v2/sites/{id}/domains/{domain}', SitesController::class, 'removeDomain', 'domain.manage', 'api.v2.sites.domains.remove'));

        // --- Domains and DNS ---
        $router->add(new Route('GET', '/api/v2/domains', DomainsController::class, 'index', 'domain.view', 'api.v2.domains.index'));
        $router->add(new Route('POST', '/api/v2/domains', DomainsController::class, 'store', 'domain.manage', 'api.v2.domains.store'));
        $router->add(new Route('DELETE', '/api/v2/domains/{id}', DomainsController::class, 'destroy', 'domain.manage', 'api.v2.domains.destroy'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/dns-records', DomainsController::class, 'records', 'domain.view', 'api.v2.dns.index'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/dns-records/form', DomainsController::class, 'recordForm', 'domain.manage', 'api.v2.dns.form'));
        $router->add(new Route('POST', '/api/v2/domains/{id}/dns-records', DomainsController::class, 'addRecord', 'domain.manage', 'api.v2.dns.store'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/zone-file', DomainsController::class, 'zoneFile', 'domain.view', 'api.v2.domains.zone'));
        // Editing all zone records at once — same permission as adding/removing one
        // at a time, since it's the same job done in bulk · the submitted text isn't
        // written to disk directly, it's parsed back into database records and the
        // system writes the file itself as usual (see DnsZoneImport)
        $router->add(new Route('GET', '/api/v2/domains/{id}/zone-form', DomainsController::class, 'zoneForm', 'domain.manage', 'api.v2.domains.zone_form'));
        $router->add(new Route('PUT', '/api/v2/domains/{id}/zone-file', DomainsController::class, 'zoneImport', 'domain.manage', 'api.v2.domains.zone_import'));
        $router->add(new Route('DELETE', '/api/v2/dns-records/{id}', DomainsController::class, 'deleteRecord', 'domain.manage', 'api.v2.dns.destroy'));
        $router->add(new Route('POST', '/api/v2/dns/reload', DomainsController::class, 'reloadAll', 'dns.manage', 'api.v2.dns.reload'));

        // --- PHP ---
        $router->add(new Route('GET', '/api/v2/php-versions', PhpVersionsController::class, 'index', 'php.view', 'api.v2.php.index'));

        // --- Panel users · customers · settings ---
        // All user accounts — admins and hosting customers have shared one resource
        // since migration 0005
        //
        // The route-level permission is set to `customer.manage`, which sysadmin
        // holds, so managing customers keeps working as before · touching an
        // **admin** account needs `user.manage` on top of that, enforced by
        // `UsersController::assertMayManage()` and guarded by a test on every
        // method. That gate must never be weakened — otherwise a sysadmin could
        // reset a superadmin's password.
        $router->add(new Route('GET', '/api/v2/users', UsersController::class, 'index', 'user.view', 'api.v2.users.index'));
        $router->add(new Route('POST', '/api/v2/users', UsersController::class, 'store', 'customer.manage', 'api.v2.users.store'));
        $router->add(new Route('GET', '/api/v2/users/{id}', UsersController::class, 'show', 'user.view', 'api.v2.users.show'));
        $router->add(new Route('PATCH', '/api/v2/users/{id}', UsersController::class, 'update', 'customer.manage', 'api.v2.users.update'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}', UsersController::class, 'destroy', 'customer.manage', 'api.v2.users.destroy'));
        $router->add(new Route('PUT', '/api/v2/users/{id}/quota', UsersController::class, 'setQuota', 'customer.manage', 'api.v2.users.quota'));
        // A sub-resource kept separate from PATCH /users/{id} on purpose — changing
        // the layout **moves real files** and briefly takes that account's website
        // down, so it must be a deliberate click, not a side effect of saving a form
        $router->add(new Route('PUT', '/api/v2/users/{id}/layout', UsersController::class, 'setLayout', 'customer.manage', 'api.v2.users.layout'));
        $router->add(new Route('POST', '/api/v2/users/{id}/password-reset', UsersController::class, 'resetPassword', 'customer.manage', 'api.v2.users.password'));
        $router->add(new Route('POST', '/api/v2/users/{id}/sites', UsersController::class, 'attachSites', 'customer.manage', 'api.v2.users.sites.attach'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/sites/{site_id}', UsersController::class, 'detachSite', 'customer.manage', 'api.v2.users.sites.detach'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/two-factor', UsersController::class, 'disableTwoFactor', 'customer.manage', 'api.v2.users.2fa'));
        // A sub-resource per §4.1 — this account's "SFTP access" · PUT = set the
        // whole thing at once (enable + password)
        // The password-setting form opens in a Modal — a single field shouldn't
        // permanently take up space on the page
        $router->add(new Route('GET', '/api/v2/users/{id}/sftp/form', UsersController::class, 'sftpForm', 'customer.manage', 'api.v2.users.sftp.form'));
        $router->add(new Route('PUT', '/api/v2/users/{id}/sftp', UsersController::class, 'enableSftp', 'customer.manage', 'api.v2.users.sftp.enable'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/sftp', UsersController::class, 'disableSftp', 'customer.manage', 'api.v2.users.sftp.disable'));


        $router->add(new Route('GET', '/api/v2/settings', V2SettingsController::class, 'show', 'settings.view', 'api.v2.settings.show'));
        $router->add(new Route('PATCH', '/api/v2/settings', V2SettingsController::class, 'update', 'settings.manage', 'api.v2.settings.update'));
        $router->add(new Route('POST', '/api/v2/settings/notification-test', V2SettingsController::class, 'testNotification', 'settings.manage', 'api.v2.settings.notify_test'));
        $router->add(new Route('POST', '/api/v2/settings/mail-config', V2SettingsController::class, 'applyMail', 'settings.manage', 'api.v2.settings.mail_config'));
        $router->add(new Route('POST', '/api/v2/settings/mail-test', V2SettingsController::class, 'testMail', 'settings.manage', 'api.v2.settings.mail_test'));

        // Switching web server — a route kept separate from PATCH /settings because
        // it's not just saving a value, it rewrites every site's config file and
        // actually restarts the service, which takes several seconds
        // The panel's own management certificate — a different thing from
        // /certificates, which belongs to websites
        $router->add(new Route('POST', '/api/v2/settings/panel-certificate', V2SettingsController::class, 'applyPanelCertificate', 'settings.manage', 'api.v2.settings.panel_cert'));
        $router->add(new Route('POST', '/api/v2/settings/webserver', V2SettingsController::class, 'applyWebserver', 'settings.manage', 'api.v2.settings.webserver'));

        // The scheduler's heartbeat — read-only (Phase A1 item 7, left pending for Phase C)
        $router->add(new Route('GET', '/api/v2/scheduled-jobs', ScheduledJobsController::class, 'index', 'settings.view', 'api.v2.scheduled_jobs'));

        // Outstanding alert thresholds — read-only (Phase E6)
        $router->add(new Route('GET', '/api/v2/alerts', AlertsController::class, 'index', 'settings.view', 'api.v2.alerts'));

        // --- SERVER-side ---
        //
        // Every route in this block uses a permission from the SERVER category,
        // which webadmin holds none of per Permissions::forRole() — so customers
        // get a 403 on every route here automatically
        $router->add(new Route('GET', '/api/v2/services', ServicesController::class, 'index', 'service.view', 'api.v2.services.index'));
        $router->add(new Route('POST', '/api/v2/services/{unit}/actions', ServicesController::class, 'action', 'service.control', 'api.v2.services.action'));

        $router->add(new Route('GET', '/api/v2/firewall', V2FirewallController::class, 'index', 'firewall.view', 'api.v2.firewall.index'));
        // Must come before {number} — otherwise "form" gets read as a rule number
        $router->add(new Route('GET', '/api/v2/firewall/rules/form', V2FirewallController::class, 'form', 'firewall.manage', 'api.v2.firewall.rules.form'));
        $router->add(new Route('POST', '/api/v2/firewall/rules', V2FirewallController::class, 'addRule', 'firewall.manage', 'api.v2.firewall.rules.add'));
        $router->add(new Route('DELETE', '/api/v2/firewall/rules/{number}', V2FirewallController::class, 'deleteRule', 'firewall.manage', 'api.v2.firewall.rules.delete'));
        $router->add(new Route('PUT', '/api/v2/firewall/enabled', V2FirewallController::class, 'setEnabled', 'firewall.manage', 'api.v2.firewall.enabled'));

        $router->add(new Route('GET', '/api/v2/ssh-config', SshConfigController::class, 'show', 'ssh.view', 'api.v2.ssh.show'));
        $router->add(new Route('PATCH', '/api/v2/ssh-config', SshConfigController::class, 'update', 'ssh.manage', 'api.v2.ssh.update'));

        // Confirming/reverting uses security.view — whoever can see a pending item
        // must be able to confirm it, or you get "seeing a countdown but unable to
        // click anything", which is worse than not seeing it at all
        $router->add(new Route('GET', '/api/v2/rollbacks', RollbacksController::class, 'index', 'security.view', 'api.v2.rollbacks.index'));
        $router->add(new Route('POST', '/api/v2/rollbacks/{id}/confirmation', RollbacksController::class, 'confirm', 'security.view', 'api.v2.rollbacks.confirm'));
        $router->add(new Route('POST', '/api/v2/rollbacks/{id}/execution', RollbacksController::class, 'execute', 'security.view', 'api.v2.rollbacks.execute'));

        $router->add(new Route('GET', '/api/v2/logs/sources', LogsController::class, 'sources', 'log.view', 'api.v2.logs.sources'));
        $router->add(new Route('GET', '/api/v2/logs', LogsController::class, 'tail', 'log.view', 'api.v2.logs.tail'));

        $router->add(new Route('GET', '/api/v2/security/scan', V2SecurityController::class, 'scan', 'security.view', 'api.v2.security.scan'));

        /*
         * Login brute-force protection — read with security.view, changed with
         * security.manage.
         *
         * Unbanning takes security.manage too, same as changing settings, because it
         * reverses the effect of a security measure — it isn't just viewing.
         */
        $router->add(new Route('GET', '/api/v2/security/protection', V2SecurityController::class, 'protection', 'security.view', 'api.v2.security.protection'));
        $router->add(new Route('GET', '/api/v2/security/protection/bans', V2SecurityController::class, 'protectionBans', 'security.view', 'api.v2.security.protection_bans'));
        $router->add(new Route('PUT', '/api/v2/security/fail2ban', V2SecurityController::class, 'fail2banSet', 'security.manage', 'api.v2.security.fail2ban_set'));
        $router->add(new Route('GET', '/api/v2/security/panel-jail', V2SecurityController::class, 'panelJail', 'security.view', 'api.v2.security.panel_jail'));
        $router->add(new Route('PUT', '/api/v2/security/panel-jail', V2SecurityController::class, 'panelJailSet', 'security.manage', 'api.v2.security.panel_jail_set'));
        $router->add(new Route('PUT', '/api/v2/security/never-ban', V2SecurityController::class, 'neverBanSet', 'security.manage', 'api.v2.security.never_ban_set'));
        $router->add(new Route('POST', '/api/v2/security/panel-jail/unban', V2SecurityController::class, 'panelJailUnban', 'security.manage', 'api.v2.security.panel_jail_unban'));

        $router->add(new Route('GET', '/api/v2/metrics', V2MetricsController::class, 'index', 'dashboard.view', 'api.v2.metrics.index'));
        $router->add(new Route('GET', '/api/v2/metrics/stream', V2MetricsController::class, 'stream', 'dashboard.view', 'api.v2.metrics.stream'));
        $router->add(new Route('GET', '/api/v2/metrics/history', V2MetricsController::class, 'history', 'dashboard.view', 'api.v2.metrics.history'));

        $router->add(new Route('GET', '/api/v2/system/info', SystemController::class, 'info', 'server.view', 'api.v2.system.info'));
        // The hostname affects the whole machine (Postfix introduces itself with
        // this name · certificates reference this name), so it uses settings.manage
        // like other machine-level settings, not server.view, which is view-only
        $router->add(new Route('PUT', '/api/v2/system/hostname', SystemController::class, 'setHostname', 'settings.manage', 'api.v2.system.hostname'));
        // health uses dashboard.view because every role needs to know whether the
        // agent is down — a customer who clicks a button and nothing happens should
        // see why, not have to guess
        $router->add(new Route('GET', '/api/v2/system/health', SystemController::class, 'health', 'dashboard.view', 'api.v2.system.health'));

        // --- File manager ---
        //
        // Every route references root (a scope key) + path (a relative path) — no
        // absolute machine path ever appears in the API · reading uses file.view,
        // changes use file.manage
        $router->add(new Route('GET', '/api/v2/files/roots', FilesController::class, 'roots', 'file.view', 'api.v2.files.roots'));
        $router->add(new Route('GET', '/api/v2/files', FilesController::class, 'index', 'file.view', 'api.v2.files.index'));
        $router->add(new Route('GET', '/api/v2/files/tree', FilesController::class, 'tree', 'file.view', 'api.v2.files.tree'));
        $router->add(new Route('GET', '/api/v2/files/search', FilesController::class, 'search', 'file.view', 'api.v2.files.search'));
        $router->add(new Route('GET', '/api/v2/files/info', FilesController::class, 'info', 'file.view', 'api.v2.files.info'));
        $router->add(new Route('GET', '/api/v2/files/content', FilesController::class, 'read', 'file.view', 'api.v2.files.read'));
        $router->add(new Route('PUT', '/api/v2/files/content', FilesController::class, 'write', 'file.manage', 'api.v2.files.write'));
        $router->add(new Route('GET', '/api/v2/files/download', FilesController::class, 'download', 'file.view', 'api.v2.files.download'));
        $router->add(new Route('POST', '/api/v2/files/upload', FilesController::class, 'upload', 'file.manage', 'api.v2.files.upload'));
        $router->add(new Route('POST', '/api/v2/files/directories', FilesController::class, 'makeDirectory', 'file.manage', 'api.v2.files.mkdir'));
        $router->add(new Route('POST', '/api/v2/files/move', FilesController::class, 'move', 'file.manage', 'api.v2.files.move'));
        $router->add(new Route('POST', '/api/v2/files/copy', FilesController::class, 'copy', 'file.manage', 'api.v2.files.copy'));
        $router->add(new Route('DELETE', '/api/v2/files', FilesController::class, 'destroy', 'file.manage', 'api.v2.files.destroy'));
        $router->add(new Route('PUT', '/api/v2/files/permissions', FilesController::class, 'setPermissions', 'file.manage', 'api.v2.files.permissions'));
        $router->add(new Route('POST', '/api/v2/files/archives', FilesController::class, 'archive', 'file.manage', 'api.v2.files.archive'));
        $router->add(new Route('POST', '/api/v2/files/extractions', FilesController::class, 'extract', 'file.manage', 'api.v2.files.extract'));

        // --- SSL certificates (referenced by site id, since one site always = one certificate) ---
        $router->add(new Route('GET', '/api/v2/certificates', CertificatesController::class, 'index', 'ssl.view', 'api.v2.certificates.index'));
        $router->add(new Route('POST', '/api/v2/certificates', CertificatesController::class, 'store', 'ssl.manage', 'api.v2.certificates.store'));
        $router->add(new Route('POST', '/api/v2/certificates/{site_id}/renewal', CertificatesController::class, 'renew', 'ssl.manage', 'api.v2.certificates.renew'));
        $router->add(new Route('PUT', '/api/v2/certificates/{site_id}/mode', CertificatesController::class, 'setMode', 'ssl.manage', 'api.v2.certificates.mode'));
        $router->add(new Route('DELETE', '/api/v2/certificates/{site_id}', CertificatesController::class, 'destroy', 'ssl.manage', 'api.v2.certificates.destroy'));

        // --- Databases (referenced by name, since name is what MariaDB knows) ---
        $router->add(new Route('GET', '/api/v2/databases', DatabasesController::class, 'index', 'db.view', 'api.v2.databases.index'));
        $router->add(new Route('POST', '/api/v2/databases', DatabasesController::class, 'store', 'db.manage', 'api.v2.databases.store'));
        // The database-creation form — returns an empty scaffold with a modal-open
        // instruction for the page (same pattern as GET /cron-jobs/0), so the page
        // never needs to know the template's filename
        $router->add(new Route('GET', '/api/v2/databases/form', DatabasesController::class, 'form', 'db.manage', 'api.v2.databases.form'));
        $router->add(new Route('DELETE', '/api/v2/databases/{name}', DatabasesController::class, 'destroy', 'db.manage', 'api.v2.databases.destroy'));
        // POST, not GET, on purpose — this logs into phpMyAdmin, it isn't an ordinary link
        $router->add(new Route('POST', '/api/v2/phpmyadmin/session', PhpMyAdminController::class, 'create', 'db.view', 'api.v2.phpmyadmin.session'));
        $router->add(new Route('POST', '/api/v2/database-users/{user}/password', DatabasesController::class, 'resetPassword', 'db.manage', 'api.v2.database_users.password'));

        // --- Automated jobs ---
        $router->add(new Route('GET', '/api/v2/cron-jobs', CronJobsController::class, 'index', 'cron.view', 'api.v2.cron.index'));
        $router->add(new Route('POST', '/api/v2/cron-jobs', CronJobsController::class, 'store', 'cron.manage', 'api.v2.cron.store'));
        $router->add(new Route('GET', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'show', 'cron.view', 'api.v2.cron.show'));
        $router->add(new Route('PATCH', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'update', 'cron.manage', 'api.v2.cron.update'));
        $router->add(new Route('DELETE', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'destroy', 'cron.manage', 'api.v2.cron.destroy'));

        /*
         * System config files — a shared resource every screen can reach
         *
         * `settings.manage` only, not `site.edit`, because these files are read by
         * services shared across the whole machine — a bad write and every site
         * stops picking up new config along with it
         *
         * **The screen sends a key from the registry, never a file path** — see
         * ConfigFileCatalog
         */
        $router->add(new Route('GET', '/api/v2/config-files', ConfigFilesController::class, 'index', 'settings.manage', 'api.v2.config_files.index'));
        $router->add(new Route('GET', '/api/v2/config-files/{key}', ConfigFilesController::class, 'show', 'settings.manage', 'api.v2.config_files.show'));
        /*
         * **Writes go to the collection, not `/{key}`** — the form lives in a
         * Modal, and nothing fills `{key}` into the route for it (`RouterManager`
         * fills in `{id}` while the template is still the page's own string, not
         * the Modal's, which opens later) · so the key is sent in the request body
         * instead
         */
        $router->add(new Route('PUT', '/api/v2/config-files', ConfigFilesController::class, 'update', 'settings.manage', 'api.v2.config_files.update'));

        // --- Mail and backups ---
        // Mail hosting — mailboxes and forwarding addresses (PLAN-MAIL Phase M2)
        $router->add(new Route('GET', '/api/v2/mailboxes', MailboxesController::class, 'index', 'mail.view', 'api.v2.mailboxes.index'));
        // Must come before {id} — otherwise "form" gets read as a mailbox id
        $router->add(new Route('GET', '/api/v2/mail/readiness', MailboxesController::class, 'readiness', 'mail.view', 'api.v2.mail.readiness'));
        // The mail hostname's certificate belongs to the whole machine, so it's a
        // machine-admin permission, not `mail.manage`, which site owners hold to
        // manage their own domain's mailboxes
        $router->add(new Route('POST', '/api/v2/mail/certificate', MailboxesController::class, 'certificate', 'settings.manage', 'api.v2.mail.certificate'));

        /*
         * The outbound mail queue (PLAN-MAIL §5) — entirely `settings.manage`
         * because the queue belongs to the whole machine, with every customer's
         * sender/recipient addresses mixed together row by row
         *
         * **`/flush` must come before `{id}`** — otherwise "flush" gets read as a
         * mail id · and clearing the whole queue is a different route from
         * deleting a single message, not a special value of `{id}`
         */
        $router->add(new Route('GET', '/api/v2/mail/queue', MailQueueController::class, 'index', 'settings.manage', 'api.v2.mail.queue.index'));
        $router->add(new Route('POST', '/api/v2/mail/queue/flush', MailQueueController::class, 'flush', 'settings.manage', 'api.v2.mail.queue.flush'));
        $router->add(new Route('DELETE', '/api/v2/mail/queue', MailQueueController::class, 'destroyAll', 'settings.manage', 'api.v2.mail.queue.destroy_all'));
        $router->add(new Route('GET', '/api/v2/mail/queue/{id}', MailQueueController::class, 'show', 'settings.manage', 'api.v2.mail.queue.show'));
        $router->add(new Route('DELETE', '/api/v2/mail/queue/{id}', MailQueueController::class, 'destroy', 'settings.manage', 'api.v2.mail.queue.destroy'));
        $router->add(new Route('GET', '/api/v2/mailboxes/form', MailboxesController::class, 'form', 'mail.manage', 'api.v2.mailboxes.form'));
        $router->add(new Route('POST', '/api/v2/mailboxes', MailboxesController::class, 'store', 'mail.manage', 'api.v2.mailboxes.store'));
        $router->add(new Route('GET', '/api/v2/mailboxes/{id}', MailboxesController::class, 'show', 'mail.view', 'api.v2.mailboxes.show'));
        $router->add(new Route('PATCH', '/api/v2/mailboxes/{id}', MailboxesController::class, 'update', 'mail.manage', 'api.v2.mailboxes.update'));
        $router->add(new Route('DELETE', '/api/v2/mailboxes/{id}', MailboxesController::class, 'destroy', 'mail.manage', 'api.v2.mailboxes.destroy'));

        $router->add(new Route('GET', '/api/v2/mail-aliases', MailAliasesController::class, 'index', 'mail.view', 'api.v2.mail_aliases.index'));
        $router->add(new Route('GET', '/api/v2/mail-aliases/form', MailAliasesController::class, 'form', 'mail.manage', 'api.v2.mail_aliases.form'));
        $router->add(new Route('POST', '/api/v2/mail-aliases', MailAliasesController::class, 'store', 'mail.manage', 'api.v2.mail_aliases.store'));
        $router->add(new Route('DELETE', '/api/v2/mail-aliases/{id}', MailAliasesController::class, 'destroy', 'mail.manage', 'api.v2.mail_aliases.destroy'));

        $router->add(new Route('GET', '/api/v2/backups', BackupsController::class, 'index', 'backup.view', 'api.v2.backups.index'));
        // Must come before {id} — otherwise "form" gets read as a backup file id
        $router->add(new Route('GET', '/api/v2/backups/form', BackupsController::class, 'form', 'backup.offsite', 'api.v2.backups.form'));
        // Where backup files are stored on this machine — same reason /form must come before {id}
        $router->add(new Route('GET', '/api/v2/backups/storage', BackupsController::class, 'storage', 'backup.view', 'api.v2.backups.storage'));
        $router->add(new Route('POST', '/api/v2/backups', BackupsController::class, 'store', 'backup.offsite', 'api.v2.backups.store'));

        /*
         * Files are referenced by **account + filename**, not a row id
         *
         * Ever since the listing started reading straight from the real folder
         * (item B4), there's no row id left to reference · and that matches
         * reality more closely: what the user clicks delete on is the file they
         * see in their own folder, not a record the panel once wrote saying that
         * file existed
         */
        $router->add(new Route('DELETE', '/api/v2/backups/{user}/{file}', BackupsController::class, 'destroy', 'backup.manage', 'api.v2.backups.destroy'));
        // Restoring uses a permission separate from create/delete — it's a command that overwrites all current data
        $router->add(new Route('POST', '/api/v2/backups/{user}/{file}/restoration', BackupsController::class, 'restore', 'backup.restore', 'api.v2.backups.restore'));
        // An offsite copy of that backup file — a noun per §4.1, not the verb "push"
        $router->add(new Route('POST', '/api/v2/backups/{user}/{file}/offsite-copy', BackupsController::class, 'pushOffsite', 'backup.offsite', 'api.v2.backups.offsite'));

        // --- Backup destinations (Phase E1) — all SERVER-tier ---
        $router->add(new Route('GET', '/api/v2/backup-destinations', BackupDestinationsController::class, 'index', 'backup.offsite', 'api.v2.backup_destinations.index'));
        $router->add(new Route('POST', '/api/v2/backup-destinations', BackupDestinationsController::class, 'store', 'backup.offsite', 'api.v2.backup_destinations.store'));
        $router->add(new Route('GET', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'show', 'backup.offsite', 'api.v2.backup_destinations.show'));
        $router->add(new Route('PATCH', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'update', 'backup.offsite', 'api.v2.backup_destinations.update'));
        $router->add(new Route('DELETE', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'destroy', 'backup.offsite', 'api.v2.backup_destinations.destroy'));
        // The destination machine's host key — read from **this machine**, the one
        // that will actually send the files. Not bound to {id} because it's needed
        // while filling out a new destination's form that doesn't have an id yet
        $router->add(new Route('POST', '/api/v2/backup-destinations/host-key', BackupDestinationsController::class, 'hostKey', 'backup.offsite', 'api.v2.backup_destinations.host_key'));

        $router->add(new Route('POST', '/api/v2/backup-destinations/{id}/verification', BackupDestinationsController::class, 'verify', 'backup.offsite', 'api.v2.backup_destinations.verify'));

        /*
         * --- Automatic backup schedule — **one, for the whole machine** (item B10) ---
         *
         * Singular on purpose · the original design was CRUD over many schedules,
         * which meant an admin with fifty customers had to hand-maintain a hundred
         * schedules, and a newly created site got no backups at all until someone
         * remembered to add one · now "what gets backed up" lives in a per-account
         * switch (`/api/v2/backup-targets`), while this just answers "when does the
         * run happen"
         */
        $router->add(new Route('GET', '/api/v2/backup-schedule', BackupSchedulesController::class, 'show', 'backup.offsite', 'api.v2.backup_schedule.show'));
        $router->add(new Route('GET', '/api/v2/backup-schedule/form', BackupSchedulesController::class, 'form', 'backup.offsite', 'api.v2.backup_schedule.form'));
        $router->add(new Route('PATCH', '/api/v2/backup-schedule', BackupSchedulesController::class, 'update', 'backup.offsite', 'api.v2.backup_schedule.update'));
        // Run the cycle right now without waiting for cron — the admin needs to be
        // able to prove the configured settings actually work before the first
        // night, not find out only when a backup file should exist but doesn't
        $router->add(new Route('POST', '/api/v2/backup-schedule/runs', BackupSchedulesController::class, 'runNow', 'backup.offsite', 'api.v2.backup_schedule.run'));

        // --- Which accounts get backed up automatically (item B3) ---
        $router->add(new Route('GET', '/api/v2/backup-targets', BackupTargetsController::class, 'index', 'backup.offsite', 'api.v2.backup_targets.index'));
        $router->add(new Route('PATCH', '/api/v2/backup-targets/{id}', BackupTargetsController::class, 'update', 'backup.offsite', 'api.v2.backup_targets.update'));

        // --- SQLite database manager ---
        // All routes require sqlite.manage — only superadmin and sysadmin hold this
        $router->add(new Route('GET', '/api/v2/sqlite/info', SqliteController::class, 'info', 'sqlite.manage', 'api.v2.sqlite.info'));
        $router->add(new Route('GET', '/api/v2/sqlite/tables', SqliteController::class, 'tables', 'sqlite.manage', 'api.v2.sqlite.tables'));
        $router->add(new Route('GET', '/api/v2/sqlite/views', SqliteController::class, 'views', 'sqlite.manage', 'api.v2.sqlite.views'));
        $router->add(new Route('GET', '/api/v2/sqlite/indexes', SqliteController::class, 'indexes', 'sqlite.manage', 'api.v2.sqlite.indexes'));
        $router->add(new Route('GET', '/api/v2/sqlite/triggers', SqliteController::class, 'triggers', 'sqlite.manage', 'api.v2.sqlite.triggers'));
        // Cross-table search and custom query — "search" and "query" before {table}
        $router->add(new Route('GET', '/api/v2/sqlite/search', SqliteController::class, 'search', 'sqlite.manage', 'api.v2.sqlite.search'));
        $router->add(new Route('POST', '/api/v2/sqlite/query', SqliteController::class, 'query', 'sqlite.manage', 'api.v2.sqlite.query'));
        // Table-specific routes
        $router->add(new Route('GET', '/api/v2/sqlite/tables/{table}', SqliteController::class, 'tableSchema', 'sqlite.manage', 'api.v2.sqlite.table_schema'));
        $router->add(new Route('GET', '/api/v2/sqlite/tables/{table}/rows', SqliteController::class, 'browse', 'sqlite.manage', 'api.v2.sqlite.table_browse'));
        $router->add(new Route('GET', '/api/v2/sqlite/tables/{table}/count', SqliteController::class, 'rowCount', 'sqlite.manage', 'api.v2.sqlite.table_count'));
        $router->add(new Route('GET', '/api/v2/sqlite/tables/{table}/export', SqliteController::class, 'export', 'sqlite.manage', 'api.v2.sqlite.table_export'));
    }
}
