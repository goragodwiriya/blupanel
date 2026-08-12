<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

use Phpcp\Http\V2\AlertsController;
use Phpcp\Http\V2\BackupDestinationsController;
use Phpcp\Http\V2\BackupSchedulesController;
use Phpcp\Http\V2\BackupsController;
use Phpcp\Http\V2\CertificatesController;
use Phpcp\Http\V2\CronJobsController;
use Phpcp\Http\V2\DashboardController as V2DashboardController;
use Phpcp\Http\V2\DatabasesController;
use Phpcp\Http\V2\DomainsController;
use Phpcp\Http\V2\FilesController;
use Phpcp\Http\V2\FirewallController as V2FirewallController;
use Phpcp\Http\V2\LogsController;
use Phpcp\Http\V2\MailAliasesController;
use Phpcp\Http\V2\MailboxesController;
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
use Phpcp\Http\SpaController;

/**
 * ตารางเส้นทางทั้งระบบ — จุดเดียวที่ตอบได้ว่า "URL ไหนต้องใช้สิทธิ์อะไร"
 *
 * permission = null หมายถึงเส้นทางสาธารณะ ต้องระบุอย่างจงใจเท่านั้น
 * ลืมใส่ permission = เส้นทางถูกปิด ไม่ใช่เปิด (ค่าเริ่มต้นปลอดภัย)
 *
 * **ทั้งระบบเหลือหน้าเว็บอยู่ไฟล์เดียว** — shell ของ SPA ที่ `/app` · ที่เหลือคือ
 * `/api/v2/*` ล้วน ๆ · UI แบบ HTML ฝั่งเซิร์ฟเวอร์ (92 เส้นทาง) กับ API v1 (8 เส้นทาง)
 * ถูกลบทิ้งทั้งชุดเมื่อ 2026-08-08 หลัง SPA ทำงานได้ครบ — ระบบยังไม่ขึ้นใช้จริง
 * จึงไม่ต้องมีช่วงเปลี่ยนผ่าน · เหตุผลเต็มอยู่ใน PLAN-V2 หัวข้อ "เลิกใช้ UI แบบ HTML"
 */
final class Routes
{
    /**
     * @return mixed
     */
    public static function build(): Router
    {
        $router = new Router();

        // เข้าเว็บที่รากโดเมนแล้วต้องไปโผล่ที่แอป ไม่ใช่ 404 · เป็นเส้นทางสาธารณะ
        // เพราะคนที่ยังไม่ล็อกอินก็ต้องถูกพาไปหน้าล็อกอินของ SPA ได้เหมือนกัน
        $router->add(new Route('GET', '/', SpaController::class, 'root', null, 'root'));

        // --- SPA (Now.js) — เฟส C ---
        //
        // ทุกเส้นทางที่นี่ส่งไฟล์ shell ตัวเดียวกันเสมอ เส้นทางย่อยของหน้าจอตัดสินฝั่งเบราว์เซอร์
        // สาธารณะโดยตั้งใจ: shell ไม่มีข้อมูลผู้ใช้เลย (ดูเหตุผลเต็มใน SpaController)
        //
        // ไฟล์จริงใต้ /app (js, css, vendor, templates, lang) ไม่มาถึงตรงนี้ —
        // Apache ส่งเองเพราะมีอยู่บนดิสก์ FallbackResource จึงไม่ทำงานกับมัน
        //
        // **ห้ามมีไดเรกทอรี `public/app/` อยู่จริง** — `FallbackResource` ของ Apache ข้าม
        // URL ที่ชี้ไปยังไฟล์หรือไดเรกทอรีที่มีอยู่จริง ถ้ามีไดเรกทอรีนั้น `mod_dir`
        // จะเข้ามาก่อน แล้วจบที่ 404 ของ Apache เองโดยที่ PHP ไม่เคยเห็นคำขอเลย
        // · ไฟล์นิ่งของ SPA จึงอยู่ที่ `public/assets/spa/` (ดู `Paths::spa()`)
        //
        // รูปที่ลงท้ายด้วย `/` มีไว้เพราะผู้ใช้พิมพ์ `/app/` กันเป็นปกติ · `php -S` กับ Apache
        // จัดการสแลชท้ายไม่เหมือนกัน จึงต้องรับทั้งสองรูปให้เหมือนกันทั้งสองที่
        $router->add(new Route('GET', '/app', SpaController::class, 'shell', null, 'app.shell'));
        $router->add(new Route('GET', '/app/', SpaController::class, 'shell', null, 'app.shell.slash'));
        $router->add(new Route('GET', '/app/{page}', SpaController::class, 'shell', null, 'app.page'));
        $router->add(new Route('GET', '/app/{page}/', SpaController::class, 'shell', null, 'app.page.slash'));

        self::apiV2($router);

        return $router;
    }

