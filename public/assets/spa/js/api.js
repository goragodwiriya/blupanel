/**
 * Adapts phpcp's REST API v2 to Now.js — PLAN-V2 phase C1, item 3
 *
 * The problem this file solves: the two sides speak different dialects, and neither can be changed
 *
 *   phpcp v2 (PLAN-V2 §4.2)   { ok, data, meta:{page, per_page, total, total_pages} }
 *                             { ok:false, error:{code, message, fields} }
 *   Now.js                    { success, message, data, meta:{page, pageSize, total, totalPages}, errors }
 *
 *   Requests: TableManager sends page / pageSize / search / sort="name asc"
 *             phpcp accepts    page / per_page / q    / sort="-name"
 *
 * Options **not** chosen, and why:
 *   - Change the PHP side to accept both parameter names — that means two
 *     names for one meaning forever, which conflicts with §4.5's binding
 *     contract, and the OpenAPI spec would stop being fully true
 *   - Edit Now.js's dist files — that makes SHA256SUMS meaningless and updating the version impossible afterward
 *
 * So the conversion happens at **one single point in the middle**, and it's
 * the exact point already declared in the plan · everything downstream of
 * this file (TableManager, FormManager, ApiComponent, ResponseHandler) works
 * on its own defaults, with no idea the server speaks a different dialect at all
 *
 * **Scope:** only converts URLs starting with /api/v2 — every other request passes through unchanged
 */
