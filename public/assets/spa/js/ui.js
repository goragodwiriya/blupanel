/**
 * เปลือกของหน้าจอ — เมนูข้าง แถบบน แถบเตือนโหมด และตัวช่วยที่ทุกหน้าใช้ร่วมกัน
 * PLAN-V2 เฟส C1 ข้อ 5
 *
 * **กฎความปลอดภัยของไฟล์นี้ (PLAN-V2 §C2–C3):** core ของ Now.js มี `innerHTML` 135 จุด
 * ข้อมูลที่ผู้ใช้หรือ OS ควบคุมได้ (ชื่อโดเมน ชื่อไฟล์ ข้อความ error) **ต้องผูกแบบ text
 * เท่านั้น** · ที่นี่ใช้ `textContent` และ `createElement` ล้วน · ค่าคงที่ของเมนูเป็นของ
 * โปรเจกต์เอง ไม่ได้มาจากเซิร์ฟเวอร์ จึงไม่มีทางเป็นพาหะของ XSS
 */
(function() {
  'use strict';

  /**
   * โครงเมนู — ตรงกับ `Kernel\Navigation::sections()` ฝั่ง PHP
   *
   * `permission` ที่นี่ทำหน้าที่เดียวกับฝั่งนั้นคือ **ตัดรายการที่กดไปแล้วจะได้ 403 ออก**
   * ไม่ใช่การบังคับสิทธิ์ · หมวดที่ไม่เหลือรายการจะไม่ถูกแสดงทั้งหมวด ผู้ดูแลเว็บไซต์
   * จึงไม่เห็นแม้แต่คำว่า "SERVER" ซึ่งเป็นเกณฑ์รับงานข้อหนึ่งของเฟสนี้
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
        // `newTab` เปิดตัวจัดการไฟล์เป็นแท็บของตัวเอง — หน้านั้นกินทั้งจอ (แถบโฟลเดอร์
        // + รายการไฟล์ + แถบสถานะ) การซ้อนเมนูของ panel ไว้อีกชั้นทำให้เหลือที่แสดง
        // ชื่อไฟล์ไม่พอ · และการทำงานกับไฟล์มักต้องสลับกลับไปดูหน้าอื่นของ panel ไปด้วย
        {key: 'files', label: 'File Manager', url: '/filemanager', icon: 'icon-folder', permission: 'file.view', newTab: true},
        {key: 'cron', label: 'Cron Jobs', url: '/cron-jobs', icon: 'icon-clock', permission: 'cron.view'},
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
        // ตั้งแต่เฟส M ผู้ดูแลกับลูกค้าอยู่ทรัพยากรเดียวกัน (`/api/v2/users`) จึงเหลือเมนูเดียว
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

  /** หมวดที่ผู้ใช้ปัจจุบันเห็นได้จริง */
  function visibleSections() {
    return MENU
      .map((section) => ({
        label: section.label,
        items: section.items.filter((item) => window.PhpcpAuth.can(item.permission))
      }))
      .filter((section) => section.items.length > 0);
  }

  // ---------------------------------------------------------------------------
  // เมนูข้าง
  // ---------------------------------------------------------------------------
  /**
   * เส้นทางของ SPA อยู่ใต้ `/app` แต่ router รู้จักมันในชื่อที่ไม่มี base
   *
   * จึงต้องใส่ทั้งสองอย่าง: `href` เป็น URL จริงเพื่อให้เปิดแท็บใหม่/คัดลอกลิงก์ได้ถูกต้อง
   * และ `data-route` เป็นชื่อเส้นทางเพื่อให้ router รับช่วงตอนคลิกปกติ (ไม่โหลดหน้าใหม่)
   */
  function link(node, route) {
    node.href = '/app' + (route === '/' ? '' : route);
    node.dataset.route = route;
    return node;
  }

  window.Now.getManager('component').define('sidebar', {
    // `sidemenu-panel` คือคลาสที่ทำให้เป็นแผงซ้ายแบบ fixed กว้าง `--menu-width`
    // และเป็นคลาสที่กฎ `.sidemenu-close .sidemenu-panel` (ปุ่มยุบเมนู) ใช้จับ
    // ขาดคลาสนี้แล้วเมนูจะไหลตามเนื้อหาปกติ กินความกว้างทั้งจอ และดันเนื้อหาลงไปข้างล่าง
    // ส่วนหัวเป็นข้อความคงที่ จึงอยู่ในเทมเพลตพร้อม data-i18n ไม่ต้องสร้างจาก JS
    // และอยู่**นอก** <nav class="sidemenu"> เพราะ nav เลื่อนได้ — ถ้าอยู่ข้างในโลโก้
    // จะเลื่อนหายไปพร้อมเมนูเมื่อรายการยาวเกินจอ
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
      // ComponentManager **แทนที่** element เจ้าบ้านด้วย root ของเทมเพลต — this.element
      // คือ <aside class="sidebar"> ตัวใหม่ ไม่ใช่ <aside data-component="sidebar"> เดิม

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

          // รายการที่เปิดแท็บใหม่ต้อง**ไม่มี `data-route`** — ไม่งั้น router จะดักคลิก
          // แล้วเรนเดอร์ทับหน้าเดิมในแท็บนี้ ก่อนที่เบราว์เซอร์จะได้เปิดแท็บใหม่เลย
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

      // **ต้องบอก MenuManager เองหลังใส่ <ul> เสร็จ**
      //
      // `createMenu()` ข้ามเมนูที่ยังไม่มี `<ul>` ("empty menu") และ MutationObserver
      // ของมันเห็น <nav> ตอนที่ ComponentManager แทรกเข้า DOM ซึ่งเป็นจังหวะที่รายการ
      // ยังไม่ถูกสร้าง — เมนูจึงไม่เคยถูกลงทะเบียน และไม่มีอะไรกระตุ้นให้ลองใหม่อีก
      //
      // อาการคือปุ่มยุบเมนูกดแล้วไม่มีอะไรเกิดขึ้น: ตัวฟังคลิกเป็น delegation ที่ระดับ
      // document จึงทำงานปกติ แต่มันวนหาเมนูใน `state.menus` ที่ว่างเปล่า
      window.MenuManager.createMenu(nav);

      // ทำเครื่องหมายรายการที่กำลังเปิดอยู่เอง
      //
      // `MenuManager.updateActiveMenu()` **ตัด base ออกจากเส้นทางปัจจุบัน** (`/app/services`
      // → `/services`) แล้วเทียบกับค่าใน `href` ตรง ๆ · แต่ `href` ของเราต้องเป็น URL เต็ม
      // (`/app/services`) เพื่อให้เปิดแท็บใหม่และคัดลอกลิงก์ได้ถูกต้อง สองค่านี้จึงไม่มีวันตรงกัน
      // · เทียบกับ `data-route` ที่เก็บชื่อเส้นทางแบบไม่มี base ไว้อยู่แล้วแทน
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
       * ป้ายของเมนูสร้างด้วย JS จึงไม่มี `data-i18n` ให้ตัวแปลภาษาไล่เก็บ
       *
       * `I18nManager.updateElements()` แปลเฉพาะ element ที่มี `data-i18n` ติดอยู่ ·
       * เมนูข้างจึงค้างเป็นภาษาเดิมทั้งแถบตอนกดสลับภาษา ทั้งที่ทุกอย่างรอบตัวเปลี่ยนหมด
       * — เขียนป้ายใหม่เองเมื่อได้รับสัญญาณ แทนที่จะไปแปะ data-i18n ให้ทุกป้าย
       * (ซึ่งจะทำให้ต้องจำว่าคีย์คืออะไรอีกที่หนึ่ง)
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
  // แถบบน
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
        // บอกภาษาที่เลือกให้ฝั่งเซิร์ฟเวอร์รู้ด้วย — ข้อความที่ API ส่งมา (รวมถึงที่
        // ส่งไปทางอีเมล) แปลที่นั่น ไม่ได้แปลในเบราว์เซอร์ · เก็บใน localStorage
        // อย่างเดียวไม่พอเพราะเซิร์ฟเวอร์อ่านไม่ได้
        document.cookie = 'phpcp_lang=' + encodeURIComponent(current) + '; path=/; max-age=31536000; samesite=lax';
        // โปรแกรมอ่านหน้าจอและตัวตัดคำของเบราว์เซอร์อ่านค่านี้ ไม่ได้อ่าน localStorage
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
  // แถบนับถอยหลังการคืนค่าอัตโนมัติ — SSH และ firewall
  //
  // **ทำไมต้องมี และทำไมต้องอยู่ทุกหน้า:** เปลี่ยนค่า sshd หรือกฎไฟร์วอลล์แล้วระบบ
  // ตั้งเวลาคืนค่าไว้ ถ้าไม่กดยืนยันภายในเวลา ค่าจะถูกคืนเป็นเดิม · ถ้าไม่มีแถบนี้
  // ผู้ดูแลที่ตั้งค่าถูกต้องแล้วเดินไปทำอย่างอื่นจะเสียค่าที่เพิ่งตั้งไปเงียบ ๆ
  // โดยไม่มีอะไรบอกเลยว่าเกิดอะไรขึ้น
  //
  // **ตัวจับเวลาจริงอยู่ฝั่งเซิร์ฟเวอร์เสมอ** (`phpcp-scheduler` เรียก `rollback.run`
  // ทุกนาที) — ตัวเลขที่นับถอยหลังบนจอเป็นแค่การแสดงผล ไม่ใช่สิ่งที่ตัดสินอะไร
  // เพราะกรณีที่กลไกนี้ถูกออกแบบมารับมือคือกรณีที่เบราว์เซอร์หลุดไปแล้ว
  //
  // ถามสถานะซ้ำทุก 15 วินาที และเดินตัวเลขเองทุกวินาทีระหว่างนั้น เพื่อไม่ต้องยิงคำขอ
  // ถี่ ๆ แค่เพื่อให้ตัวเลขขยับ
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

        // ปุ่มทั้งสองใช้ `apiRefresh` ของเฟรมเวิร์ก — ยืนยัน · แจ้งผล · แล้วให้แถบนี้
        // ถามสถานะใหม่ผ่านสัญญาณ phpcp:rollback
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
        // ลูกค้าไม่มีสิทธิ์ security.view — ไม่ต้องถามเลย จะได้ไม่มี 403 รัวใน console
        if (!window.PhpcpAuth.can('security.view')) return;

        try {
          const rows = await window.PhpcpApi.get('/rollbacks');
          pending = Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
        } catch (error) {
          // ถามไม่ได้ก็ไม่ต้องเดา — ซ่อนแถบไว้ดีกว่าขึ้นตัวเลขที่ไม่จริง
          pending = null;
        }

        paint();
      };

      this._tick = window.setInterval(() => {
        if (!pending) return;

        pending.remaining_seconds -= 1;

        // หมดเวลาแล้ว: scheduler กำลังจะคืนค่าให้ ถามสถานะจริงแทนการเดา
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
  // แถบเตือนโหมด — ปิดไม่ได้โดยตั้งใจ (PLAN-V2 เฟส C1 ข้อ 5)
  //
  // ในโหมด sandbox/dryrun คำสั่งทุกอย่าง "สำเร็จ" โดยไม่แตะเครื่องจริงเลย ถ้าแถบนี้
  // ปิดได้ ผู้ดูแลจะเผลอเชื่อว่าตัวเองแก้ค่าบนเซิร์ฟเวอร์ไปแล้วทั้งที่ไม่มีอะไรเกิดขึ้น
  // ---------------------------------------------------------------------------
  window.Now.getManager('component').define('mode-banner', {
    template: '<div class="mode-banner" hidden></div>',

    mounted() {
      // root ของเทมเพลตแทนที่ element เจ้าบ้านไปแล้ว — this.element คือแถบนั้นเอง
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
  // การกระทำแบบประกาศเพิ่มอีกสองตัว — ลงทะเบียนผ่าน `registerAction` ซึ่งเป็น
  // จุดต่อของเฟรมเวิร์กเอง ไม่ใช่การผูก event handler เองทีละปุ่ม
  //
  // ของที่มีมาให้แล้วและใช้ตรง ๆ ได้เลย: requestApi · toggleClass · copyToClipboard
  // · exportCsv · print — อย่าเขียนใหม่
  // ---------------------------------------------------------------------------
  const events = window.Now.getManager('eventsystem') || window.EventSystemManager;

  /** ส่งสัญญาณให้ ApiComponent ที่ตั้ง `data-refresh-event` ไว้ดึงข้อมูลรอบใหม่ */
  events.registerAction('emit', (event, element) => {
    window.EventManager.emit(element.dataset.emitEvent || 'phpcp:reload', {trigger: element});
  });

  /**
   * เรียก API แล้วรีเฟรชสิ่งที่ต้องเปลี่ยนตาม
   *
   * ใช้ตัว `requestApi` ของเฟรมเวิร์กทำงานจริงทั้งหมด (ยืนยันก่อน · สถานะกำลังโหลด ·
   * แจ้งผลสำเร็จ/ล้มเหลว) แล้วต่อท้ายด้วยการรีเฟรชเท่านั้น — ไม่เขียนซ้ำสิ่งที่มีอยู่แล้ว
   *
   *   data-refresh-table   ชื่อ `data-table` ที่ต้องโหลดใหม่
   *   data-emit-event      ชื่อสัญญาณสำหรับ ApiComponent (ค่าเริ่มต้น phpcp:reload)
   *   data-go              เส้นทางที่ต้องพาไปหลังสำเร็จ (เช่นหลังลบทรัพยากรที่กำลังเปิดอยู่)
   */
  events.registerAction('apiRefresh', async (event, element) => {
    const requestApi = events.state.actions.get('requestApi');

    await requestApi(event, element);

    if (element.dataset.refreshTable) {
      window.TableManager.loadTableData(element.dataset.refreshTable, {force: true});
    }

    window.EventManager.emit(element.dataset.emitEvent || 'phpcp:reload', {trigger: element});

    if (element.dataset.go) {
      window.RouterManager.navigate(element.dataset.go);
    }
  });

  /**
   * เปิด phpMyAdmin โดยไม่ต้องพิมพ์รหัส
   *
   * ต้องเป็นโค้ดจริงไม่ใช่ลิงก์ประกาศ เพราะเป็นสองจังหวะที่แยกกันไม่ได้: POST ที่มี
   * CSRF token (ซึ่งเป็นตัวสร้าง session ฝั่ง phpMyAdmin) แล้วค่อยพาไปที่ URL ที่ได้
   * · ลิงก์ `<a href>` ตรง ๆ ทำแบบนี้ไม่ได้เลยเพราะมันคือ GET ที่ไม่มี token
   *
   *   data-db   ชื่อฐานข้อมูลที่จะเปิดตรงไปหน้าโครงสร้าง (ไม่ใส่ = หน้าแรก)
   */
  events.registerAction('openPhpMyAdmin', async (event, element) => {
    try {
      const result = await window.PhpcpApi.post('/phpmyadmin/session', {db: element.dataset.db || ''});

      // แท็บใหม่เพราะ phpMyAdmin เป็นแอปแยก ไม่ใช่หน้าใน panel
      window.open(result.url, '_blank', 'noopener');
    } catch (error) {
      window.NotificationManager.error(error.message);
    }
  });

  /**
   * ส่งไฟล์สำรองออกไปยังปลายทางที่เลือกไว้เหนือตาราง
   *
   * ต้องเป็นโค้ดจริงเพราะปลายทางถูกเลือกที่ **นอกแถว** — `data-param-*` ของ
   * `apiRefresh` ถูกประกอบตอนเรนเดอร์แถว จึงไม่รู้ค่าที่ผู้ใช้จะเลือกทีหลัง
   */
  events.registerAction('pushOffsite', async (event, element) => {
    const picker = document.querySelector('[data-offsite-destination]');
    const destination = picker ? Number(picker.value) : 0;

    if (!destination) {
      window.NotificationManager.error(window.Now.translate('Add a destination first'));

      return;
    }

    try {
      const result = await window.PhpcpApi.post('/backups/' + element.dataset.backupId + '/offsite-copy', {
        destination_id: destination,
      });

      window.NotificationManager.success(result.message);
      window.TableManager.loadTableData('backups', {force: true});
    } catch (error) {
      window.NotificationManager.error(error.message);
    }
  });

  // ---------------------------------------------------------------------------
  // เติมค่าจาก query string ลงในเทมเพลตก่อนเรนเดอร์ — `{id}` ในเส้นทางของ API
  //
  // **ทำไมต้องทำตั้งแต่ยังเป็นสตริง:** `ApiComponent` ยิงคำขอทันทีที่ถูก mount ซึ่งเกิด
  // ระหว่างการประมวลผลเทมเพลต ก่อน `data-script` ของหน้าจะได้ทำงาน · ถ้ารอไปแก้ทีหลัง
  // คำขอแรกจะออกไปพร้อม `{id}` ดิบ ๆ แล้วได้ 404
  //
  // **ทำไมต้องมีอย่างนี้เลย:** REST v2 อ้างทรัพยากรด้วย**เส้นทาง** (`/sites/{id}`) ตาม §4.1
  // ส่วน `data-url-params` ของ Now.js เติมได้เฉพาะ query string
  //
  // แทนเฉพาะชื่อที่มีอยู่จริงใน query และ encode ทุกค่า — ค่าที่ไม่รู้จักถูกทิ้งไว้ตามเดิม
  // เพื่อให้เห็นชัดว่าเทมเพลตอ้างพารามิเตอร์ที่ไม่มี ไม่ใช่กลายเป็นเส้นทางที่ดูปกติแต่ผิด
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
  // ตารางที่ตัวกรองของมันต้องรอโหลดตัวเลือกก่อน
  //
  // หน้า Logs และ File Manager มีตัวกรองเป็น <select> ที่ตัวเลือกมาจาก endpoint
  // ของมันเอง (`/logs/sources`, `/files/roots`) · TableManager อ่านค่าตัวกรองตอนโหลด
  // ครั้งแรกซึ่งเกิดก่อนตัวเลือกมาถึง คำขอแรกจึงออกไปโดยไม่มี `source`/`root` แล้วได้ 403
  //
  // ฟังสัญญาณ `api:loaded` ของ ApiComponent แล้วสั่งโหลดตารางที่ระบุใหม่หนึ่งครั้ง —
  // เทมเพลตเขียนแค่ `data-reload-table="ชื่อตาราง"` บน element ที่ครอบ <select> ไว้
  // ---------------------------------------------------------------------------
  // ตัวกรองของตารางเปลี่ยน -> ApiComponent ที่ป้อนข้อมูลให้ตารางนั้นต้องดึงใหม่
  //
  // ใช้กับตารางที่ผูกด้วย `data-attr` (ไม่ได้ยิงคำขอเอง) เท่านั้น — ตารางที่มี
  // `data-source` โหลดใหม่ด้วยตัวเองอยู่แล้ว ไม่ต้องมีใครสั่ง
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
  // `data-reload-table` บนฟอร์ม — โหลดตารางใหม่หลังบันทึกสำเร็จ
  //
  // FormManager รีเฟรชตารางผ่าน `actions[]` ที่เซิร์ฟเวอร์ส่งกลับมา (ดู FRAMEWORK_GUIDE
  // "Pattern 3") แต่ REST v2 ตอบเป็นข้อมูลล้วนตาม §4.2 ไม่มีคำสั่งสั่งงานหน้าจอปนมาด้วย
  // — และไม่ควรมี เพราะนั่นคือการผูก API เข้ากับหน้าจอตัวใดตัวหนึ่ง
  //
  // จึงต่อที่ `ResponseHandler.process` ซึ่งเป็นจุดที่ FormManager เรียกหลังส่งสำเร็จเสมอ
  // และได้ context ที่มี element ของฟอร์มติดมาด้วย · ผลคือเทมเพลตเขียนแค่
  // `data-reload-table="ชื่อตาราง"` เหมือนแอตทริบิวต์อื่นของเฟรมเวิร์ก
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

    return result;
  };

  // ---------------------------------------------------------------------------
  // ตัวช่วยที่หน้าจอใช้ร่วมกัน
  // ---------------------------------------------------------------------------
  const Ui = {
    menu: MENU,

    /** ขนาดเป็นหน่วยที่คนอ่านออก — รับค่าเป็นไบต์ */
    bytes(value) {
      const n = Number(value) || 0;
      const units = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      let size = n;
      while (size >= 1024 && i < units.length - 1) {size /= 1024; i++;}
      return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
    },

    /**
     * ยืนยันคำสั่งที่ทำลายของ — SECURITY §4 กำหนดสามระดับ
     *
     * `type` ต้องเป็น 'normal' (กดยืนยัน) | 'danger' (กดยืนยันพร้อมคำเตือนแดง)
     * | 'critical' (ต้องพิมพ์ข้อความยืนยันให้ตรง)
     *
     * ระดับ critical ใช้ `Modal` ของ Now.js แทน `prompt()` ของเบราว์เซอร์เพราะต้อง
     * แสดงชื่อทรัพยากรให้ผู้ใช้เทียบได้ชัดว่ากำลังลบตัวไหน
     */
    async confirm(options) {
      const type = options.type || 'normal';
      const title = options.title || t('Please confirm');

      if (type !== 'critical') {
        return window.DialogManager.confirm(options.message, title, {
          className: type === 'danger' ? 'dialog-danger' : ''
        });
      }

      // ระดับสูงสุด: ต้องพิมพ์ชื่อทรัพยากรให้ตรงเป๊ะ · เทียบแบบ case-sensitive โดยตั้งใจ
      // เพราะชื่อโดเมนและชื่อฐานข้อมูลเป็นสิ่งที่ต้องอ่านให้ตรงตัวจริง ๆ ก่อนลบ
      const answer = await window.DialogManager.prompt(options.message, '', title, {
        className: 'dialog-danger'
      });

      return typeof answer === 'string' && answer.trim() === options.expect;
    },

    /** แสดงข้อผิดพลาดจาก PhpcpApi ให้เป็นข้อความเดียวที่ผู้ใช้อ่านรู้เรื่อง */
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
