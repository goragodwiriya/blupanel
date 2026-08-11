/**
 * จุดเริ่มของ SPA — PLAN-V2 เฟส C1 ข้อ 2 และ 5
 *
 * ลำดับการเริ่มระบบมีเหตุผลกำกับทุกขั้น อย่าสลับ:
 *
 *   1. ผูกด่านสิทธิ์เข้ากับ router  ต้องก่อน Now.init เพราะ RouterManager.init()
 *                                  พาไปหน้าแรกทันทีที่มันเริ่มทำงาน
 *   2. ถาม GET /api/v2/session      ต้องก่อนเช่นกัน ด่านในข้อ 1 จะได้มีสถานะจริงให้ตัดสิน
 *                                  ไม่ใช่เดาว่า "ยังไม่ล็อกอิน" แล้วเด้งคนที่ล็อกอินอยู่ออก
 *   3. Now.init(...)                เริ่ม manager ทั้งหมดแล้วเรนเดอร์หน้าแรก
 */
document.addEventListener('DOMContentLoaded', async () => {
  // สองค่านี้ **ต่างกันโดยตั้งใจ** และห้ามรวมเป็นค่าเดียว:
  //   ROUTE_BASE  URL ที่ผู้ใช้เห็นบนแถบที่อยู่ — ต้องไม่ตรงกับไดเรกทอรีจริงบนดิสก์
  //               ไม่งั้น mod_dir ของ Apache จะจัดการเองก่อนถึง FallbackResource
  //   ASSET_BASE  ที่เก็บไฟล์จริง อยู่ใต้ /assets/ ซึ่งเป็นที่ของไฟล์นิ่งอยู่แล้ว
  const ROUTE_BASE = '/app';
  const ASSET_BASE = '/assets/spa';

  /**
   * ตารางเส้นทางของหน้าจอ
   *
   * `permission` เป็นฟิลด์ของโปรเจกต์นี้เอง ไม่ใช่ของ Now.js — `PhpcpAuth.guard` อ่านมัน
   * ผ่าน `to.route.permission` · ค่าต้องตรงกับ permission ที่เส้นทาง API ของหน้านั้นใช้
   * เพื่อไม่ให้เกิดหน้าที่เปิดได้แต่ทุกคำขอในหน้าถูกปฏิเสธ
   *
   * เส้นทางเป็นชื่อระดับเดียวคั่นด้วยขีดตาม FRAMEWORK_GUIDE — หน้ารายละเอียดใช้
   * query string (`/site?id=5`) ไม่ใช่ path ซ้อน
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
    // ตัวจัดการไฟล์เป็นหน้าเต็มจอที่เปิดในแท็บของตัวเอง (เมนูใน ui.js ตั้ง target=_blank)
    // จึงไม่มีเมนูข้างและแถบบนในเทมเพลตของมัน — เหมือน login.html ที่ไม่มี chrome เช่นกัน
    '/filemanager': { template: 'filemanager.html', title: '{LNG_File Manager}', permission: 'file.view' },
    '/cron-jobs': { template: 'cron-jobs.html', title: '{LNG_Cron Jobs}', permission: 'cron.view' },
    '/cron-job': { template: 'cron-job.html', title: '{LNG_Cron Jobs}', permission: 'cron.view' },
    '/backups': { template: 'backups.html', title: '{LNG_Backups}', permission: 'backup.view' },

    // --- SERVER ---
    '/server': { template: 'server.html', title: '{LNG_Server Overview}', permission: 'server.view' },
    '/services': { template: 'services.html', title: '{LNG_Services}', permission: 'service.view' },
    '/security': { template: 'security.html', title: '{LNG_Security}', permission: 'security.view' },
    '/firewall': { template: 'firewall.html', title: '{LNG_Firewall}', permission: 'firewall.view' },
    '/ssh': { template: 'ssh.html', title: '{LNG_SSH}', permission: 'ssh.view' },
    '/logs': { template: 'logs.html', title: '{LNG_Logs}', permission: 'log.view' },
    '/users': { template: 'users.html', title: '{LNG_Users}', permission: 'user.view' },
    '/user': { template: 'user.html', title: '{LNG_User}', permission: 'user.view' },
    // ฟอร์มสร้างแยกไฟล์ออกจากตาราง — หนึ่งเทมเพลตทำหน้าที่เดียว
    '/user-create': { template: 'user-create.html', title: '{LNG_Add user}', permission: 'customer.manage' },
    '/settings': { template: 'settings.html', title: '{LNG_Settings}', permission: 'settings.view' }
  };

  window.RouterManager.beforeEach((to) => window.PhpcpAuth.guard(to));

  try {
    await window.PhpcpAuth.refresh();
  } catch (error) {
    // ถามสถานะไม่ได้ = เซิร์ฟเวอร์มีปัญหาระดับที่หน้าเว็บทำอะไรต่อไม่ได้เลย
    // บอกตรง ๆ ดีกว่าปล่อยให้แอปเริ่มแล้วพังเป็นชิ้น ๆ ในที่ที่หาสาเหตุยากกว่า
    document.getElementById('main').textContent =
      'ติดต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่อีกครั้ง (' + (error && error.message ? error.message : 'ไม่ทราบสาเหตุ') + ')';
    return;
  }

  await window.Now.init({
    // production = ใช้ไฟล์ bundle ที่ commit ไว้ ไม่ไล่โหลดไฟล์ core ทีละตัว
    environment: 'production',

    paths: {
      framework: ASSET_BASE + '/vendor/now',
      application: ASSET_BASE,
      components: ASSET_BASE + '/js/components',
      templates: ASSET_BASE + '/templates',
      translations: ASSET_BASE + '/lang'
    },

    // การตัดสินใจ N7 — ไม่เปิด eval เด็ดขาด CSP ของ panel ไม่มี 'unsafe-eval'
    allowEval: false,

    // ระบบยืนยันตัวตนของเฟรมเวิร์กปิดไว้ — เหตุผลเต็มอยู่หัวไฟล์ js/auth.js
    auth: { enabled: false },

    security: {
      csrf: {
        enabled: true,
        tokenName: '_token',
        headerName: 'X-CSRF-Token',
        metaName: 'csrf-token',
        // `GET /api/v2/session` คือแหล่ง token เดียวของระบบ · รูปคำตอบตรงกับที่
        // SecurityManager คาดไว้พอดี (body.data.csrf_token) จึงใช้ได้โดยไม่ต้องแปลงอะไร
        tokenUrl: '/api/v2/session'
      }
    },

    i18n: {
      enabled: true,
      defaultLocale: 'th',
      availableLocales: ['th', 'en'],
      storageKey: 'phpcp_lang'
    },

    // ธีมสว่าง/มืดเก็บที่เครื่องผู้ใช้ · ปิดการดึง config จาก API เพราะ panel
    // ไม่มี endpoint นั้นและไม่ควรมี — ค่าที่ต้องรู้ทั้งหมดมากับ /session แล้ว
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
      // ปิด auth ของ router เพราะมันผูกกับ AuthManager · ด่านจริงคือ beforeEach ข้างบน
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