(function () {
  'use strict';

  const BASE = '/api/v2';

  /** true when this URL belongs to REST API v2 (accepts both a bare path and a full URL) */
  function isV2(url) {
    if (typeof url !== 'string') return false;
    const path = url.startsWith('http') ? new URL(url, window.location.origin).pathname : url;
    return path.startsWith(BASE);
  }

  /**
   * Now.js's parameter names → phpcp's names (§4.5)
   *
   * `sort` differs the most: Now.js sends "name asc,other desc," but phpcp
   * only sorts by one column at a time and uses a leading `-` for desc
   * instead · only the first column is kept, since that's what the backend
   * can actually do — dropping the rest silently is better than sending a
   * value the backend would reject entirely, sorting differently from what the user clicked
   */
  function toV2Params(params) {
    // ApiService accepts params in several shapes (string, URLSearchParams,
    // object) — only a plain object is converted here; any other shape means
    // the caller already assembled the query themselves, so it passes through unchanged
    if (!params || typeof params !== 'object' || params instanceof URLSearchParams) {
      return params;
    }

    const out = {};

    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') return;

      switch (key) {
        case 'pageSize':
          out.per_page = value;
          break;
        case 'search':
          out.q = value;
          break;
        case 'sort': {
          const first = String(value).split(',')[0].trim();
          if (!first) break;
          const [field, direction] = first.split(/\s+/);
          out.sort = (direction || '').toLowerCase() === 'desc' ? '-' + field : field;
          break;
        }
        // total/totalPages are values TableManager keeps for itself, not filters — never sent back
        case 'total':
        case 'totalPages':
          break;
        default:
          out[key] = value;
      }
    });

    return out;
  }

  /**
   * phpcp's meta → Now.js's meta
   *
   * **Adds keys, never replaces** — v2's meta isn't only pagination, many
   * endpoints put data the screen needs right here (the settings page's
   * `keys`, a log's `levels`, a security scan's `score`) · returning only
   * the four pagination keys would make that data disappear silently, leaving the screen blank with no error to see
   */
  function toNowMeta(meta) {
    const pageSize = Number(meta.per_page ?? meta.pageSize ?? 0) || 0;
    const total = Number(meta.total ?? 0) || 0;
    const out = Object.assign({}, meta);

    // Only fills in the names TableManager recognizes when the response is genuinely paginated
    if ('per_page' in meta || 'total_pages' in meta) {
      out.page = Number(meta.page ?? 1) || 1;
      out.pageSize = pageSize;
      out.total = total;
      out.totalPages = Number(meta.total_pages ?? meta.totalPages ?? (pageSize > 0 ? Math.ceil(total / pageSize) : 1)) || 1;
    }

    return out;
  }

  /**
   * v2's body → the body Now.js understands
   *
   * Also kept under the name `code` from `error.code`, since some screens
   * genuinely need to tell the cause apart (QUOTA_EXCEEDED, for example, must go to the quota page, not just pop a red toast)
   */
  function toNowBody(body, status) {
    if (!body || typeof body !== 'object') return body;
    if (!('ok' in body)) return body;   // Not a v2 envelope — passed through unchanged

    if (body.ok === true) {
      // Splits off the envelope's three keys, then **sends the rest up to the top level unchanged as before**
      //
      // A command's response has no `data` at all (the "one response, one
      // job" rule) — the values a caller needs therefore live at the body
      // level · wrapping everything in `data` would let the framework unwrap
      // the inner layer, and `actions`/`message` sitting at the outer level would disappear
      const rest = {};

      Object.keys(body).forEach((key) => {
        if (key !== 'ok' && key !== 'data' && key !== 'meta') rest[key] = body[key];
      });

      const out = Object.assign({ success: true }, rest);

      // Only a read-only response has `data` — a command response must
      // never have one, or the framework's `response.data.data ?? response.data` would always pick the inner layer
      if (body.data !== undefined) {
        out.data = body.data;
      }

      if (body.meta && typeof body.meta === 'object') {
        out.meta = toNowMeta(body.meta);
      }

      // The success message a capability sent back at the data level — FormManager uses this to raise a toast
      if (out.message === undefined && body.data && typeof body.data === 'object'
          && typeof body.data.message === 'string') {
        out.message = body.data.message;
      }

      return out;
    }

    const error = body.error && typeof body.error === 'object' ? body.error : {};

    return {
      success: false,
      code: error.code || 'INTERNAL_ERROR',
      message: error.message || (window.Now && window.Now.translate
        ? window.Now.translate('An error occurred ({status})', { status })
        : 'An error occurred (' + status + ')'),
      errors: error.fields || {},
      data: null
    };
  }

  /**
   * What to do for each error code — PLAN-V2 §4.4, and the phase B handoff notes
   *
   * Done in this one place so every screen behaves the same way with no
   * duplicated code (except 419, which needs a retry, handled in the request patch below)
   */
  function reactToError(status, body) {
    const code = body && body.code;

    if (status === 401 && code !== 'TWO_FACTOR_REQUIRED') {
      // The session expired mid-use — sends the user back to login, remembering where they were headed
      if (window.PhpcpAuth) window.PhpcpAuth.onSessionLost();
      return;
    }

    if (status === 503 && code === 'AGENT_UNAVAILABLE' && window.PhpcpAuth) {
      window.PhpcpAuth.setAgentAvailable(false);
    }
  }

  // ---------------------------------------------------------------------------
  // Splice point 1 — a GET request's parameter names
  //
  // Has to be spliced at ApiService.buildUrlWithParams, because ApiService
  // assembles the query string before calling HttpClient — by then,
  // HttpClient's own request interceptor can no longer see the parameters
  // (it only receives the config, not the url)
  // ---------------------------------------------------------------------------
  const buildUrl = window.ApiService.buildUrlWithParams.bind(window.ApiService);

  window.ApiService.buildUrlWithParams = function (url, params) {
    if (!isV2(url)) return buildUrl(url, params);

    // ApiService sends params as either a single object or an array of objects (when options.params is used)
    const mapped = Array.isArray(params) ? params.map(toV2Params) : toV2Params(params);

    return buildUrl(url, mapped);
  };

  // ---------------------------------------------------------------------------
  // Splice point 2 — the response's shape
  //
  // Covers both paths the framework genuinely uses: HttpClient (ApiService,
  // TableManager, FormManager) and simpleFetch (the fallback some managers call directly)
  // ---------------------------------------------------------------------------
  window.http.addResponseInterceptor(
    (response) => adaptResponse(response),
    (response) => adaptResponse(response)
  );

  function adaptResponse(response) {
    if (!response || typeof response !== 'object') return response;
    if (!isV2(response.url || '')) return response;

    response.data = toNowBody(response.data, response.status);

    if (response.data && response.data.success === false) {
      reactToError(response.status, response.data);
    }

    return response;
  }

  const rawFetch = window.simpleFetch.fetch.bind(window.simpleFetch);

  window.simpleFetch.fetch = async function (url, options) {
    const response = await rawFetch(url, options);
    return isV2(url) ? adaptResponse(Object.assign(response, { url: response.url || url })) : response;
  };

  // ---------------------------------------------------------------------------
  // Splice point 3 — 419 CSRF_INVALID: request a fresh token and retry once (§4.4)
  //
  // Done at the HttpClient.request level, since that's the one place both
  // ApiService and a direct http.* call pass through the same way · retries
  // **exactly once** — if the second attempt still gets 419, the session has
  // genuinely expired, not just an old token, and retrying further would
  // turn into resending a data-changing command the user never asked for again
  // ---------------------------------------------------------------------------
  const rawRequest = window.http.request.bind(window.http);

  window.http.request = async function (url, options = {}) {
    const response = await rawRequest(url, options);

    if (response && response.status === 419 && isV2(url) && !options.__csrfRetried) {
      // The fresh token already arrives in the 419 response's header
      // (CsrfProtection::withFreshToken) — HttpClient stores it itself when reading the header, so a retry gets the right token immediately
      const fresh = response.headers && response.headers['x-csrf-token'];
      if (fresh) window.http.setCsrfToken(fresh);

      return rawRequest(url, Object.assign({}, options, { __csrfRetried: true }));
    }

    return response;
  };

  // ---------------------------------------------------------------------------
  // A direct API-calling helper for cases a data-attribute can't cover
  // (a button needing a three-level confirmation, polling status, reading a value to fill a graph)
  // ---------------------------------------------------------------------------
  const Api = {
    base: BASE,

    /** Assembles a v2 path from its pieces, encoding each one itself */
    url(...parts) {
      return BASE + '/' + parts.map((p) => encodeURIComponent(String(p))).join('/');
    },

    async get(path, params) {
      const response = await window.ApiService.get(BASE + path, params || {}, { cache: false });
      return Api.unwrap(response);
    },

    /**
     * Like get, but also returns `meta`
     *
     * Many endpoints put data the screen needs into meta rather than data —
     * the settings page's list of editable keys, a log's filterable levels,
     * and a security scan's overall score (since data is an array of check
     * items) · §4.2 already permits meta to hold whatever's necessary
     */
    async getFull(path, params) {
      const response = await window.ApiService.get(BASE + path, params || {}, { cache: false });
      const data = Api.unwrap(response);

      return { data: data, meta: (response.data && response.data.meta) || {} };
    },

    async send(method, path, body) {
      const fn = method.toLowerCase();
      const response = await window.http[fn](BASE + path, body === undefined ? null : body);
      return Api.unwrap(response);
    },

    post(path, body) { return Api.send('post', path, body); },
    put(path, body) { return Api.send('put', path, body); },
    patch(path, body) { return Api.send('patch', path, body); },
    del(path, body) { return Api.send('delete', path, body); },

    /**
     * Returns data on success · throws an Error carrying code/fields along with it on failure
     *
     * 204 has no body — treated as success, returning an empty object, never an error
     */
    unwrap(response) {
      const body = response && response.data;

      if (response && response.status === 204) return {};

      // **This API's envelope is `{ok: true, data, meta}`**, as
      // ApiController writes it — never `{success: true}` · the old code
      // only checked `success`, so it threw an error for every successful
      // response, and a caller that caught it silently would just render
      // empty with nothing flagging the cause (this is why both the
      // security score and the meter bar on the Security page disappeared)
      //
      // Still accepts `success` too, so an older or external endpoint doesn't break along with it
      if (body && (body.ok === true || body.success === true)) {
        // A "succeeded" response has no `data` key at all — the value a
        // caller needs lives at the top level alongside `message` (see
        // ApiController::done) · returns the whole body in that case,
        // never undefined, which would immediately break `result.url` / `result.message`
        return body.data !== undefined ? body.data : body;
      }

      const error = new Error((body && body.message) || (response && response.statusText)
        || (window.Now && window.Now.translate ? window.Now.translate('The request failed') : 'The request failed'));
      error.code = (body && body.code) || 'INTERNAL_ERROR';
      error.status = response ? response.status : 0;
      error.fields = (body && body.errors) || {};
      throw error;
    }
  };

  window.PhpcpApi = Api;
})();
