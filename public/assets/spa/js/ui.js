/**
 * The screen's shell — the sidebar, topbar, mode-warning bar, and helpers every page shares
 * PLAN-V2 phase C1, item 5
 *
 * **This file's security rule (PLAN-V2 §C2–C3):** Now.js's own core has 135
 * `innerHTML` call sites — data a user or the OS controls (a domain name, a
 * filename, an error message) **must only ever be bound as text** · this
 * file only ever uses `textContent` and `createElement` · the menu's own
 * constants belong to this project, never come from the server, and so can never be an XSS carrier
 */
(function() {
  'use strict';

  /**
   * The menu's structure — matches `Kernel\Navigation::sections()` on the PHP side
   *
   * `permission` here does the exact same job as that side: **it removes an
   * item that would get 403 if clicked**, it never enforces a permission ·
   * a section left with no items isn't shown at all, so a website admin
   * never sees even the word "SERVER" — one of this phase's own acceptance criteria
   */
  const MENU = [
    {
      label: '',
      items: [
        {key: 'dashboard', label: 'Dashboard', url: '/', icon: 'icon-dashboard', permission: 'dashboard.view'}
      ]
    },
    {
      label: 'HOSTING',
      items: [
        {key: 'sites', label: 'Websites', url: '/sites', icon: 'icon-website', permission: 'site.view'},
        {key: 'domains', label: 'Domains', url: '/domains', icon: 'icon-link', permission: 'domain.view'},
        {key: 'ssl', label: 'SSL Certificates', url: '/certificates', icon: 'icon-lock', permission: 'ssl.view'},
        {key: 'php', label: 'PHP', url: '/php-versions', icon: 'icon-code', permission: 'php.view'},
        {key: 'databases', label: 'Databases', url: '/databases', icon: 'icon-database', permission: 'db.view'},
        // `newTab` opens the file manager in its own tab — that page fills
        // the entire screen (folder tree + file list + status bar), and
        // stacking the panel's own menu on top would leave too little room
        // to show filenames · working with files also often means switching back to look at another panel page too
        {key: 'files', label: 'File Manager', url: '/filemanager', icon: 'icon-folder', permission: 'file.view', newTab: true},
        {key: 'cron', label: 'Cron Jobs', url: '/cron-jobs', icon: 'icon-clock', permission: 'cron.view'},
        {key: 'mail', label: 'Mailboxes', url: '/mailboxes', icon: 'icon-email', permission: 'mail.view'},
        {key: 'mailqueue', label: 'Mail queue', url: '/mail-queue', icon: 'icon-send', permission: 'settings.manage'},
        {key: 'backups', label: 'Backups', url: '/backups', icon: 'icon-stack', permission: 'backup.view'}
      ]
    },
    {
      label: 'SERVER',
      items: [
        {key: 'server', label: 'Server Overview', url: '/server', icon: 'icon-cpu', permission: 'server.view'},
        {key: 'services', label: 'Services', url: '/services', icon: 'icon-stats', permission: 'service.view'},
        {key: 'security', label: 'Security', url: '/security', icon: 'icon-shield', permission: 'security.view'},
        {key: 'firewall', label: 'Firewall', url: '/firewall', icon: 'icon-fire', permission: 'firewall.view'},
        {key: 'ssh', label: 'SSH', url: '/ssh', icon: 'icon-keyboard', permission: 'ssh.view'},
        {key: 'logs', label: 'Logs', url: '/logs', icon: 'icon-list', permission: 'log.view'},
        // Since phase M, admins and customers share the same resource (`/api/v2/users`), so only one menu item is left
        {key: 'users', label: 'Users', url: '/users', icon: 'icon-users', permission: 'user.view'},
        {key: 'settings', label: 'Settings', url: '/settings', icon: 'icon-cog', permission: 'settings.view'}
      ]
    }
  ];

  const t = (text) => (window.Now && window.Now.translate ? window.Now.translate(text) : text);

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  /** The sections the current user can genuinely see */
  function visibleSections() {
    return MENU
      .map((section) => ({
        label: section.label,
        items: section.items.filter((item) => window.PhpcpAuth.can(item.permission))
      }))
      .filter((section) => section.items.length > 0);
  }

  // ---------------------------------------------------------------------------
  // The sidebar
  // ---------------------------------------------------------------------------
  /**
   * The SPA's routes live under `/app`, but the router knows them by a name with no base
   *
   * So both must be set: `href` as the real URL, so opening a new tab or
   * copying the link works correctly, and `data-route` as the route name, so the router takes over on a normal click (no page reload)
   */
  function link(node, route) {
    node.href = '/app' + (route === '/' ? '' : route);
    node.dataset.route = route;
    return node;
  }

  window.Now.getManager('component').define('sidebar', {
    // `sidemenu-panel` is the class that makes this a fixed left panel,
    // `--menu-width` wide, and it's the class the
    // `.sidemenu-close .sidemenu-panel` rule (the collapse button) grabs
    // onto — without it, the menu flows like ordinary content, fills the
    // whole screen's width, and pushes the page content down · the header
    // is static text, so it lives in the template with data-i18n, never
    // needing to be built from JS · and it sits **outside**
    // `<nav class="sidemenu">`, since nav itself can scroll — if the logo
    // were inside it, it would scroll away along with the menu once the list is longer than the screen
    template: [
      '<aside class="sidebar sidemenu-panel">',
      '  <div class="sidebar-header">',
      '    <a class="logo" href="/app" data-route="/" aria-label="BluPanel"></a>',
      '    <div class="logo-text" data-i18n><em>Blu</em>Panel</div>',
      '  </div>',
      '  <nav class="sidemenu" data-component="menu" aria-label="Main menu"></nav>',
      '</aside>'
    ].join(''),

    mounted() {
      // ComponentManager **replaces** the host element with the template's
      // own root — this.element is the new <aside class="sidebar">, never the original <aside data-component="sidebar">

      const nav = this.element.querySelector('.sidemenu');
      const list = el('ul');

      visibleSections().forEach((section) => {
        if (section.label) {
          const heading = el('li', 'sidemenu-title');
          heading.dataset.navSection = section.label;
          heading.appendChild(el('span', null, t(section.label)));
          list.appendChild(heading);
        }

        section.items.forEach((item) => {
          const li = el('li');
          const anchor = link(el('a', item.icon), item.url);
          anchor.dataset.navKey = item.key;

          // An item that opens a new tab must have **no `data-route`** — or
          // the router would intercept the click and render over the current tab before the browser ever gets to open the new one
          if (item.newTab) {
            delete anchor.dataset.route;
            anchor.target = '_blank';
            anchor.rel = 'noopener';
          }

          anchor.appendChild(el('span', null, t(item.label)));
          li.appendChild(anchor);
          list.appendChild(li);
        });
      });

      nav.appendChild(list);

      // **Must tell MenuManager itself, right after inserting the `<ul>`**
      //
      // `createMenu()` skips a menu that has no `<ul>` yet ("empty menu"),
      // and its own MutationObserver sees the `<nav>` at the moment
      // ComponentManager inserts it into the DOM — the exact moment the
      // items don't exist yet — so the menu never gets registered, and nothing ever triggers a retry
      //
      // The symptom was clicking the collapse button and nothing happening:
      // the click listener is delegated at the document level, so it fires
      // normally, but it searches for the menu in an empty `state.menus`
      window.MenuManager.createMenu(nav);

      // Marks the currently open item itself
      //
      // `MenuManager.updateActiveMenu()` **strips the base off the current
      // path** (`/app/services` → `/services`) and compares it directly
      // against the value in `href` · but our `href` has to be the full URL
      // (`/app/services`), so opening a new tab and copying the link both
      // work correctly — so these two values could never match · compared
      // against `data-route` instead, which already stores the route name with no base
      const markActive = () => {
        const here = window.RouterManager.getPath();

        nav.querySelectorAll('a[data-route]').forEach((anchor) => {
          const isActive = anchor.dataset.route === here;

          anchor.classList.toggle('active', isActive);

          if (isActive) {
            anchor.setAttribute('aria-current', 'page');
          } else {
            anchor.removeAttribute('aria-current');
          }
        });
      };

      markActive();
      this._offRoute = window.EventManager.on('route:changed', markActive);

      /*
       * A menu label is built with JS, so it has no `data-i18n` for the translator to pick up
       *
       * `I18nManager.updateElements()` only translates an element that
       * carries `data-i18n` · so the sidebar would stay stuck in the old
       * language entirely when switching languages, even though everything
       * around it changed — rewrites the labels itself when the signal
       * arrives, instead of attaching `data-i18n` to every label (which would mean remembering what the key is in yet another place)
       */
      const repaintLabels = () => {
        visibleSections().forEach((section) => {
          section.items.forEach((item) => {
            const label = nav.querySelector(`a[data-nav-key="${item.key}"] span`);

            if (label) {
              label.textContent = t(item.label);
            }
          });
        });

        nav.querySelectorAll('.sidemenu-title').forEach((heading) => {
          const span = heading.querySelector('span');

          if (span) {
            span.textContent = t(heading.dataset.navSection || '');
          }
        });
      };

      this._offLocale = window.EventManager.on('i18n:updated', repaintLabels);
    },

    destroyed() {
      if (typeof this._offRoute === 'function') this._offRoute();
      if (typeof this._offLocale === 'function') this._offLocale();
    }
  });

  // ---------------------------------------------------------------------------
  // The topbar
  // ---------------------------------------------------------------------------
  window.Now.getManager('component').define('topbar', {
    template: [
      '<header class="topbar">',
      '  <div class="topbar-left">',
      '    <h1 class="page-title" data-i18n>Web Hosting Control Panel</h1>',
      '  </div>',
      '  <div class="topbar-right">',
      '    <span class="agent-dot" title="Agent status">&#9679;</span>',
      '    <button type="button" class="lang-toggle btn" data-lang-toggle>TH</button>',
      '    <button type="button" class="btn" data-component="config" title="Toggle theme"></button>',
      '    <button type="button" class="btn user-menu" data-logout>',
      '      <span class="user-name"></span>',
      '      <span class="icon-signout"></span>',
      '    </button>',
      '    <button class="menu-toggle sidemenu-toggle">',
      '      <span class="toggle-icon">',
      '        <span class="toggle-bar"></span>',
      '        <span class="toggle-bar"></span>',
      '        <span class="toggle-bar"></span>',
      '      </span>',
      '    </button>',
      '  </div>',
      '</header>'
    ].join(''),

    mounted() {
      const root = this.element;

      const paint = (session) => {
        const name = session.user ? (session.user.display_name || session.user.username || '') : '';
        const nameEl = root.querySelector('.user-name');
        if (nameEl) nameEl.textContent = name;

        const dot = root.querySelector('.agent-dot');
        if (dot) {
          dot.classList.toggle('is-down', session.agentAvailable === false);
          dot.title = session.agentAvailable === false ? t('Agent is not responding') : t('Agent is responding');
        }
      };

      paint(window.PhpcpAuth.snapshot());
      this._off = window.EventManager.on('phpcp:session', paint);

      root.querySelector('[data-logout]').addEventListener('click', () => window.PhpcpAuth.logout());

      const lang = root.querySelector('[data-lang-toggle]');
      const paintLang = () => {
        const current = window.I18nManager.state.current || 'en';
        lang.textContent = current.toUpperCase();
        // Tells the server side which language was chosen too — the text
        // the API sends back (including what goes out by email) is
        // translated there, never in the browser · storing it in
        // localStorage alone isn't enough, since the server can't read it
        document.cookie = 'phpcp_lang=' + encodeURIComponent(current) + '; path=/; max-age=31536000; samesite=lax';
        // A screen reader and the browser's own word-breaking read this value, never localStorage
        document.documentElement.lang = current;
      };
      paintLang();
      lang.addEventListener('click', async () => {
        const next = window.I18nManager.state.current === 'th' ? 'en' : 'th';
        await window.I18nManager.setLocale(next);
        paintLang();
      });
    },

    destroyed() {
      if (typeof this._off === 'function') this._off();
      if (typeof this._offRoute === 'function') this._offRoute();
    }
  });


  // ---------------------------------------------------------------------------
  // The automatic-rollback countdown bar — SSH and the firewall
  //
  // **Why this exists, and why it must be on every page:** changing an sshd
  // value or a firewall rule sets the system to revert it on a timer — if
  // not confirmed within the window, the value reverts back · without this
  // bar, an admin who set the value correctly and walked away to do
  // something else would silently lose what they just set, with nothing at all saying what happened
  //
  // **The real timer always lives server-side** (`phpcp-scheduler` calls
  // `rollback.run` every minute) — the number counting down on screen is
  // only ever a display, never something that decides anything, since the
  // case this mechanism is designed to handle is exactly the case where the browser has already been cut off
  //
  // Asks for status again every 15 seconds, and counts the number down
  // itself every second in between, so it never has to fire requests just that frequently only to make the number move
  // ---------------------------------------------------------------------------
  const ROLLBACK_POLL_MS = 15000;

  window.Now.getManager('component').define('rollback-bar', {
    template: '<div class="rollback-bar" hidden></div>',

    mounted() {
      const bar = this.element;
      let pending = null;

      const countdown = (seconds) => {
        const s = Math.max(0, seconds | 0);

        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
      };

      const paint = () => {
        if (!pending) {
          bar.hidden = true;
          bar.textContent = '';
          return;
        }

        bar.hidden = false;
        bar.textContent = '';

        const text = el('span', 'rollback-text');
        text.appendChild(el('strong', null, t('Waiting for confirmation')));
        text.appendChild(document.createTextNode(' — ' + pending.description));
        bar.appendChild(text);

        bar.appendChild(el('span', 'rollback-clock', countdown(pending.remaining_seconds)));

        // Both buttons use the framework's own `apiRefresh` — confirm ·
        // report the result · then have this bar ask for its status again via the phpcp:rollback signal
        const confirm = el('button', 'btn btn-primary icon-shield', t('Confirm — I can still reach this server'));
        confirm.type = 'button';
        confirm.dataset.action = 'click.prevent:apiRefresh';
        confirm.dataset.apiUrl = '/api/v2/rollbacks/' + pending.id + '/confirmation';
        confirm.dataset.apiMethod = 'post';
        confirm.dataset.notifySuccess = 'true';
        confirm.dataset.emitEvent = 'phpcp:rollback';
        bar.appendChild(confirm);

        const revert = el('button', 'btn icon-refresh', t('Revert now'));
        revert.type = 'button';
        revert.dataset.action = 'click.prevent:apiRefresh';
        revert.dataset.apiUrl = '/api/v2/rollbacks/' + pending.id + '/execution';
        revert.dataset.apiMethod = 'post';
        revert.dataset.confirm = 'Revert the change back to the previous value now?';
        revert.dataset.notifySuccess = 'true';
        revert.dataset.emitEvent = 'phpcp:rollback';
        bar.appendChild(revert);
      };

      const load = async () => {
        // A customer has no security.view permission — never asks at all, so the console never fills up with 403s
        if (!window.PhpcpAuth.can('security.view')) return;

        try {
          const rows = await window.PhpcpApi.get('/rollbacks');
          pending = Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
        } catch (error) {
          // Unable to ask means never guessing either — hiding the bar is better than showing a number that isn't real
          pending = null;
        }

        paint();
      };

      this._tick = window.setInterval(() => {
        if (!pending) return;

        pending.remaining_seconds -= 1;

        // Time's up: the scheduler is about to revert it — asks for the real status instead of guessing
        if (pending.remaining_seconds <= 0) {
          load();
          return;
        }

        paint();
      }, 1000);

      this._poll = window.setInterval(load, ROLLBACK_POLL_MS);
      this._off = window.EventManager.on('phpcp:rollback', load);

      load();
    },

    destroyed() {
      window.clearInterval(this._tick);
      window.clearInterval(this._poll);
      if (typeof this._off === 'function') this._off();
    }
  });

  // ---------------------------------------------------------------------------
  // The mode-warning bar — deliberately not dismissible (PLAN-V2 phase C1, item 5)
  //
  // In sandbox/dryrun mode, every command "succeeds" without touching the
  // real machine at all — if this bar could be dismissed, an admin could end
  // up believing they'd changed a value on the server when nothing actually happened
  // ---------------------------------------------------------------------------
  window.Now.getManager('component').define('mode-banner', {
    template: '<div class="mode-banner" hidden></div>',

    mounted() {
      // The template's own root has already replaced the host element — this.element is the bar itself
      const banner = this.element;

      const paint = (session) => {
        const parts = [];

        if (session.mode && session.mode !== 'production') {
          parts.push(t('Mode') + ': ' + (session.modeLabel || session.mode) + ' — ' + t('commands do not affect the real server'));
        }

        if (session.agentAvailable === false) {
          parts.push(t('Agent is not responding — commands that change the system will fail'));
        }

        banner.textContent = parts.join(' · ');
        banner.hidden = parts.length === 0;
      };

      paint(window.PhpcpAuth.snapshot());
      this._off = window.EventManager.on('phpcp:session', paint);
    },

    destroyed() {
      if (typeof this._off === 'function') this._off();
    }
  });


  // ---------------------------------------------------------------------------
  // A couple more declarative actions — registered through the framework's
  // own `registerAction` splice point, never a hand-bound event handler per button
  //
  // What's already provided and can be used directly: requestApi ·
  // toggleClass · copyToClipboard · exportCsv · print — never rewrite these
  // ---------------------------------------------------------------------------
  const events = window.Now.getManager('eventsystem') || window.EventSystemManager;

  /**
   * Fills a ready-made value into an input — an example button that gives a correct value immediately
   *
   *   data-fill-target   the id of the field to fill
   *   data-fill-value    the value to insert
   *
   * Exists for a value that's **hard to write correctly by hand, but where
   * only a few real-world shapes are ever actually used**, like a cron
   * schedule (`0 3 * * *`) · a user who can't remember the syntax can still
   * click to pick one, while someone who knows it can still type it
   * themselves as before, since the field stays free text, never replaced by a fixed list of choices
   *
   * Also fires `input` afterward — the framework's own validator and value
   * binding both listen for this event · just setting `value` triggers no event at all per the DOM standard
   */
  events.registerAction('fillField', (event, element) => {
    const field = document.getElementById(element.dataset.fillTarget || '');

    if (!field) return;

    field.value = element.dataset.fillValue || '';
    field.dispatchEvent(new Event('input', {bubbles: true}));
    field.focus();
  });

  /** Emits a signal so an ApiComponent with `data-refresh-event` set fetches a fresh round of data */
  events.registerAction('emit', (event, element) => {
    window.EventManager.emit(element.dataset.emitEvent || 'phpcp:reload', {trigger: element});
  });

  /**
   * Calls the API, then refreshes whatever needs to follow along
   *
   * Lets the framework's own `requestApi` do all the real work (confirm
   * first · a loading state · report success/failure), then only appends
   * the refresh afterward — never rewrites what already exists
   *
   *   data-refresh-table   the `data-table` name to reload
   *   data-emit-event      the signal name for ApiComponent (defaults to phpcp:reload)
   *   data-go              the route to navigate to on success (such as after deleting the resource currently open)
   */
  events.registerAction('apiRefresh', async (event, element) => {
    const requestApi = events.state.actions.get('requestApi');

    await requestApi(event, element);

    if (element.dataset.refreshTable) {
      window.TableManager.loadTableData(element.dataset.refreshTable, {force: true});
    }

    window.EventManager.emit(element.dataset.emitEvent || 'phpcp:reload', {trigger: element});

    /*
     * **Also asks the "waiting for confirmation" bar again every time**
     *
     * A command that scheduled a rollback (a firewall rule · an SSH setting
     * · a service's config file) must show the user the bar immediately,
     * never waiting for the next poll round, which is as much as 15 seconds
     * away · if they closed the page before that, they'd never find out they needed to confirm, and the value just set would revert silently
     *
     * Fires every time with no check for whether this particular command
     * scheduled anything — the bar's own `load()` already skips itself when
     * the user has no permission, and asking one extra time is far cheaper than missing it once
     */
    window.EventManager.emit('phpcp:rollback', {trigger: element});

    if (element.dataset.go) {
      window.RouterManager.navigate(element.dataset.go);
    }
  });

  /**
   * Opens phpMyAdmin without typing a password
   *
   * Has to be genuine code, not a declared link, because it's two steps that
   * can't be separated: a POST that carries the CSRF token (which is what
   * creates the phpMyAdmin-side session), then navigating to the resulting
   * URL · a plain `<a href>` link can never do this, since that's a GET with no token
   *
   *   data-db   the database name to open straight to its structure page (omitted = the front page)
   */
  events.registerAction('openPhpMyAdmin', async (event, element) => {
    try {
      const result = await window.PhpcpApi.post('/phpmyadmin/session', {db: element.dataset.db || ''});

      // A new tab, since phpMyAdmin is a separate app, not a page inside the panel
      window.open(result.url, '_blank', 'noopener');
    } catch (error) {
      window.NotificationManager.error(error.message);
    }
  });

  /**
   * Pushes a backup file out to the offsite destination
   *
   * **Has to be genuine code, not `data-row-actions`**, because the
   * filename belongs to the customer — they can name it anything at all ·
   * the value `data-row-actions` substitutes into a URL isn't encoded, so a
   * name with a space or Thai characters would lead to a URL that doesn't genuinely exist
   *
   * There's no destination picker anymore — a machine can only have one
   * destination (PLAN-BACKUP-V2 §4.2), so the server already knows the answer without having to ask
   */
  events.registerAction('pushOffsite', async (event, element) => {
    const path = '/backups/' + Number(element.dataset.backupUser)
      + '/' + encodeURIComponent(element.dataset.backupFile) + '/offsite-copy';

    try {
      const result = await window.PhpcpApi.post(path, {});

      window.NotificationManager.success(result.message);
      window.TableManager.loadTableData('backups', {force: true});
    } catch (error) {
      window.NotificationManager.error(error.message);
    }
  });

  /**
   * Reads the destination machine's host key to fill into the form
   *
   * **Has to be genuine code, not a `requestApi` declared with an attribute**
   *
   * `requestApi` never calls `ResponseHandler.process()` at all — it only
   * checks whether the response has an action of type `notification`, to
   * decide whether to show a message on its own, and stops there · an
   * `update` command meant to fill a field would therefore be dropped silently (confirmed from the framework's own source)
   *
   * Same reason as `pushOffsite` above: the values that need sending
   * (host/port) come from a field the user just typed into, and the result
   * has to land in a different field — neither of these can be declared with an attribute
   */
  events.registerAction('readHostKey', async (event, element) => {
    const form = element.closest('form');
    const field = form ? form.querySelector('[name="known_hosts"]') : null;
    const host = form ? (form.querySelector('[name="host"]') || {}).value : '';
    const port = form ? (form.querySelector('[name="port"]') || {}).value : '';

    if (!host || !String(host).trim()) {
      window.NotificationManager.error(window.Now.translate('Fill in the host first'));

      return;
    }

    const label = element.textContent;
    element.disabled = true;
    element.textContent = window.Now.translate('Reading...');

    try {
      const result = await window.PhpcpApi.post('/backup-destinations/host-key', {
        host: String(host).trim(),
        port: Number(port) || 22,
      });

      if (field) {
        field.value = result.known_hosts || '';
      }

      /*
       * The fingerprint has to stay visible to compare against, not a bar that disappears on its own
       *
       * This button can't verify the destination machine's identity for the
       * user — `ssh-keyscan` trusts whatever the other end answers with,
       * exactly like any first connection · the only thing an admin can
       * genuinely do is compare the fingerprint against the one read from
       * that machine's own console, which means switching screens to look
       */
      window.NotificationManager.success(result.message);

      const marks = document.querySelector('[data-host-key-fingerprint]');

      if (marks) {
        marks.hidden = false;
        marks.textContent = (result.fingerprints || []).join('\n');
      }
    } catch (error) {
      window.NotificationManager.error(error.message);
    } finally {
      element.disabled = false;
      element.textContent = label;
    }
  });

  // ---------------------------------------------------------------------------
  // Fills query string values into a template before rendering — `{id}` in an API route
  //
  // **Why this has to happen while it's still a string:** `ApiComponent`
  // fires its request the moment it's mounted, which happens during template
  // processing, before the page's own `data-script` ever gets to run · if
  // this waited to fix it up afterward, the first request would go out with a raw `{id}` and get a 404
  //
  // **Why this has to exist at all:** REST v2 references a resource by its
  // **route** (`/sites/{id}`) per §4.1, while Now.js's own `data-url-params`
  // can only fill in a query string
  //
  // Only substitutes a name that genuinely exists in the query, and encodes
  // every value — an unrecognized name is left as-is, so it's obvious the
  // template is referencing a parameter that doesn't exist, rather than turning into a route that looks normal but is wrong
  // ---------------------------------------------------------------------------
  const rawRender = window.RouterManager.render.bind(window.RouterManager);

  window.RouterManager.render = function(html) {
    if (typeof html === 'string' && html.indexOf('{') !== -1) {
      const query = new URLSearchParams(window.location.search);

      html = html.replace(/\{([a-z_][a-z0-9_]*)\}/gi, (match, name) =>
        query.has(name) ? encodeURIComponent(query.get(name)) : match);
    }

    return rawRender(html);
  };

  // ---------------------------------------------------------------------------
  // A table whose filter has to wait for its own options to load first
  //
  // The Logs and File Manager pages have a filter as a <select> whose
  // options come from their own endpoint (`/logs/sources`, `/files/roots`) ·
  // TableManager reads the filter's value on its first load, which happens
  // before the options ever arrive, so the first request goes out with no `source`/`root` and gets a 403
  //
  // Listens for ApiComponent's own `api:loaded` signal and triggers reloading
  // the named table exactly once — the template only needs to write
  // `data-reload-table="tableName"` on the element wrapping the <select>
  // ---------------------------------------------------------------------------
  // A table's filter changes -> the ApiComponent feeding that table must fetch again
  //
  // Only applies to a table bound via `data-attr` (never fires its own
  // request) — a table with `data-source` already reloads itself on its own, no one needs to trigger it
  window.EventManager.on('table:filterAction', (event) => {
    const form = document.querySelector('[data-table-filter="' + event.tableId + '"][data-emit-event]');

    if (form) {
      window.EventManager.emit(form.dataset.emitEvent, {tableId: event.tableId});
    }
  });

  document.addEventListener('api:loaded', (event) => {
    const host = event.target;

    if (host && host.dataset && host.dataset.reloadTable) {
      window.TableManager.loadTableData(host.dataset.reloadTable, {force: true});
    }
  });

  // ---------------------------------------------------------------------------
  // `data-reload-table` on a form — reloads a table after a successful save
  //
  // FormManager refreshes a table via the `actions[]` the server sends back
  // (see FRAMEWORK_GUIDE "Pattern 3"), but REST v2 answers with pure data per
  // §4.2, with no screen-driving command mixed in at all — and it shouldn't,
  // since that would bind the API to one specific screen
  //
  // So this splices into `ResponseHandler.process`, the one place
  // FormManager always calls after a successful submit, and which receives a
  // context that carries the form's own element · the result is a template
  // only ever needing to write `data-reload-table="tableName"`, like any other framework attribute
  // ---------------------------------------------------------------------------
  const rawProcess = window.ResponseHandler.process.bind(window.ResponseHandler);

  window.ResponseHandler.process = async function(payload, context) {
    const result = await rawProcess(payload, context);
    const form = context && context.form;

    if (form && form.dataset.reloadTable) {
      window.TableManager.loadTableData(form.dataset.reloadTable, {force: true});
    }

    if (form && form.dataset.emitEvent) {
      window.EventManager.emit(form.dataset.emitEvent, {form: form});
    }

    /*
     * A response that attaches the result of scheduling a rollback = there's
     * something waiting for confirmation, and the bar must come up
     * immediately · decided from the data in the response, never from who
     * fired the request — covers both a form and a table-row button, and
     * covers any new command added in the future with no need to touch this again
     *
     * Two names, since two layers name it differently: a capability returns
     * `rollback_id`, while the controller attaches `pending_rollback` for the screen to count down
     */
    /*
     * **Never write `payload.data || payload`** — FormManager never sends a
     * raw body in here, it sends the result of
     * `normalizeSubmitBindingPayload()`, which always fills in `data: []`
     * when the response has no such key · an empty array is truthy in JS, so
     * writing it that way would read the empty array instead of the payload, and the condition could never be true (genuinely hit this on 2026-08-13)
     *
     * Checks both the top level and the `data` level when it's an object,
     * since the two paths that call this method send different shapes: a
     * form sends the already-converted shape, a table-row button sends payloadOf()
     */
    const body = payload && typeof payload === 'object' ? payload : {};
    const nested = body.data && !Array.isArray(body.data) && typeof body.data === 'object' ? body.data : {};

    if (body.rollback_id || body.pending_rollback || nested.rollback_id || nested.pending_rollback) {
      window.EventManager.emit('phpcp:rollback', {});
    }

    return result;
  };

  // ---------------------------------------------------------------------------
  // Helpers every page shares
  // ---------------------------------------------------------------------------
  const Ui = {
    menu: MENU,

    /** Size in a human-readable unit — takes a value in bytes */
    bytes(value) {
      const n = Number(value) || 0;
      const units = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      let size = n;
      while (size >= 1024 && i < units.length - 1) {size /= 1024; i++;}
      return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
    },

    /**
     * Confirms a destructive command — SECURITY §4 defines three levels
     *
     * `type` must be 'normal' (click to confirm) | 'danger' (click to
     * confirm with a red warning) | 'critical' (must type the confirmation text exactly)
     *
     * The critical level uses Now.js's own `Modal` instead of the browser's
     * `prompt()`, since it needs to show the resource's name so the user can clearly compare which one is being deleted
     */
    async confirm(options) {
      const type = options.type || 'normal';
      const title = options.title || t('Please confirm');

      if (type !== 'critical') {
        return window.DialogManager.confirm(options.message, title, {
          className: type === 'danger' ? 'dialog-danger' : ''
        });
      }

      // The highest level: must type the resource's name exactly · compared
      // case-sensitively on purpose, since a domain name and a database name are things that must be read exactly right before deleting
      const answer = await window.DialogManager.prompt(options.message, '', title, {
        className: 'dialog-danger'
      });

      return typeof answer === 'string' && answer.trim() === options.expect;
    },

    /** Shows an error from PhpcpApi as a single message the user can actually read */
    error(err) {
      const fields = err && err.fields ? Object.values(err.fields) : [];
      const detail = fields.length > 0 ? fields.join(' · ') : '';
      window.NotificationManager.error(detail || (err && err.message) || t('Request failed'));
    },

    success(message) {
      window.NotificationManager.success(message || t('Saved successfully'));
    }
  };

  window.PhpcpUi = Ui;
})();
