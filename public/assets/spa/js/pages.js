/**
 * Per-page scripts — called by Now.js's `data-script="functionName"`
 *
 * **This file's rule: nothing here that a data-attribute could do instead**
 *
 * What the framework already does, and must never be rewritten here:
 *
 *   Table              `data-table` + `data-source` (fires its own requests,
 *                      paginates, sorts, and searches itself) or
 *                      `data-attr="data:name"` to bind to already-loaded data
 *   A loaded page       `data-component="api"` + `data-endpoint`, then bind values with `data-text`
 *   A single piece      `data-refresh-event` lets a button trigger a reload
 *   of data
 *   Form                `data-form` + `data-load-api` + `data-method` + `data-ajax-submit`
 *                      binds existing values with `data-attr="value:field"` / `checked:field`
 *   API-calling button  `data-action="click.prevent:requestApi"` (or our own `apiRefresh`)
 *                      + `data-api-url` `data-api-method` `data-confirm` `data-notify-success`
 *   Formatting a value   the `data-text="x | bytes"` pipe and `data-formatter` — see js/formatters.js
 *
 * Only two things are left here, because they genuinely can't be declared:
 *   1. Anything that must change the app's **session state** (login · 2FA
 *      verification · password change) — FormManager can submit a form, but doesn't know PhpcpAuth or where to navigate next
 *   2. The server overview page's **SSE** — a stream, not a single request
 *
 * Hiding a section with no permission is now entirely the framework's job —
 * `data-if="permissions[...]"` everywhere, no `data-can`/`showByPermission` left in any template anymore
 */
