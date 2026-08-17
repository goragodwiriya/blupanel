/**
 * The SPA's starting point — PLAN-V2 phase C1, items 2 and 5
 *
 * The startup order has a reason behind every step — never reorder it:
 *
 *   1. Bind the permission gate to the router  Must come before Now.init,
 *                                              since RouterManager.init() navigates to the first page the moment it starts
 *   2. Ask GET /api/v2/session                  Must also come first, so the
 *                                              gate in step 1 has a genuine
 *                                              status to decide from, instead
 *                                              of guessing "not signed in
 *                                              yet" and bouncing someone who's already signed in
 *   3. Now.init(...)                            Starts every manager, then renders the first page
 */
document.addEventListener('DOMContentLoaded', async () => {
  // These two values are **deliberately different** and must never be merged into one:
  //   ROUTE_BASE  the URL the user sees in the address bar — must never match
  //               a real directory on disk, or Apache's own mod_dir would
  //               handle it before it ever reaches FallbackResource
  //   ASSET_BASE  where the real files live, under /assets/, which is already where static files belong
  const ROUTE_BASE = '/app';
  const ASSET_BASE = '/assets/spa';

  /**
   * The screen route table
   *
   * `permission` is this project's own field, not Now.js's — `PhpcpAuth.guard`
   * reads it via `to.route.permission` · the value must match the permission
   * the page's own API routes use, so a page never ends up openable while every request inside it gets rejected
   *
   * Routes are single-level names separated by dashes, per FRAMEWORK_GUIDE —
   * a detail page uses a query string (`/site?id=5`), never a nested path
   */
  const routes = {
    '/': { template: 'dashboard.html', title: '{LNG_Dashboard}', permission: 'dashboard.view' },

    '/login': { template: 'login.html', title: '{LNG_Sign in}' },
    '/login-2fa': { template: 'login-2fa.html', title: '{LNG_Two-factor verification}' },
    '/change-password': { template: 'change-password.html', title: '{LNG_Change password}' },
    '/forbidden': { template: 'forbidden.html', title: '{LNG_Access denied}' },

    // --- HOSTING ---
    '/sites': { template: 'sites.html', title: '{LNG_Websites}', permission: 'site.view' },
    '/site': { template: 'site.html', title: '{LNG_Website}', permission: 'site.view' },
    '/site-create': { template: 'site-create.html', title: '{LNG_Add website}', permission: 'site.create' },
    '/domains': { template: 'domains.html', title: '{LNG_Domains}', permission: 'domain.view' },
    '/domain': { template: 'domain.html', title: '{LNG_DNS records}', permission: 'domain.view' },
    '/certificates': { template: 'certificates.html', title: '{LNG_SSL Certificates}', permission: 'ssl.view' },
    '/php-versions': { template: 'php-versions.html', title: '{LNG_PHP}', permission: 'php.view' },
    '/databases': { template: 'databases.html', title: '{LNG_Databases}', permission: 'db.view' },
    // The file manager is a full-screen page that opens in its own tab (the
    // menu in ui.js sets target=_blank), so its template has no sidebar or
    // topbar — same as login.html, which also has no chrome
    '/filemanager': { template: 'filemanager.html', title: '{LNG_File Manager}', permission: 'file.view' },
    '/cron-jobs': { template: 'cron-jobs.html', title: '{LNG_Cron Jobs}', permission: 'cron.view' },
    '/mailboxes': { template: 'mailboxes.html', title: '{LNG_Mailboxes}', permission: 'mail.view' },
    // The queue belongs to the whole machine and mixes every customer's
    // addresses together — the gate lives at the route level, never as a
    // hidden card on another page, since a hidden component still genuinely fires its request
    '/mail-queue': { template: 'mail-queue.html', title: '{LNG_Mail queue}', permission: 'settings.manage' },
    '/cron-job': { template: 'cron-job.html', title: '{LNG_Cron Jobs}', permission: 'cron.view' },
    '/backups': { template: 'backups.html', title: '{LNG_Backups}', permission: 'backup.view' },
    // The destination form has a dozen or so fields, so it's its own page
    // instead of a Modal, per FRAMEWORK_GUIDE — one file handles both create (id=0) and edit
    '/backup-destination': { template: 'backup-destination.html', title: '{LNG_Offsite destination}', permission: 'backup.offsite' },

    // --- SERVER ---
    '/server': { template: 'server.html', title: '{LNG_Server Overview}', permission: 'server.view' },
    '/services': { template: 'services.html', title: '{LNG_Services}', permission: 'service.view' },
    '/security': { template: 'security.html', title: '{LNG_Security}', permission: 'security.view' },
    '/firewall': { template: 'firewall.html', title: '{LNG_Firewall}', permission: 'firewall.view' },
    '/ssh': { template: 'ssh.html', title: '{LNG_SSH}', permission: 'ssh.view' },
    '/logs': { template: 'logs.html', title: '{LNG_Logs}', permission: 'log.view' },
    '/users': { template: 'users.html', title: '{LNG_Users}', permission: 'user.view' },
    '/user': { template: 'user.html', title: '{LNG_User}', permission: 'user.view' },
    // The create form is a separate file from the table — one template, one job
    '/user-create': { template: 'user-create.html', title: '{LNG_Add user}', permission: 'customer.manage' },
    '/settings': { template: 'settings.html', title: '{LNG_Settings}', permission: 'settings.view' }
  };

  window.RouterManager.beforeEach((to) => window.PhpcpAuth.guard(to));

  try {
    await window.PhpcpAuth.refresh();
  } catch (error) {
    // Unable to ask for status = the server has a problem serious enough
    // that the web page can't do anything further at all · stating this
    // directly is better than letting the app start and then fail in
    // pieces, somewhere harder to diagnose · plain English here, not
    // translated — Now.js hasn't been initialized yet at this point, so no translation catalog is loaded
    document.getElementById('main').textContent =
      'Cannot reach the server, please try again (' + (error && error.message ? error.message : 'unknown cause') + ')';
    return;
  }

  await window.Now.init({
    // production = uses the committed bundle files, doesn't load each core file one by one
    environment: 'production',

    paths: {
      framework: ASSET_BASE + '/vendor/now',
      application: ASSET_BASE,
      components: ASSET_BASE + '/js/components',
      templates: ASSET_BASE + '/templates',
      translations: ASSET_BASE + '/lang'
    },

    // Decision N7 — eval is never enabled, the panel's CSP has no 'unsafe-eval'
    allowEval: false,

    // The framework's own authentication system is disabled — the full reason is at the top of js/auth.js
    auth: { enabled: false },

    security: {
      csrf: {
        enabled: true,
        tokenName: '_token',
        headerName: 'X-CSRF-Token',
        metaName: 'csrf-token',
        // `GET /api/v2/session` is the system's one source of the token ·
        // the response shape matches exactly what SecurityManager expects (body.data.csrf_token), so it works with no conversion needed
        tokenUrl: '/api/v2/session'
      }
    },

    // The source text in the templates is English and doubles as the
    // translation key, so the default locale is English — Thai comes from
    // lang/th.json, the same single catalogue the server side also reads
    i18n: {
      enabled: true,
      defaultLocale: 'en',
      availableLocales: ['th', 'en'],
      storageKey: 'phpcp_lang'
    },

    // Light/dark theme is stored on the user's own machine · fetching config
    // from the API is disabled, since the panel has no such endpoint and
    // shouldn't — every value that needs to be known already arrives with /session
    config: {
      enabled: true,
      defaultTheme: 'light',
      storageKey: 'phpcp_theme',
      api: { enabled: false }
    },

    router: {
      enabled: true,
      mode: 'history',
      base: ROUTE_BASE,
      // The router's own auth is disabled, since it's bound to AuthManager · the real gate is beforeEach above
      auth: { enabled: false },
      notFound: {
        behavior: 'render',
        template: 'not-found.html',
        title: '{LNG_Page not found}'
      },
      routes: routes
    }
  });
});
