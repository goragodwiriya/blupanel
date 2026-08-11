/**
 * ตัวจัดการไฟล์เต็มจอ — คู่กับ templates/filemanager.html และ css/filemanager.css
 *
 * **ทำไมหน้านี้ถึงเป็น JS จริง ไม่ใช่การประกาศด้วยแอตทริบิวต์เหมือนหน้าอื่น:**
 * ทุกหน้าที่เหลือของ panel คือ "ตารางข้อมูล + ฟอร์ม" ซึ่ง TableManager/FormManager
 * ทำได้ครบ · หน้านี้คือ "ตัวจัดการไฟล์" ซึ่งพฤติกรรมทั้งชุดไม่มีทางประกาศได้เลย:
 * เลือกหลายรายการด้วย Shift/Ctrl · คลิกขวา · ลากไฟล์มาวาง · คลิปบอร์ดข้ามโฟลเดอร์ ·
 * คีย์ลัด · สลับมุมมองไอคอน · ตัวแก้ไขและตัวอย่างไฟล์ในกล่องซ้อน
 *
 * **กฎความปลอดภัยของไฟล์นี้ (เหมือน ui.js):** ชื่อไฟล์และเส้นทางเป็นค่าที่ผู้ใช้
 * ตั้งเองบนดิสก์ — สร้าง DOM ด้วย `createElement` + `textContent` เท่านั้น
 * **ห้าม `innerHTML` แม้แต่จุดเดียวในไฟล์นี้** และห้ามเขียน `element.style.x = ...`
 * เพราะ CSP เป็น `style-src 'self'` ไม่มี `unsafe-inline`
 *
 * **ทุกคำสั่งวิ่งผ่าน `/api/v2/files/*` ซึ่งส่งต่อให้ agent เสมอ** — ไฟล์ถูกแตะใน
 * สิทธิ์ของเจ้าของเว็บผ่าน `Executor::asUser()` และถูกบันทึก audit ทุกครั้ง
 * หน้านี้ไม่มีทางลัดไปยัง filesystem และไม่มีระบบล็อกอินของตัวเอง
 */