(function() {
  'use strict';

  const t = (text, params) => window.Now.translate(text, params);

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  /** A status pill — the same shape formatters.js uses in table cells */
  const pill = (text, tone) => el('span', 'pill pill-' + (tone || 'muted'), text);

  /** Binds a form that must change the app's session state — only three pages qualify */
  function sessionForm(root, submit) {
    const form = root.querySelector('form');

    const handler = async (event) => {
      event.preventDefault();

      const button = form.querySelector('button[type=submit]');
      button.disabled = true;

      try {
        await submit(form);
      } catch (error) {
        window.PhpcpUi.error(error);
      } finally {
        button.disabled = false;
      }
    };

    form.addEventListener('submit', handler);

    return () => form.removeEventListener('submit', handler);
  }

  // ===========================================================================
  // Login · 2FA verification · Password change
  // ===========================================================================
  window.pageLogin = (root) => sessionForm(root, async (form) => {
    try {
      const session = await window.PhpcpAuth.login({
        username: form.username.value.trim(),
        password: form.password.value
      });

      window.RouterManager.navigate(session.authenticated ? window.PhpcpAuth.takeIntended() : '/login-2fa');
    } catch (error) {
      // 401 TWO_FACTOR_REQUIRED isn't a failure — the password was correct, only verification is left
      if (error.code === 'TWO_FACTOR_REQUIRED') {
        await window.PhpcpAuth.refresh();
        window.RouterManager.navigate('/login-2fa');
        return;
      }

      form.password.value = '';
      form.password.focus();
      throw error;
    }
  });

  window.pageTwoFactor = (root) => sessionForm(root, async (form) => {
    try {
      await window.PhpcpAuth.verifyTwoFactor(form.code.value.trim());
      window.RouterManager.navigate(window.PhpcpAuth.takeIntended());
    } catch (error) {
      form.code.value = '';
      form.code.focus();
      throw error;
    }
  });

  window.pageChangePassword = (root) => sessionForm(root, async (form) => {
    await window.PhpcpApi.patch('/me/password', {
      current_password: form.current_password.value,
      new_password: form.new_password.value
    });

    window.PhpcpUi.success(t('Password changed'));
    await window.PhpcpAuth.refresh();
    window.RouterManager.navigate('/');
  });

  // ===========================================================================
  // A detail page referencing a resource by id
  //
  // Filling `{id}` into the route happens at `RouterManager.render` (see
  // js/ui.js) while the template is still a string · the only job left here
  // is guarding against the page being opened with no id at all
  // ===========================================================================
  window.pageDetail = function(root) {
    if (!new URLSearchParams(window.location.search).get('id')) {
      // A broken link — going back to the list is better than showing a blank page with no idea why
      window.RouterManager.navigate(root.dataset.fallback || '/');
    }
  };

  // ===========================================================================
  // Security score
  //
  // **Why this needs JS at all:** the overall score lives in the response's
  // `meta` (since `data` is the list of checks), and **no Now.js directive
  // can bind to meta** — ApiComponent only sends `data` into a template's
  // context · the checks table itself is still fully declarative as normal
  // ===========================================================================
  window.pageSecurityScore = async function(root) {
    try {
      const result = await window.PhpcpApi.getFull('/security/scan');
      const score = Number(result.meta.score) || 0;

      root.querySelector('[data-score]').textContent = score + ' / 100 · ' + (result.meta.grade || '');
      root.querySelector('[data-score-meter] span').style.width = Math.max(0, Math.min(100, score)) + '%';
      root.querySelector('[data-score-meter]').classList.toggle('is-crit', score < 50);
      root.querySelector('[data-score-meter]').classList.toggle('is-warn', score >= 50 && score < 80);
    } catch (error) {
      // The checks table loads separately and can still come up fine — losing only the score needs no error shown
    }
  };

  // ===========================================================================
  // Server overview — live values via SSE (phase C4)
  //
  // The first batch of numbers and machine info comes from the template's
  // own ApiComponent · the only thing done here is receiving the stream and
  // overwriting the numbers live every 2 seconds, which isn't a single request and so can't be declared
  // ===========================================================================
  /**
   * The history graph's time-range picker buttons — PLAN-V2 phase E6
   *
   * The graph fetches its own data via `data-url` (the API answers in the
   * shape `[{name, data:[{label,value}]}]`, which `GraphComponent` reads
   * directly) — all that's left is **switching the url**, which can't be
   * declared with an attribute since it has to be bound to a click and has to tell the component to reload
   */
  /**
   * How often the graph reloads, based on the selected range (milliseconds)
   *
   * The data collector (`metrics.record`) writes one row per minute, so the
   * most meaningful frequency is one minute — anything more frequent just
   * gets the same data back · a longer range already moves slowly (one point
   * on a 30-day graph is an average across several hours), so it doesn't need to be asked as often
   */
  const METRICS_REFRESH_MS = {'20m': 60000, '24h': 60000, '7d': 300000, '30d': 900000, '1y': 900000};

  function metricsRanges(root) {
    const holder = root.querySelector('[data-metrics-graph]');
    const buttons = Array.from(root.querySelectorAll('[data-metrics-ranges] [data-range]'));
    const stamp = root.querySelector('[data-metrics-updated]');

    if (!holder || buttons.length === 0) return null;

    let range = (buttons.find(b => b.classList.contains('is-active')) || buttons[0]).dataset.range;

    const load = () => {
      const instance = window.GraphComponent && window.GraphComponent.getInstance(holder);
      if (!instance) return false;

      // **Must change `instance.options.url`, never the element's `data-url`**
      // `refresh()` loads from the options remembered at creation time, it
      // never reads the dataset again — confirmed in a real browser that
      // editing the dataset does nothing to the graph at all (2026-08-10)
      instance.options.url = '/api/v2/metrics/history?range=' + encodeURIComponent(range);
      instance.refresh();

      if (stamp) {
        // States when the data was genuinely loaded, instead of just letting
        // the graph shift silently — an admin needs to tell apart "the graph
        // is still because it reloaded and got the same value" from "the
        // graph is still because it's stuck," which look identical otherwise
        stamp.hidden = false;
        stamp.textContent = (window.Now && Now.translate ? Now.translate('Updated') : 'Updated')
          + ' ' + new Date().toLocaleTimeString();
      }

      return true;
    };

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        buttons.forEach(b => b.classList.toggle('is-active', b === button));
        range = button.dataset.range;
        load();
      });
    });

    return {
      load,
      interval: () => METRICS_REFRESH_MS[range] || 60000,
    };
  }

  /**
   * Reloads the graph on the same cadence as the data collector
   *
   * **Stops when the tab isn't visible** — a page left open overnight would
   * fire thousands of requests with nobody ever seeing a single one · fires
   * one immediate reload on returning, since the data has gone stale by then
   *
   * Timed to land on second 5 of the next minute, not just a plain
   * setInterval — the collector runs at the start of the minute, and asking
   * before it finishes writing would get the previous minute's data, leaving the graph permanently one minute behind
   */
  function metricsAutoRefresh(graph) {
    if (!graph) return () => {};

    let timer = null;

    const schedule = () => {
      clearTimeout(timer);

      const step = graph.interval();
      const now = Date.now();
      const delay = step === 60000
        ? (60000 - (now % 60000)) + 5000
        : step;

      timer = setTimeout(() => {
        if (document.visibilityState === 'visible') graph.load();
        schedule();
      }, delay);
    };

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        graph.load();
        schedule();
      } else {
        clearTimeout(timer);
      }
    };

    document.addEventListener('visibilitychange', onVisible);
    schedule();

    return () => {
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }

  function logLineKey(line, counts) {
    const text = String(line.text || '');
    const level = String(line.level || '');
    const base = level + '\u0000' + text;
    const count = (counts.get(base) || 0) + 1;
    counts.set(base, count);

    return base + '\u0000' + count;
  }

  const LOG_HIGHLIGHTS = [
    {className: 'log-token-error', regex: /\b(?:error|failed|failure|fatal|panic|denied|invalid|exception|critical)\b/ig},
    {className: 'log-token-warn', regex: /\b(?:warn|warning|timeout|retry|slow|deprecated|blocked)\b/ig},
    {className: 'log-token-ok', regex: /\b(?:ok|success|successful|started|completed|accepted|enabled)\b/ig},
    {className: 'log-token-status-danger', regex: /\b5\d{2}\b/g},
    {className: 'log-token-status-warn', regex: /\b4\d{2}\b/g},
    {className: 'log-token-status-ok', regex: /\b[23]\d{2}\b/g},
    {className: 'log-token-ip', regex: /\b(?:\d{1,3}\.){3}\d{1,3}\b/g},
    {className: 'log-token-time', regex: /\b\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/g},
    {className: 'log-token-time', regex: /\b[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\b/g},
    {className: 'log-token-method', regex: /\b(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/g},
    {className: 'log-token-path', regex: /(?:^|[\s"'(])(?:\/[^\s"')\]]+|\w+:\/\/[^\s"')\]]+)/g}
  ];

  function highlightedLogFragments(text) {
    const value = String(text || '');
    const ranges = [];

    LOG_HIGHLIGHTS.forEach((rule) => {
      rule.regex.lastIndex = 0;

      let match;
      while ((match = rule.regex.exec(value)) !== null) {
        const raw = match[0];
        const leading = raw.match(/^[\s"'(]/)?.[0] || '';
        const start = match.index + leading.length;
        const end = match.index + raw.length;

        if (start >= end || ranges.some((range) => start < range.end && end > range.start)) continue;
        ranges.push({start, end, className: rule.className});
      }
    });

    ranges.sort((a, b) => a.start - b.start);

    const fragments = [];
    let cursor = 0;

    ranges.forEach((range) => {
      if (range.start > cursor) {
        fragments.push({text: value.slice(cursor, range.start), className: ''});
      }

      fragments.push({text: value.slice(range.start, range.end), className: range.className});
      cursor = range.end;
    });

    if (cursor < value.length) {
      fragments.push({text: value.slice(cursor), className: ''});
    }

    return fragments.length > 0 ? fragments : [{text: value, className: ''}];
  }

  function paintLogMessage(container, text) {
    const fragment = document.createDocumentFragment();

    highlightedLogFragments(text).forEach((part) => {
      const node = part.className ? el('span', part.className) : document.createTextNode('');
      node.textContent = part.text;
      fragment.appendChild(node);
    });

    container.replaceChildren(fragment);
  }

  function updateLogRow(row, line) {
    const cells = row._logCells;
    const label = line.level_label || line.level || 'line';
    const tone = line.level_tone || 'muted';

    cells.number.textContent = String(line.n || '');
    cells.level.replaceChildren(pill(t(label), tone));
    paintLogMessage(cells.message, line.text || '');

    row.dataset.tone = tone;
  }

  function createLogRow(line) {
    const row = el('div', 'log-tail-line');
    const number = el('span', 'log-tail-number');
    const level = el('span', 'log-tail-level');
    const message = el('span', 'log-tail-message mono');

    row._logCells = {number, level, message};
    row.append(number, level, message);
    updateLogRow(row, line);

    return row;
  }

  /**
   * The Logs page — a custom tail viewer instead of a TableManager table
   *
   * **Deliberately doesn't use SSE** — the panel's pool has `pm.max_children
   * = 4`, and a single stream holds one worker hostage until it times out ·
   * a dashboard left open already takes one · if this page took another too,
   * two admins with both pages open at once would hang the whole panel ·
   * polling, on the other hand, is a short request that returns its worker every time
   *
   * Defaults to off — a page that auto-refreshes with nobody asking for it is the hardest kind to read while actively troubleshooting
   */
  window.pageLogs = function(root) {
    const picker = root.querySelector('[data-log-refresh]');
    const stamp = root.querySelector('[data-log-updated]');
    const form = root.querySelector('[data-log-form]');
    const viewer = root.querySelector('[data-log-tail]');
    const linesEl = root.querySelector('[data-log-lines]');
    const empty = root.querySelector('[data-log-empty]');

    if (!picker || !form || !viewer || !linesEl) return () => {};

    let timer = null;
    let destroyed = false;
    let loading = false;
    let loadId = 0;
    let lastSignature = '';
    let searchTimer = null;

    const selectedParams = () => {
      const data = new FormData(form);
      const params = {};
      const source = String(data.get('source') || '').trim();
      const pageSize = Number(data.get('pageSize')) || 20;
      const search = String(data.get('q') || '').trim();

      if (source) params.source = source;
      params.pageSize = pageSize;
      if (search) params.q = search;

      return params;
    };

    const nearBottom = () => viewer.scrollHeight - viewer.scrollTop - viewer.clientHeight < 32;

    const schedule = () => {
      clearTimeout(timer);

      const seconds = Number(picker.value) || 0;
      if (destroyed || seconds <= 0) return;

      timer = setTimeout(async () => {
        await load({automatic: true});
        schedule();
      }, seconds * 1000);
    };

    const render = (rows) => {
      const signature = rows.map((line) => [
        line.n || '',
        line.level || '',
        line.level_tone || '',
        line.level_label || '',
        line.text || ''
      ].join('\u0001')).join('\u0002');

      if (signature === lastSignature) return false;

      const shouldPinToBottom = nearBottom();
      const oldRows = new Map();
      const fragment = document.createDocumentFragment();
      const counts = new Map();

      Array.from(linesEl.children).forEach((row) => {
        if (row.dataset.key) oldRows.set(row.dataset.key, row);
      });

      rows.forEach((line) => {
        const key = logLineKey(line, counts);
        const row = oldRows.get(key) || createLogRow(line);

        row.dataset.key = key;
        updateLogRow(row, line);

        if (!oldRows.has(key)) {
          row.classList.add('is-new');
          window.setTimeout(() => row.classList.remove('is-new'), 650);
        }

        fragment.appendChild(row);
      });

      linesEl.replaceChildren(fragment);
      lastSignature = signature;

      if (empty) empty.hidden = rows.length > 0;
      linesEl.hidden = rows.length === 0;

      if (shouldPinToBottom) {
        requestAnimationFrame(() => {
          viewer.scrollTop = viewer.scrollHeight;
        });
      }

      return true;
    };

    async function load(options = {}) {
      if (loading && options.automatic) return;

      const id = ++loadId;
      loading = true;
      linesEl.setAttribute('aria-busy', 'true');

      try {
        const result = await window.PhpcpApi.getFull('/logs', selectedParams());
        if (destroyed || id !== loadId) return;

        const rows = Array.isArray(result.data) ? result.data : [];
        render(rows);

        if (stamp) {
          stamp.hidden = false;
          stamp.textContent = t('Updated') + ' ' + new Date().toLocaleTimeString();
        }
      } catch (error) {
        if (!destroyed && id === loadId) window.PhpcpUi.error(error);
      } finally {
        if (!destroyed && id === loadId) {
          loading = false;
          linesEl.setAttribute('aria-busy', 'false');
        }
      }
    }

    const onChange = () => {
      const seconds = Number(picker.value) || 0;

      // Turned off must also remove the timestamp, or a lingering one reads as though it's still refreshing
      if (stamp && seconds === 0) {
        stamp.hidden = true;
        stamp.textContent = '';
      }

      schedule();
    };

    const onFilterChange = () => {
      lastSignature = '';
      load();
      schedule();
    };

    const onSearch = () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(onFilterChange, 250);
    };

    const onSubmit = (event) => {
      event.preventDefault();
      onFilterChange();
    };

    const onApiLoaded = (event) => {
      const target = event.target;

      if (target && root.contains(target)
        && (target.matches?.('#log_source') || target.querySelector?.('#log_source'))) {
        onFilterChange();
      }
    };

    picker.addEventListener('change', onChange);
    form.addEventListener('change', onFilterChange);
    form.addEventListener('input', onSearch);
    form.addEventListener('submit', onSubmit);
    document.addEventListener('api:loaded', onApiLoaded);

    load();

    return () => {
      destroyed = true;
      clearTimeout(timer);
      clearTimeout(searchTimer);
      picker.removeEventListener('change', onChange);
      form.removeEventListener('change', onFilterChange);
      form.removeEventListener('input', onSearch);
      form.removeEventListener('submit', onSubmit);
      document.removeEventListener('api:loaded', onApiLoaded);
    };
  };

  window.pageServer = function(root) {
    // EventSource used directly, not through Now.js, per the plan (C4) — the
    // session cookie rides along on its own since it's the same origin, and the browser auto-reconnects on drop
    const stream = new EventSource('/api/v2/metrics/stream');
    const stopAutoRefresh = metricsAutoRefresh(metricsRanges(root));

    const paint = (metrics) => {
      const set = (selector, value) => {
        const node = root.querySelector(selector);
        if (node) node.textContent = value;
      };
      const meter = (selector, percent) => {
        const node = root.querySelector(selector);
        if (node) node.style.width = Math.max(0, Math.min(100, Number(percent) || 0)) + '%';
      };

      set('[data-live-cpu]', window.formatters.fixed(metrics.cpu && metrics.cpu.percent) + '%');
      meter('[data-live-cpu-meter]', metrics.cpu && metrics.cpu.percent);
      set('[data-live-mem]', window.formatters.bytes(metrics.memory && metrics.memory.used));
      meter('[data-live-mem-meter]', metrics.memory && metrics.memory.percent);
      set('[data-live-disk]', window.formatters.bytes(metrics.disk && metrics.disk.used));
      meter('[data-live-disk-meter]', metrics.disk && metrics.disk.percent);
    };

    // **Must listen for the name `metrics`, never `message`** — the server
    // sends `event: metrics` on every round (MetricsController::send), and
    // per the SSE spec, a named event never reaches a `message` listener at
    // all · `message` exists only for text with no name given — this is why
    // the card never moved even once before, despite the stream genuinely sending data
    stream.addEventListener('metrics', (event) => {
      try {
        paint(JSON.parse(event.data));
      } catch (e) {
        /* A line that can't be parsed is skipped — the next one arrives in 2 seconds */
      }
    });

    // The server closes the stream on schedule (30 minutes) and lets the
    // browser reconnect on its own — not a failure, must never be closed
    // for good, or the card would stop moving permanently until the page is reloaded
    let expectReconnect = false;
    stream.addEventListener('bye', () => {
      expectReconnect = true;
    });

    /*
      The name `error` arrives via two paths that must be told apart:

        1. The server sends `event: error` because the agent stumbled — **does** come with data
           The server side tolerates three rounds before giving up on its own, so nothing needs to be done here
        2. The connection genuinely dropped (session expired, network cut) — no data
           Closed for good so it doesn't reconnect endlessly against an endpoint answering 401 every time
    */
    stream.addEventListener('error', (event) => {
      if (event && typeof event.data === 'string') return;

      if (expectReconnect) {
        expectReconnect = false;
        return;
      }

      stream.close();
    });

    return () => {
      stream.close();
      stopAutoRefresh();
    };
  };

  // ===========================================================================
  // SQLite Manager — the one page that stays scripted
  // ===========================================================================
  // Everything about a SQLite table is dynamic: which table is picked, what
  // columns it has, what a query returns. So the rows and query tables are
  // TableManager tables with dynamic columns — the backend ships column
  // descriptors with every response (`{field, label, cellClass}`) and
  // TableManager builds the headers itself. This page only decides which
  // endpoint feeds the rows table (its data-source is rewritten per selected
  // table; paging and sorting are then TableManager's own server-side
  // round-trips) and pushes data into the other tables with setData().
  window.pageSqlite = function(root) {
    let tablesData = [];
    let currentTable = '';
    let structureLoaded = false;

    // --- Elements ------------------------------------------------------------
    const listEl = root.querySelector('[data-sqlite-table-list]');
    const filterEl = root.querySelector('[data-sqlite-table-filter]');

    const statusTableEl = root.querySelector('[data-sqlite-status-table]');
    const statusMetaEl = root.querySelector('[data-sqlite-status-meta]');
    const statusPageEl = root.querySelector('[data-sqlite-status-page]');

    const schemaSqlEl = root.querySelector('[data-sqlite-schema-sql]');

    const queryInput = root.querySelector('#sqlite-query-input');
    const runQueryBtn = root.querySelector('[data-sqlite-run-query]');
    const clearQueryBtn = root.querySelector('[data-sqlite-clear-query]');
    const queryResultsEl = root.querySelector('[data-sqlite-query-results]');
    const queryMetaEl = root.querySelector('[data-sqlite-query-meta]');
    const queryStatusEl = root.querySelector('[data-sqlite-query-status]');

    const searchInput = root.querySelector('#sqlite-search-input');
    const runSearchBtn = root.querySelector('[data-sqlite-run-search]');
    const searchResultsEl = root.querySelector('[data-sqlite-search-results]');
    const searchCountEl = root.querySelector('[data-sqlite-search-count]');
    const searchBodyEl = root.querySelector('[data-sqlite-search-body]');

    /**
     * A table's TableManager state — registered on demand, since the dynamic
     * observer may not have run yet when the page script starts
     */
    const tableState = (name) => {
      const element = root.querySelector('table[data-table="' + name + '"]');
      if (!element) return null;
      if (!window.TableManager.state.tables.has(name)) window.TableManager.initTable(element);
      return window.TableManager.state.tables.get(name);
    };

    // --- Lazy-load the structure tab the first time it is opened -------------
    root.querySelectorAll('[data-component="tabs"]').forEach((tabsEl) => {
      tabsEl.addEventListener('tabs:tabchange', (event) => {
        if (event.detail && event.detail.tabId === 'structure' && !structureLoaded) {
          structureLoaded = true;
          loadStructure();
        }
      });
    });

    // --- Toolbar refresh -------------------------------------------------------
    // The framework's emit action already refreshes the db-info bar above;
    // the table list is this page's own state, so it reloads here
    const refreshBtn = root.querySelector('[data-sqlite-refresh]');
    if (refreshBtn) refreshBtn.addEventListener('click', () => loadTables());

    // --- Table list ----------------------------------------------------------
    async function loadTables() {
      try {
        tablesData = (await window.PhpcpApi.get('/sqlite/tables')) || [];
        renderTableList(tablesData);
        if (tablesData.length > 0) selectTable(tablesData[0].name);
      } catch (error) {
        if (listEl) listEl.replaceChildren(el('li', 'comment', error.message || 'Error loading tables'));
      }
    }

    function renderTableList(tables) {
      if (!listEl) return;

      if (tables.length === 0) {
        listEl.replaceChildren(el('li', 'comment', t('No tables found')));
        return;
      }

      const fragment = document.createDocumentFragment();
      tables.forEach((table) => {
        const li = el('li', table.name === currentTable ? 'is-active' : '');
        li.appendChild(el('span', 'mono', table.name));
        li.appendChild(el('span', 'sqm-rows', String(table.row_count || 0)));
        li.addEventListener('click', () => selectTable(table.name));
        fragment.appendChild(li);
      });
      listEl.replaceChildren(fragment);
    }

    if (filterEl) {
      filterEl.addEventListener('input', () => {
        const term = filterEl.value.toLowerCase().trim();
        renderTableList(tablesData.filter((table) => table.name.toLowerCase().includes(term)));
      });
    }

    // --- Selected table: schema + rows ----------------------------------------
    function selectTable(tableName) {
      currentTable = tableName;
      if (statusTableEl) statusTableEl.textContent = tableName;
      renderTableList(tablesData);

      const rowsTable = tableState('sqliteRows');
      if (rowsTable) {
        // The rows table has no fixed data-source — it points at whichever
        // table is selected. Sorting and paging from here on are
        // TableManager's own requests back to this endpoint.
        rowsTable.element.dataset.source = '/api/v2/sqlite/tables/' + encodeURIComponent(tableName) + '/rows';
        rowsTable.sortState = {};
        rowsTable.config.params.page = 1;
        window.TableManager.loadTableData('sqliteRows', {force: true});
      }

      loadSchema(tableName);
    }

    async function loadSchema(tableName) {
      try {
        const schema = (await window.PhpcpApi.get('/sqlite/tables/' + encodeURIComponent(tableName))) || {};

        if (statusMetaEl) {
          statusMetaEl.textContent = (schema.columns || []).length + ' ' + t('columns')
            + ' · ' + (schema.row_count || 0) + ' ' + t('rows');
        }

        // Field names match the thead in sqlite.html (pk · notnull · dflt)
        window.TableManager.setData('sqliteSchema', (schema.columns || []).map((col) => ({
          cid: col.cid,
          name: col.name,
          type: col.type || '',
          pk: col.primary_key ? t('Yes') : '—',
          notnull: col.notnull ? t('Yes') : '—',
          dflt: col.default_value === null || col.default_value === undefined ? 'NULL' : col.default_value
        })));

        if (schemaSqlEl) schemaSqlEl.textContent = schema.sql || '';
      } catch (error) {
        if (statusMetaEl) statusMetaEl.textContent = '';
        if (schemaSqlEl) schemaSqlEl.textContent = '';
        window.PhpcpUi.error(error);
      }
    }

    // --- Status bar follows every rows-table render ---------------------------
    // EventManager hands its listener the whole context — the payload is in `.data`
    const onRowsRender = (context) => {
      const data = (context && context.data) || {};
      if (!statusPageEl || data.tableId !== 'sqliteRows') return;

      const params = window.TableManager.state.tables.get('sqliteRows');
      const current = params ? params.config.params : {};

      statusPageEl.textContent = t('Page {page} of {pages}', {
        page: current.page || 1,
        pages: current.totalPages || 1
      }) + ' · ' + (current.total || 0) + ' ' + t('rows');
    };

    window.EventManager.on('table:render', onRowsRender);

    // --- Export ---------------------------------------------------------------
    root.querySelectorAll('[data-sqlite-export]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!currentTable) return;
        const format = button.dataset.sqliteExport;
        try {
          const data = await window.PhpcpApi.get('/sqlite/tables/' + encodeURIComponent(currentTable) + '/export', {format: format});
          const content = format === 'csv' ? data.content : JSON.stringify(data.rows, null, 2);
          const blob = new Blob([content], {type: format === 'csv' ? 'text/csv' : 'application/json'});
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = currentTable + '.' + format;
          link.click();
          URL.revokeObjectURL(url);
        } catch (error) {
          window.PhpcpUi.error(error);
        }
      });
    });

    // --- SQL console ----------------------------------------------------------
    if (runQueryBtn) {
      runQueryBtn.addEventListener('click', async () => {
        const sql = queryInput ? queryInput.value.trim() : '';
        if (!sql) {
          window.PhpcpUi.error(t('SQL query is required'));
          return;
        }

        runQueryBtn.disabled = true;
        const started = performance.now();

        try {
          const data = (await window.PhpcpApi.post('/sqlite/query', {sql: sql})) || {};
          const elapsed = Math.round(performance.now() - started);

          if (queryResultsEl) queryResultsEl.hidden = false;
          if (queryStatusEl) queryStatusEl.textContent = t('Query executed successfully');
          if (queryMetaEl) {
            queryMetaEl.textContent = (data.row_count || 0) + ' ' + t('rows') + ' · ' + elapsed + ' ms'
              + (data.truncated ? ' · ' + t('truncated') : '');
          }

          // Columns arrive as TableManager descriptors straight from the
          // backend — the dynamic headers rebuild themselves per query
          window.TableManager.setData('sqliteQuery', {
            data: data.rows || [],
            columns: data.columns || [],
            meta: {total: (data.rows || []).length}
          });
        } catch (error) {
          if (queryResultsEl) queryResultsEl.hidden = false;
          if (queryStatusEl) queryStatusEl.textContent = t('Query failed');
          if (queryMetaEl) queryMetaEl.textContent = '';
          window.PhpcpUi.error(error);
        } finally {
          runQueryBtn.disabled = false;
        }
      });
    }

    if (clearQueryBtn) {
      clearQueryBtn.addEventListener('click', () => {
        if (queryInput) queryInput.value = '';
        if (queryResultsEl) queryResultsEl.hidden = true;
      });
    }

    root.querySelectorAll('[data-sqlite-query-preset]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        if (queryInput) queryInput.value = link.dataset.sqliteQueryPreset;
      });
    });

    // --- Global search ---------------------------------------------------------
    // Deliberately a plain tbody, not a data table: every match comes from a
    // different table, so there is no shared column set to build headers from
    function runSearch() {
      const term = searchInput ? searchInput.value.trim() : '';
      if (term.length < 2) {
        window.PhpcpUi.error(t('Search term must be at least 2 characters'));
        return;
      }

      runSearchBtn.disabled = true;

      window.PhpcpApi.get('/sqlite/search', {q: term}).then((matches) => {
        matches = matches || [];

        if (searchResultsEl) searchResultsEl.hidden = false;
        if (searchCountEl) searchCountEl.textContent = matches.length + ' ' + t('matches');

        if (matches.length === 0) {
          const empty = el('tr');
          empty.appendChild(el('td', 'comment', t('No data available')));
          searchBodyEl.replaceChildren(empty);
          return;
        }

        const fragment = document.createDocumentFragment();
        matches.forEach((match) => {
          const tr = document.createElement('tr');
          const label = el('td', '', '');
          label.appendChild(pill(match.table, 'muted'));
          const data = el('td', '', '');
          const pre = el('pre', 'sqm-sql mono');
          pre.textContent = JSON.stringify(match.row, null, 2);
          data.appendChild(pre);
          tr.appendChild(label);
          tr.appendChild(data);
          fragment.appendChild(tr);
        });
        searchBodyEl.replaceChildren(fragment);
      }).catch((error) => {
        if (searchResultsEl) searchResultsEl.hidden = false;
        const failed = el('tr');
        failed.appendChild(el('td', 'comment', error.message));
        searchBodyEl.replaceChildren(failed);
      }).finally(() => {
        runSearchBtn.disabled = false;
      });
    }

    if (runSearchBtn) runSearchBtn.addEventListener('click', runSearch);
    if (searchInput) searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') runSearch();
    });

    // --- Indexes & triggers ------------------------------------------------------
    async function loadStructure() {
      try {
        const [indexes, triggers] = await Promise.all([
          window.PhpcpApi.get('/sqlite/indexes'),
          window.PhpcpApi.get('/sqlite/triggers')
        ]);

        // Field names match the theads in sqlite.html (tbl · uniq)
        window.TableManager.setData('sqliteIndexes', (indexes || []).map((idx) => ({
          name: idx.name,
          tbl: idx.table || '—',
          uniq: idx.unique ? t('Yes') : '—'
        })));

        window.TableManager.setData('sqliteTriggers', (triggers || []).map((name) => ({name: name})));
      } catch (error) {
        window.PhpcpUi.error(error);
      }
    }

    loadTables();

    return () => window.EventManager.off('table:render', onRowsRender);
  };
})();
