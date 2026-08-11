/**
 * เซสชันและสิทธิ์ฝั่งหน้าเว็บ — PLAN-V2 เฟส C1 ข้อ 4
 *
 * **ไม่ใช้ AuthManager ของ Now.js โดยตั้งใจ** (ต่อยอดจากการตัดสินใจ N6):
 * AuthManager ออกแบบมาสำหรับ token ที่ JS ถืออยู่ — มันเก็บผู้ใช้ลง localStorage,
 * ต่ออายุ token เอง และคาดหวังคำตอบรูป {success, token, user} · phpcp ไม่มี token
 * ให้ JS เลยแม้แต่ตัวเดียว ความจริงทั้งหมดอยู่ที่คุกกี้ HttpOnly + ตารางเซสชันบนเซิร์ฟเวอร์
 * การยัด AuthManager เข้ามาจะได้สำเนาสถานะชุดที่สองที่ไม่มีวันตรงกับของจริง
 *
 * แทนที่ด้วยของที่เล็กกว่าและตรงกับระบบ: เรียก `GET /api/v2/session` หนึ่งครั้งตอนเปิดแอป
 * (คำขอเดียวได้ครบทั้งสถานะล็อกอิน, CSRF token, โหมด, สถานะ agent และรายการสิทธิ์)
 * แล้วให้ router ถามสถานะนั้นก่อนเข้าทุกหน้า
 *
 * **สิทธิ์ที่เก็บไว้ที่นี่ใช้เพื่อซ่อน/แสดงเมนูเท่านั้น** — §4.4 ระบุชัดว่าการบังคับสิทธิ์จริง
 * ยังอยู่ที่ middleware และ agent เหมือนเดิม ห้ามคิดว่าซ่อนปุ่มแล้วปลอดภัย
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

  /** เส้นทางที่เข้าได้โดยยังไม่ล็อกอิน */
  const PUBLIC_ROUTES = ['/login', '/login-2fa'];

  /**
   * เก็บ CSRF token ไว้ในทุกที่ที่เฟรมเวิร์กไปหยิบ
   *
   * มีสามที่จริง ๆ ไม่ใช่ที่เดียว: `HttpClient` เก็บในตัวมันเอง · `simpleFetch` อ่านจาก
   * `<meta name="csrf-token">` เท่านั้น (ไม่มี state ของตัวเอง) · `SecurityManager`
   * อ่าน meta ตอน init แล้วข้ามการยิงขอ token ใหม่ถ้าเจอ
   *
   * ตั้ง meta ตั้งแต่ตอน bootstrap จึงประหยัดคำขอไปหนึ่งครั้ง และที่สำคัญกว่าคือ
   * ทำให้ทุกเส้นทางเห็น token **ตัวเดียวกัน** — ตอน session หมุน (ทุก 15 นาที) ถ้ามีที่ใด
   * ที่หนึ่งถือของเก่าไว้ คำขอจากที่นั่นจะโดน 419 แบบสุ่มที่หาสาเหตุยากมาก
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
    // `GET /api/v2/session` ส่งสิทธิ์เป็น **map ที่มีครบทุกตัว** พร้อมค่า true/false
    // เพื่อให้เทมเพลตเขียน `data-if="permissions['x']"` ได้ตรง ๆ (บนอาร์เรย์จะได้
    // undefined ซึ่ง data-if ตีความว่า "แสดง")
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

    /** อ่านสถานะแบบคัดลอก เพื่อไม่ให้ผู้เรียกแก้ของกลางโดยไม่ตั้งใจ */
    snapshot() {
      return Object.assign({}, state, { user: state.user ? Object.assign({}, state.user) : null });
    },

    /** ดึงสถานะจากเซิร์ฟเวอร์ — เรียกตอนเปิดแอป และทุกครั้งที่สถานะอาจเปลี่ยน */
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
     * ออกจากระบบ
     *
     * ล้างสถานะในหน้าเว็บเสมอแม้คำขอจะล้มเหลว — ผู้ใช้ที่กดออกจากระบบต้องได้ผลลัพธ์
     * ที่คาดเดาได้เสมอ และคุกกี้ที่ยังค้างอยู่จะถูกปฏิเสธเองเมื่อเซิร์ฟเวอร์ลบเซสชันแล้ว
     */
    async logout() {
      try {
        await window.PhpcpApi.del('/session');
      } catch (e) {
        /* ไม่ต้องทำอะไร — ล้างสถานะข้างล่างแล้วพาไปหน้าล็อกอินอยู่ดี */
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

    /** true เมื่อมีสิทธิ์อย่างน้อยหนึ่งตัวในรายการ — ใช้ตัดสินว่าจะแสดงเมนูกลุ่มไหม */
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
     * เซสชันหายระหว่างใช้งาน (ได้ 401 จากคำขอใด ๆ)
     *
     * จำหน้าที่ผู้ใช้อยู่ไว้ก่อนพาไปล็อกอิน เพื่อพากลับมาที่เดิมหลังล็อกอินเสร็จ —
     * เก็บเฉพาะ path ภายในของ SPA เท่านั้น กัน open redirect แบบเดียวกับฝั่ง PHP
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

    /** ปลายทางหลังล็อกอินสำเร็จ */
    takeIntended() {
      const next = Auth.intended || '/';
      Auth.intended = null;
      return next;
    },

    /**
     * ด่านหน้าทุกเส้นทางของ router
     *
     * คืน string = ให้ router เปลี่ยนไปเส้นทางนั้นแทน · คืน true = ผ่าน
     *
     * ลำดับการตัดสินสำคัญ: ยืนยัน 2FA มาก่อนเปลี่ยนรหัสผ่าน และทั้งคู่มาก่อนสิทธิ์ของหน้า
     * ให้ตรงกับลำดับใน `Middleware\Authenticate` ฝั่ง PHP — ถ้าสองฝั่งเรียงไม่เหมือนกัน
     * ผู้ใช้จะเจอหน้าที่ขึ้นมาแล้วทุกคำขอในหน้านั้นถูกปฏิเสธ ซึ่งดูเหมือนระบบพัง
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
        return '/';   // ล็อกอินแล้วยังเปิดหน้าล็อกอินอีก
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