(function () {
  'use strict';

  /**
   * แปลข้อความ พร้อมส่งค่าแทนที่ให้ตัวแปลเอง
   *
   * **ห้ามใช้ `.replace('{count}', n)` กับผลลัพธ์** — `I18nManager.interpolate()`
   * กิน `{...}` ทุกตัวในข้อความไปแล้วตั้งแต่ตอนแปล คีย์ `'{count} items'` ที่ไม่ได้
   * ส่ง params มาด้วยจึงกลายเป็น "count รายการ" (วงเล็บหาย เหลือแต่ชื่อตัวแปร)
   * แล้ว replace ทีหลังก็ไม่เจออะไรให้แทน · เจอจากการทดสอบบนเบราว์เซอร์จริง
   */
  const t = (text, params) => (window.Now && window.Now.translate
    ? window.Now.translate(text, params || {})
    : text);
  const fmt = () => window.formatters || {};

  /** ไอคอนตามชนิดเชิงความหมายที่ `FileCatalog::kind()` ส่งมา */
  const KIND_ICON = {
    folder: 'icon-folder',
    image: 'icon-image',
    archive: 'icon-zip',
    document: 'icon-document',
    media: 'icon-video',
    code: 'icon-code',
    style: 'icon-brush',
    markup: 'icon-code',
    data: 'icon-database',
    text: 'icon-document',
    file: 'icon-file'
  };

  /**
   * ชนิดสื่อสำหรับ "ดูตัวอย่าง" — allowlist ไม่ใช่การเดาจาก Content-Type ของเซิร์ฟเวอร์
   *
   * `GET /files/download` ส่ง `application/octet-stream` เสมอโดยตั้งใจ (กัน stored XSS
   * จากไฟล์ .html ของผู้ใช้) เบราว์เซอร์จึงเล่นไฟล์จาก URL นั้นตรง ๆ ไม่ได้ ·
   * หน้านี้ดึงไบต์มาแล้วประกอบเป็น `data:` ที่ฝั่งเบราว์เซอร์เอง โดยยอมรับเฉพาะ
   * นามสกุลในรายการนี้ — ชนิดที่ไม่รู้จักถูกเสนอให้ดาวน์โหลดแทน ไม่ใช่เดาชนิดให้
   *
   * SVG อยู่ในรายการได้เพราะแสดงผ่าน `<img>` ซึ่งตามสเปกแล้วรันสคริปต์ข้างในไม่ได้
   */
  const PREVIEW_TYPES = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
    webp: 'image/webp', bmp: 'image/bmp', avif: 'image/avif', ico: 'image/x-icon',
    svg: 'image/svg+xml',
    mp4: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg', mov: 'video/quicktime',
    mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', flac: 'audio/flac', m4a: 'audio/mp4'
  };

  const ARCHIVE_EXTENSIONS = ['zip'];

  /** คำสั่งที่ต้องมีสิทธิ์ `file.manage` — ปุ่มและรายการเมนูของมันถูกซ่อนเมื่อไม่มีสิทธิ์ */
  const WRITE_COMMANDS = ['mkdir', 'touch', 'upload', 'rename', 'cut', 'paste', 'zip', 'unzip', 'chmod', 'delete'];

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function button(className, text) {
    const node = el('button', className, text);
    node.type = 'button';
    return node;
  }

  function extensionOf(name) {
    const at = String(name).lastIndexOf('.');
    return at <= 0 ? '' : String(name).slice(at + 1).toLowerCase();
  }

  /**
   * คำสั่งที่เปลี่ยนแปลงข้อมูลทุกตัววิ่งผ่านที่นี่ที่เดียว
   *
   * ใช้ `http.request` ตรง ๆ ไม่ใช่ `PhpcpApi.post/del` เพราะ **`http.delete()` ของ
   * เฟรมเวิร์กตีความพารามิเตอร์ที่สองเป็น options ไม่ใช่ body** — `PhpcpApi.del()`
   * จึงส่ง body ไม่ออก และคำสั่งลบจะกลายเป็นคำขอที่ไม่มีรายการอะไรติดไปเลย
   * · เส้นทางนี้ยังได้ CSRF, การลองใหม่เมื่อ 419 และตัวแปลง envelope ครบเหมือนกัน
   */
  async function send(method, path, body) {
    const response = await window.http.request(window.PhpcpApi.base + path, {
      method: method,
      body: body === undefined ? null : body
    });

    return window.PhpcpApi.unwrap(response);
  }

  /** URL ของ endpoint ดาวน์โหลด — ใช้ทั้งกับปุ่มดาวน์โหลดและการดึงไบต์มาทำตัวอย่าง */
  function downloadUrl(root, path) {
    return window.PhpcpApi.base + '/files/download?root=' + encodeURIComponent(root)
      + '&path=' + encodeURIComponent(path);
  }

  // ===========================================================================
  window.pageFileManager = function (page) {
    const find = (selector) => page.querySelector(selector);

    const refs = {
      rootSelect: find('[data-fm-root]'),
      up: find('[data-fm-up]'),
      reload: find('[data-fm-reload]'),
      search: find('[data-fm-search]'),
      crumbs: find('[data-fm-crumbs]'),
      commands: find('[data-fm-commands]'),
      tree: find('[data-fm-tree]'),
      pane: find('[data-fm-pane]'),
      count: find('[data-fm-count]'),
      pager: find('[data-fm-pager]'),
      disk: find('[data-fm-disk]'),
      modal: find('[data-fm-modal]'),
      modalTitle: find('[data-fm-modal-title]'),
      modalBody: find('[data-fm-modal-body]'),
      modalFoot: find('[data-fm-modal-foot]'),
      modalClose: find('[data-fm-modal-close]'),
      context: find('[data-fm-context]'),
      drop: find('[data-fm-drop]')
    };

    const query = new URLSearchParams(window.location.search);

    const state = {
      root: query.get('root') || '',
      path: query.get('path') || '',
      scopes: [],
      entries: [],
      shown: [],
      rows: [],
      meta: {},
      selected: new Set(),
      anchor: -1,
      view: window.localStorage.getItem('phpcp_fm_view') === 'grid' ? 'grid' : 'list',
      sort: { field: 'name', desc: false },
      clipboard: null,
      query: '',
      crumbs: [],
      page: 1,
      perPage: 500,
      canWrite: window.PhpcpAuth.can('file.manage'),
      nodes: new Map(),
      expanded: new Set(['']),
      busy: false
    };

    // -----------------------------------------------------------------------
    // ตัวช่วยเล็ก ๆ
    // -----------------------------------------------------------------------
    const selectedEntries = () => state.shown.filter((entry) => state.selected.has(entry.path));

    const fail = (error) => window.PhpcpUi.error(error);

    /**
     * เส้นทางบนแถบที่อยู่ต้องตรงกับสิ่งที่เห็นเสมอ
     *
     * ใช้ `history.replaceState` ไม่ใช่ `RouterManager.navigate` เพราะการนำทางของ
     * router จะเรนเดอร์เทมเพลตใหม่ทั้งหน้า — สถานะที่ผู้ใช้สร้างไว้ (สิ่งที่เลือก
     * คลิปบอร์ด แถบโฟลเดอร์ที่กางอยู่) จะหายทุกครั้งที่เปลี่ยนโฟลเดอร์
     */
    function syncUrl() {
      const url = new URL(window.location.href);

      url.searchParams.set('root', state.root);
      url.searchParams.set('path', state.path);
      window.history.replaceState({}, '', url.toString());
    }

    // -----------------------------------------------------------------------
    // การโหลดข้อมูล
    // -----------------------------------------------------------------------
    async function loadScopes() {
      const result = await window.PhpcpApi.getFull('/files/roots');

      state.scopes = Array.isArray(result.data) ? result.data : [];

      if (state.scopes.length === 0) {
        throw new Error(t('Your account has no file scope to open'));
      }

      const known = state.scopes.some((scope) => scope.key === state.root);
      if (!known) {
        state.root = state.scopes[0].key;
        state.path = '';
      }

      refs.rootSelect.textContent = '';
      state.scopes.forEach((scope) => {
        const option = el('option', null, scope.label);
        option.value = scope.key;
        option.selected = scope.key === state.root;
        refs.rootSelect.appendChild(option);
      });
    }

    async function loadList() {
      state.busy = true;

      try {
        const result = state.query !== ''
          ? await window.PhpcpApi.getFull('/files/search', {
            root: state.root, path: state.path, q: state.query
          })
          : await window.PhpcpApi.getFull('/files', {
            root: state.root, path: state.path, page: state.page, per_page: state.perPage
          });

        state.entries = Array.isArray(result.data) ? result.data : [];
        state.meta = result.meta || {};

        // เส้นทางนำทางมาเฉพาะกับคำตอบของการ**เปิดโฟลเดอร์** — ผลการค้นหาข้ามโฟลเดอร์
        // จึงไม่มีให้ · เก็บอันล่าสุดไว้ เพราะ "ตอนนี้อยู่ที่ไหน" ไม่ได้เปลี่ยนไปตามการค้นหา
        if (Array.isArray(state.meta.breadcrumb)) {
            state.crumbs = state.meta.breadcrumb;
        }
        state.selected.clear();
        state.anchor = -1;
      } catch (error) {
        state.entries = [];
        state.meta = {};
        fail(error);
      } finally {
        state.busy = false;
      }

      renderCrumbs();
      renderList();
      renderStatus();
      syncUrl();
    }

    /** โหลดโครงโฟลเดอร์ของขอบเขตปัจจุบันสองชั้นแรก */
    async function loadTree() {
      state.nodes.clear();

      try {
        const result = await window.PhpcpApi.getFull('/files/tree', { root: state.root, path: '', depth: 2 });

        storeNodes('', Array.isArray(result.data) ? result.data : []);
      } catch (error) {
        state.nodes.set('', []);
      }

      renderTree();
    }

    /** เก็บโหนดลง Map แบบแบน — ลูกที่ติดมากับคำตอบถูกเก็บแยกไปในคีย์ของตัวเอง */
    function storeNodes(path, nodes) {
      state.nodes.set(path, nodes.map((node) => ({
        name: node.name,
        path: node.path,
        hasChildren: node.has_children === true
      })));

      nodes.forEach((node) => {
        if (Array.isArray(node.children) && node.children.length > 0) {
          storeNodes(node.path, node.children);
        }
      });
    }

    async function expandNode(path) {
      if (!state.nodes.has(path)) {
        try {
          const result = await window.PhpcpApi.getFull('/files/tree', { root: state.root, path: path, depth: 1 });

          storeNodes(path, Array.isArray(result.data) ? result.data : []);
        } catch (error) {
          state.nodes.set(path, []);
        }
      }

      state.expanded.add(path);
      renderTree();
    }

    // -----------------------------------------------------------------------
    // การนำทาง
    // -----------------------------------------------------------------------
    function goTo(path) {
      state.path = path;
      state.page = 1;
      state.query = '';
      refs.search.value = '';

      // เปิดกิ่งของโฟลเดอร์ที่กำลังไปให้เห็นในแถบซ้ายด้วย
      let walked = '';
      state.expanded.add('');
      path.split('/').filter(Boolean).forEach((segment) => {
        walked = walked === '' ? segment : walked + '/' + segment;
        state.expanded.add(walked);
      });

      loadList();
      renderTree();
    }

    function goUp() {
      if (state.path === '') return;

      const at = state.path.lastIndexOf('/');
      goTo(at === -1 ? '' : state.path.slice(0, at));
    }

    // -----------------------------------------------------------------------
    // การวาดหน้าจอ
    // -----------------------------------------------------------------------
    function renderCrumbs() {
      refs.crumbs.textContent = '';

      const trail = state.crumbs.length > 0 ? state.crumbs : [{ label: '/', path: '' }];

      trail.forEach((step, index) => {
        if (index > 0) refs.crumbs.appendChild(el('span', 'fm-crumb-sep', '/'));

        const crumb = button(null, step.label);
        crumb.addEventListener('click', () => goTo(step.path));
        refs.crumbs.appendChild(crumb);
      });

      if (state.query !== '') {
        refs.crumbs.appendChild(el('span', 'fm-crumb-sep', '·'));
        refs.crumbs.appendChild(el('span', 'fm-muted', t('Search results') + ': ' + state.query));
      }
    }

    function renderTree() {
      refs.tree.textContent = '';
      refs.tree.appendChild(treeLevel('', 0));
    }

    function treeLevel(path, depth) {
      const list = el('ul');

      (state.nodes.get(path) || []).forEach((node) => {
        const item = el('li');
        const row = el('div', 'fm-node' + (node.path === state.path ? ' is-current' : ''));

        const twist = button('fm-twist icon-' + (state.expanded.has(node.path) ? 'down' : 'next'));
        twist.hidden = !node.hasChildren;
        twist.addEventListener('click', (event) => {
          event.stopPropagation();

          if (state.expanded.has(node.path)) {
            state.expanded.delete(node.path);
            renderTree();
          } else {
            expandNode(node.path);
          }
        });

        const label = button('fm-label icon-folder', node.name);
        label.title = node.path;
        label.addEventListener('click', () => goTo(node.path));

        row.appendChild(twist);
        row.appendChild(label);
        item.appendChild(row);

        if (state.expanded.has(node.path) && depth < 12) {
          item.appendChild(treeLevel(node.path, depth + 1));
        }

        list.appendChild(item);
      });

      return list;
    }

    /** เรียงตามคอลัมน์ที่เลือก โดยโฟลเดอร์ขึ้นก่อนไฟล์เสมอ */
    function sortEntries() {
      const field = state.sort.field;
      const direction = state.sort.desc ? -1 : 1;

      state.shown = state.entries.slice().sort((a, b) => {
        const rank = (entry) => (entry.type === 'dir' ? 0 : 1);
        if (rank(a) !== rank(b)) return rank(a) - rank(b);

        if (field === 'size') return ((a.size || 0) - (b.size || 0)) * direction;
        if (field === 'mtime') return ((a.mtime || 0) - (b.mtime || 0)) * direction;
        if (field === 'mode') return String(a.mode).localeCompare(String(b.mode)) * direction;

        return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' }) * direction;
      });
    }

    function renderList() {
      sortEntries();
      refs.pane.textContent = '';
      state.rows = [];

      if (state.shown.length === 0) {
        refs.pane.appendChild(el('div', 'fm-empty', state.query !== '' ? t('Nothing matched') : t('This folder is empty')));
        renderCommands();

        return;
      }

      refs.pane.appendChild(state.view === 'grid' ? gridView() : listView());
      renderCommands();
    }

    /**
     * ทาสีการเลือกใหม่โดย**ไม่สร้าง DOM ใหม่**
     *
     * จำเป็นจริง ๆ ไม่ใช่แค่เรื่องความเร็ว: ถ้าวาดรายการใหม่ทุกครั้งที่คลิก แถวที่ถูก
     * คลิกครั้งแรกจะถูกแทนที่ก่อนคลิกครั้งที่สองจะมาถึง เบราว์เซอร์จึงไม่นับเป็น
     * `dblclick` เลย — ดับเบิลคลิกเพื่อเปิดโฟลเดอร์จะไม่ทำงานทั้งหน้า
     */
    function paintSelection() {
      state.rows.forEach((node, index) => {
        const entry = state.shown[index];

        node.classList.toggle('is-selected', state.selected.has(entry.path));
        node.classList.toggle('is-cut', isCut(entry));
      });

      renderCommands();
      renderStatus();
    }

    function listView() {
      const table = el('table');
      const head = el('thead');
      const headRow = el('tr');

      [
        { field: 'name', label: 'Name' },
        { field: 'size', label: 'Size' },
        { field: 'mtime', label: 'Modified' },
        { field: 'mode', label: 'Permissions' }
      ].forEach((column) => {
        const cell = el('th', null, t(column.label));

        if (state.sort.field === column.field) {
          cell.appendChild(el('span', 'fm-sort', state.sort.desc ? ' ▼' : ' ▲'));
        }

        cell.addEventListener('click', () => {
          state.sort = {
            field: column.field,
            desc: state.sort.field === column.field ? !state.sort.desc : false
          };
          renderList();
        });

        headRow.appendChild(cell);
      });

      head.appendChild(headRow);
      table.appendChild(head);

      const body = el('tbody');

      state.shown.forEach((entry, index) => {
        const row = el('tr', rowClass('fm-row', entry));

        const name = el('td');
        const wrap = el('div', 'fm-name');
        wrap.appendChild(el('span', 'fm-icon ' + iconFor(entry)));
        wrap.appendChild(el('span', null, entry.name));
        name.title = entry.path;
        name.appendChild(wrap);

        row.appendChild(name);
        row.appendChild(el('td', 'fm-muted', entry.type === 'dir' ? '—' : fmt().bytes(entry.size)));
        row.appendChild(el('td', 'fm-muted', fmt().datetime(entry.mtime)));
        row.appendChild(el('td', 'fm-mono fm-muted', entry.mode));

        bindRow(row, entry, index);
        body.appendChild(row);
      });

      table.appendChild(body);

      return table;
    }

    function gridView() {
      const grid = el('div', 'fm-grid');

      state.shown.forEach((entry, index) => {
        const tile = el('div', rowClass('fm-tile', entry));

        tile.appendChild(el('span', 'fm-icon ' + iconFor(entry)));
        tile.appendChild(el('span', 'fm-tile-name', entry.name));
        tile.title = entry.path;

        bindRow(tile, entry, index);
        grid.appendChild(tile);
      });

      return grid;
    }

    function isCut(entry) {
      return state.clipboard !== null
        && state.clipboard.op === 'cut'
        && state.clipboard.root === state.root
        && state.clipboard.items.indexOf(entry.path) !== -1;
    }

    function rowClass(base, entry) {
      let className = base;

      if (state.selected.has(entry.path)) className += ' is-selected';
      if (isCut(entry)) className += ' is-cut';

      return className;
    }

    function iconFor(entry) {
      if (entry.type === 'link') return 'icon-link';

      return KIND_ICON[entry.kind] || KIND_ICON.file;
    }

    function bindRow(node, entry, index) {
      state.rows[index] = node;

      node.addEventListener('click', (event) => {
        selectAt(index, event);
        paintSelection();
      });

      node.addEventListener('dblclick', () => open(entry));

      node.addEventListener('contextmenu', (event) => {
        event.preventDefault();

        if (!state.selected.has(entry.path)) {
          state.selected.clear();
          state.selected.add(entry.path);
          state.anchor = index;
          paintSelection();
        }

        openContextMenu(event.clientX, event.clientY, entry);
      });
    }

    /** เลือกตามปุ่มที่กดค้าง — เหมือนตัวจัดการไฟล์ของระบบปฏิบัติการ */
    function selectAt(index, event) {
      const entry = state.shown[index];

      if (event.shiftKey && state.anchor >= 0) {
        const from = Math.min(state.anchor, index);
        const to = Math.max(state.anchor, index);

        state.selected.clear();
        for (let i = from; i <= to; i++) state.selected.add(state.shown[i].path);

        return;
      }

      if (event.ctrlKey || event.metaKey) {
        if (state.selected.has(entry.path)) {
          state.selected.delete(entry.path);
        } else {
          state.selected.add(entry.path);
        }
        state.anchor = index;

        return;
      }

      state.selected.clear();
      state.selected.add(entry.path);
      state.anchor = index;
    }

    function renderStatus() {
      const chosen = state.selected.size;

      refs.count.textContent = chosen > 0
        ? t('{count} items', {count: state.shown.length}) + ' · ' + t('{count} selected', {count: chosen})
        : t('{count} items', {count: state.shown.length});

      refs.pager.textContent = '';

      const pages = Number(state.meta.pages || 1);
      if (state.query === '' && pages > 1) {
        const previous = button(null, '‹');
        previous.disabled = state.page <= 1;
        previous.addEventListener('click', () => { state.page -= 1; loadList(); });

        const next = button(null, '›');
        next.disabled = state.page >= pages;
        next.addEventListener('click', () => { state.page += 1; loadList(); });

        refs.pager.appendChild(previous);
        refs.pager.appendChild(el('span', null, state.page + ' / ' + pages));
        refs.pager.appendChild(next);
      }

      if (state.meta.truncated === true) {
        refs.pager.appendChild(el('span', 'fm-muted', ' · ' + t('Results were cut short — narrow the search')));
      }

      const disk = state.meta.disk;
      refs.disk.textContent = disk && disk.total
        ? t('Free') + ': ' + fmt().bytes(disk.free) + ' / ' + fmt().bytes(disk.total)
        : '';
    }

    /**
     * เปิด/ปิดปุ่มตามสิ่งที่เลือกอยู่
     *
     * `data-fm-need` บอกเงื่อนไขของแต่ละปุ่มไว้ในเทมเพลต — ตรรกะจึงอยู่ที่เดียว
     * และการเพิ่มปุ่มใหม่ไม่ต้องมาแก้ฟังก์ชันนี้
     */
    function renderCommands() {
      const chosen = selectedEntries();
      const single = chosen.length === 1 ? chosen[0] : null;

      refs.commands.querySelectorAll('[data-fm-do]').forEach((node) => {
        const need = node.dataset.fmNeed;

        // ปุ่มที่เปลี่ยนแปลงไฟล์ถูก**ซ่อน** ไม่ใช่แค่ปิดใช้งาน เมื่อไม่มีสิทธิ์
        // file.manage — แถวปุ่มที่กดไม่ได้ครึ่งแถวไม่ได้บอกอะไรผู้ใช้นอกจากความสับสน
        if (WRITE_COMMANDS.indexOf(node.dataset.fmDo) !== -1) {
          node.hidden = !state.canWrite;
        }

        if (need === 'any') node.disabled = chosen.length === 0;
        else if (need === 'one') node.disabled = single === null;
        else if (need === 'one-file') node.disabled = single === null || single.type !== 'file';
        else if (need === 'archive') {
          node.disabled = single === null || ARCHIVE_EXTENSIONS.indexOf(extensionOf(single.name)) === -1;
        } else if (need === 'clipboard') node.disabled = state.clipboard === null;
        else node.disabled = false;
      });
    }

    // -----------------------------------------------------------------------
    // การเปิดรายการ
    // -----------------------------------------------------------------------
    function open(entry) {
      if (entry.type === 'dir') {
        goTo(entry.path);
        return;
      }

      if (entry.editable) {
        openEditor(entry);
        return;
      }

      if (PREVIEW_TYPES[extensionOf(entry.name)]) {
        openPreview(entry);
        return;
      }

      download(entry);
    }

    function download(entry) {
      // ลิงก์ชั่วคราวแทน window.open — endpoint ส่ง Content-Disposition: attachment
      // อยู่แล้ว เบราว์เซอร์จึงบันทึกไฟล์ให้โดยไม่เปิดแท็บเปล่าค้างไว้
      const link = el('a');

      link.href = downloadUrl(state.root, entry.path);
      link.rel = 'noopener';
      link.download = entry.name;
      page.appendChild(link);
      link.click();
      link.remove();
    }

    // -----------------------------------------------------------------------
    // กล่องซ้อน
    // -----------------------------------------------------------------------
    function openModal(title, build, buttons) {
      refs.modalTitle.textContent = title;
      refs.modalBody.textContent = '';
      refs.modalFoot.textContent = '';

      build(refs.modalBody);

      (buttons || []).forEach((spec) => {
        const node = button('btn ' + (spec.className || ''), spec.label);
        node.addEventListener('click', spec.onClick);
        refs.modalFoot.appendChild(node);
      });

      const close = button('btn', t('Close'));
      close.addEventListener('click', closeModal);
      refs.modalFoot.appendChild(close);

      refs.modal.hidden = false;

      const focusable = refs.modalBody.querySelector('input, textarea, select');
      if (focusable) focusable.focus();
    }

    function closeModal() {
      refs.modal.hidden = true;
      refs.modalBody.textContent = '';
      refs.modalFoot.textContent = '';
    }

    /** กล่องกรอกค่าเดียว — ใช้กับสร้างโฟลเดอร์ สร้างไฟล์ เปลี่ยนชื่อ และตั้งชื่อไฟล์บีบอัด */
    function askText(title, label, value, onSubmit) {
      let input = null;

      openModal(title, (body) => {
        body.appendChild(el('label', null, label));
        input = el('input');
        input.type = 'text';
        input.value = value || '';
        input.className = 'form-control';
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            submit();
          }
        });
        body.appendChild(input);
      }, [{
        label: t('Save'),
        className: 'btn-primary icon-save',
        onClick: () => submit()
      }]);

      // เลือกเฉพาะส่วนชื่อ ไม่รวมนามสกุล — คนเปลี่ยนชื่อไฟล์แทบไม่เคยอยากเปลี่ยนนามสกุล
      const dot = String(value || '').lastIndexOf('.');
      if (dot > 0) input.setSelectionRange(0, dot);

      function submit() {
        const text = input.value.trim();
        if (text === '') return;

        closeModal();
        onSubmit(text);
      }
    }

    // -----------------------------------------------------------------------
    // ตัวแก้ไขข้อความ
    // -----------------------------------------------------------------------
    async function openEditor(entry) {
      let file;

      try {
        file = await window.PhpcpApi.get('/files/content', { root: state.root, path: entry.path });
      } catch (error) {
        fail(error);
        return;
      }

      let area = null;

      openModal(entry.name, (body) => {
        area = el('textarea', 'fm-editor');
        area.value = file.content || '';
        area.spellcheck = false;
        area.addEventListener('keydown', (event) => {
          if ((event.ctrlKey || event.metaKey) && event.key === 's') {
            event.preventDefault();
            save();
          }
        });
        body.appendChild(area);
      }, [{
        label: t('Save'),
        className: 'btn-primary icon-save',
        onClick: () => save()
      }]);

      async function save() {
        try {
          await send('PUT', '/files/content', { root: state.root, path: entry.path, content: area.value });
          window.PhpcpUi.success(t('File saved'));
          closeModal();
          loadList();
        } catch (error) {
          fail(error);
        }
      }
    }

    // -----------------------------------------------------------------------
    // ตัวอย่างไฟล์ — ดึงไบต์มาแล้วประกอบเป็น data: ในเบราว์เซอร์
    // -----------------------------------------------------------------------
    async function openPreview(entry) {
      const mime = PREVIEW_TYPES[extensionOf(entry.name)];

      openModal(entry.name, (body) => {
        body.appendChild(el('div', 'fm-muted', t('Loading')));
      }, []);

      let dataUrl;

      try {
        const response = await window.fetch(downloadUrl(state.root, entry.path), { credentials: 'same-origin' });

        if (!response.ok) throw new Error(t('This file is too large to preview'));

        const blob = await response.blob();

        dataUrl = await new Promise((resolve, reject) => {
          const reader = new window.FileReader();

          reader.onload = () => resolve(String(reader.result));
          reader.onerror = () => reject(new Error(t('Could not read the file')));
          reader.readAsDataURL(new window.Blob([blob], { type: mime }));
        });
      } catch (error) {
        refs.modalBody.textContent = '';
        refs.modalBody.appendChild(el('div', 'fm-muted', error.message));

        return;
      }

      refs.modalBody.textContent = '';

      const holder = el('div', 'fm-preview');
      const kind = mime.split('/')[0];
      const media = el(kind === 'image' ? 'img' : (kind === 'video' ? 'video' : 'audio'));

      media.src = dataUrl;
      if (kind !== 'image') media.controls = true;
      if (kind === 'image') media.alt = entry.name;

      holder.appendChild(media);
      refs.modalBody.appendChild(holder);
    }

    // -----------------------------------------------------------------------
    // คุณสมบัติ
    // -----------------------------------------------------------------------
    async function showInfo(entry) {
      let info;

      try {
        info = await window.PhpcpApi.get('/files/info', { root: state.root, path: entry.path });
      } catch (error) {
        fail(error);
        return;
      }

      openModal(t('Properties'), (body) => {
        const table = el('table', 'fm-props');

        const rows = [
          [t('Name'), info.name],
          [t('Path'), '/' + info.path],
          [t('Type'), info.type === 'dir' ? t('Folder') : (info.type === 'link' ? t('Symbolic link') : t('File'))],
          [t('Size'), info.type === 'dir' ? '—' : fmt().bytes(info.size)],
          [t('Items'), info.entries === null || info.entries === undefined ? '—' : String(info.entries)],
          [t('Modified'), fmt().datetime(info.mtime)],
          [t('Permissions'), info.mode],
          [t('Owner'), info.owner + ' / ' + info.group],
          [t('Link target'), info.link || '—']
        ];

        rows.forEach((pair) => {
          const row = el('tr');

          row.appendChild(el('th', null, pair[0]));
          row.appendChild(el('td', null, pair[1]));
          table.appendChild(row);
        });

        body.appendChild(table);
      }, []);
    }

    // -----------------------------------------------------------------------
    // คำสั่งที่เปลี่ยนแปลงไฟล์
    // -----------------------------------------------------------------------
    /** เรียกคำสั่ง แจ้งผล แล้วโหลดรายการใหม่ — คืน true เมื่อสำเร็จจริงเท่านั้น */
    async function run(work, message) {
      try {
        await work();
      } catch (error) {
        fail(error);

        return false;
      }

      window.PhpcpUi.success(message);
      loadList();

      return true;
    }

    function makeDirectory() {
      askText(t('New folder'), t('Folder name'), '', (name) => {
        run(
          () => send('POST', '/files/directories', { root: state.root, path: state.path, name: name }),
          t('Folder created')
        ).then((ok) => { if (ok) loadTree(); });
      });
    }

    function makeFile() {
      askText(t('New file'), t('File name'), '', (name) => {
        run(
          () => send('PUT', '/files/content', {
            root: state.root,
            path: state.path === '' ? name : state.path + '/' + name,
            content: '',
            create: true
          }),
          t('File created')
        );
      });
    }

    function rename(entry) {
      askText(t('Rename'), t('New name'), entry.name, (name) => {
        run(
          () => send('POST', '/files/move', {
            root: state.root,
            items: [entry.path],
            destination: state.path,
            rename: name
          }),
          t('Renamed')
        ).then((ok) => { if (ok) loadTree(); });
      });
    }

    function remember(op) {
      const chosen = selectedEntries();
      if (chosen.length === 0) return;

      state.clipboard = { op: op, root: state.root, items: chosen.map((entry) => entry.path) };
      window.PhpcpUi.success(op === 'copy' ? t('Copied to clipboard') : t('Cut to clipboard'));
      paintSelection();
    }

    function paste() {
      if (!state.clipboard) return;

      // ข้ามขอบเขตยังทำไม่ได้ — API รับ `root` เดียวต่อคำสั่ง และการคัดลอกข้ามขอบเขต
      // แปลว่าข้ามสิทธิ์ของเจ้าของไฟล์ด้วย ซึ่งต้องออกแบบแยกไม่ใช่แถมมากับปุ่มวาง
      if (state.clipboard.root !== state.root) {
        window.NotificationManager.error(t('Paste works inside the same scope only'));

        return;
      }

      const move = state.clipboard.op === 'cut';

      run(
        () => send('POST', move ? '/files/move' : '/files/copy', {
          root: state.root,
          items: state.clipboard.items,
          destination: state.path
        }),
        move ? t('Moved') : t('Copied')
      ).then((ok) => {
        if (!ok) return;

        if (move) state.clipboard = null;
        loadTree();
      });
    }

    function compress() {
      const chosen = selectedEntries();
      if (chosen.length === 0) return;

      const suggested = (chosen.length === 1 ? chosen[0].name : 'archive') + '.zip';

      askText(t('Compress'), t('Archive name'), suggested, (name) => {
        run(
          () => send('POST', '/files/archives', {
            root: state.root,
            path: state.path,
            items: chosen.map((entry) => entry.path),
            archive: name
          }),
          t('Archive created')
        );
      });
    }

    function extract(entry) {
      run(
        () => send('POST', '/files/extractions', {
          root: state.root,
          path: entry.path,
          destination: state.path
        }),
        t('Extracted')
      ).then((ok) => { if (ok) loadTree(); });
    }

    function changeMode(entry) {
      let select = null;
      let recursive = null;

      const modes = entry.type === 'dir'
        ? ['0755', '0750', '0700']
        : ['0644', '0640', '0600'];

      openModal(t('Permissions'), (body) => {
        body.appendChild(el('label', null, entry.name));

        select = el('select', 'form-control');
        modes.forEach((mode) => {
          const option = el('option', null, mode);
          option.value = mode;
          option.selected = mode === entry.mode;
          select.appendChild(option);
        });
        body.appendChild(select);

        if (entry.type === 'dir') {
          const line = el('label');

          recursive = el('input');
          recursive.type = 'checkbox';
          line.appendChild(recursive);
          line.appendChild(document.createTextNode(' ' + t('Apply to everything inside')));
          body.appendChild(line);
        }
      }, [{
        label: t('Save'),
        className: 'btn-primary icon-save',
        onClick: () => {
          closeModal();
          run(
            () => send('PUT', '/files/permissions', {
              root: state.root,
              path: entry.path,
              mode: select.value,
              recursive: recursive ? recursive.checked : false
            }),
            t('Permissions changed')
          );
        }
      }]);
    }

    async function remove() {
      const chosen = selectedEntries();
      if (chosen.length === 0) return;

      // ลบถาวร ไม่มีถังขยะ — ต้องบอกให้ชัดก่อน ไม่ใช่ให้ผู้ใช้ค้นพบเอาเองทีหลัง
      const ok = await window.PhpcpUi.confirm({
        type: 'danger',
        title: t('Delete'),
        message: t('Delete {count} items permanently? This cannot be undone.', {count: chosen.length})
      });

      if (!ok) return;

      run(
        () => send('DELETE', '/files', { root: state.root, items: chosen.map((entry) => entry.path) }),
        t('Deleted')
      ).then((ok) => { if (ok) loadTree(); });
    }

    async function upload(files) {
      const list = Array.from(files || []);
      if (list.length === 0) return;

      const form = new window.FormData();

      form.append('root', state.root);
      form.append('path', state.path);
      list.forEach((file) => form.append('files[]', file));

      try {
        await send('POST', '/files/upload', form);
        window.PhpcpUi.success(t('{count} files uploaded', {count: list.length}));
        loadList();
      } catch (error) {
        fail(error);
      }
    }

    /**
     * ตัวเลือกไฟล์สำหรับปุ่ม "อัปโหลด" — สร้างเองและ**ไม่ใส่ลง DOM**
     *
     * เขียนไว้ในเทมเพลตไม่ได้: `TextElementFactory` ของ Now.js แปลง
     * `<input type="file">` ทุกตัวในหน้าเป็นวิดเจ็ตของตัวเอง (`div.file-display`)
     * ซึ่งมองเห็นได้ แม้ input เดิมจะมีแอตทริบิวต์ `hidden` — ผลคือคำว่า "Choose file"
     * ลอยค้างอยู่ท้ายหน้าตลอดเวลา · เจอจากการดูภาพหน้าจอของระบบจริง ไม่มีอะไรใน console ฟ้อง
     *
     * element ที่ไม่ได้อยู่ใน DOM ยังเปิดหน้าต่างเลือกไฟล์ได้ตามปกติ เมื่อ `click()`
     * ถูกเรียกจากการกดของผู้ใช้จริง
     */
    let picker = null;

    function filePicker() {
      if (picker !== null) return picker;

      picker = document.createElement('input');
      picker.type = 'file';
      picker.multiple = true;
      picker.addEventListener('change', () => {
        upload(picker.files);
        picker.value = '';
      });

      return picker;
    }

    // -----------------------------------------------------------------------
    // ตารางคำสั่ง — ชื่อใน `data-fm-do` ตรงกับคีย์ที่นี่
    // -----------------------------------------------------------------------
    const commands = {
      mkdir: makeDirectory,
      touch: makeFile,
      upload: () => filePicker().click(),
      download: () => { const one = selectedEntries()[0]; if (one) download(one); },
      open: () => { const one = selectedEntries()[0]; if (one) open(one); },
      rename: () => { const one = selectedEntries()[0]; if (one) rename(one); },
      copy: () => remember('copy'),
      cut: () => remember('cut'),
      paste: paste,
      zip: compress,
      unzip: () => { const one = selectedEntries()[0]; if (one) extract(one); },
      chmod: () => { const one = selectedEntries()[0]; if (one) changeMode(one); },
      info: () => { const one = selectedEntries()[0]; if (one) showInfo(one); },
      delete: remove,
      refresh: () => loadList()
    };

    // -----------------------------------------------------------------------
    // เมนูคลิกขวา
    // -----------------------------------------------------------------------
    function openContextMenu(x, y, entry) {
      refs.context.textContent = '';

      const items = entry
        ? [
          { label: t('Open'), icon: 'icon-folder-open', command: 'open' },
          { label: t('Download'), icon: 'icon-download', command: 'download', when: entry.type === 'file' },
          { separator: true },
          { label: t('Rename'), icon: 'icon-edit', command: 'rename' },
          { label: t('Copy'), icon: 'icon-copy', command: 'copy' },
          { label: t('Cut'), icon: 'icon-cut', command: 'cut' },
          { label: t('Paste'), icon: 'icon-import', command: 'paste', when: state.clipboard !== null },
          { separator: true },
          { label: t('Compress'), icon: 'icon-zip', command: 'zip' },
          {
            label: t('Extract'), icon: 'icon-unzip', command: 'unzip',
            when: ARCHIVE_EXTENSIONS.indexOf(extensionOf(entry.name)) !== -1
          },
          { label: t('Permissions'), icon: 'icon-lock', command: 'chmod' },
          { label: t('Properties'), icon: 'icon-info', command: 'info' },
          { separator: true },
          { label: t('Delete'), icon: 'icon-delete', command: 'delete' }
        ]
        : [
          { label: t('New folder'), icon: 'icon-create-folder', command: 'mkdir' },
          { label: t('New file'), icon: 'icon-new', command: 'touch' },
          { label: t('Upload'), icon: 'icon-upload', command: 'upload' },
          { label: t('Paste'), icon: 'icon-import', command: 'paste', when: state.clipboard !== null },
          { separator: true },
          { label: t('Refresh'), icon: 'icon-refresh', command: 'refresh' }
        ];

      items.forEach((item) => {
        if (WRITE_COMMANDS.indexOf(item.command) !== -1 && !state.canWrite) return;
        if (item.when === false) return;

        const line = el('li');

        if (item.separator) {
          line.className = 'fm-context-sep';
          refs.context.appendChild(line);

          return;
        }

        const action = button(item.icon, item.label);
        action.addEventListener('click', () => {
          closeContextMenu();
          commands[item.command]();
        });

        line.appendChild(action);
        refs.context.appendChild(line);
      });

      // ตำแหน่งส่งผ่าน custom property — CSP ห้าม inline style แต่ setProperty
      // เป็นการเซ็ตผ่าน CSSOM ซึ่งไม่ใช่แอตทริบิวต์ style จึงไม่ถูกบล็อก
      refs.context.hidden = false;

      const box = refs.context.getBoundingClientRect();
      const left = Math.min(x, window.innerWidth - box.width - 8);
      const top = Math.min(y, window.innerHeight - box.height - 8);

      refs.context.style.setProperty('--fm-menu-left', Math.max(0, left) + 'px');
      refs.context.style.setProperty('--fm-menu-top', Math.max(0, top) + 'px');
    }

    function closeContextMenu() {
      refs.context.hidden = true;
    }

    // -----------------------------------------------------------------------
    // การผูกเหตุการณ์
    // -----------------------------------------------------------------------
    page.querySelectorAll('[data-tip]').forEach((node) => {
      const tip = t(node.dataset.tip);

      node.title = tip;

      if (node.tagName === 'INPUT') {
        node.placeholder = tip;
        node.setAttribute('aria-label', tip);
      } else if (!node.textContent.trim()) {
        node.setAttribute('aria-label', tip);
      }
    });

    refs.commands.addEventListener('click', (event) => {
      const node = event.target.closest('[data-fm-do]');

      if (node && commands[node.dataset.fmDo]) commands[node.dataset.fmDo]();
    });

    refs.rootSelect.addEventListener('change', () => {
      state.root = refs.rootSelect.value;
      state.path = '';
      state.expanded = new Set(['']);
      state.clipboard = null;
      loadTree();
      goTo('');
    });

    refs.up.addEventListener('click', goUp);
    refs.reload.addEventListener('click', () => loadList());

    let searchTimer = 0;
    refs.search.addEventListener('input', () => {
      window.clearTimeout(searchTimer);

      searchTimer = window.setTimeout(() => {
        const term = refs.search.value.trim();

        // ต่ำกว่าสองตัวอักษรคือกลับไปดูโฟลเดอร์ตามปกติ — เท่ากับเกณฑ์ที่ agent บังคับ
        state.query = term.length >= 2 ? term : '';
        state.page = 1;
        loadList();
      }, 400);
    });

    page.querySelectorAll('[data-fm-view]').forEach((node) => {
      node.classList.toggle('is-active', node.dataset.fmView === state.view);

      node.addEventListener('click', () => {
        state.view = node.dataset.fmView;
        window.localStorage.setItem('phpcp_fm_view', state.view);

        page.querySelectorAll('[data-fm-view]').forEach((other) => {
          other.classList.toggle('is-active', other.dataset.fmView === state.view);
        });

        renderList();
      });
    });

    refs.modalClose.addEventListener('click', closeModal);

    refs.pane.addEventListener('contextmenu', (event) => {
      if (event.target.closest('.fm-row, .fm-tile')) return;

      event.preventDefault();
      state.selected.clear();
      paintSelection();
      openContextMenu(event.clientX, event.clientY, null);
    });

    refs.pane.addEventListener('click', (event) => {
      if (event.target.closest('.fm-row, .fm-tile, th')) return;

      state.selected.clear();
      paintSelection();
    });

    // --- คีย์ลัด ---
    const onKeyDown = (event) => {
      if (!refs.modal.hidden) {
        if (event.key === 'Escape') closeModal();

        return;
      }

      const inField = event.target.matches('input, textarea, select');

      if (event.key === 'Escape') {
        closeContextMenu();
        state.selected.clear();
        paintSelection();

        return;
      }

      if (inField) return;

      const ctrl = event.ctrlKey || event.metaKey;

      if (ctrl && event.key.toLowerCase() === 'a') {
        event.preventDefault();
        state.shown.forEach((entry) => state.selected.add(entry.path));
        paintSelection();

        return;
      }

      if (ctrl && event.key.toLowerCase() === 'c') { remember('copy'); return; }
      if (ctrl && event.key.toLowerCase() === 'x' && state.canWrite) { remember('cut'); return; }
      if (ctrl && event.key.toLowerCase() === 'v' && state.canWrite) { paste(); return; }

      if (event.key === 'F2' && state.canWrite) { commands.rename(); return; }
      if (event.key === 'Delete' && state.canWrite) { remove(); return; }
      if (event.key === 'Backspace') { event.preventDefault(); goUp(); return; }

      if (event.key === 'Enter') {
        const one = selectedEntries()[0];
        if (one) open(one);
      }
    };

    const onDocumentClick = (event) => {
      if (!refs.context.contains(event.target)) closeContextMenu();
    };

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('click', onDocumentClick);

    // --- ลากไฟล์มาวาง ---
    let dragDepth = 0;

    const onDragEnter = (event) => {
      if (!state.canWrite) return;
      // ลากรายการภายในหน้าเอง (ไม่ใช่ไฟล์จากเครื่อง) ไม่ต้องขึ้นชั้นวาง
      if (Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') === -1) return;

      dragDepth++;
      refs.drop.hidden = false;
    };

    const onDragOver = (event) => {
      if (!refs.drop.hidden) event.preventDefault();
    };

    const onDragLeave = () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) refs.drop.hidden = true;
    };

    const onDrop = (event) => {
      if (refs.drop.hidden) return;

      event.preventDefault();
      dragDepth = 0;
      refs.drop.hidden = true;
      upload(event.dataTransfer.files);
    };

    page.addEventListener('dragenter', onDragEnter);
    page.addEventListener('dragover', onDragOver);
    page.addEventListener('dragleave', onDragLeave);
    page.addEventListener('drop', onDrop);

    // -----------------------------------------------------------------------
    // เริ่มทำงาน
    // -----------------------------------------------------------------------
    (async function start() {
      try {
        await loadScopes();
      } catch (error) {
        refs.pane.appendChild(el('div', 'fm-empty', error.message));

        return;
      }

      await loadTree();
      await loadList();
    })();

    return function cleanup() {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('click', onDocumentClick);
      window.clearTimeout(searchTimer);
    };
  };
})();
