/**
 * ตัวเชื่อม REST API v2 ของ phpcp เข้ากับ Now.js — PLAN-V2 เฟส C1 ข้อ 3
 *
 * ปัญหาที่ไฟล์นี้แก้: สองฝั่งพูดคนละสำเนียง และทั้งคู่เปลี่ยนไม่ได้
 *
 *   phpcp v2 (PLAN-V2 §4.2)   { ok, data, meta:{page, per_page, total, total_pages} }
 *                             { ok:false, error:{code, message, fields} }
 *   Now.js                    { success, message, data, meta:{page, pageSize, total, totalPages}, errors }
 *
 *   คำขอ: TableManager ส่ง page / pageSize / search / sort="ชื่อ asc"
 *         phpcp รับ        page / per_page / q    / sort="-ชื่อ"
 *
 * ทางเลือกที่ **ไม่** เลือก และเหตุผล:
 *   - แก้ฝั่ง PHP ให้รับชื่อพารามิเตอร์สองแบบ — ได้สองชื่อต่อหนึ่งความหมายตลอดไป
 *     ขัดกับ §4.5 ที่เป็นสัญญาผูกมัด และ OpenAPI จะเลิกเป็นความจริงทั้งฉบับ
 *   - แก้ไฟล์ dist ของ Now.js — ทำให้ SHA256SUMS ไร้ความหมายและอัปเดตเวอร์ชันไม่ได้อีก
 *
 * จึงแปลงที่ **จุดเดียวตรงกลาง** และเป็นจุดที่ประกาศไว้ในแผนอยู่แล้ว · ทุกอย่างใต้ไฟล์นี้
 * (TableManager, FormManager, ApiComponent, ResponseHandler) จึงทำงานตามค่าเริ่มต้นของมัน
 * โดยไม่ต้องรู้เลยว่าเซิร์ฟเวอร์พูดอีกสำเนียง
 *
 * **ขอบเขต:** แปลงเฉพาะ URL ที่ขึ้นต้นด้วย /api/v2 เท่านั้น คำขออื่นปล่อยผ่านตามเดิม
 */
