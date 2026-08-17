/**
 * The web page's session and permissions — PLAN-V2 phase C1, item 4
 *
 * **Deliberately doesn't use Now.js's own AuthManager** (building on decision
 * N6): AuthManager is designed for a token JS itself holds — it stores the
 * user in localStorage, renews the token itself, and expects a response
 * shaped {success, token, user} · phpcp gives JS no token at all, not even
 * one — the entire truth lives in the HttpOnly cookie plus the session table
 * on the server · forcing AuthManager in would just create a second copy of state that could never match the real one
 *
 * Replaced with something smaller that matches the system: call `GET
 * /api/v2/session` once when the app opens (one request gets the full
 * picture — login status, CSRF token, mode, agent status, and the
 * permission list) and let the router check that status before entering every page
 *
 * **The permissions stored here are only used to show/hide menu items** —
 * §4.4 states plainly that real permission enforcement still lives at the
 * middleware and the agent, exactly as before — never assume hiding a button makes anything safe
 */
(function () {
  'use strict';

  const state = {
    ready: false,
    authenticated: false,
    twoFactorPending: false,
    mustChangePassword: false,
    user: null,
    permissions: {},
    mode: 'production',
    modeLabel: '',
    agentAvailable: true
  };

  /** Routes reachable without being signed in */
  const PUBLIC_ROUTES = ['/login', '/login-2fa'];

  /**
   * Stores the CSRF token everywhere the framework goes to fetch it
   *
   * There are genuinely three places, not one: `HttpClient` stores it in
   * itself · `simpleFetch` only reads from `<meta name="csrf-token">` (has
   * no state of its own) · `SecurityManager` reads the meta tag at init and
   * skips firing a request for a fresh token if it finds one
   *
   * Setting the meta tag right from bootstrap saves one request, and more
   * importantly makes every path see the **exact same** token — when the
   * session rotates (every 15 minutes), if any one of these held an old
   * value, a request from there would get a random, very hard-to-diagnose 419
   */
  function setCsrfToken(token) {
    window.http.setCsrfToken(token);

    let meta = document.querySelector('meta[name="csrf-token"]');

    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'csrf-token';
      document.head.appendChild(meta);
    }

    meta.content = token;
  }

  function apply(data) {
    state.ready = true;
    state.authenticated = data.authenticated === true;
    state.twoFactorPending = data.two_factor_pending === true;
    state.mustChangePassword = data.must_change_password === true;
    state.user = data.user || null;
    // `GET /api/v2/session` sends permissions as a **map that carries every
    // one of them**, each with a true/false value, so a template can write
    // `data-if="permissions['x']"` directly (an array would give
    // undefined, which data-if interprets as "show")
    state.permissions = data.permissions && typeof data.permissions === 'object'
      ? data.permissions
      : {};
    state.mode = data.mode || 'production';
    state.modeLabel = data.mode_label || '';
    state.agentAvailable = data.agent_available !== false;

    if (data.csrf_token) {
      setCsrfToken(data.csrf_token);
    }

    window.EventManager.emit('phpcp:session', Auth.snapshot());
  }

  const Auth = {
    state: state,

    /** Reads state as a copy, so a caller can't accidentally edit the shared state */
    snapshot() {
      return Object.assign({}, state, { user: state.user ? Object.assign({}, state.user) : null });
    },

    /** Fetches state from the server — called when the app opens, and any time the state might have changed */
    async refresh() {
      const data = await window.PhpcpApi.get('/session');
      apply(data);
      return Auth.snapshot();
    },

    async login(credentials) {
      const data = await window.PhpcpApi.post('/session', credentials);
      apply(data);
      return Auth.snapshot();
    },

    async verifyTwoFactor(code) {
      const data = await window.PhpcpApi.post('/session/2fa', { code: code });
      apply(data);
      return Auth.snapshot();
    },

    /**
     * Sign out
     *
     * Always clears the web page's state even if the request fails — a user
     * who clicks sign out must always get a predictable result, and a cookie
     * still lingering will be rejected on its own once the server has deleted the session
     */
    async logout() {
      try {
        await window.PhpcpApi.del('/session');
      } catch (e) {
        /* Nothing to do — the state below gets cleared and the user goes back to login regardless */
      }

      state.authenticated = false;
      state.twoFactorPending = false;
      state.user = null;
      state.permissions = {};

      window.EventManager.emit('phpcp:session', Auth.snapshot());
      window.RouterManager.navigate('/login');
    },

    can(permission) {
      return state.permissions[permission] === true;
    },

    /** true when at least one permission in the list is held — used to decide whether to show a menu group */
    canAny(permissions) {
      return permissions.some(Auth.can);
    },

    isServerAdmin() {
      return Auth.canAny(['service.view', 'firewall.view', 'ssh.view', 'log.view', 'security.view', 'settings.view']);
    },

    setAgentAvailable(available) {
      if (state.agentAvailable === available) return;
      state.agentAvailable = available;
      window.EventManager.emit('phpcp:session', Auth.snapshot());
    },

    /**
     * The session was lost mid-use (a 401 from any request)
     *
     * Remembers the page the user was on before sending them to login, to
     * return them there once signed in again — only ever stores an internal
     * SPA path, guarding against an open redirect the same way the PHP side does
     */
    onSessionLost() {
      if (!state.authenticated) return;

      state.authenticated = false;
      state.user = null;
      state.permissions = {};

      const here = window.RouterManager.getPath ? window.RouterManager.getPath(true) : '/';
      Auth.intended = here && here.indexOf('/login') !== 0 ? here : '/';

      window.EventManager.emit('phpcp:session', Auth.snapshot());
      window.RouterManager.navigate('/login');
    },

    /** The destination after a successful login */
    takeIntended() {
      const next = Auth.intended || '/';
      Auth.intended = null;
      return next;
    },

    /**
     * The gate every one of the router's routes passes through
     *
     * Returns a string = tells the router to go to that route instead ·
     * returns true = passes through
     *
     * The decision order matters: confirming 2FA comes before changing the
     * password, and both come before a page's own permission, matching the
     * order in `Middleware\Authenticate` on the PHP side exactly — if the
     * two sides order these differently, the user would see a page come up
     * with every request inside it rejected, which looks like the system is broken
     */
    guard(to) {
      const path = (to && to.path) || '/';

      if (state.twoFactorPending) {
        return path === '/login-2fa' ? true : '/login-2fa';
      }

      if (!state.authenticated) {
        return PUBLIC_ROUTES.indexOf(path) !== -1 ? true : '/login';
      }

      if (PUBLIC_ROUTES.indexOf(path) !== -1) {
        return '/';   // Already signed in but still opening the login page
      }

      if (state.mustChangePassword && path !== '/change-password') {
        return '/change-password';
      }

      const required = to && to.route && to.route.permission;

      return !required || Auth.can(required) ? true : '/forbidden';
    }
  };

  Auth.intended = null;
  window.PhpcpAuth = Auth;
})();
