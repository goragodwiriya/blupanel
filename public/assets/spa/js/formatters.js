/**
 * ตัวจัดรูปแบบค่าที่เทมเพลตเรียกใช้ได้โดยตรง — ไม่ต้องเขียน JS ต่อหน้า
 *
 * `window.formatters` ใช้กับ **ทุก element** ผ่านไปป์ใน data-text
 * `<span data-text="memory.used | bytes"></span>` — รับ argument ต่อท้ายด้วย `:` ได้
 * เช่น `| fixed:1`
 *
 * เกือบทุกตารางในระบบตอนนี้ใช้ `data-row-actions`/`data-template`/`data-format` แบบ
 * ประกาศล้วนแล้ว (ดู site.html/users.html เป็นต้นแบบ) — ที่เหลือในไฟล์นี้คือปุ่มเดียว
 * ที่ประกาศไม่ได้จริง ๆ เพราะปลายทางถูกเลือกไว้นอกแถว (ดูคอมเมนต์ที่ formatBackupActions)
 */
(function() {
  'use strict';

  const t = (text) => (window.Now && window.Now.translate ? window.Now.translate(text) : text);

  /** ขนาดเป็นหน่วยที่คนอ่านออก — รับค่าเป็นไบต์ */
  function bytes(value) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = Number(value) || 0;
    let i = 0;

    while (size >= 1024 && i < units.length - 1) {
      size /= 1024;
      i++;
    }

    return (i === 0 ? size : size.toFixed(1)) + ' ' + units[i];
  }

  /** ช่วงเวลาเป็นข้อความ — รับค่าเป็นวินาที */
  function duration(value) {
    const seconds = Math.max(0, Number(value) || 0);
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) return days + ' ' + t('days') + ' ' + hours + ' ' + t('hr');
    if (hours > 0) return hours + ' ' + t('hr') + ' ' + minutes + ' ' + t('min');
    return minutes + ' ' + t('min');
  }

  /** unix timestamp → วันเวลาที่อ่านได้ · 0/null = ยังไม่เคยเกิดขึ้น */
  function datetime(value) {
    const seconds = Number(value) || 0;

    return seconds > 0 ? window.Utils.date.format(new Date(seconds * 1000), 'D MMMM YYYY HH:mm') : '—';
  }

  /**
   * ทับตัวจัดรูป `datetime` ที่มีมากับเฟรมเวิร์ก
   *
   * `Utils.string.applyFormatters()` ค้นตามลำดับ **filters ของ context → builtinFormatters →
   * `window.formatters`** · ชื่อ `datetime` ชนกับตัวที่มีอยู่แล้ว ตัวของเราจึงไม่เคย
   * ถูกเรียกเลย และ `| datetime` ทุกที่ในเทมเพลตแสดงเป็นปี 1970 เพราะตัวเดิมตีความ
   * ตัวเลขเป็น **มิลลิวินาที** ส่วน API ของเราส่งเป็น **วินาที** ทั้งระบบ
   *
   * ทับที่ต้นทางแทนการเปลี่ยนไปใช้ชื่ออื่นในเทมเพลต เพราะการเปลี่ยนชื่อทิ้งกับดักไว้:
   * คนที่เขียน `| datetime` ในภายหลังจะได้ปี 1970 กลับมาเงียบ ๆ อีกครั้ง
   *
   * บั๊กนี้ไม่มีอะไรฟ้องเลย — หน้าเรนเดอร์ครบ console สะอาด เห็นได้ทางเดียวคืออ่านวันที่
   * บนหน้าจอแล้วเทียบกับค่าที่ API ส่งมา
   */
  if (window.Utils && window.Utils.string && window.Utils.string.builtinFormatters) {
    window.Utils.string.builtinFormatters.datetime = datetime;
  }

  window.formatters = Object.assign(window.formatters || {}, {
    bytes: bytes,
    duration: duration,
    datetime: datetime,
    /** ทศนิยมตายตัว — `| fixed` = จำนวนเต็ม, `| fixed:2` = สองตำแหน่ง */
    fixed: (value, digits) => (Number(value) || 0).toFixed(Number(digits) || 0),
    /** ข้อความว่างให้แสดงขีดแทน ไม่ใช่ปล่อยช่องโล่งจนดูเหมือนหน้าโหลดไม่เสร็จ */
    dash: (value) => (value === null || value === undefined || value === '' ? '—' : String(value))
  });

  // ---------------------------------------------------------------------------
  // ฟังก์ชันที่นิพจน์ในเทมเพลตเรียกได้ — `ExpressionEvaluator.registerFunction`
  //
  // **ทำไมต้องมี `isOn` ทั้งที่เขียนเทียบเองได้:** ตัวประเมินนิพจน์ของ Now.js อ่าน
  // `values['a.b']` เดี่ยว ๆ ได้ แต่**พอมีตัวดำเนินการต่อท้ายจะแยกวิเคราะห์ไม่ผ่าน** —
  // `values['a.b'] === '1'` คืนค่าเป็นสตริง `"values["` ไม่ใช่ boolean (ทดสอบยืนยันแล้ว
  // ในเบราว์เซอร์) · และค่าบูลีนของ `SettingsRepository` เก็บเป็นสตริง `"0"`/`"1"`
  // ซึ่ง `"0"` เป็น truthy ใน JS สวิตช์จึงติดหมดทุกตัวถ้าผูกค่าดิบตรง ๆ
  //
  // ห่อการเทียบไว้ในฟังก์ชันจึงเลี่ยงข้อจำกัดนั้นได้โดยไม่ต้องแก้รูปคำตอบของ API
  // ---------------------------------------------------------------------------
  const isOn = (value) => value === '1' || value === 1 || value === true || value === 'true';

  window.ExpressionEvaluator.registerFunction('isOn', isOn);

  /**
   * ปุ่มเดียวที่เหลืออยู่ในโค้ดฝั่งนี้สำหรับตาราง backups — restore/delete ย้ายไปเป็น
   * data-row-actions แบบประกาศแล้ว (ดู backups.html) ส่วนนี้ต้องเป็นโค้ดจริงเพราะ
   * ปลายทางที่จะส่งไปถูกเลือกไว้ **นอกแถว** (ตัวเลือกเหนือตาราง) — data-param-* ของ
   * data-row-actions ประกอบตอนเรนเดอร์แถว จึงอ่านค่าที่ผู้ใช้เพิ่งเลือกทีหลังไม่ได้
   */
  window.formatBackupActions = (cell, id) => {
    cell.textContent = '';

    if (!window.PhpcpAuth.can('backup.offsite')) return;

    const push = document.createElement('button');
    push.type = 'button';
    push.className = 'btn btn-sm icon-cloud';
    push.dataset.action = 'click.prevent:pushOffsite';
    push.dataset.backupId = String(id);
    push.title = t('Copy to the selected destination');
    cell.appendChild(push);
  };
})();
