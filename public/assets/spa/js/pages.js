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

  const t = (text) => window.Now.translate(text);

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

  /**
   * The Logs page — binds the "auto-refresh" dropdown to TableManager's own timer
   *
   * All the polling itself belongs to the framework
   * (`data-refresh-interval` / `setRefreshInterval`) — only two things are
   * left here that can't be declared with a data-attribute: reading the
   * dropdown's value and forwarding it, and writing down when data last arrived
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

    if (!picker) return () => {};

    const onChange = () => {
      const seconds = Number(picker.value) || 0;

      window.TableManager.setRefreshInterval('logLines', seconds);

      // Turned off must also remove the timestamp, or a lingering one reads as though it's still refreshing
      if (stamp && seconds === 0) {
        stamp.hidden = true;
        stamp.textContent = '';
      }
    };

    // States when data genuinely arrived — without this line, "the log has
    // no new lines" and "refreshing has died" look completely identical,
    // which are two very different things while sitting there watching for whether anyone is hitting the site

    const onRefreshed = (event) => {
      if (!stamp || event?.detail?.tableId !== 'logLines') return;

      stamp.hidden = false;
      stamp.textContent = t('Updated') + ' ' + new Date().toLocaleTimeString();
    };

    picker.addEventListener('change', onChange);
    window.EventManager.on('table:refreshed', onRefreshed);

    return () => {
      picker.removeEventListener('change', onChange);
      window.EventManager.off('table:refreshed', onRefreshed);

      // Leaving the page must never leave a trailing request behind · the
      // framework's own destroyTable() already stops it, but the order
      // between tearing down the page and tearing down the table isn't guaranteed
      window.TableManager.setRefreshInterval('logLines', 0);
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
})();
