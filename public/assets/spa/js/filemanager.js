/**
 * The full-screen file manager — paired with templates/filemanager.html and css/filemanager.css
 *
 * **Why this page is genuine JS, not declared with attributes like every other page:**
 * every other remaining page in the panel is "a data table + a form," which
 * TableManager/FormManager can fully handle · this page is "a file manager,"
 * whose entire set of behaviors can never be declared at all: multi-select
 * with Shift/Ctrl · right-click · drag-and-drop · a clipboard that crosses
 * folders · keyboard shortcuts · switching to icon view · an editor and file preview in a nested box
 *
 * **This file's security rule (same as ui.js):** a filename and path are
 * values the user set themselves on disk — the DOM is only ever built with
 * `createElement` + `textContent` · **not a single `innerHTML` anywhere in
 * this file**, and never write `element.style.x = ...`, since the CSP is `style-src 'self'` with no `unsafe-inline`
 *
 * **Every command travels through `/api/v2/files/*`, which always forwards
 * to the agent** — a file is touched under the website owner's own
 * privileges via `Executor::asUser()`, and every one is recorded to the
 * audit log · this page has no shortcut to the filesystem and no login system of its own
 */
(function () {
  'use strict';

  /**
   * Translates text, passing substitution values to the translator itself
   *
   * **Never call `.replace('{count}', n)` on the result** —
   * `I18nManager.interpolate()` already consumes every `{...}` in the text
   * during translation, so a key like `'{count} items'` called without
   * params becomes "count items" (the braces are gone, only the variable
   * name is left), and a later replace finds nothing left to substitute · found through testing in a real browser
   */
  const t = (text, params) => (window.Now && window.Now.translate
    ? window.Now.translate(text, params || {})
    : text);
  const fmt = () => window.formatters || {};

  /** Icons by the semantic kind `FileCatalog::kind()` sends */
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
   * Media types for "preview" — an allowlist, never a guess from the server's own Content-Type
   *
   * `GET /files/download` always deliberately sends
   * `application/octet-stream` (prevents stored XSS from a user's .html
   * file), so the browser can't play a file directly from that URL at all ·
   * this page fetches the bytes and assembles a `data:` URI on the browser
   * side itself, only ever accepting an extension in this list — an
   * unrecognized type is offered for download instead, never guessed at
   *
   * SVG can be in this list because it's shown via `<img>`, which per spec can never run a script inside it
   */
  const PREVIEW_TYPES = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
    webp: 'image/webp', bmp: 'image/bmp', avif: 'image/avif', ico: 'image/x-icon',
    svg: 'image/svg+xml',
    mp4: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg', mov: 'video/quicktime',
    mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', flac: 'audio/flac', m4a: 'audio/mp4'
  };

  /*
   * Extensions "extract" can be clicked for — must match the server side's `FileUnzip::kindOf()`
   *
   * `gz` covers both `.tar.gz` and `.sql.gz`, since `extensionOf()` returns
   * only what's after the last dot · the server side is what tells apart
   * which is a tar and which is a single file (the order matters there) —
   * here it only decides whether to show the menu item
   */
  const ARCHIVE_EXTENSIONS = ['zip', 'gz', 'tgz', 'tar'];

  /** Commands that require the `file.manage` permission — their buttons and menu items are hidden without it */
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
   * Every data-changing command travels through this one place
   *
   * Uses `http.request` directly, not `PhpcpApi.post/del`, because **the
   * framework's `http.delete()` interprets the second parameter as options,
   * not a body** — `PhpcpApi.del()` therefore can never send a body out at
   * all, and a delete command would turn into a request with no item list
   * attached whatsoever · this path still gets CSRF, the 419 retry, and the envelope converter, all the same
   */
  async function send(method, path, body) {
    const response = await window.http.request(window.PhpcpApi.base + path, {
      method: method,
      body: body === undefined ? null : body
    });

    return window.PhpcpApi.unwrap(response);
  }

  /** The download endpoint's URL — used by both the download button and fetching bytes for a preview */
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
    // Small helpers
    // -----------------------------------------------------------------------
    const selectedEntries = () => state.shown.filter((entry) => state.selected.has(entry.path));

    const fail = (error) => window.PhpcpUi.error(error);

    /**
     * The path in the address bar must always match what's being shown
     *
     * Uses `history.replaceState`, not `RouterManager.navigate`, because the
     * router's own navigation would re-render the entire page's template —
     * the state the user built up (what's selected, the clipboard, which folders are expanded) would be lost every time the folder changes
     */
    function syncUrl() {
      const url = new URL(window.location.href);

      url.searchParams.set('root', state.root);
      url.searchParams.set('path', state.path);
      window.history.replaceState({}, '', url.toString());
    }

    // -----------------------------------------------------------------------
    // Loading data
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

        // Breadcrumbs only arrive with an **opening-a-folder** response — a
        // search that crosses folders never has any · the last one is kept,
        // since "where we currently are" doesn't change just because of a search
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

    /** Loads the current scope's folder tree, the first two levels deep */
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

    /** Stores nodes in a flat Map — children that come attached to the response are stored under their own key separately */
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
    // Navigation
    // -----------------------------------------------------------------------
    function goTo(path) {
      state.path = path;
      state.page = 1;
      state.query = '';
      refs.search.value = '';

      // Also expands the destination folder's branch, so it shows in the left panel too
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
    // Rendering
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

    /** Sorts by the selected column, with folders always coming before files */
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
     * Repaints the selection **without rebuilding the DOM**
     *
     * Genuinely necessary, not just about speed: if the list were redrawn on
     * every click, the row from the first click would be replaced before the
     * second click ever arrived, so the browser would never count it as a
     * `dblclick` at all — double-clicking to open a folder would stop working across the entire page
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

    /** Selects according to which key is held down — same as the operating system's own file manager */
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
     * Enables/disables buttons based on what's currently selected
     *
     * `data-fm-need` states each button's condition right in the template —
     * so the logic lives in one place, and adding a new button never needs to touch this function
     */
    function renderCommands() {
      const chosen = selectedEntries();
      const single = chosen.length === 1 ? chosen[0] : null;

      refs.commands.querySelectorAll('[data-fm-do]').forEach((node) => {
        const need = node.dataset.fmNeed;

        // A button that changes files is **hidden**, not just disabled, when
        // the file.manage permission is missing — a toolbar half full of unclickable buttons tells the user nothing but confusion
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
    // Opening an item
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
      // A temporary link instead of window.open — the endpoint already sends
      // Content-Disposition: attachment, so the browser saves the file without leaving a blank tab open
      const link = el('a');

      link.href = downloadUrl(state.root, entry.path);
      link.rel = 'noopener';
      link.download = entry.name;
      page.appendChild(link);
      link.click();
      link.remove();
    }

    // -----------------------------------------------------------------------
    // The modal
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

    /** A single-value input modal — used for creating a folder, creating a file, renaming, and naming a compressed archive */
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

      // Selects only the name part, not the extension — someone renaming a file almost never wants to change the extension too
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
    // The text editor
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
    // File preview — fetches the bytes and assembles a data: URI in the browser
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
    // Properties
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
    // Commands that change files
    // -----------------------------------------------------------------------
    /** Calls a command, reports the result, then reloads the list — returns true only on genuine success */
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

      // Crossing scopes still can't be done — the API accepts a single
      // `root` per command, and copying across scopes would also mean
      // crossing the file owner's own privileges, which needs its own
      // separate design, not something tacked onto the paste button
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

      // A permanent delete, no trash bin — this must be stated clearly up front, not something the user discovers for themselves afterward
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
     * The file picker for the "upload" button — built by hand and **never inserted into the DOM**
     *
     * Can't be written in the template: Now.js's own `TextElementFactory`
     * converts every `<input type="file">` on the page into its own widget
     * (`div.file-display`), which stays visible even when the original input
     * carries a `hidden` attribute — the result was the words "Choose file"
     * floating at the bottom of the page permanently · found by looking at
     * screenshots of the real system, nothing in the console ever flagged it
     *
     * An element that isn't in the DOM still opens the file-picker dialog
     * normally when `click()` is called from a genuine user gesture
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
    // The command table — a name in `data-fm-do` matches a key here
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
    // The right-click menu
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

      // Position is passed via a custom property — the CSP forbids inline
      // style, but setProperty sets it through the CSSOM, which isn't the style attribute, so it isn't blocked
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
    // Binding events
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

        // Fewer than two characters goes back to viewing the folder normally — matches the threshold the agent enforces
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

    // --- Keyboard shortcuts ---
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

    // --- Drag and drop ---
    let dragDepth = 0;

    const onDragEnter = (event) => {
      if (!state.canWrite) return;
      // Dragging an item within the page itself (not a file from the machine) never needs the drop overlay
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
    // Startup
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
