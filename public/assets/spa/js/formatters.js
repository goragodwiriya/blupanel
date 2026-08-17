/**
 * Value formatters templates can call directly — no page-specific JS needed.
 *
 * `window.formatters` applies to **every element** through the pipe in data-text:
 * `<span data-text="memory.used | bytes"></span>` — takes a trailing `:` argument,
 * e.g. `| fixed:1`.
 *
 * Almost every table in the system now uses declarative
 * `data-row-actions`/`data-template`/`data-format` (see site.html/users.html as the
 * model) — what remains in this file is the one button that genuinely can't be
 * declared, because its destination is chosen outside the row (see the comment at
 * formatBackupActions).
 */
(function() {
  'use strict';

  const t = (text) => (window.Now && window.Now.translate ? window.Now.translate(text) : text);

  /** Size in a human-readable unit — takes a value in bytes */
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

  /** A duration as text — takes a value in seconds */
  function duration(value) {
    const seconds = Math.max(0, Number(value) || 0);
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) return days + ' ' + t('days') + ' ' + hours + ' ' + t('hr');
    if (hours > 0) return hours + ' ' + t('hr') + ' ' + minutes + ' ' + t('min');
    return minutes + ' ' + t('min');
  }

  /** unix timestamp → a readable date and time · 0/null = never happened yet */
  function datetime(value) {
    const seconds = Number(value) || 0;

    return seconds > 0 ? window.Utils.date.format(new Date(seconds * 1000), 'D MMMM YYYY HH:mm') : '—';
  }

  /**
   * Overrides the framework's own built-in `datetime` formatter
   *
   * `Utils.string.applyFormatters()` searches in order: **the context's own
   * filters → builtinFormatters → `window.formatters`** · the name
   * `datetime` collides with one that already exists, so ours was never
   * being called at all, and every `| datetime` in every template rendered
   * as the year 1970, because the built-in one interprets the number as
   * **milliseconds**, while our API sends **seconds** system-wide
   *
   * Overridden at the source instead of switching templates to a different
   * name, since renaming would leave a trap behind: whoever writes `| datetime` later would silently get the year 1970 back again
   *
   * Nothing about this bug ever raised a flag — the page rendered fully, the
   * console stayed clean, the only way to see it was reading the date on screen and comparing it against what the API actually sent
   */
  if (window.Utils && window.Utils.string && window.Utils.string.builtinFormatters) {
    window.Utils.string.builtinFormatters.datetime = datetime;
  }

  window.formatters = Object.assign(window.formatters || {}, {
    bytes: bytes,
    duration: duration,
    datetime: datetime,
    /** A fixed decimal count — `| fixed` = a whole number, `| fixed:2` = two places */
    fixed: (value, digits) => (Number(value) || 0).toFixed(Number(digits) || 0),
    /** An empty value shows a dash instead — never left blank, which would look like the page hasn't finished loading */
    dash: (value) => (value === null || value === undefined || value === '' ? '—' : String(value))
  });

  // ---------------------------------------------------------------------------
  // A function template expressions can call — `ExpressionEvaluator.registerFunction`
  //
  // **Why `isOn` needs to exist at all, when it could just be written as a
  // direct comparison:** Now.js's own expression evaluator can read a bare
  // `values['a.b']`, but **fails to parse it once an operator follows** —
  // `values['a.b'] === '1'` returns the string `"values["` instead of a
  // boolean (confirmed by testing in the browser) · and
  // `SettingsRepository`'s boolean values are stored as the strings
  // `"0"`/`"1"`, where `"0"` is truthy in JS — so every switch would show as
  // on if the raw value were bound directly
  //
  // Wrapping the comparison in a function sidesteps that limitation without needing to change the API's response shape
  // ---------------------------------------------------------------------------
  const isOn = (value) => value === '1' || value === 1 || value === true || value === 'true';

  window.ExpressionEvaluator.registerFunction('isOn', isOn);

  /**
   * The one button still left as real code for the backups table —
   * restore/delete already moved to declarative data-row-actions (see
   * backups.html) · this one has to stay real code because its destination
   * is chosen **outside the row** (a selector above the table) — a
   * data-row-actions's data-param-* is assembled at row-render time, so it can't read a value the user selects afterward
   *
   * A file is referenced by **account + filename**, never a row id, ever
   * since the list started reading from the real folder (PLAN-BACKUP-V2 item
   * B4) — both values must therefore come from the row, not from a single column's value
   */
  window.formatBackupActions = (cell, userId, row) => {
    cell.textContent = '';

    if (!window.PhpcpAuth.can('backup.offsite')) return;

    const push = document.createElement('button');
    push.type = 'button';
    push.className = 'btn small icon-cloud';
    push.dataset.action = 'click.prevent:pushOffsite';
    // Bound to the `user_id` column, since `name` is already used as the
    // filename column — one table can't have a duplicate data-field · the filename is read from the row instead
    push.dataset.backupUser = String(userId || 0);
    push.dataset.backupFile = String((row && row.name) || '');
    push.title = t('Copy to the selected destination');
    cell.appendChild(push);
  };

  /**
   * The "back up this account?" checkbox — saves immediately on change, no Save button
   *
   * **Has to be a real formatter, not `data-template`** — a formatter
   * receives the whole row as its third argument, so it knows the account
   * id and can genuinely bind `change` · `data-template` can only assemble
   * text, so a checkbox drawn from it could be clicked but nothing would happen
   *
   * Saves immediately because this is a single standalone switch that needs
   * no confirmation alongside any other field · on failure it must **check
   * itself back** to match what's genuinely true on the server, rather than
   * leaving the screen showing a state that doesn't actually exist, which
   * would make an admin believe backup was enabled for that customer when it wasn't
   */
  function backupTargetToggle(field) {
    return (cell, value, row) => {
      cell.textContent = '';

      const box = document.createElement('input');
      box.type = 'checkbox';
      box.checked = isOn(value) || value === true;
      box.disabled = !(row && row.can_manage);
      // An account with no home yet can't be checked — states the reason right on the checkbox, instead of a click that silently does nothing
      box.title = row && row.reason
        ? row.reason
        : t('Include this account in the automatic backup round');

      box.addEventListener('change', async () => {
        const body = {};
        body[field] = box.checked;

        box.disabled = true;

        try {
          await window.PhpcpApi.patch('/backup-targets/' + Number(row.id), body);
          window.NotificationManager.success(window.Now.translate('Saved'));
        } catch (error) {
          box.checked = !box.checked;
          window.NotificationManager.error(error.message);
        } finally {
          box.disabled = false;
        }
      });

      cell.appendChild(box);
    };
  }

  window.formatBackupFilesToggle = backupTargetToggle('backup_files');
  window.formatBackupDatabaseToggle = backupTargetToggle('backup_database');
})();
