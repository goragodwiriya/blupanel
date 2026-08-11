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

    // ---- ผลตรวจจากภายนอก -------------------------------------------------
    //
    // ยังไม่เคยรันตัวสแกน = 404 ซึ่งไม่ใช่ข้อผิดพลาด · ซ่อนส่วนนี้ไว้เงียบ ๆ
    // ดีกว่าขึ้น toast แดงทุกครั้งที่เปิดหน้าบนเครื่องที่ยังไม่ได้สแกน
    try {
      const audit = await window.PhpcpApi.getFull('/security/audit');
      const panel = root.querySelector('[data-audit-panel]');
      const meta = audit.meta;

      panel.hidden = false;

      const summary = Object.entries(meta.summary || {})
        .filter(([, count]) => Number(count) > 0)
        .map(([level, count]) => level + ' ' + count)
        .join(' · ');

      const parts = [
        meta.target,
        t('Scanned') + ' ' + window.formatters.datetime(meta.scanned_at),
        summary
      ].filter(Boolean);

      const line = root.querySelector('[data-audit-meta]');
      line.textContent = parts.join(' — ');

      // เตือนเมื่อผลตรวจเก่าเกินเกณฑ์ที่เซิร์ฟเวอร์กำหนด ไม่ใช่เกณฑ์ที่หน้าจอคิดเอง
      if (meta.stale) {
        line.appendChild(document.createTextNode(' '));
        const warn = el('span', 'pill pill-warn', t('This scan is out of date'));
        line.appendChild(warn);
      }

      const body = root.querySelector('[data-audit-rows]');
      body.textContent = '';

      (audit.data || []).forEach((row) => {
        const tr = el('tr');
        const severity = String(row.severity || 'INFO');

        const level = el('td', 'center');
        level.appendChild(pill(severity, {
          CRITICAL: 'danger', HIGH: 'danger', MEDIUM: 'warn', LOW: 'muted', INFO: 'muted'
        }[severity] || 'muted'));
        tr.appendChild(level);

        tr.appendChild(el('td', null, row.check || row.category || ''));
        tr.appendChild(el('td', null, row.detail || row.message || ''));

        body.appendChild(tr);
      });
    } catch (error) {
      /* 404 = ยังไม่เคยรันตัวสแกน — ปล่อยส่วนนี้ซ่อนไว้ */
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
  function metricsRanges(root) {
    const holder = root.querySelector('[data-metrics-graph]');
    const buttons = Array.from(root.querySelectorAll('[data-metrics-ranges] [data-range]'));

    if (!holder || buttons.length === 0) return;

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        buttons.forEach(b => b.classList.toggle('is-active', b === button));

        const instance = window.GraphComponent && window.GraphComponent.getInstance(holder);
        if (!instance) return;

        // **ต้องเปลี่ยนที่ `instance.options.url` ไม่ใช่ `data-url` ของ element**
        // `refresh()` โหลดจาก options ที่จำไว้ตอนสร้าง ไม่ได้อ่าน dataset ใหม่ —
        // พิสูจน์ในเบราว์เซอร์จริงแล้วว่าแก้ dataset แล้วกราฟไม่เปลี่ยนเลย (2026-08-10)
        instance.options.url = '/api/v2/metrics/history?range=' + encodeURIComponent(button.dataset.range);
        instance.refresh();
      });
    });
  }

  window.pageServer = function(root) {
    // EventSource ใช้ตรง ๆ ไม่ผ่าน Now.js ตามที่แผนระบุ (C4) — คุกกี้ session ติดไปเอง
    // เพราะเป็น origin เดียวกัน และเบราว์เซอร์ต่อกลับให้อัตโนมัติเมื่อหลุด
    const stream = new EventSource('/api/v2/metrics/stream');
    metricsRanges(root);

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

    stream.addEventListener('message', (event) => {
      try {
        paint(JSON.parse(event.data));
      } catch (e) {
        /* บรรทัดที่แปลงไม่ได้ให้ข้ามไป รอบถัดไปมาใน 2 วินาที */
      }
    });

    // สตรีมล้ม (agent ล่ม/เซสชันหมดอายุ) — ปิดทิ้ง ไม่ปล่อยให้ต่อใหม่ไม่รู้จบ
    stream.addEventListener('error', () => stream.close());

    return () => stream.close();
  };
})();