    /**
     * REST API v2 — PLAN-V2 เฟส B
     *
     * เพิ่มล้วน ไม่แตะเส้นทางเดิมแม้แต่เส้นเดียว UI แบบ HTML จึงใช้งานได้ตลอดเฟสนี้
     * และถ้า v2 มีปัญหาก็ถอยกลับได้ด้วยการลบบล็อกนี้ทิ้งบล็อกเดียว
     *
     * ทุกเส้นทางที่นี่ตอบ JSON เสมอ — HttpKernel และ middleware ทั้งเจ็ดตัวเช็ค
     * `Request::isApiV2()` แล้วเปลี่ยนรูปแบบคำตอบให้เองโดยที่ controller ไม่ต้องรู้เรื่อง
     */
    private static function apiV2(Router $router): void
    {
        // --- เซสชัน: เรียกได้โดยไม่ต้องล็อกอิน (จุด bootstrap ของ SPA) ---
        $router->add(new Route('GET', '/api/v2/session', SessionController::class, 'show', null, 'api.v2.session.show'));
        $router->add(new Route('POST', '/api/v2/session', SessionController::class, 'create', null, 'api.v2.session.create'));
        $router->add(new Route('POST', '/api/v2/session/2fa', SessionController::class, 'verifyTwoFactor', null, 'api.v2.session.2fa'));
        // ออกจากระบบไม่ต้องมีสิทธิ์ใด ๆ — คนที่ถือคุกกี้อยู่ต้องทิ้งมันได้เสมอ
        $router->add(new Route('DELETE', '/api/v2/session', SessionController::class, 'destroy', null, 'api.v2.session.destroy'));

        // --- แดชบอร์ด: ทุกอย่างที่หน้าแรกต้องใช้ในคำขอเดียว ---
        $router->add(new Route('GET', '/api/v2/dashboard', V2DashboardController::class, 'index', 'dashboard.view', 'api.v2.dashboard'));

        // --- บัญชีของตัวเอง ---
        $router->add(new Route('GET', '/api/v2/me', MeController::class, 'show', 'dashboard.view', 'api.v2.me.show'));
        $router->add(new Route('PATCH', '/api/v2/me/password', MeController::class, 'changePassword', 'dashboard.view', 'api.v2.me.password'));

        // --- เว็บไซต์ ---
        $router->add(new Route('GET', '/api/v2/sites', SitesController::class, 'index', 'site.view', 'api.v2.sites.index'));
        $router->add(new Route('POST', '/api/v2/sites', SitesController::class, 'store', 'site.create', 'api.v2.sites.store'));
        $router->add(new Route('GET', '/api/v2/sites/{id}', SitesController::class, 'show', 'site.view', 'api.v2.sites.show'));
        $router->add(new Route('PATCH', '/api/v2/sites/{id}', SitesController::class, 'update', 'site.edit', 'api.v2.sites.update'));
        $router->add(new Route('DELETE', '/api/v2/sites/{id}', SitesController::class, 'destroy', 'site.delete', 'api.v2.sites.destroy'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/php-version', SitesController::class, 'setPhpVersion', 'site.edit', 'api.v2.sites.php'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/suspension', SitesController::class, 'setSuspension', 'site.suspend', 'api.v2.sites.suspension'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/owner-reset', SitesController::class, 'resetOwner', 'site.edit', 'api.v2.sites.owner_reset'));

        // จำกัดอัตราคำขอต่อเว็บ — บังคับใช้ด้วย fail2ban (เฟส E5)
        $router->add(new Route('GET', '/api/v2/sites/{id}/rate-limit', SitesController::class, 'rateLimit', 'site.view', 'api.v2.sites.rate_limit'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/rate-limit', SitesController::class, 'setRateLimit', 'site.edit', 'api.v2.sites.rate_limit.set'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/rate-limit/bans', SitesController::class, 'rateLimitBans', 'site.view', 'api.v2.sites.rate_limit.bans'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/rate-limit/unban', SitesController::class, 'unbanIp', 'site.edit', 'api.v2.sites.rate_limit.unban'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/domains', SitesController::class, 'domains', 'domain.view', 'api.v2.sites.domains'));
        $router->add(new Route('GET', '/api/v2/sites/{id}/domains/form', SitesController::class, 'domainForm', 'domain.manage', 'api.v2.sites.domains.form'));
        $router->add(new Route('POST', '/api/v2/sites/{id}/domains', SitesController::class, 'addDomain', 'domain.manage', 'api.v2.sites.domains.add'));
        $router->add(new Route('PUT', '/api/v2/sites/{id}/domains', SitesController::class, 'setDomains', 'domain.manage', 'api.v2.sites.domains.set'));
        $router->add(new Route('DELETE', '/api/v2/sites/{id}/domains/{domain}', SitesController::class, 'removeDomain', 'domain.manage', 'api.v2.sites.domains.remove'));

        // --- โดเมนและ DNS ---
        $router->add(new Route('GET', '/api/v2/domains', DomainsController::class, 'index', 'domain.view', 'api.v2.domains.index'));
        $router->add(new Route('POST', '/api/v2/domains', DomainsController::class, 'store', 'domain.manage', 'api.v2.domains.store'));
        $router->add(new Route('DELETE', '/api/v2/domains/{id}', DomainsController::class, 'destroy', 'domain.manage', 'api.v2.domains.destroy'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/dns-records', DomainsController::class, 'records', 'domain.view', 'api.v2.dns.index'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/dns-records/form', DomainsController::class, 'recordForm', 'domain.manage', 'api.v2.dns.form'));
        $router->add(new Route('POST', '/api/v2/domains/{id}/dns-records', DomainsController::class, 'addRecord', 'domain.manage', 'api.v2.dns.store'));
        $router->add(new Route('GET', '/api/v2/domains/{id}/zone-file', DomainsController::class, 'zoneFile', 'domain.view', 'api.v2.domains.zone'));
        $router->add(new Route('DELETE', '/api/v2/dns-records/{id}', DomainsController::class, 'deleteRecord', 'domain.manage', 'api.v2.dns.destroy'));
        $router->add(new Route('POST', '/api/v2/dns/reload', DomainsController::class, 'reloadAll', 'dns.manage', 'api.v2.dns.reload'));

        // --- PHP ---
        $router->add(new Route('GET', '/api/v2/php-versions', PhpVersionsController::class, 'index', 'php.view', 'api.v2.php.index'));

        // --- ผู้ใช้ panel · ลูกค้า · ค่าตั้ง ---
        // บัญชีผู้ใช้ทั้งหมด — ผู้ดูแลระบบและลูกค้าโฮสติ้งอยู่ทรัพยากรเดียวกันตั้งแต่ migration 0005
        //
        // สิทธิ์ระดับ route ตั้งไว้ที่ `customer.manage` ซึ่ง sysadmin มี เพื่อให้จัดการลูกค้าได้
        // ต่อไปเหมือนเดิม · การแตะบัญชี**ผู้ดูแล**ต้องมี `user.manage` เพิ่ม ซึ่ง
        // UsersController::assertMayManage() บังคับอยู่ และมีเทสต์เฝ้าทุกเมธอด
        // ห้ามลดด่านนั้นออกโดยเด็ดขาด ไม่งั้น sysadmin จะรีเซ็ตรหัสผ่าน superadmin ได้
        $router->add(new Route('GET', '/api/v2/users', UsersController::class, 'index', 'user.view', 'api.v2.users.index'));
        $router->add(new Route('POST', '/api/v2/users', UsersController::class, 'store', 'customer.manage', 'api.v2.users.store'));
        $router->add(new Route('GET', '/api/v2/users/{id}', UsersController::class, 'show', 'user.view', 'api.v2.users.show'));
        $router->add(new Route('PATCH', '/api/v2/users/{id}', UsersController::class, 'update', 'customer.manage', 'api.v2.users.update'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}', UsersController::class, 'destroy', 'customer.manage', 'api.v2.users.destroy'));
        $router->add(new Route('PUT', '/api/v2/users/{id}/quota', UsersController::class, 'setQuota', 'customer.manage', 'api.v2.users.quota'));
        $router->add(new Route('POST', '/api/v2/users/{id}/password-reset', UsersController::class, 'resetPassword', 'customer.manage', 'api.v2.users.password'));
        $router->add(new Route('POST', '/api/v2/users/{id}/sites', UsersController::class, 'attachSites', 'customer.manage', 'api.v2.users.sites.attach'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/sites/{site_id}', UsersController::class, 'detachSite', 'customer.manage', 'api.v2.users.sites.detach'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/two-factor', UsersController::class, 'disableTwoFactor', 'customer.manage', 'api.v2.users.2fa'));
        // sub-resource ตาม §4.1 — "การเข้าถึงผ่าน SFTP" ของบัญชีนี้ · PUT = ตั้งค่าทั้งชุด (เปิด+รหัสผ่าน)
        $router->add(new Route('PUT', '/api/v2/users/{id}/sftp', UsersController::class, 'enableSftp', 'customer.manage', 'api.v2.users.sftp.enable'));
        $router->add(new Route('DELETE', '/api/v2/users/{id}/sftp', UsersController::class, 'disableSftp', 'customer.manage', 'api.v2.users.sftp.disable'));


        $router->add(new Route('GET', '/api/v2/settings', V2SettingsController::class, 'show', 'settings.view', 'api.v2.settings.show'));
        $router->add(new Route('PATCH', '/api/v2/settings', V2SettingsController::class, 'update', 'settings.manage', 'api.v2.settings.update'));
        $router->add(new Route('POST', '/api/v2/settings/notification-test', V2SettingsController::class, 'testNotification', 'settings.manage', 'api.v2.settings.notify_test'));
        $router->add(new Route('POST', '/api/v2/settings/mail-config', V2SettingsController::class, 'applyMail', 'settings.manage', 'api.v2.settings.mail_config'));
        $router->add(new Route('POST', '/api/v2/settings/mail-test', V2SettingsController::class, 'testMail', 'settings.manage', 'api.v2.settings.mail_test'));

        // เปลี่ยนเว็บเซิร์ฟเวอร์ — เส้นทางแยกจาก PATCH /settings เพราะไม่ใช่แค่บันทึกค่า
        // แต่เขียนไฟล์ตั้งค่าของทุกเว็บใหม่แล้วรีสตาร์ตบริการจริง ซึ่งใช้เวลาหลายวินาที
        $router->add(new Route('POST', '/api/v2/settings/webserver', V2SettingsController::class, 'applyWebserver', 'settings.manage', 'api.v2.settings.webserver'));

        // ชีพจรของตัวจับเวลา — อ่านอย่างเดียว (เฟส A1 ข้อ 7 ที่ค้างไว้ให้เฟส C)
        $router->add(new Route('GET', '/api/v2/scheduled-jobs', ScheduledJobsController::class, 'index', 'settings.view', 'api.v2.scheduled_jobs'));

        // เกณฑ์เตือนที่ยังค้างอยู่ — อ่านอย่างเดียว (เฟส E6)
        $router->add(new Route('GET', '/api/v2/alerts', AlertsController::class, 'index', 'settings.view', 'api.v2.alerts'));

        // --- ฝั่ง SERVER ---
        //
        // ทุกเส้นทางในบล็อกนี้ใช้ permission หมวด SERVER ซึ่ง webadmin ไม่มีสักตัวเดียว
        // ตาม Permissions::forRole() — ลูกค้าจึงได้ 403 ทุกเส้นทางที่นี่โดยอัตโนมัติ
        $router->add(new Route('GET', '/api/v2/services', ServicesController::class, 'index', 'service.view', 'api.v2.services.index'));
        $router->add(new Route('POST', '/api/v2/services/{unit}/actions', ServicesController::class, 'action', 'service.control', 'api.v2.services.action'));

        $router->add(new Route('GET', '/api/v2/firewall', V2FirewallController::class, 'index', 'firewall.view', 'api.v2.firewall.index'));
        // ต้องมาก่อน {number} — ไม่งั้น "form" ถูกอ่านเป็นหมายเลขกฎ
        $router->add(new Route('GET', '/api/v2/firewall/rules/form', V2FirewallController::class, 'form', 'firewall.manage', 'api.v2.firewall.rules.form'));
        $router->add(new Route('POST', '/api/v2/firewall/rules', V2FirewallController::class, 'addRule', 'firewall.manage', 'api.v2.firewall.rules.add'));
        $router->add(new Route('DELETE', '/api/v2/firewall/rules/{number}', V2FirewallController::class, 'deleteRule', 'firewall.manage', 'api.v2.firewall.rules.delete'));
        $router->add(new Route('PUT', '/api/v2/firewall/enabled', V2FirewallController::class, 'setEnabled', 'firewall.manage', 'api.v2.firewall.enabled'));

        $router->add(new Route('GET', '/api/v2/ssh-config', SshConfigController::class, 'show', 'ssh.view', 'api.v2.ssh.show'));
        $router->add(new Route('PATCH', '/api/v2/ssh-config', SshConfigController::class, 'update', 'ssh.manage', 'api.v2.ssh.update'));

        // การยืนยัน/คืนค่าใช้สิทธิ์ security.view — คนที่เห็นว่ามีรายการค้างต้องกดยืนยันได้
        // ไม่งั้นจะเกิดสภาพ "เห็นตัวนับถอยหลังแต่กดอะไรไม่ได้" ซึ่งแย่กว่าไม่เห็นเลย
        $router->add(new Route('GET', '/api/v2/rollbacks', RollbacksController::class, 'index', 'security.view', 'api.v2.rollbacks.index'));
        $router->add(new Route('POST', '/api/v2/rollbacks/{id}/confirmation', RollbacksController::class, 'confirm', 'security.view', 'api.v2.rollbacks.confirm'));
        $router->add(new Route('POST', '/api/v2/rollbacks/{id}/execution', RollbacksController::class, 'execute', 'security.view', 'api.v2.rollbacks.execute'));

        $router->add(new Route('GET', '/api/v2/logs/sources', LogsController::class, 'sources', 'log.view', 'api.v2.logs.sources'));
        $router->add(new Route('GET', '/api/v2/logs', LogsController::class, 'tail', 'log.view', 'api.v2.logs.tail'));

        $router->add(new Route('GET', '/api/v2/security/scan', V2SecurityController::class, 'scan', 'security.view', 'api.v2.security.scan'));
        // ผลตรวจจากภายนอกที่ tools/security-audit.sh ทิ้งไว้ — คนละมุมมองกับ scan
        $router->add(new Route('GET', '/api/v2/security/audit', V2SecurityController::class, 'audit', 'security.view', 'api.v2.security.audit'));

        $router->add(new Route('GET', '/api/v2/metrics', V2MetricsController::class, 'index', 'dashboard.view', 'api.v2.metrics.index'));
        $router->add(new Route('GET', '/api/v2/metrics/stream', V2MetricsController::class, 'stream', 'dashboard.view', 'api.v2.metrics.stream'));
        $router->add(new Route('GET', '/api/v2/metrics/history', V2MetricsController::class, 'history', 'dashboard.view', 'api.v2.metrics.history'));

        $router->add(new Route('GET', '/api/v2/system/info', SystemController::class, 'info', 'server.view', 'api.v2.system.info'));
        // health ใช้ dashboard.view เพราะทุกบทบาทต้องรู้ได้ว่า agent ล่มหรือไม่ —
        // ลูกค้าที่กดปุ่มแล้วไม่มีอะไรเกิดขึ้นควรเห็นสาเหตุ ไม่ใช่เดาเอง
        $router->add(new Route('GET', '/api/v2/system/health', SystemController::class, 'health', 'dashboard.view', 'api.v2.system.health'));

        // --- ตัวจัดการไฟล์ ---
        //
        // ทุกเส้นทางอ้าง root (คีย์ขอบเขต) + path (เส้นทางสัมพัทธ์) ไม่มีเส้นทางสัมบูรณ์
        // ของเครื่องปรากฏใน API เลย · การอ่านใช้สิทธิ์ file.view การเปลี่ยนแปลงใช้ file.manage
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

        // --- ใบรับรอง SSL (อ้างด้วย site id เพราะหนึ่งเว็บ = หนึ่งใบเสมอ) ---
        $router->add(new Route('GET', '/api/v2/certificates', CertificatesController::class, 'index', 'ssl.view', 'api.v2.certificates.index'));
        $router->add(new Route('POST', '/api/v2/certificates', CertificatesController::class, 'store', 'ssl.manage', 'api.v2.certificates.store'));
        $router->add(new Route('POST', '/api/v2/certificates/{site_id}/renewal', CertificatesController::class, 'renew', 'ssl.manage', 'api.v2.certificates.renew'));
        $router->add(new Route('PUT', '/api/v2/certificates/{site_id}/mode', CertificatesController::class, 'setMode', 'ssl.manage', 'api.v2.certificates.mode'));
        $router->add(new Route('DELETE', '/api/v2/certificates/{site_id}', CertificatesController::class, 'destroy', 'ssl.manage', 'api.v2.certificates.destroy'));

        // --- ฐานข้อมูล (อ้างด้วยชื่อ เพราะชื่อคือสิ่งที่ MariaDB รู้จัก) ---
        $router->add(new Route('GET', '/api/v2/databases', DatabasesController::class, 'index', 'db.view', 'api.v2.databases.index'));
        $router->add(new Route('POST', '/api/v2/databases', DatabasesController::class, 'store', 'db.manage', 'api.v2.databases.store'));
        // ฟอร์มสร้างฐานข้อมูล — คืนโครงเปล่าพร้อมคำสั่งเปิด modal ให้หน้าเว็บ
        // (แบบเดียวกับ GET /cron-jobs/0) หน้าเว็บจึงไม่ต้องรู้จักชื่อไฟล์เทมเพลต
        $router->add(new Route('GET', '/api/v2/databases/form', DatabasesController::class, 'form', 'db.manage', 'api.v2.databases.form'));
        $router->add(new Route('DELETE', '/api/v2/databases/{name}', DatabasesController::class, 'destroy', 'db.manage', 'api.v2.databases.destroy'));
        // POST ไม่ใช่ GET โดยตั้งใจ — นี่คือการล็อกอินเข้า phpMyAdmin ไม่ใช่ลิงก์ธรรมดา
        $router->add(new Route('POST', '/api/v2/phpmyadmin/session', PhpMyAdminController::class, 'create', 'db.view', 'api.v2.phpmyadmin.session'));
        $router->add(new Route('POST', '/api/v2/database-users/{user}/password', DatabasesController::class, 'resetPassword', 'db.manage', 'api.v2.database_users.password'));

        // --- งานอัตโนมัติ ---
        $router->add(new Route('GET', '/api/v2/cron-jobs', CronJobsController::class, 'index', 'cron.view', 'api.v2.cron.index'));
        $router->add(new Route('POST', '/api/v2/cron-jobs', CronJobsController::class, 'store', 'cron.manage', 'api.v2.cron.store'));
        $router->add(new Route('GET', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'show', 'cron.view', 'api.v2.cron.show'));
        $router->add(new Route('PATCH', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'update', 'cron.manage', 'api.v2.cron.update'));
        $router->add(new Route('DELETE', '/api/v2/cron-jobs/{id}', CronJobsController::class, 'destroy', 'cron.manage', 'api.v2.cron.destroy'));

        // --- ข้อมูลสำรอง ---
        // เมลโฮสติ้ง — กล่องจดหมายและที่อยู่ส่งต่อ (PLAN-MAIL เฟส M2)
        $router->add(new Route('GET', '/api/v2/mailboxes', MailboxesController::class, 'index', 'mail.view', 'api.v2.mailboxes.index'));
        // ต้องมาก่อน {id} — ไม่งั้น "form" ถูกอ่านเป็นรหัสกล่อง
        $router->add(new Route('GET', '/api/v2/mail/readiness', MailboxesController::class, 'readiness', 'mail.view', 'api.v2.mail.readiness'));
        $router->add(new Route('GET', '/api/v2/mailboxes/form', MailboxesController::class, 'form', 'mail.manage', 'api.v2.mailboxes.form'));
        $router->add(new Route('POST', '/api/v2/mailboxes', MailboxesController::class, 'store', 'mail.manage', 'api.v2.mailboxes.store'));
        $router->add(new Route('GET', '/api/v2/mailboxes/{id}', MailboxesController::class, 'show', 'mail.view', 'api.v2.mailboxes.show'));
        $router->add(new Route('PATCH', '/api/v2/mailboxes/{id}', MailboxesController::class, 'update', 'mail.manage', 'api.v2.mailboxes.update'));
        $router->add(new Route('DELETE', '/api/v2/mailboxes/{id}', MailboxesController::class, 'destroy', 'mail.manage', 'api.v2.mailboxes.destroy'));

        $router->add(new Route('GET', '/api/v2/mail-aliases/form', MailAliasesController::class, 'form', 'mail.manage', 'api.v2.mail_aliases.form'));
        $router->add(new Route('POST', '/api/v2/mail-aliases', MailAliasesController::class, 'store', 'mail.manage', 'api.v2.mail_aliases.store'));
        $router->add(new Route('DELETE', '/api/v2/mail-aliases/{id}', MailAliasesController::class, 'destroy', 'mail.manage', 'api.v2.mail_aliases.destroy'));

        $router->add(new Route('GET', '/api/v2/backups', BackupsController::class, 'index', 'backup.view', 'api.v2.backups.index'));
        // ต้องมาก่อน {id} — ไม่งั้น "form" ถูกอ่านเป็นรหัสไฟล์สำรอง
        $router->add(new Route('GET', '/api/v2/backups/form', BackupsController::class, 'form', 'backup.manage', 'api.v2.backups.form'));
        $router->add(new Route('POST', '/api/v2/backups', BackupsController::class, 'store', 'backup.manage', 'api.v2.backups.store'));
        $router->add(new Route('DELETE', '/api/v2/backups/{id}', BackupsController::class, 'destroy', 'backup.manage', 'api.v2.backups.destroy'));
        // กู้คืนใช้สิทธิ์แยกจากการสร้าง/ลบ — เป็นคำสั่งที่เขียนทับข้อมูลปัจจุบันทั้งหมด
        $router->add(new Route('POST', '/api/v2/backups/{id}/restoration', BackupsController::class, 'restore', 'backup.restore', 'api.v2.backups.restore'));
        // สำเนาที่อยู่นอกเครื่องของไฟล์สำรองนั้น — คำนามตาม §4.1 ไม่ใช่กริยา "push"
        $router->add(new Route('POST', '/api/v2/backups/{id}/offsite-copy', BackupsController::class, 'pushOffsite', 'backup.offsite', 'api.v2.backups.offsite'));

        // --- ปลายทางของไฟล์สำรอง (เฟส E1) — ของชั้น SERVER ทั้งหมด ---
        $router->add(new Route('GET', '/api/v2/backup-destinations', BackupDestinationsController::class, 'index', 'backup.offsite', 'api.v2.backup_destinations.index'));
        $router->add(new Route('POST', '/api/v2/backup-destinations', BackupDestinationsController::class, 'store', 'backup.offsite', 'api.v2.backup_destinations.store'));
        $router->add(new Route('GET', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'show', 'backup.offsite', 'api.v2.backup_destinations.show'));
        $router->add(new Route('PATCH', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'update', 'backup.offsite', 'api.v2.backup_destinations.update'));
        $router->add(new Route('DELETE', '/api/v2/backup-destinations/{id}', BackupDestinationsController::class, 'destroy', 'backup.offsite', 'api.v2.backup_destinations.destroy'));
        $router->add(new Route('POST', '/api/v2/backup-destinations/{id}/verification', BackupDestinationsController::class, 'verify', 'backup.offsite', 'api.v2.backup_destinations.verify'));

        // --- ตารางเวลาสำรองอัตโนมัติ — แตะได้เฉพาะงานที่ทรัพยากรนี้สร้างเอง ---
        $router->add(new Route('GET', '/api/v2/backup-schedules', BackupSchedulesController::class, 'index', 'backup.offsite', 'api.v2.backup_schedules.index'));
        $router->add(new Route('GET', '/api/v2/backup-schedules/form', BackupSchedulesController::class, 'form', 'backup.offsite', 'api.v2.backup_schedules.form'));
        $router->add(new Route('POST', '/api/v2/backup-schedules', BackupSchedulesController::class, 'store', 'backup.offsite', 'api.v2.backup_schedules.store'));
        $router->add(new Route('PATCH', '/api/v2/backup-schedules/{id}', BackupSchedulesController::class, 'update', 'backup.offsite', 'api.v2.backup_schedules.update'));
        $router->add(new Route('DELETE', '/api/v2/backup-schedules/{id}', BackupSchedulesController::class, 'destroy', 'backup.offsite', 'api.v2.backup_schedules.destroy'));
    }
}
