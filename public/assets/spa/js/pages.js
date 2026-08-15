/**
 * สคริปต์ประจำหน้า — เรียกโดย `data-script="ชื่อฟังก์ชัน"` ของ Now.js
 *
 * **กฎของไฟล์นี้: ห้ามมีอะไรที่ data-attribute ทำแทนได้**
 *
 * สิ่งที่เฟรมเวิร์กทำให้อยู่แล้วและห้ามเขียนใหม่ที่นี่:
 *
 *   ตาราง          `data-table` + `data-source` (ยิงเอง แบ่งหน้าเอง เรียงเอง ค้นหาเอง)
 *                  หรือ `data-attr="data:ชื่อ"` เพื่อผูกกับข้อมูลที่โหลดมาแล้ว
 *   หน้าที่โหลด    `data-component="api"` + `data-endpoint` แล้วผูกค่าด้วย `data-text`
 *   ข้อมูลก้อนเดียว `data-refresh-event` ให้ปุ่มสั่งโหลดใหม่ได้
 *   ฟอร์ม          `data-form` + `data-load-api` + `data-method` + `data-ajax-submit`
 *                  ผูกค่าเดิมด้วย `data-attr="value:ฟิลด์"` / `checked:ฟิลด์`
 *   ปุ่มเรียก API   `data-action="click.prevent:requestApi"` (หรือ `apiRefresh` ของเรา)
 *                  + `data-api-url` `data-api-method` `data-confirm` `data-notify-success`
 *   จัดรูปแบบค่า   ไปป์ `data-text="x | bytes"` และ `data-formatter` — ดู js/formatters.js
 *
 * เหลือไว้ที่นี่เฉพาะสองอย่างที่ประกาศไม่ได้จริง ๆ:
 *   1. สิ่งที่ต้องเปลี่ยน **สถานะเซสชัน** ของแอป (ล็อกอิน · ยืนยัน 2FA · เปลี่ยนรหัสผ่าน)
 *      — FormManager ส่งฟอร์มได้ แต่ไม่รู้จัก PhpcpAuth และไม่รู้ว่าต้องพาไปไหนต่อ
 *   2. **SSE** ของหน้าภาพรวมเซิร์ฟเวอร์ — เป็นสตรีมที่ไม่ใช่คำขอครั้งเดียว
 *
 * การซ่อนส่วนที่ไม่มีสิทธิ์เป็นของเฟรมเวิร์กล้วนแล้วตอนนี้ — `data-if="permissions[...]"`
 * ทุกจุด ไม่มี `data-can`/`showByPermission` เหลืออยู่ในเทมเพลตแล้ว
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

  /** ป้ายสถานะ — รูปแบบเดียวกับที่ formatters.js ใช้ในเซลล์ตาราง */
  const pill = (text, tone) => el('span', 'pill pill-' + (tone || 'muted'), text);

  /** ผูกฟอร์มที่ต้องเปลี่ยนสถานะเซสชันของแอป — สามหน้าเท่านั้นที่เข้าข่าย */
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
  // เข้าสู่ระบบ · ยืนยัน 2FA · เปลี่ยนรหัสผ่าน
  // ===========================================================================
  window.pageLogin = (root) => sessionForm(root, async (form) => {
    try {
      const session = await window.PhpcpAuth.login({
        username: form.username.value.trim(),
        password: form.password.value
      });

      window.RouterManager.navigate(session.authenticated ? window.PhpcpAuth.takeIntended() : '/login-2fa');
    } catch (error) {
      // 401 TWO_FACTOR_REQUIRED ไม่ใช่ความล้มเหลว — รหัสผ่านถูกแล้ว เหลือแค่ยืนยันตัวตน
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
  // หน้ารายละเอียดที่อ้างทรัพยากรด้วย id
  //
  // การเติม `{id}` ลงในเส้นทางเกิดที่ `RouterManager.render` (ดู js/ui.js) ตั้งแต่
  // เทมเพลตยังเป็นสตริง · ที่นี่เหลือหน้าที่เดียวคือกันกรณีเปิดหน้ามาโดยไม่มี id เลย
  // ===========================================================================
  window.pageDetail = function(root) {
    if (!new URLSearchParams(window.location.search).get('id')) {
      // ลิงก์เสีย — พากลับรายการดีกว่าปล่อยให้เห็นหน้าที่ว่างเปล่าโดยไม่รู้ว่าทำไม
      window.RouterManager.navigate(root.dataset.fallback || '/');
    }
  };

  // ===========================================================================
  // คะแนนความปลอดภัย
  //
  // **ทำไมต้องมี JS:** คะแนนรวมอยู่ใน `meta` ของคำตอบ (เพราะ `data` เป็นรายการตรวจ)
  // และ **ไม่มี directive ใดของ Now.js ผูกถึง meta ได้** — ApiComponent ส่งเฉพาะ `data`
  // เข้า context ของเทมเพลต · ตารางรายการตรวจยังเป็นการประกาศล้วนตามปกติ
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
      // ตารางรายการตรวจโหลดแยกกันและยังขึ้นได้ — คะแนนหายไปอย่างเดียวไม่ต้องขึ้น error
    }
  };

  // ===========================================================================
  // ภาพรวมเซิร์ฟเวอร์ — ค่าสดผ่าน SSE (เฟส C4)
  //
  // ตัวเลขก้อนแรกและข้อมูลเครื่องมาจาก ApiComponent ในเทมเพลต · ที่นี่ทำอย่างเดียว
  // คือรับสตรีมมาเขียนทับตัวเลขให้สดทุก 2 วินาที ซึ่งไม่ใช่คำขอครั้งเดียวจึงประกาศไม่ได้
  // ===========================================================================
  /**
   * ปุ่มเลือกช่วงเวลาของกราฟย้อนหลัง — PLAN-V2 เฟส E6
   *
   * กราฟดึงข้อมูลเองผ่าน `data-url` (API ตอบรูป `[{name, data:[{label,value}]}]` ที่
   * `GraphComponent` อ่านได้ตรง ๆ) เหลือแค่การ**สลับ url** ที่ประกาศด้วยแอตทริบิวต์ไม่ได้
   * เพราะต้องผูกกับการคลิกและต้องบอกคอมโพเนนต์ให้โหลดใหม่
   */
  /**
   * ความถี่ในการโหลดกราฟใหม่ ตามช่วงเวลาที่เลือก (มิลลิวินาที)
   *
   * ตัวเก็บข้อมูล (`metrics.record`) เขียนหนึ่งแถวต่อนาที ความถี่ที่มีความหมายที่สุด
   * จึงเป็นหนึ่งนาที — ถี่กว่านั้นได้ข้อมูลชุดเดิมกลับมา · ช่วงยาวขยับช้าอยู่แล้ว
   * (จุดหนึ่งจุดในกราฟ 30 วันคือค่าเฉลี่ยหลายชั่วโมง) จึงไม่ต้องถามบ่อยเท่ากัน
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

      // **ต้องเปลี่ยนที่ `instance.options.url` ไม่ใช่ `data-url` ของ element**
      // `refresh()` โหลดจาก options ที่จำไว้ตอนสร้าง ไม่ได้อ่าน dataset ใหม่ —
      // พิสูจน์ในเบราว์เซอร์จริงแล้วว่าแก้ dataset แล้วกราฟไม่เปลี่ยนเลย (2026-08-10)
      instance.options.url = '/api/v2/metrics/history?range=' + encodeURIComponent(range);
      instance.refresh();

      if (stamp) {
        // บอกเวลาที่ข้อมูลถูกโหลดจริง ไม่ใช่แค่ปล่อยให้กราฟขยับเงียบ ๆ —
        // ผู้ดูแลต้องแยกออกว่า "กราฟนิ่งเพราะโหลดใหม่แล้วค่าเท่าเดิม" กับ
        // "กราฟนิ่งเพราะมันค้าง" ซึ่งสองอย่างนี้หน้าตาเหมือนกันทุกประการ
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
   * โหลดกราฟใหม่ตามจังหวะของตัวเก็บข้อมูล
   *
   * **หยุดเมื่อแท็บไม่ได้ถูกมองอยู่** — หน้าที่เปิดค้างไว้ข้ามคืนจะยิงคำขอนับพันครั้ง
   * โดยไม่มีใครเห็นสักครั้งเดียว · กลับมาแล้วโหลดทันทีหนึ่งรอบเพราะข้อมูลเก่าไปแล้ว
   *
   * จับเวลาให้ตรงกับวินาทีที่ 5 ของนาทีถัดไป ไม่ใช่ตั้ง setInterval เฉย ๆ —
   * ตัวเก็บข้อมูลทำงานตอนต้นนาที ถ้าถามก่อนมันเขียนเสร็จจะได้ข้อมูลของนาทีก่อน
   * แล้วกราฟจะตามหลังอยู่หนึ่งนาทีตลอดเวลา
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
   * หน้า Logs — ผูก dropdown "รีเฟรชอัตโนมัติ" เข้ากับตัวจับเวลาของ TableManager
   *
   * การ poll ทั้งหมดเป็นของเฟรมเวิร์ก (`data-refresh-interval` / `setRefreshInterval`)
   * ที่นี่เหลือแค่สองอย่างที่ประกาศด้วย data-attribute ไม่ได้: อ่านค่าจาก dropdown
   * แล้วส่งต่อ กับเขียนเวลาที่ข้อมูลมาถึงล่าสุด
   *
   * **ไม่ใช้ SSE โดยตั้งใจ** — pool ของ panel มี `pm.max_children = 4` และสตรีมหนึ่งเส้น
   * ยึด worker ไว้หนึ่งตัวจนหมดเวลา · แดชบอร์ดที่เปิดค้างกินไปแล้วหนึ่งตัว ถ้าหน้านี้
   * กินอีกตัว ผู้ดูแลสองคนที่เปิดทั้งสองหน้าพร้อมกันจะทำให้ panel ค้างทั้งระบบ
   * ส่วนการ poll เป็นคำขอสั้น ๆ ที่คืน worker ทุกครั้ง
   *
   * ค่าเริ่มต้นคือปิด — หน้าที่รีเฟรชเองโดยไม่มีใครสั่งคือหน้าที่อ่านยากตอนกำลังไล่ปัญหา
   */
  window.pageLogs = function(root) {
    const picker = root.querySelector('[data-log-refresh]');
    const stamp = root.querySelector('[data-log-updated]');

    if (!picker) return () => {};

    const onChange = () => {
      const seconds = Number(picker.value) || 0;

      window.TableManager.setRefreshInterval('logLines', seconds);

      // ปิดแล้วต้องเอาเวลาออกด้วย ไม่งั้นเวลาที่ค้างอยู่จะอ่านเหมือนว่ายังรีเฟรชอยู่
      if (stamp && seconds === 0) {
        stamp.hidden = true;
        stamp.textContent = '';
      }
    };

    // บอกเวลาที่ข้อมูลมาถึงจริง — ไม่มีบรรทัดนี้ "log ไม่มีบรรทัดใหม่" กับ
    // "การรีเฟรชตายไปแล้ว" จะหน้าตาเหมือนกันทุกประการ ซึ่งเป็นสองอย่างที่ต่างกันมาก
    // ตอนกำลังนั่งดูว่ามีใครยิงเว็บอยู่หรือเปล่า
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

      // ออกจากหน้าไปแล้วต้องไม่มีคำขอตามหลังไป · destroyTable() ของเฟรมเวิร์ก
      // หยุดให้อยู่แล้ว แต่ลำดับการถอดหน้ากับการถอดตารางไม่ได้รับประกันไว้
      window.TableManager.setRefreshInterval('logLines', 0);
    };
  };

  window.pageServer = function(root) {
    // EventSource ใช้ตรง ๆ ไม่ผ่าน Now.js ตามที่แผนระบุ (C4) — คุกกี้ session ติดไปเอง
    // เพราะเป็น origin เดียวกัน และเบราว์เซอร์ต่อกลับให้อัตโนมัติเมื่อหลุด
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

    // **ต้องดักชื่อ `metrics` ไม่ใช่ `message`** — เซิร์ฟเวอร์ส่ง `event: metrics`
    // มาทุกรอบ (MetricsController::send) และตามสเปกของ SSE เหตุการณ์ที่มีชื่อ
    // จะไม่เข้า listener ของ `message` เลย · `message` มีไว้สำหรับข้อความที่ไม่ระบุชื่อ
    // เท่านั้น — ที่ผ่านมาการ์ดจึงไม่เคยขยับแม้แต่ครั้งเดียวทั้งที่สตรีมส่งข้อมูลอยู่
    stream.addEventListener('metrics', (event) => {
      try {
        paint(JSON.parse(event.data));
      } catch (e) {
        /* บรรทัดที่แปลงไม่ได้ให้ข้ามไป รอบถัดไปมาใน 2 วินาที */
      }
    });

    // เซิร์ฟเวอร์ปิดสตรีมตามกำหนด (30 นาที) แล้วให้เบราว์เซอร์ต่อใหม่เอง —
    // ไม่ใช่ความล้มเหลว ต้องไม่ปิดทิ้ง ไม่งั้นการ์ดจะหยุดขยับถาวรจนกว่าจะรีโหลดหน้า
    let expectReconnect = false;
    stream.addEventListener('bye', () => {
      expectReconnect = true;
    });

    /*
      ชื่อ `error` มาได้สองทางที่ต้องแยกกัน:

        1. เซิร์ฟเวอร์ส่ง `event: error` เพราะ agent สะดุด — **มี** data มาด้วย
           ฝั่งเซิร์ฟเวอร์ทนให้สามรอบก่อนจะเลิกเอง เราจึงไม่ต้องทำอะไร
        2. การเชื่อมต่อหลุดจริง (เซสชันหมดอายุ, เครือข่ายขาด) — ไม่มี data
           ปิดทิ้งเพื่อไม่ให้ต่อใหม่ไม่รู้จบกับปลายทางที่ตอบ 401 ทุกครั้ง
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
