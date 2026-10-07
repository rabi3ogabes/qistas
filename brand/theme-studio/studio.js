/* Qistas Theme Studio — admin prototype.
 * Mirrors the production data model (see supabase/migrations/…theme_engine.sql) and uses the same resolver
 * (brand/shared/qistas-theme.js) as the app, so what an admin previews here is what users get.
 */
(function () {
  'use strict';
  const Q = window.QistasTheme, IC = window.QIcon, PV = window.QistasPreview, LG = window.QistasLogo;
  const KEY = 'qistas.themeStudio.v2';
  const LANGS = [['en', 'English'], ['ar', 'العربية'], ['fr', 'Français'], ['es', 'Español'], ['ur', 'اردو']];
  const MOTIFS = [['crescent', 'Crescent'], ['sparkle', 'Sparkle'], ['bars', 'National bars']];
  const PLANS = [['free', 'Free'], ['pro', 'Pro'], ['business', 'Business'], ['lifetime', 'Lifetime']];
  const HIJRI = ['Muharram', 'Safar', 'Rabīʿ I', 'Rabīʿ II', 'Jumādā I', 'Jumādā II', 'Rajab', 'Shaʿbān', 'Ramadan', 'Shawwāl', 'Dhū al-Qaʿdah', 'Dhū al-Ḥijjah'];
  const MONTHS = Array.from({ length: 12 }, (_, i) => new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, i, 1))));
  const GROUPS = ['Brand', 'Surfaces', 'Text', 'Status', 'Tiles', 'Hero', 'Logo'];

  const clone = (o) => JSON.parse(JSON.stringify(o));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const ic = (n, s) => IC.svg(n, { size: s || 18 });
  const HEX = /^#[0-9a-f]{6}$/i;
  const todayISO = () => new Date().toISOString().slice(0, 10);
  const nowFor = (iso) => new Date(iso + 'T12:00:00Z');
  const fmtDate = (d, tz) => new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz || 'UTC' }).format(d);
  const fmtDateTime = (iso, tz) => new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz || 'UTC' }).format(new Date(iso));

  /* ------------------------------------------------------------------ state */
  function fresh() {
    const themes = clone(window.QISTAS_SEED.themes).map((t) => Object.assign({ version: 1, updatedAt: t.publishedAt }, t));
    const versions = {};
    themes.forEach((t) => { versions[t.id] = [{ version: 1, at: t.publishedAt, note: 'Seed', snapshot: clone(t) }]; });
    const countrySettings = {};
    Object.keys(Q.COUNTRIES).forEach((c) => { countrySettings[c] = { hijriOffsetDays: 0 }; });
    return {
      themes, versions, countrySettings, selectedId: 'country-sa', tab: 'colors', editMode: 'light', kind: 'all', q: '',
      preview: { source: 'theme', country: 'SA', date: todayISO(), lang: 'en', mode: 'light', numerals: 'latn', merchant: false, merchantColor: '#9A2E5B' }
    };
  }
  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (raw) { const s = JSON.parse(raw); if (s && Array.isArray(s.themes) && s.versions) return s; }
    } catch (e) { /* storage unavailable: start fresh */ }
    return fresh();
  }
  let saveTimer = null;
  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } }, 120);
  }
  let S = load();
  if (!S.themes.find((t) => t.id === S.selectedId)) S.selectedId = S.themes[0].id;

  const sel = () => S.themes.find((t) => t.id === S.selectedId);
  const baseTheme = () => S.themes.find((t) => t.kind === 'base');
  const lastCommit = (t) => { const v = S.versions[t.id]; return v && v[v.length - 1]; };
  const stableKey = (t) => { const c = clone(t); delete c.version; delete c.updatedAt; delete c.publishedAt; return JSON.stringify(c); };
  const isDirty = (t) => { const c = lastCommit(t); return !c || stableKey(c.snapshot) !== stableKey(t); };
  const liveThemes = () => S.themes.map((t) => lastCommit(t) && clone(lastCommit(t).snapshot)).filter((t) => t && t.status === 'published');
  const resSel = () => Q.resolveSingle(S.themes, S.selectedId);
  const nameOf = (t) => (t.name && (t.name.en || t.name.ar)) || t.id;
  const touch = (t) => { t.updatedAt = new Date().toISOString(); save(); };

  function commit(t, note) {
    const prev = lastCommit(t);
    t.version = (prev ? prev.version : 0) + 1;
    t.updatedAt = new Date().toISOString();
    if (t.status === 'published') t.publishedAt = t.updatedAt;
    S.versions[t.id] = S.versions[t.id] || [];
    S.versions[t.id].push({ version: t.version, at: t.updatedAt, note, snapshot: clone(t) });
    save();
  }

  function activeCtx(country) {
    const m = Q.COUNTRIES[country] || {};
    return { timeZone: m.tz || 'UTC', hijriOffsetDays: ((S.countrySettings[country] || {}).hijriOffsetDays) || 0 };
  }
  /** First run of active days on/after `fromISO` for a theme in a country. */
  function findWindow(theme, country, fromISO, maxDays) {
    const ctx = activeCtx(country), t0 = +nowFor(fromISO), day = 86400000;
    for (let i = 0; i < (maxDays || 800); i++) {
      if (Q.isActive(theme, new Date(t0 + i * day), ctx)) {
        let j = i;
        while (j - i < 120 && Q.isActive(theme, new Date(t0 + (j + 1) * day), ctx)) j++;
        return { start: new Date(t0 + i * day), end: new Date(t0 + j * day) };
      }
    }
    return null;
  }

  function previewResolution() {
    const p = S.preview, tenantAccent = p.merchant ? p.merchantColor : null;
    if (p.source === 'live') {
      return Q.resolve({ themes: liveThemes(), country: p.country, now: nowFor(p.date), tenantAccent, hijriOffsetDays: activeCtx(p.country).hijriOffsetDays });
    }
    return Q.resolveSingle(S.themes, S.selectedId, { country: p.country, tenantAccent });
  }

  /* ------------------------------------------------------------------ small UI helpers */
  function toast(msg, icon) {
    const root = $('#toastRoot'), el = document.createElement('div');
    el.className = 'toast'; el.innerHTML = ic(icon || 'check', 16) + '<span>' + esc(msg) + '</span>';
    root.appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 260); }, 2600);
  }
  function modal(o) {
    const root = $('#modalRoot');
    root.innerHTML = '<div class="scrim" data-act="modal-close"><div class="modal ' + (o.wide ? 'wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="mt">' +
      '<h3 id="mt" class="q-display">' + esc(o.title) + '</h3>' + o.body +
      '<div class="actions">' + (o.actions || []).map((a, i) => '<button class="btn ' + (a.cls || 'outline') + '" data-modal-act="' + i + '">' + esc(a.label) + '</button>').join('') + '</div></div></div>';
    root._actions = o.actions || [];
    const first = $('.modal input, .modal button[data-modal-act]:last-child', root);
    if (first) first.focus();
  }
  const closeModal = () => { $('#modalRoot').innerHTML = ''; };
  function download(name, obj) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' }));
    a.download = name; document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  /* ------------------------------------------------------------------ theme list */
  function scopeText(t) {
    if (t.kind === 'base') return 'Every country';
    const c = (t.scope && t.scope.countries) || ['*'];
    const cs = c.includes('*') ? 'Every country' : c.length > 3 ? c.slice(0, 3).join(', ') + ' +' + (c.length - 3) : c.join(', ');
    return cs + ' · ' + scheduleShort(t);
  }
  function scheduleShort(t) {
    const s = t.schedule || {}, r = s.recurrence;
    if (r && r.type === 'hijri_yearly') return HIJRI[r.month - 1] + ' ' + r.day + (r.spanDays > 1 ? ' +' + (r.spanDays - 1) + 'd' : '');
    if (r && r.type === 'gregorian_yearly') return MONTHS[r.month - 1].slice(0, 3) + ' ' + r.day + (r.spanDays > 1 ? ' +' + (r.spanDays - 1) + 'd' : '');
    if (s.startsAt || s.endsAt) return 'Date window';
    return 'Always on';
  }
  function statusChip(t) {
    return t.status === 'published' ? '<span class="chip live"><i class="dot"></i>Published</span>' : t.status === 'draft' ? '<span class="chip draft">Draft</span>' : '<span class="chip arch">Archived</span>';
  }
  function renderList() {
    const live = (() => { try { return new Set(Q.resolve({ themes: liveThemes(), country: S.preview.country, now: nowFor(S.preview.date), hijriOffsetDays: activeCtx(S.preview.country).hijriOffsetDays }).applied.map((a) => a.id)); } catch (e) { return new Set(); } })();
    const q = S.q.trim().toLowerCase();
    const items = S.themes.filter((t) => (S.kind === 'all' || t.kind === S.kind) && (!q || nameOf(t).toLowerCase().includes(q) || (t.name.ar || '').includes(q)));
    const order = { base: 0, country: 1, event: 2 }, titles = { base: 'Base', country: 'Countries', event: 'Events' };
    let html = '', last = '';
    items.sort((a, b) => (order[a.kind] - order[b.kind]) || nameOf(a).localeCompare(nameOf(b))).forEach((t) => {
      if (t.kind !== last) { html += '<div class="grp">' + titles[t.kind] + '</div>'; last = t.kind; }
      const r = Q.resolveSingle(S.themes, t.id).tokens.light;
      html += '<button class="trow-l ' + (t.id === S.selectedId ? 'on' : '') + '" data-act="select" data-id="' + esc(t.id) + '">' +
        '<span class="sw2"><i class="p" style="background:' + r.primary + '"></i><i class="a" style="background:' + r.accent + '"></i></span>' +
        '<span class="tl-main"><b>' + esc(nameOf(t)) + '</b><small>' + esc(scopeText(t)) + '</small></span>' +
        '<span class="tl-end">' + (t.status === 'published' ? '' : statusChip(t)) + (live.has(t.id) && t.kind !== 'base' ? '<span class="chip live"><i class="dot"></i>Live now</span>' : '') + '</span></button>';
    });
    $('#themeList').innerHTML = html || '<div class="empty">No themes match.</div>';
  }

  /* ------------------------------------------------------------------ editor shell */
  function renderEditor() {
    const t = sel(), dirty = isDirty(t), res = resSel(), fails = Q.validate(res.tokens).filter((c) => !c.pass && c.blocking).length;
    let actions = '';
    if (t.status === 'draft') actions = '<button class="btn outline sm" data-act="save-draft"' + (dirty ? '' : ' disabled') + '>Save draft</button><button class="btn primary sm" data-act="publish">Publish…</button>';
    else if (t.status === 'published') actions = '<button class="btn ghost sm" data-act="revert"' + (dirty ? '' : ' disabled') + '>' + ic('undo', 15) + 'Revert</button><button class="btn primary sm" data-act="publish"' + (dirty ? '' : ' disabled') + '>Publish changes…</button>' + (t.kind !== 'base' ? '<button class="btn outline sm" data-act="unpublish">Unpublish</button>' : '');
    else actions = '<button class="btn primary sm" data-act="restore-draft">Restore as draft</button>';
    const tools = t.kind === 'base' ? '' : '<button class="icon-btn" data-act="duplicate" title="Duplicate" aria-label="Duplicate theme">' + ic('copy', 17) + '</button>' + (t.status !== 'archived' ? '<button class="icon-btn" data-act="archive" title="Archive" aria-label="Archive theme">' + ic('trash', 17) + '</button>' : '') + '<button class="icon-btn" data-act="export-one" title="Export JSON" aria-label="Export theme as JSON">' + ic('download', 17) + '</button>';
    const tabs = [['colors', 'Colours', ''], ['schedule', 'Schedule & scope', ''], ['copy', 'Copy', ''], ['a11y', 'Accessibility', fails ? '<span class="chip bad">' + fails + '</span>' : '<span class="chip live">✓</span>'], ['history', 'History', '<span class="chip">' + (S.versions[t.id] || []).length + '</span>']];
    $('#editor').innerHTML =
      '<div class="ed-head"><div class="ed-title"><div><h2 class="q-display">' + esc(nameOf(t)) + '</h2><div class="meta">' +
      '<span class="chip kind">' + t.kind + '</span>' + statusChip(t) + '<span class="chip">v' + (t.version || 1) + '</span>' +
      '<span id="dirtyChip">' + (dirty ? '<span class="chip draft">Unsaved changes</span>' : '') + '</span></div></div>' +
      '<div class="ed-actions">' + tools + actions + '</div></div>' +
      '<div class="tabs" role="tablist">' + tabs.map((x) => '<button class="tab ' + (S.tab === x[0] ? 'on' : '') + '" role="tab" aria-selected="' + (S.tab === x[0]) + '" data-act="tab" data-tab="' + x[0] + '">' + x[1] + x[2] + '</button>').join('') + '</div></div>' +
      '<div class="ed-body" id="edBody"></div>';
    renderBody();
  }
  function renderBody() {
    const f = { colors: renderColors, schedule: renderSchedule, copy: renderCopy, a11y: renderA11y, history: renderHistory }[S.tab];
    $('#edBody').innerHTML = f();
    if (S.tab === 'colors') updateColorRows();
  }

  /* ------------------------------------------------------------------ colours tab */
  function renderColors() {
    const t = sel(), mode = S.editMode;
    const byGroup = {};
    Q.TOKEN_NAMES.forEach((n) => { (byGroup[Q.TOKEN_META[n].group] = byGroup[Q.TOKEN_META[n].group] || []).push(n); });
    const note = t.kind === 'base'
      ? '<div class="note">' + ic('alert', 17) + '<div><b>You are editing the base theme.</b> Every country and event inherits from it. Changes are versioned and can be rolled back.</div></div>'
      : '<div class="note info">' + ic('sparkles', 17) + '<div>Only the colours you <b>pin</b> are stored. Everything else is inherited from the base theme or <b>auto-derived</b> (text-on-colour, hero gradient, dark-mode surfaces, accessible accent text, logo colours).</div></div>';
    return note + '<div class="mode-bar"><div class="seg" role="group" aria-label="Colour mode"><button data-act="edit-mode" data-m="light" class="' + (mode === 'light' ? 'on' : '') + '">' + ic('sun', 14) + ' Light</button><button data-act="edit-mode" data-m="dark" class="' + (mode === 'dark' ? 'on' : '') + '">' + ic('moon', 14) + ' Dark</button></div>' +
      '<span class="hint">Click a swatch or type a hex. ↺ returns a colour to inherited / auto.</span></div>' +
      GROUPS.map((g) => '<section class="tgroup"><h4>' + g + '</h4>' + (byGroup[g] || []).map((n) => {
        const m = Q.TOKEN_META[n];
        return '<div class="trow" data-token="' + n + '"><label class="swatch" title="Pick colour"><i></i><input type="color" data-act="tok-color" aria-label="' + esc(m.label) + ' colour"></label>' +
          '<div class="tinfo"><b>' + esc(m.label) + '</b><small>' + esc(m.hint) + '</small></div>' +
          '<input class="hex" type="text" data-act="tok-hex" maxlength="7" spellcheck="false" autocomplete="off" aria-label="' + esc(m.label) + ' hex value">' +
          '<span class="state"></span><button class="icon-btn" data-act="tok-reset" title="Reset to inherited / auto" aria-label="Reset ' + esc(m.label) + '">' + ic('undo', 15) + '</button><span class="ratio"></span></div>';
      }).join('') + '</section>').join('');
  }
  function updateColorRows() {
    const t = sel(), mode = S.editMode, res = resSel(), tok = res.tokens[mode], base = baseTheme().tokens[mode], explicit = t.tokens[mode] || {};
    const checks = Q.validate(res.tokens).filter((c) => c.mode === mode);
    $$('.trow[data-token]').forEach((row) => {
      const n = row.dataset.token, v = tok[n], pinned = t.kind === 'base' || Object.prototype.hasOwnProperty.call(explicit, n);
      const stateEl = $('.state', row), hexEl = $('.hex', row), colEl = $('input[type=color]', row);
      $('.swatch i', row).style.background = v;
      if (document.activeElement !== hexEl) hexEl.value = v;
      if (document.activeElement !== colEl) colEl.value = v.toLowerCase();
      const st = pinned ? 'pinned' : v.toUpperCase() !== (base[n] || '').toUpperCase() ? 'auto' : 'inherited';
      stateEl.className = 'state ' + st; stateEl.textContent = st === 'pinned' ? 'Pinned' : st === 'auto' ? 'Auto' : 'Inherited';
      $('[data-act=tok-reset]', row).disabled = !pinned || t.kind === 'base';
      const rel = checks.filter((c) => c.fg === n || c.bg === n);
      const ratioEl = $('.ratio', row);
      if (!rel.length) { ratioEl.className = 'ratio na'; ratioEl.textContent = '–'; ratioEl.title = ''; }
      else {
        const worst = rel.reduce((a, b) => (a.ratio < b.ratio ? a : b));
        ratioEl.className = 'ratio ' + (rel.every((c) => c.pass) ? '' : 'bad'); ratioEl.textContent = worst.ratio.toFixed(1);
        ratioEl.title = rel.map((c) => c.label + ': ' + c.ratio + ':1 (min ' + c.min + ')').join('\n');
      }
    });
  }
  function setToken(n, val) {
    const t = sel(); t.tokens = t.tokens || { light: {}, dark: {} };
    t.tokens[S.editMode] = t.tokens[S.editMode] || {};
    t.tokens[S.editMode][n] = val.toUpperCase(); touch(t); live();
  }

  /* ------------------------------------------------------------------ schedule & scope tab */
  function schedType(t) {
    const s = t.schedule || {}, r = s.recurrence;
    return r ? (r.type === 'hijri_yearly' ? 'hijri' : 'gregorian') : (s.startsAt || s.endsAt) ? 'window' : 'always';
  }
  const toLocalInput = (iso) => { if (!iso) return ''; const d = new Date(iso), p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  function scheduleSummary(t) {
    const type = schedType(t), s = t.schedule || {}, r = s.recurrence || {};
    let line = '';
    if (type === 'always') line = 'Always on whenever it is published and in scope.';
    else if (type === 'window') line = 'Active from <b>' + (s.startsAt ? fmtDateTime(s.startsAt) : 'now') + '</b> until <b>' + (s.endsAt ? fmtDateTime(s.endsAt) : 'further notice') + '</b>.';
    else if (type === 'gregorian') line = 'Every year from <b>' + r.day + ' ' + MONTHS[r.month - 1] + '</b> for <b>' + (r.spanDays || 1) + ' day' + ((r.spanDays || 1) > 1 ? 's' : '') + '</b> (Gregorian).';
    else line = 'Every year from <b>' + r.day + ' ' + HIJRI[r.month - 1] + '</b> (Hijri, Umm al-Qura) for <b>' + (r.spanDays || 1) + ' day' + ((r.spanDays || 1) > 1 ? 's' : '') + '</b>.';
    let nxt = '';
    if (type === 'gregorian' || type === 'hijri') {
      const cs = (t.scope.countries || ['*']).filter((c) => c !== '*' && Q.COUNTRIES[c]);
      const country = cs[0] || S.preview.country, w = findWindow(t, country, todayISO());
      const tz = (Q.COUNTRIES[country] || {}).tz;
      nxt = w ? '<span>Next in <b>' + country + '</b>: <b>' + fmtDate(w.start, 'UTC') + '</b> → <b>' + fmtDate(w.end, 'UTC') + '</b></span>' : '<span>No occurrence in the next 2 years.</span>';
      void tz;
    }
    return '<div class="summary"><span>' + line + '</span>' + nxt + '</div>';
  }
  function renderSchedule() {
    const t = sel(), type = schedType(t), s = t.schedule || {}, r = s.recurrence || {}, isBase = t.kind === 'base';
    const sc = t.scope || (t.scope = { countries: ['*'], plans: ['*'], tenants: ['*'] });
    const chip = (act, val, label, on) => '<button type="button" class="pick ' + (on ? 'on' : '') + '" data-act="' + act + '" data-v="' + esc(val) + '" aria-pressed="' + on + '"' + (isBase ? ' disabled' : '') + '>' + esc(label) + '</button>';
    const cOn = (c) => (sc.countries || []).includes(c), allC = (sc.countries || ['*']).includes('*');
    const monthOpts = (list, v) => list.map((m, i) => '<option value="' + (i + 1) + '"' + (v === i + 1 ? ' selected' : '') + '>' + m + '</option>').join('');
    const radio = (v, l, sub) => '<label><input type="radio" name="stype" value="' + v + '" data-act="stype"' + (type === v ? ' checked' : '') + (isBase ? ' disabled' : '') + '>' + l + '<small>' + sub + '</small></label>';
    return '<div class="form">' +
      (isBase ? '<div class="note info">' + ic('lock', 17) + '<div>The base theme is always on and applies everywhere. Scope and schedule are locked.</div></div>' : '') +
      '<div class="row2"><div class="field"><label for="nameEn">Name (English)</label><input type="text" id="nameEn" data-act="name-en" value="' + esc(t.name.en || '') + '"></div>' +
      '<div class="field"><label for="nameAr">Name (Arabic)</label><input type="text" id="nameAr" dir="rtl" lang="ar" data-act="name-ar" value="' + esc(t.name.ar || '') + '"></div></div>' +
      (isBase ? '' : '<div class="row3"><div class="field"><label for="kind">Layer</label><select id="kind" data-act="kind"><option value="country"' + (t.kind === 'country' ? ' selected' : '') + '>Country</option><option value="event"' + (t.kind === 'event' ? ' selected' : '') + '>Event</option></select></div>' +
      '<div class="field"><label for="prio">Priority <span class="hint">(higher wins)</span></label><input type="number" id="prio" min="0" max="1000" data-act="prio" value="' + (t.priority || 0) + '"></div>' +
      '<div class="field"><label>Layer order</label><span class="hint" style="padding-top:9px">Base → Country → Event → Merchant accent</span></div></div>') +
      '<div class="card"><div class="field"><span class="lbl">Countries</span><div class="chips">' + chip('scope-c', '*', 'All countries', allC) + Object.keys(Q.COUNTRIES).map((c) => chip('scope-c', c, c + ' · ' + Q.COUNTRIES[c].name, !allC && cOn(c))).join('') + '</div></div>' +
      '<div class="field"><span class="lbl">Plans</span><div class="chips">' + chip('scope-p', '*', 'All plans', (sc.plans || ['*']).includes('*')) + PLANS.map((p) => chip('scope-p', p[0], p[1], !(sc.plans || ['*']).includes('*') && sc.plans.includes(p[0]))).join('') + '</div></div>' +
      '<div class="field"><label for="tenants">Specific merchants <span class="hint">(optional · comma-separated tenant IDs · empty = all)</span></label><input type="text" id="tenants" data-act="tenants" placeholder="All merchants" value="' + esc((sc.tenants || ['*']).includes('*') ? '' : sc.tenants.join(', ')) + '"' + (isBase ? ' disabled' : '') + '></div></div>' +
      '<div class="field"><span class="lbl">Schedule</span><div class="radio-row">' + radio('always', 'Always on', 'No end date') + radio('window', 'Date window', 'One-off campaign') + radio('gregorian', 'Every year', 'Fixed calendar date') + radio('hijri', 'Every year (Hijri)', 'Ramadan, Eid…') + '</div></div>' +
      (type === 'window' ? '<div class="row2"><div class="field"><label for="starts">Starts</label><input type="datetime-local" id="starts" data-act="starts" value="' + toLocalInput(s.startsAt) + '"></div><div class="field"><label for="ends">Ends</label><input type="datetime-local" id="ends" data-act="ends" value="' + toLocalInput(s.endsAt) + '"></div></div>' : '') +
      (type === 'gregorian' ? '<div class="row3"><div class="field"><label for="rm">Month</label><select id="rm" data-act="rec-month">' + monthOpts(MONTHS, r.month) + '</select></div><div class="field"><label for="rd">Day</label><input type="number" id="rd" min="1" max="31" data-act="rec-day" value="' + r.day + '"></div><div class="field"><label for="rs">Lasts (days)</label><input type="number" id="rs" min="1" max="60" data-act="rec-span" value="' + (r.spanDays || 1) + '"></div></div>' : '') +
      (type === 'hijri' ? '<div class="row3"><div class="field"><label for="rm">Hijri month</label><select id="rm" data-act="rec-month">' + monthOpts(HIJRI, r.month) + '</select></div><div class="field"><label for="rd">Day</label><input type="number" id="rd" min="1" max="30" data-act="rec-day" value="' + r.day + '"></div><div class="field"><label for="rs">Lasts (days)</label><input type="number" id="rs" min="1" max="60" data-act="rec-span" value="' + (r.spanDays || 1) + '"></div></div><p class="hint" style="margin:0">Uses the Umm al-Qura calendar. Countries that sight the moon later get a per-country offset in the preview panel.</p>' : '') +
      scheduleSummary(t) +
      '<div class="card"><label class="switch"><span><b>Let merchants keep their own accent colour</b><br><span class="hint">Off = locked campaign: the merchant\'s accent is ignored while this theme is live (National Day, brand campaigns).</span></span><input type="checkbox" data-act="allow-accent"' + (t.allowTenantAccent !== false ? ' checked' : '') + '></label>' +
      '<div class="field"><span class="lbl">Decorative motifs</span><div class="chips">' + MOTIFS.map((m) => chip('motif', m[0], m[1], (t.motifs || []).includes(m[0]))).join('') + '</div></div></div>' +
      '</div>';
  }

  /* ------------------------------------------------------------------ copy tab */
  function renderCopy() {
    const t = sel(); t.copy = t.copy || {};
    const keys = [['greeting', 'Home greeting', 'Replaces “Good morning” on the dashboard header while this theme is live.'], ['tagline', 'Tagline', 'Shown under the logo on the splash screen.']];
    return '<div class="form"><div class="note info">' + ic('globe', 17) + '<div>Event greetings are translated for all five launch languages. Leave a field empty to inherit; English is the final fallback.</div></div>' +
      keys.map((k) => '<div class="card"><div><b>' + k[1] + '</b><br><span class="hint">' + k[2] + '</span></div><div class="copy-grid">' +
        LANGS.map((l) => '<div class="copy-row"><span class="lang">' + l[1] + '</span><input type="text" data-act="copy" data-key="' + k[0] + '" data-lang="' + l[0] + '"' + (l[0] === 'ar' || l[0] === 'ur' ? ' dir="rtl"' : '') + ' lang="' + l[0] + '" value="' + esc((t.copy[k[0]] || {})[l[0]] || '') + '" aria-label="' + k[1] + ' (' + l[1] + ')" placeholder="' + (t.kind === 'base' ? '' : 'Inherit') + '"><span class="hint">' + esc(inheritedCopy(t, k[0], l[0])) + '</span></div>').join('') +
        '</div></div>').join('') + '</div>';
  }
  function inheritedCopy(t, key, lang) { const b = baseTheme(); return t.kind === 'base' ? '' : (b.copy[key] && b.copy[key][lang]) ? 'Base: ' + b.copy[key][lang] : ''; }

  /* ------------------------------------------------------------------ accessibility tab */
  function renderA11y() {
    const res = resSel(), checks = Q.validate(res.tokens), fails = checks.filter((c) => !c.pass), blockers = fails.filter((c) => c.blocking);
    const rows = (mode) => checks.filter((c) => c.mode === mode).map((c) => {
      const T = res.tokens[mode];
      return '<tr><td><span class="pair"><i style="background:' + T[c.bg] + ';color:' + T[c.fg] + '">Aa</i>' + esc(c.label) + '</span></td><td>' + c.min + ':1</td><td class="tnum"><b>' + c.ratio.toFixed(2) + '</b></td><td>' + (c.pass ? '<span class="chip live">Pass</span>' : '<span class="chip bad">' + (c.blocking ? 'Fails — blocks publish' : 'Advisory') + '</span>') + '</td></tr>';
    }).join('');
    return '<div class="form" style="max-width:none">' +
      (blockers.length ? '<div class="note bad">' + ic('alert', 17) + '<div><b>' + blockers.length + ' pair' + (blockers.length > 1 ? 's' : '') + ' fail WCAG AA.</b> This theme cannot be published until they are fixed. <button class="btn gold sm" style="margin-inline-start:10px" data-act="autofix">Auto-fix</button></div></div>'
        : '<div class="note info">' + ic('shield', 17) + '<div><b>All ' + checks.length + ' checks pass.</b> Even so, clients re-verify every resolved palette and silently repair any pair that slips through.</div></div>') +
      ['light', 'dark'].map((m) => '<div class="card"><b style="text-transform:capitalize">' + m + ' mode</b><table class="check-table"><thead><tr><th>Pair</th><th>Minimum</th><th>Ratio</th><th>Result</th></tr></thead><tbody>' + rows(m) + '</tbody></table></div>').join('') + '</div>';
  }

  /* ------------------------------------------------------------------ history tab */
  function renderHistory() {
    const t = sel(), list = (S.versions[t.id] || []).slice().reverse(), cur = (lastCommit(t) || {}).version;
    return '<div class="form" style="max-width:none"><div class="hist">' + list.map((v) => {
      const snap = v.snapshot, n = ['light', 'dark'].reduce((a, m) => a + Object.keys((snap.tokens && snap.tokens[m]) || {}).length, 0);
      return '<div class="hist-row"><span class="v">v' + v.version + '</span><div><b>' + esc(v.note || 'Saved') + '</b><br><span class="hint">' + fmtDateTime(v.at) + ' · ' + snap.status + ' · ' + n + ' pinned colour' + (n === 1 ? '' : 's') + '</span></div>' +
        (v.version === cur ? '<span class="chip live">Current</span>' : '<button class="btn outline sm" data-act="restore-v" data-v="' + v.version + '">Restore</button>') + '</div>';
    }).join('') + '</div></div>';
  }

  /* ------------------------------------------------------------------ preview column */
  function renderPreviewCol() {
    const p = S.preview, evs = S.themes.filter((t) => t.kind === 'event' && t.status !== 'archived');
    const off = (S.countrySettings[p.country] || {}).hijriOffsetDays || 0;
    $('#previewCol').innerHTML =
      '<div class="pv-controls"><div class="seg" role="group" aria-label="Preview source" style="justify-self:start"><button data-act="pv-source" data-v="theme" class="' + (p.source === 'theme' ? 'on' : '') + '">Selected theme</button><button data-act="pv-source" data-v="live" class="' + (p.source === 'live' ? 'on' : '') + '">Live resolver</button></div>' +
      '<div class="row"><div><span class="lbl">Country</span><select data-act="pv-country" aria-label="Preview country">' + Object.keys(Q.COUNTRIES).map((c) => '<option value="' + c + '"' + (p.country === c ? ' selected' : '') + '>' + c + ' · ' + Q.COUNTRIES[c].name + '</option>').join('') + '</select></div>' +
      '<div><span class="lbl">Language</span><select data-act="pv-lang" aria-label="Preview language">' + LANGS.map((l) => '<option value="' + l[0] + '"' + (p.lang === l[0] ? ' selected' : '') + '>' + l[1] + '</option>').join('') + '</select></div></div>' +
      '<div class="row"><div><span class="lbl">Date <span style="text-transform:none;letter-spacing:0">(live resolver)</span></span><input type="date" data-act="pv-date" value="' + p.date + '" aria-label="Preview date"></div>' +
      '<div><span class="lbl">Jump to event</span><select data-act="pv-jump" aria-label="Jump to event"><option value="">Choose…</option>' + evs.map((e) => '<option value="' + esc(e.id) + '">' + esc(nameOf(e)) + '</option>').join('') + '</select></div></div>' +
      '<div class="row" style="align-items:end"><div><span class="lbl">Mode</span><div class="seg"><button data-act="pv-mode" data-v="light" class="' + (p.mode === 'light' ? 'on' : '') + '">Light</button><button data-act="pv-mode" data-v="dark" class="' + (p.mode === 'dark' ? 'on' : '') + '">Dark</button></div></div>' +
      '<div><span class="lbl">Numerals</span><div class="seg"><button data-act="pv-num" data-v="latn" class="' + (p.numerals === 'latn' ? 'on' : '') + '">123</button><button data-act="pv-num" data-v="arab" class="' + (p.numerals === 'arab' ? 'on' : '') + '">١٢٣</button></div></div></div>' +
      '<div class="row" style="align-items:center"><label class="switch" style="gap:10px"><span style="font-size:12.5px;font-weight:600">Merchant accent</span><input type="checkbox" data-act="pv-merchant"' + (p.merchant ? ' checked' : '') + '></label>' +
      '<div class="switch"><span style="font-size:12.5px;font-weight:600">Moon sighting <span class="hint">(' + p.country + ')</span></span><select data-act="pv-offset" style="width:72px;height:34px" aria-label="Hijri offset days"><option value="-1"' + (off === -1 ? ' selected' : '') + '>−1 d</option><option value="0"' + (off === 0 ? ' selected' : '') + '>0</option><option value="1"' + (off === 1 ? ' selected' : '') + '>+1 d</option></select></div></div>' +
      (p.merchant ? '<div class="row"><div class="field"><span class="lbl">Merchant\'s accent colour</span><input type="color" data-act="pv-merchant-color" value="' + p.merchantColor + '" style="width:100%;height:36px;border-radius:12px;border:1px solid var(--q-line);padding:2px;background:var(--q-surface)" aria-label="Merchant accent colour"></div><div></div></div>' : '') +
      '</div><div class="pv-stage" id="stage"></div><div class="stack" id="stack"></div>';
    renderPhone();
  }
  function renderPhone() {
    const p = S.preview, r = previewResolution(), stage = $('#stage');
    if (!stage) return;
    PV.render(stage, { tokens: r.tokens, mode: p.mode, lang: p.lang, country: p.country, copy: r.copy, motifs: r.motifs, numerals: p.numerals });
    const tz = (Q.COUNTRIES[p.country] || {}).tz;
    const kinds = { base: 'Base', country: 'Country', event: 'Event' };
    const lockedBy = r.applied.filter((a) => (S.themes.find((t) => t.id === a.id) || {}).allowTenantAccent === false).map((a) => a.name.en);
    $('#stack').innerHTML = '<h5>Resolved stack · ' + p.country + (p.source === 'live' ? ' · ' + fmtDate(nowFor(p.date), 'UTC') : '') + '</h5><div class="layers">' +
      r.applied.map((a, i) => '<div class="layer ' + (i === r.applied.length - 1 ? 'top' : '') + '"><span class="n">' + (i + 1) + '</span><span>' + esc((a.name && (a.name.en || a.name.ar)) || a.id) + '</span><span class="chip kind">' + kinds[a.kind] + '</span></div>').join('') + '</div>' +
      (p.merchant ? '<div class="mini-note">' + ic(r.tenantAccentApplied ? 'check' : 'lock', 15) + '<span>' + (r.tenantAccentApplied ? '<b>Merchant accent applied.</b> Their gold follows their own brand.' : '<b>Merchant accent locked</b> by “' + esc(lockedBy.join(', ')) + '” while this campaign is live.') + '</span></div>' : '') +
      (p.source === 'live' ? '<div class="mini-note">' + ic('clock', 15) + '<span>Clients re-check at <b>' + fmtDateTime(r.validUntil, tz) + '</b> (' + esc(tz || 'UTC') + ') or instantly when an admin publishes.</span></div>' : '<div class="mini-note">' + ic('eye', 15) + '<span>Showing <b>this theme over the base</b>, ignoring scope and schedule, so you can design it any day of the year.</span></div>') +
      (r.repaired.length ? '<div class="mini-note">' + ic('shield', 15) + '<span><b>Safeguard repaired ' + r.repaired.length + ' colour' + (r.repaired.length > 1 ? 's' : '') + '</b> at runtime so text stays readable.</span></div>' : '');
  }

  /* ------------------------------------------------------------------ refresh helpers */
  function live() { // cheap refresh during typing / colour dragging
    const t = sel(), dirty = isDirty(t);
    const dc = $('#dirtyChip'); if (dc) dc.innerHTML = dirty ? '<span class="chip draft">Unsaved changes</span>' : '';
    $$('[data-act=save-draft],[data-act=revert]').forEach((b) => { b.disabled = !dirty; });
    $$('[data-act=publish]').forEach((b) => { if (t.status === 'published') b.disabled = !dirty; });
    const fails = Q.validate(resSel().tokens).filter((c) => !c.pass && c.blocking).length, tb = $('.tab[data-tab=a11y]');
    if (tb) tb.innerHTML = 'Accessibility' + (fails ? '<span class="chip bad">' + fails + '</span>' : '<span class="chip live">✓</span>');
    if (S.tab === 'colors') updateColorRows();
    renderPhone();
    const row = $('.trow-l.on'); if (row) { const r = resSel().tokens.light, sw = $('.sw2', row); sw.querySelector('.p').style.background = r.primary; sw.querySelector('.a').style.background = r.accent; }
  }
  function renderAll() { renderList(); renderEditor(); renderPreviewCol(); save(); }

  /* ------------------------------------------------------------------ actions */
  function publishFlow() {
    const t = sel(), res = resSel(), checks = Q.validate(res.tokens), blockers = checks.filter((c) => !c.pass && c.blocking);
    if (blockers.length) {
      modal({ title: 'Fix contrast before publishing', wide: true,
        body: '<p>These pairs fail WCAG AA. Auto-fix nudges only the failing foreground colours (and pins them in this theme).</p><div class="failist">' + blockers.map((c) => '<div><span>' + c.mode + ' · ' + esc(c.label) + '</span><b>' + c.ratio.toFixed(2) + ' / ' + c.min + '</b></div>').join('') + '</div>',
        actions: [{ label: 'Cancel', cls: 'ghost' }, { label: 'Auto-fix and continue', cls: 'gold', run: () => { applyAutoFix(); closeModal(); publishFlow(); } }] });
      return;
    }
    const sc = t.scope.countries.includes('*') ? 'every country' : t.scope.countries.join(', ');
    modal({ title: t.status === 'published' ? 'Publish changes?' : 'Publish this theme?',
      body: '<div class="summary"><span><b>' + esc(nameOf(t)) + '</b> · ' + t.kind + '</span><span>Scope: <b>' + esc(sc) + '</b></span><span>Schedule: <b>' + esc(scheduleShort(t)) + '</b></span><span>Merchant accent: <b>' + (t.allowTenantAccent === false ? 'locked while live' : 'allowed') + '</b></span></div>' +
        '<p>' + (t.kind === 'base' ? '<b>This is the base theme: it changes the look of the whole product.</b> ' : '') + 'Clients pick it up within a minute through Realtime, or at their next scheduled re-check. You can roll back from History at any time.</p>',
      actions: [{ label: 'Cancel', cls: 'ghost' }, { label: 'Publish now', cls: 'primary', run: () => { t.status = 'published'; commit(t, 'Published'); closeModal(); renderAll(); toast('Published v' + t.version); } }] });
  }
  function applyAutoFix() {
    const t = sel(), res = resSel(), fixed = Q.autoFix(res.tokens);
    t.tokens = t.tokens || { light: {}, dark: {} };
    fixed.changed.forEach((c) => { t.tokens[c.mode] = t.tokens[c.mode] || {}; t.tokens[c.mode][c.token] = c.to; });
    touch(t); renderAll(); toast('Fixed ' + fixed.changed.length + ' colour' + (fixed.changed.length === 1 ? '' : 's'), 'shield');
  }
  function slug(s) { return (s || 'theme').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'theme'; }
  function createTheme(name, kind, from) {
    const base = from ? clone(from) : { kind, name: { en: name, ar: '' }, status: 'draft', priority: 10, scope: { countries: kind === 'country' ? [S.preview.country] : ['*'], plans: ['*'], tenants: ['*'] }, schedule: { always: true }, allowTenantAccent: true, motifs: [], copy: {}, tokens: { light: {}, dark: {} } };
    const t = Object.assign(base, { id: slug(name) + '-' + Math.random().toString(36).slice(2, 6), status: 'draft', publishedAt: null, version: 0 });
    if (from) t.name = { en: name, ar: from.name.ar || '' };
    S.themes.push(t); commit(t, from ? 'Duplicated' : 'Created'); S.selectedId = t.id; S.tab = 'colors';
    renderAll(); toast('Created “' + name + '”');
  }
  function validTheme(t) {
    const ok = (o) => o && Object.values(o).every((v) => HEX.test(v));
    return t && typeof t.id === 'string' && ['base', 'country', 'event'].includes(t.kind) && t.name && t.tokens && ok(t.tokens.light) && ok(t.tokens.dark || {}) && t.scope && t.schedule;
  }

  /* ------------------------------------------------------------------ events */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act],[data-modal-act],[data-kind]');
    if (!el) return;
    if (el.dataset.modalAct != null) { const a = $('#modalRoot')._actions[+el.dataset.modalAct]; if (a && a.run) a.run(); else closeModal(); return; }
    if (el.dataset.kind && el.parentElement.id === 'kindFilter') { S.kind = el.dataset.kind; $$('#kindFilter button').forEach((b) => b.classList.toggle('on', b === el)); renderList(); return; }
    const act = el.dataset.act, t = sel();
    switch (act) {
      case 'modal-close': if (e.target === el) closeModal(); break;
      case 'select': S.selectedId = el.dataset.id; renderAll(); break;
      case 'tab': S.tab = el.dataset.tab; renderEditor(); break;
      case 'edit-mode': S.editMode = el.dataset.m; renderBody(); break;
      case 'tok-reset': { const n = el.closest('.trow').dataset.token; if (t.tokens[S.editMode]) delete t.tokens[S.editMode][n]; touch(t); live(); break; }
      case 'scope-c': { const v = el.dataset.v, cs = t.scope.countries.slice(); let next;
        if (v === '*') next = ['*']; else { const i = cs.indexOf(v), base = cs.filter((c) => c !== '*'); next = i > -1 ? base.filter((c) => c !== v) : base.concat(v); if (!next.length) next = ['*']; }
        t.scope.countries = next; touch(t); renderAll(); break; }
      case 'scope-p': { const v = el.dataset.v, ps = (t.scope.plans || ['*']).slice(); let next;
        if (v === '*') next = ['*']; else { const base = ps.filter((c) => c !== '*'), i = base.indexOf(v); next = i > -1 ? base.filter((c) => c !== v) : base.concat(v); if (!next.length) next = ['*']; }
        t.scope.plans = next; touch(t); renderAll(); break; }
      case 'motif': { const v = el.dataset.v, m = (t.motifs || []).slice(), i = m.indexOf(v); if (i > -1) m.splice(i, 1); else m.push(v); t.motifs = m; touch(t); renderAll(); break; }
      case 'new': modal({ title: 'New theme', body: '<div class="field"><label for="ntName">Name</label><input type="text" id="ntName" placeholder="e.g. Founding Day" autocomplete="off"></div><div class="field"><label for="ntKind">Layer</label><select id="ntKind"><option value="event">Event (seasonal / campaign)</option><option value="country">Country</option></select></div>',
        actions: [{ label: 'Cancel', cls: 'ghost' }, { label: 'Create draft', cls: 'primary', run: () => { const n = $('#ntName').value.trim(), k = $('#ntKind').value; if (!n) { $('#ntName').focus(); return; } closeModal(); createTheme(n, k); } }] }); break;
      case 'duplicate': modal({ title: 'Duplicate theme', body: '<div class="field"><label for="dupName">Name</label><input type="text" id="dupName" value="' + esc(nameOf(t) + ' copy') + '"></div>',
        actions: [{ label: 'Cancel', cls: 'ghost' }, { label: 'Duplicate', cls: 'primary', run: () => { const n = $('#dupName').value.trim() || nameOf(t) + ' copy'; closeModal(); createTheme(n, t.kind, t); } }] }); break;
      case 'archive': t.status = 'archived'; commit(t, 'Archived'); renderAll(); toast('Archived. Clients stop using it immediately.', 'trash'); break;
      case 'restore-draft': t.status = 'draft'; commit(t, 'Restored as draft'); renderAll(); break;
      case 'unpublish': t.status = 'draft'; commit(t, 'Unpublished'); renderAll(); toast('Unpublished. Back to draft.'); break;
      case 'save-draft': commit(t, 'Draft saved'); renderAll(); toast('Draft saved'); break;
      case 'revert': { const c = lastCommit(t); Object.keys(t).forEach((k) => delete t[k]); Object.assign(t, clone(c.snapshot)); save(); renderAll(); toast('Changes reverted', 'undo'); break; }
      case 'publish': publishFlow(); break;
      case 'autofix': applyAutoFix(); break;
      case 'restore-v': { const v = (S.versions[t.id] || []).find((x) => x.version === +el.dataset.v); if (v) { const keep = t.version; Object.keys(t).forEach((k) => delete t[k]); Object.assign(t, clone(v.snapshot), { version: keep }); save(); S.tab = 'colors'; renderAll(); toast('Restored v' + v.version + ' into the editor. Publish to make it live.', 'history'); } break; }
      case 'export-one': download('qistas-theme-' + t.id + '.json', { $schema: 'qistas-themes/1', themes: [t] }); break;
      case 'export-all': download('qistas-themes.json', { $schema: 'qistas-themes/1', exportedAt: new Date().toISOString(), themes: S.themes }); break;
      case 'import': $('#importFile').click(); break;
      case 'reset-all': modal({ title: 'Reset to the seed themes?', body: '<p>This discards every change, new theme and version history stored in this browser.</p>', actions: [{ label: 'Cancel', cls: 'ghost' }, { label: 'Reset', cls: 'danger', run: () => { S = fresh(); closeModal(); renderAll(); toast('Reset to seed', 'refresh'); } }] }); break;
      case 'pv-source': S.preview.source = el.dataset.v; renderPreviewCol(); renderList(); save(); break;
      case 'pv-mode': S.preview.mode = el.dataset.v; renderPreviewCol(); save(); break;
      case 'pv-num': S.preview.numerals = el.dataset.v; renderPreviewCol(); save(); break;
    }
  });
  document.addEventListener('input', (e) => {
    const el = e.target, act = el.dataset && el.dataset.act, t = sel();
    if (act === 'tok-color') setToken(el.closest('.trow').dataset.token, el.value);
    else if (act === 'tok-hex') { const v = el.value.startsWith('#') ? el.value : '#' + el.value; if (HEX.test(v)) setToken(el.closest('.trow').dataset.token, v); }
    else if (act === 'pv-merchant-color') { S.preview.merchantColor = el.value; save(); renderPhone(); }
    else if (act === 'copy') { const k = el.dataset.key, l = el.dataset.lang; t.copy = t.copy || {}; t.copy[k] = t.copy[k] || {}; if (el.value.trim()) t.copy[k][l] = el.value; else delete t.copy[k][l]; if (!Object.keys(t.copy[k]).length) delete t.copy[k]; touch(t); live(); }
    else if (act === 'name-en' || act === 'name-ar') { t.name[act === 'name-en' ? 'en' : 'ar'] = el.value; touch(t); const h = $('.ed-title h2'); if (h) h.textContent = nameOf(t); renderList(); live(); }
    else if (el.id === 'q') { S.q = el.value; renderList(); }
  });
  document.addEventListener('change', (e) => {
    const el = e.target, act = el.dataset && el.dataset.act, t = sel();
    switch (act) {
      case 'tok-hex': { const v = el.value.startsWith('#') ? el.value : '#' + el.value; if (!HEX.test(v)) updateColorRows(); else el.value = v.toUpperCase(); break; }
      case 'kind': t.kind = el.value; touch(t); renderAll(); break;
      case 'prio': t.priority = Math.max(0, Math.min(1000, +el.value || 0)); touch(t); live(); break;
      case 'tenants': { const v = el.value.split(',').map((x) => x.trim()).filter(Boolean); t.scope.tenants = v.length ? v : ['*']; touch(t); live(); break; }
      case 'allow-accent': t.allowTenantAccent = el.checked; touch(t); renderAll(); break;
      case 'stype': {
        const v = el.value, y = new Date().getFullYear(), now = new Date();
        t.schedule = v === 'always' ? { always: true } : v === 'window' ? { startsAt: now.toISOString(), endsAt: new Date(+now + 7 * 864e5).toISOString() }
          : v === 'gregorian' ? { recurrence: { type: 'gregorian_yearly', month: 1, day: 1, spanDays: 1 } } : { recurrence: { type: 'hijri_yearly', month: 9, day: 1, spanDays: 1 } };
        void y; touch(t); renderAll(); break; }
      case 'starts': case 'ends': { const k = act === 'starts' ? 'startsAt' : 'endsAt'; t.schedule[k] = el.value ? new Date(el.value).toISOString() : undefined; if (!el.value) delete t.schedule[k]; touch(t); renderAll(); break; }
      case 'rec-month': t.schedule.recurrence.month = +el.value; touch(t); renderAll(); break;
      case 'rec-day': t.schedule.recurrence.day = Math.max(1, Math.min(31, +el.value || 1)); touch(t); renderAll(); break;
      case 'rec-span': t.schedule.recurrence.spanDays = Math.max(1, Math.min(60, +el.value || 1)); touch(t); renderAll(); break;
      case 'pv-country': S.preview.country = el.value; renderPreviewCol(); renderList(); save(); break;
      case 'pv-lang': S.preview.lang = el.value; renderPhone(); save(); break;
      case 'pv-date': if (el.value) { S.preview.date = el.value; renderPhone(); renderList(); save(); } break;
      case 'pv-merchant': S.preview.merchant = el.checked; renderPreviewCol(); save(); break;
      case 'pv-offset': S.countrySettings[S.preview.country] = { hijriOffsetDays: +el.value }; renderPhone(); renderList(); save(); break;
      case 'pv-jump': {
        const ev = S.themes.find((x) => x.id === el.value); if (!ev) break;
        const cs = (ev.scope.countries || ['*']).filter((c) => c !== '*' && Q.COUNTRIES[c]);
        if (cs.length && !cs.includes(S.preview.country)) S.preview.country = cs[0];
        const w = findWindow(ev, S.preview.country, todayISO());
        if (w) { S.preview.date = w.start.toISOString().slice(0, 10); S.preview.source = 'live'; toast('Jumped to ' + nameOf(ev) + ' · ' + fmtDate(w.start, 'UTC'), 'calendar'); if (ev.status !== 'published') toast('Draft themes only show in “Selected theme” mode', 'alert'); }
        else toast('No occurrence in the next 2 years', 'alert');
        renderPreviewCol(); renderList(); save(); break; }
    }
    if (el.id === 'importFile' && el.files[0]) {
      el.files[0].text().then((txt) => {
        try {
          const data = JSON.parse(txt), list = Array.isArray(data) ? data : data.themes || [data];
          const bad = list.filter((x) => !validTheme(x));
          if (bad.length) throw new Error('Invalid theme: ' + (bad[0].id || 'unknown') + '. Colours must be #RRGGBB.');
          list.forEach((x) => { const i = S.themes.findIndex((t2) => t2.id === x.id); const copy = Object.assign({ version: 0 }, clone(x)); if (i > -1) { copy.version = S.themes[i].version; S.themes[i] = copy; } else S.themes.push(copy); commit(copy, 'Imported'); });
          renderAll(); toast('Imported ' + list.length + ' theme' + (list.length > 1 ? 's' : ''), 'upload');
        } catch (err) { toast(err.message || 'Could not import this file', 'alert'); }
        el.value = '';
      });
    }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('#modalRoot').firstChild) closeModal(); });
  window.addEventListener('storage', () => { /* another tab edited the studio: keep this tab's working copy */ });

  /* ------------------------------------------------------------------ boot */
  function boot() {
    $('#railLogo').innerHTML = LG.svg('symbolSmall', { title: 'Qistas', className: 'q-logo' });
    const map = { 'r-accounts': 'accounts', 'r-plans': 'layers', 'r-theme': 'palette', 'r-pay': 'card', 'r-support': 'life', 'r-system': 'server' };
    Object.keys(map).forEach((id) => { $('#' + id).innerHTML = ic(map[id], 21); });
    ['crumbSep', 'crumbSep2'].forEach((id) => { $('#' + id).innerHTML = ic('chevronRight', 14); });
    $$('[data-ic]').forEach((el) => { el.innerHTML = ic(el.dataset.ic, 16); el.style.display = 'inline-flex'; });
    const qs = new URLSearchParams(location.search);
    if (qs.get('theme') && S.themes.find((t) => t.id === qs.get('theme'))) S.selectedId = qs.get('theme');
    if (qs.get('tab')) S.tab = qs.get('tab');
    if (qs.get('edit')) S.editMode = qs.get('edit');
    ['source', 'country', 'date', 'lang', 'mode', 'numerals'].forEach((k) => { if (qs.get(k)) S.preview[k] = qs.get(k); });
    if (qs.get('merchant')) { S.preview.merchant = true; S.preview.merchantColor = '#' + qs.get('merchant').replace('#', ''); }
    $('#q').value = S.q;
    $$('#kindFilter button').forEach((b) => b.classList.toggle('on', b.dataset.kind === S.kind));
    renderAll();
  }
  boot();
  window.__studio = { get state() { return S; }, resolve: previewResolution };
})();