(function () {
  'use strict';

  const BASE = '/api/v2';

  /** true เมื่อ URL นี้เป็นของ REST API v2 (รับได้ทั้ง path ล้วนและ URL เต็ม) */
  function isV2(url) {
    if (typeof url !== 'string') return false;
    const path = url.startsWith('http') ? new URL(url, window.location.origin).pathname : url;
    return path.startsWith(BASE);
  }

  /**
   * ชื่อพารามิเตอร์ของ Now.js → ชื่อของ phpcp (§4.5)
   *
   * `sort` ต่างที่สุด: Now.js ส่ง "ชื่อ asc,อื่น desc" แต่ phpcp เรียงได้ทีละคอลัมน์
   * และใช้ `-` นำหน้าแทน desc · เอาเฉพาะคอลัมน์แรกเพราะนั่นคือสิ่งที่ backend ทำได้จริง
   * ทิ้งไปเงียบ ๆ ดีกว่าส่งค่าที่ backend จะปัดตกทั้งก้อนแล้วเรียงผิดจากที่ผู้ใช้กด
   */
  function toV2Params(params) {
    // ApiService ยอมรับ params ได้หลายรูป (string, URLSearchParams, object) — แปลงเฉพาะ
    // object ธรรมดาเท่านั้น รูปอื่นแปลว่าผู้เรียกประกอบ query เองแล้ว ปล่อยผ่านตามเดิม
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
        // total/totalPages เป็นค่าที่ TableManager เก็บไว้เอง ไม่ใช่ตัวกรอง — ไม่ต้องส่งกลับ
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
   * meta ของ phpcp → meta ของ Now.js
   *
   * **เพิ่มคีย์ ไม่ใช่แทนที่** — meta ของ v2 ไม่ได้มีแค่การแบ่งหน้า หลาย endpoint
   * ใส่ข้อมูลที่หน้าจอต้องใช้ไว้ที่นี่ (`keys` ของหน้าตั้งค่า, `levels` ของ log,
   * `score` ของการสแกนความปลอดภัย) ถ้าคืนเฉพาะสี่คีย์ของการแบ่งหน้า ข้อมูลพวกนั้น
   * จะหายไปเงียบ ๆ และหน้าจอจะว่างโดยไม่มี error ให้เห็น
   */
  function toNowMeta(meta) {
    const pageSize = Number(meta.per_page ?? meta.pageSize ?? 0) || 0;
    const total = Number(meta.total ?? 0) || 0;
    const out = Object.assign({}, meta);

    // เติมชื่อที่ TableManager รู้จักเฉพาะเมื่อคำตอบเป็นแบบแบ่งหน้าจริง ๆ
    if ('per_page' in meta || 'total_pages' in meta) {
      out.page = Number(meta.page ?? 1) || 1;
      out.pageSize = pageSize;
      out.total = total;
      out.totalPages = Number(meta.total_pages ?? meta.totalPages ?? (pageSize > 0 ? Math.ceil(total / pageSize) : 1)) || 1;
    }

    return out;
  }

  /**
   * body ของ v2 → body ที่ Now.js เข้าใจ
   *
   * เก็บ `error.code` ไว้ในชื่อ `code` ด้วย เพราะหน้าจอบางหน้าต้องแยกแยะสาเหตุจริง ๆ
   * (เช่น QUOTA_EXCEEDED ต้องพาไปหน้าโควตา ไม่ใช่แค่ขึ้น toast แดง)
   */
  function toNowBody(body, status) {
    if (!body || typeof body !== 'object') return body;
    if (!('ok' in body)) return body;   // ไม่ใช่ envelope ของ v2 — ปล่อยตามเดิม

    if (body.ok === true) {
      // แยกสามคีย์ของ envelope ออก แล้ว **ส่งคีย์ที่เหลือขึ้นไประดับบนสุดตามเดิม**
      //
      // คำตอบของคำสั่งไม่มี `data` เลย (กฎ "คำตอบหนึ่งทำหน้าที่เดียว") ค่าที่ผู้เรียก
      // ต้องใช้จึงอยู่ระดับ body · ถ้าห่อทุกอย่างลง `data` เฟรมเวิร์กจะแกะได้ชั้นใน
      // แล้ว `actions` กับ `message` ที่อยู่ชั้นนอกจะหายไป
      const rest = {};

      Object.keys(body).forEach((key) => {
        if (key !== 'ok' && key !== 'data' && key !== 'meta') rest[key] = body[key];
      });

      const out = Object.assign({ success: true }, rest);

      // คำตอบแบบอ่านเท่านั้นที่มี `data` — คำตอบแบบสั่งงานต้องไม่มี ไม่งั้น
      // `response.data.data ?? response.data` ของเฟรมเวิร์กจะเลือกชั้นในเสมอ
      if (body.data !== undefined) {
        out.data = body.data;
      }

      if (body.meta && typeof body.meta === 'object') {
        out.meta = toNowMeta(body.meta);
      }

      // ข้อความสำเร็จที่ capability ส่งกลับมาในชั้น data — FormManager ใช้ขึ้น toast
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
      message: error.message || 'เกิดข้อผิดพลาด (' + status + ')',
      errors: error.fields || {},
      data: null
    };
  }

  /**
   * สิ่งที่ต้องทำเมื่อได้รหัสข้อผิดพลาดแต่ละแบบ — PLAN-V2 §4.4 และหมายเหตุส่งมอบเฟส B
   *
   * ทำที่นี่ที่เดียวเพื่อให้ทุกหน้าจอมีพฤติกรรมเหมือนกันโดยไม่ต้องเขียนซ้ำ
   * (ยกเว้น 419 ที่ต้องลองใหม่ ซึ่งจัดการใน patch ของ request ด้านล่าง)
   */
  function reactToError(status, body) {
    const code = body && body.code;

    if (status === 401 && code !== 'TWO_FACTOR_REQUIRED') {
      // เซสชันหมดอายุระหว่างใช้งาน — พากลับหน้าล็อกอินโดยจำปลายทางไว้
      if (window.PhpcpAuth) window.PhpcpAuth.onSessionLost();
      return;
    }

    if (status === 503 && code === 'AGENT_UNAVAILABLE' && window.PhpcpAuth) {
      window.PhpcpAuth.setAgentAvailable(false);
    }
  }

  // ---------------------------------------------------------------------------
  // จุดต่อที่ 1 — ชื่อพารามิเตอร์ของคำขอ GET
  //
  // ต้องต่อที่ ApiService.buildUrlWithParams เพราะ ApiService ประกอบ query string
  // เสร็จแล้วจึงเรียก HttpClient ทำให้ request interceptor ของ HttpClient มองไม่เห็น
  // พารามิเตอร์อีกต่อไป (มันได้รับแค่ config ไม่ได้รับ url)
  // ---------------------------------------------------------------------------
  const buildUrl = window.ApiService.buildUrlWithParams.bind(window.ApiService);

  window.ApiService.buildUrlWithParams = function (url, params) {
    if (!isV2(url)) return buildUrl(url, params);

    // ApiService ส่ง params มาได้ทั้ง object เดี่ยวและ array ของ object (กรณีมี options.params)
    const mapped = Array.isArray(params) ? params.map(toV2Params) : toV2Params(params);

    return buildUrl(url, mapped);
  };

  // ---------------------------------------------------------------------------
  // จุดต่อที่ 2 — รูปร่างของคำตอบ
  //
  // ครอบทั้งสองเส้นทางที่เฟรมเวิร์กใช้จริง: HttpClient (ApiService, TableManager,
  // FormManager) และ simpleFetch (ตัวสำรองที่บาง manager เรียกตรง)
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
  // จุดต่อที่ 3 — 419 CSRF_INVALID: ขอ token ใหม่แล้วลองใหม่หนึ่งครั้ง (§4.4)
  //
  // ทำที่ระดับ HttpClient.request เพราะเป็นจุดเดียวที่ทั้ง ApiService และการเรียก
  // http.* ตรง ๆ ผ่านเหมือนกันหมด · ลองใหม่ **ครั้งเดียว** เท่านั้น — ถ้าครั้งที่สอง
  // ยัง 419 อีก แปลว่าเซสชันหมดอายุจริง ไม่ใช่แค่ token เก่า การวนซ้ำจะกลายเป็น
  // การยิงคำสั่งที่เปลี่ยนข้อมูลซ้ำโดยที่ผู้ใช้ไม่ได้สั่ง
  // ---------------------------------------------------------------------------
  const rawRequest = window.http.request.bind(window.http);

  window.http.request = async function (url, options = {}) {
    const response = await rawRequest(url, options);

    if (response && response.status === 419 && isV2(url) && !options.__csrfRetried) {
      // token ใหม่มากับ header ของคำตอบ 419 อยู่แล้ว (CsrfProtection::withFreshToken)
      // HttpClient เก็บให้เองตอนอ่าน header — เรียกซ้ำจึงได้ token ที่ถูกต้องทันที
      const fresh = response.headers && response.headers['x-csrf-token'];
      if (fresh) window.http.setCsrfToken(fresh);

      return rawRequest(url, Object.assign({}, options, { __csrfRetried: true }));
    }

    return response;
  };

  // ---------------------------------------------------------------------------
  // ตัวช่วยเรียก API แบบตรง ๆ สำหรับกรณีที่ data-attribute ทำแทนไม่ได้
  // (ปุ่มที่ต้องถามยืนยันสามระดับ, การ poll สถานะ, การอ่านค่ามาเติมกราฟ)
  // ---------------------------------------------------------------------------
  const Api = {
    base: BASE,

    /** ประกอบ path ของ v2 จากชิ้นส่วน โดย encode ทุกชิ้นให้เอง */
    url(...parts) {
      return BASE + '/' + parts.map((p) => encodeURIComponent(String(p))).join('/');
    },

    async get(path, params) {
      const response = await window.ApiService.get(BASE + path, params || {}, { cache: false });
      return Api.unwrap(response);
    },

    /**
     * เหมือน get แต่คืน `meta` มาด้วย
     *
     * หลาย endpoint ใส่ข้อมูลที่หน้าจอต้องใช้ไว้ใน meta ไม่ใช่ data — เช่นรายการคีย์
     * ที่แก้ได้ของหน้าตั้งค่า, ระดับ log ที่กรองได้, และคะแนนรวมของการสแกนความปลอดภัย
     * (เพราะ data เป็น array ของรายการตรวจ) · §4.2 อนุญาตไว้แล้วว่า meta มีได้เมื่อจำเป็น
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
     * คืน data เมื่อสำเร็จ · โยน Error ที่พก code/fields ไปด้วยเมื่อไม่สำเร็จ
     *
     * 204 ไม่มี body — ถือว่าสำเร็จและคืน object ว่าง ไม่ใช่ error
     */
    unwrap(response) {
      const body = response && response.data;

      if (response && response.status === 204) return {};

      if (body && body.success === true) return body.data;

      const error = new Error((body && body.message) || (response && response.statusText) || 'คำขอล้มเหลว');
      error.code = (body && body.code) || 'INTERNAL_ERROR';
      error.status = response ? response.status : 0;
      error.fields = (body && body.errors) || {};
      throw error;
    }
  };

  window.PhpcpApi = Api;
})();
