/*!
 * Qistas Theme Runtime  v1.0
 *
 * One implementation of the theme engine, used by the Theme Studio (admin preview) and the
 * brand intro, and mirrored 1:1 by the Laravel resolver and the Flutter client. It is
 * dependency-free and runs in browsers and Node (UMD).
 *
 * Layering (lowest to highest):  base  <  country  <  event  <  tenant accent
 *   - A theme only stores the tokens it CHANGES. Dependent tokens (text-on-colour, hero gradient,
 *     dark-mode surfaces, accessible gold text, logo colours...) are derived automatically unless
 *     the theme pins them.
 *   - The tenant's own accent is applied last, unless an active theme sets allowTenantAccent=false
 *     (a locked campaign).
 *   - A safeguard re-checks WCAG contrast on the resolved result, so a bad palette that slips past
 *     the admin console can never render unreadable text.
 *
 * Schedules: always | startsAt/endsAt window | recurrence { gregorian_yearly | hijri_yearly }.
 * Hijri dates use the Umm al-Qura calendar with an optional per-country offsetDays
 * (+1 = the local moon-sighting starts one day later).
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.QistasTheme = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /* ------------------------------------------------------------------ colour maths */
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

  function hexToRgb(hex) {
    let h = String(hex).replace('#', '');
    if (h.length === 3) h = h.split('').map((c) => c + c).join('');
    const n = parseInt(h, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map((v) => clamp(Math.round(v), 0, 255).toString(16).padStart(2, '0')).join('').toUpperCase();
  }
  function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    let h = 0, s = 0;
    const l = (max + min) / 2;
    if (max !== min) {
      const d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h *= 60;
    }
    return [h, s, l];
  }
  function hslToRgb(h, s, l) {
    h = ((h % 360) + 360) % 360;
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    let r = 0, g = 0, b = 0;
    if (h < 60) [r, g, b] = [c, x, 0];
    else if (h < 120) [r, g, b] = [x, c, 0];
    else if (h < 180) [r, g, b] = [0, c, x];
    else if (h < 240) [r, g, b] = [0, x, c];
    else if (h < 300) [r, g, b] = [x, 0, c];
    else [r, g, b] = [c, 0, x];
    return [(r + m) * 255, (g + m) * 255, (b + m) * 255];
  }
  const hexToHsl = (hex) => rgbToHsl(...hexToRgb(hex));
  const hslToHex = (h, s, l) => rgbToHex(...hslToRgb(h, clamp(s, 0, 1), clamp(l, 0, 1)));

  function mix(a, b, t) {
    const A = hexToRgb(a), B = hexToRgb(b);
    return rgbToHex(A[0] + (B[0] - A[0]) * t, A[1] + (B[1] - A[1]) * t, A[2] + (B[2] - A[2]) * t);
  }
  function luminance(hex) {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    const [r, g, b] = hexToRgb(hex);
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  }
  function contrast(a, b) {
    const la = luminance(a), lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }
  function lighten(hex, amt) { const [h, s, l] = hexToHsl(hex); return hslToHex(h, s, l + amt); }
  function darken(hex, amt) { return lighten(hex, -amt); }

  /** Pick the more readable of two candidate text colours for a background (falls back to pure black/white). */
  function readableOn(bg, light, dark) {
    light = light || '#F7F3EA'; dark = dark || '#0B1F44';
    const best = contrast(light, bg) >= contrast(dark, bg) ? light : dark;
    if (contrast(best, bg) >= 4.5) return best;
    return contrast('#FFFFFF', bg) >= contrast('#000000', bg) ? '#FFFFFF' : '#000000';
  }
  const darkerOf = (a, b) => (luminance(a) <= luminance(b) ? a : b);
  const lighterOf = (a, b) => (luminance(a) >= luminance(b) ? a : b);
  /** Text colour that stays readable on EVERY one of several backgrounds (e.g. a gradient's stops). */
  function readableOnAll(bgs, light, dark) {
    light = light || '#F7F3EA'; dark = dark || '#0B1F44';
    const worst = (c) => Math.min.apply(null, bgs.map((b) => contrast(c, b)));
    const best = worst(light) >= worst(dark) ? light : dark;
    if (worst(best) >= 4.5) return best;
    return worst('#FFFFFF') >= worst('#000000') ? '#FFFFFF' : '#000000';
  }
  /** Move fg away from bg (in lightness) until it reaches the contrast ratio min. */
  function ensureContrast(fg, bg, min) {
    min = min || 4.5;
    if (contrast(fg, bg) >= min) return fg.toUpperCase();
    const [h, s, l] = hexToHsl(fg);
    const dir = luminance(bg) > 0.4 ? -1 : 1;
    for (let i = 1; i <= 100; i++) {
      const c = hslToHex(h, s, clamp(l + dir * i * 0.01, 0, 1));
      if (contrast(c, bg) >= min) return c;
    }
    return dir < 0 ? '#000000' : '#FFFFFF';
  }

  /* ------------------------------------------------------------------ token catalogue */
  const MODES = ['light', 'dark'];
  const TOKEN_NAMES = [
    'primary', 'onPrimary', 'action', 'onAction', 'accent', 'onAccent', 'accentText',
    'info', 'onInfo', 'bg', 'surface', 'surfaceAlt', 'ink', 'inkMuted', 'line',
    'positive', 'warning', 'danger', 'tintSky', 'tintBlush', 'tintSand', 'tintMint',
    'heroFrom', 'heroTo', 'logoInk', 'logoAccent'
  ];
  /** Human labels + grouping for the admin UI. */
  const TOKEN_META = {
    primary:    { group: 'Brand', label: 'Primary',            hint: 'Hero cards, headers, brand surfaces' },
    onPrimary:  { group: 'Brand', label: 'Text on primary',    hint: 'Auto-derived for contrast' },
    action:     { group: 'Brand', label: 'Button fill',        hint: 'Main buttons (light: primary, dark: accent)' },
    onAction:   { group: 'Brand', label: 'Text on button',     hint: 'Auto-derived for contrast' },
    accent:     { group: 'Brand', label: 'Accent (gold)',      hint: 'Highlights, rings, hairlines, the plumb-bob' },
    onAccent:   { group: 'Brand', label: 'Text on accent',     hint: 'Auto-derived for contrast' },
    accentText: { group: 'Brand', label: 'Accent as text',     hint: 'Accessible shade of the accent for small text' },
    info:       { group: 'Brand', label: 'Interactive blue',   hint: 'Links, selected states, focus rings' },
    onInfo:     { group: 'Brand', label: 'Text on interactive', hint: 'Auto-derived for contrast' },
    bg:         { group: 'Surfaces', label: 'Background',      hint: 'App canvas' },
    surface:    { group: 'Surfaces', label: 'Card surface',    hint: 'Cards, sheets, inputs' },
    surfaceAlt: { group: 'Surfaces', label: 'Subtle panel',    hint: 'Quiet panels, skeletons' },
    ink:        { group: 'Text', label: 'Text',                hint: 'Primary text' },
    inkMuted:   { group: 'Text', label: 'Muted text',          hint: 'Secondary text, captions' },
    line:       { group: 'Text', label: 'Hairline',            hint: 'Borders and dividers' },
    positive:   { group: 'Status', label: 'Paid / positive',   hint: 'Paid, settled' },
    warning:    { group: 'Status', label: 'Due soon',          hint: 'Due, pending' },
    danger:     { group: 'Status', label: 'Overdue',           hint: 'Overdue, defaulted' },
    tintSky:    { group: 'Tiles', label: 'Tile: sky',          hint: 'Pastel service tile' },
    tintBlush:  { group: 'Tiles', label: 'Tile: blush',        hint: 'Pastel service tile' },
    tintSand:   { group: 'Tiles', label: 'Tile: sand',         hint: 'Pastel service tile' },
    tintMint:   { group: 'Tiles', label: 'Tile: mint',         hint: 'Pastel service tile' },
    heroFrom:   { group: 'Hero', label: 'Hero gradient start', hint: 'Dashboard hero card' },
    heroTo:     { group: 'Hero', label: 'Hero gradient end',   hint: 'Dashboard hero card' },
    logoInk:    { group: 'Logo', label: 'Logo ink',            hint: 'Wordmark colour (keep high contrast)' },
    logoAccent: { group: 'Logo', label: 'Logo accent',         hint: 'Plumb-bob / qaf dots' }
  };

  /** Pairs checked on every publish. [foreground, background, minimum ratio, label, blocking] */
  const CHECKS = [
    ['ink', 'bg', 4.5, 'Text on background', true],
    ['ink', 'surface', 4.5, 'Text on card', true],
    ['inkMuted', 'bg', 4.5, 'Muted text on background', true],
    ['inkMuted', 'surface', 4.5, 'Muted text on card', true],
    ['onPrimary', 'primary', 4.5, 'Text on primary', true],
    ['onAction', 'action', 4.5, 'Text on button', true],
    ['onAccent', 'accent', 4.5, 'Text on accent', true],
    ['accentText', 'bg', 4.5, 'Accent text on background', true],
    ['accentText', 'surface', 4.5, 'Accent text on card', true],
    ['info', 'bg', 4.5, 'Link on background', true],
    ['info', 'surface', 4.5, 'Link on card', true],
    ['onInfo', 'info', 4.5, 'Text on interactive', true],
    ['positive', 'surface', 4.5, 'Paid label on card', true],
    ['warning', 'surface', 4.5, 'Due label on card', true],
    ['danger', 'surface', 4.5, 'Overdue label on card', true],
    ['ink', 'tintSky', 4.5, 'Text on sky tile', true],
    ['ink', 'tintBlush', 4.5, 'Text on blush tile', true],
    ['ink', 'tintSand', 4.5, 'Text on sand tile', true],
    ['ink', 'tintMint', 4.5, 'Text on mint tile', true],
    ['onPrimary', 'heroFrom', 4.5, 'Text on hero (start)', true],
    ['onPrimary', 'heroTo', 4.5, 'Text on hero (end)', true],
    ['logoInk', 'bg', 3, 'Logo on background', false]
  ];

  /* ------------------------------------------------------------------ countries */
  const COUNTRIES = {
    SA: { name: 'Saudi Arabia', currency: 'SAR', locale: 'ar-SA', lang: 'ar', tz: 'Asia/Riyadh', hijriOffsetDays: 0 },
    AE: { name: 'United Arab Emirates', currency: 'AED', locale: 'ar-AE', lang: 'ar', tz: 'Asia/Dubai', hijriOffsetDays: 0 },
    EG: { name: 'Egypt', currency: 'EGP', locale: 'ar-EG', lang: 'ar', tz: 'Africa/Cairo', hijriOffsetDays: 0 },
    MA: { name: 'Morocco', currency: 'MAD', locale: 'ar-MA', lang: 'ar', tz: 'Africa/Casablanca', hijriOffsetDays: 0 },
    PK: { name: 'Pakistan', currency: 'PKR', locale: 'ur-PK', lang: 'ur', tz: 'Asia/Karachi', hijriOffsetDays: 0 },
    FR: { name: 'France', currency: 'EUR', locale: 'fr-FR', lang: 'fr', tz: 'Europe/Paris', hijriOffsetDays: 0 },
    ES: { name: 'Spain', currency: 'EUR', locale: 'es-ES', lang: 'es', tz: 'Europe/Madrid', hijriOffsetDays: 0 },
    US: { name: 'United States', currency: 'USD', locale: 'en-US', lang: 'en', tz: 'America/New_York', hijriOffsetDays: 0 }
  };

  /* ------------------------------------------------------------------ calendar helpers */
  const hijriFmt = (function () {
    try { return new Intl.DateTimeFormat('en-u-ca-islamic-umalqura-nu-latn', { day: 'numeric', month: 'numeric', year: 'numeric', timeZone: 'UTC' }); }
    catch (e) { return null; }
  })();

  /** Civil date parts of an instant in a given IANA time zone. */
  function localDate(now, tz) {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: tz || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
    const g = (t) => +parts.find((p) => p.type === t).value;
    return { y: g('year'), m: g('month'), d: g('day') };
  }
  /** Hijri (Umm al-Qura) parts for a civil date {y,m,d}. */
  const hijriCache = new Map();
  function hijriOf(civil) {
    if (!hijriFmt) return null;
    const key = civil.y * 10000 + civil.m * 100 + civil.d;
    let hit = hijriCache.get(key);
    if (!hit) {
      const parts = hijriFmt.formatToParts(new Date(Date.UTC(civil.y, civil.m - 1, civil.d, 12)));
      const g = (t) => +parts.find((p) => p.type === t).value;
      hit = { y: g('year'), m: g('month'), d: g('day') };
      hijriCache.set(key, hit);
    }
    return hit;
  }
  function addDays(civil, n) {
    const t = new Date(Date.UTC(civil.y, civil.m - 1, civil.d + n, 12));
    return { y: t.getUTCFullYear(), m: t.getUTCMonth() + 1, d: t.getUTCDate() };
  }

  /** Is this theme's schedule active at `now`? */
  function isActive(theme, now, ctx) {
    ctx = ctx || {};
    const s = theme.schedule || { always: true };
    const t = +now;
    if (s.startsAt && t < Date.parse(s.startsAt)) return false;
    if (s.endsAt && t >= Date.parse(s.endsAt)) return false;
    const r = s.recurrence;
    if (!r) return true;
    const today = localDate(now, ctx.timeZone);
    const span = Math.max(1, r.spanDays || 1);
    if (r.type === 'gregorian_yearly') {
      for (let k = 0; k < span; k++) {
        const c = addDays(today, -k);
        if (c.m === r.month && c.d === r.day) return true;
      }
      return false;
    }
    if (r.type === 'hijri_yearly') {
      const offset = ctx.hijriOffsetDays != null ? ctx.hijriOffsetDays : (r.offsetDays || 0);
      for (let k = 0; k < span; k++) {
        const h = hijriOf(addDays(today, -offset - k));
        if (h && h.m === r.month && h.d === r.day) return true;
      }
      return false;
    }
    return false;
  }

  /** The next instant at which the answer can change (local midnight, or a one-off start/end). */
  function nextBoundary(themes, now, tz) {
    const lp = new Intl.DateTimeFormat('en-GB', { timeZone: tz || 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(now);
    const g = (t) => +lp.find((p) => p.type === t).value;
    const secs = g('hour') * 3600 + g('minute') * 60 + g('second');
    let next = +now + (86400 - secs) * 1000;
    themes.forEach((th) => {
      const s = th.schedule || {};
      [s.startsAt, s.endsAt].forEach((iso) => {
        if (!iso) return;
        const ms = Date.parse(iso);
        if (ms > +now && ms < next) next = ms;
      });
    });
    return new Date(next).toISOString();
  }

  /* ------------------------------------------------------------------ scope */
  function inScope(theme, ctx) {
    const sc = theme.scope || {};
    const match = (list, v) => !list || list.includes('*') || (v != null && list.includes(v));
    return match(sc.countries, ctx.country) && match(sc.plans, ctx.planId) && match(sc.tenants, ctx.tenantId);
  }

  /* ------------------------------------------------------------------ derivation */
  function deriveLight(L, ex, bgOverride) {
    if (ex.has('primary')) {
      if (!ex.has('heroFrom')) L.heroFrom = L.primary;
      if (!ex.has('heroTo')) L.heroTo = lighten(L.primary, 0.1);
      if (!ex.has('action')) L.action = L.primary;
      if (!ex.has('logoInk')) L.logoInk = L.primary;
    }
    if (ex.has('primary') || ex.has('heroFrom') || ex.has('heroTo')) {
      if (!ex.has('onPrimary')) L.onPrimary = readableOnAll([L.primary, L.heroFrom, L.heroTo]);
    }
    if (ex.has('primary') || ex.has('action')) {
      if (!ex.has('onAction')) L.onAction = readableOn(L.action);
    }
    if (ex.has('accent')) {
      if (!ex.has('onAccent')) L.onAccent = readableOn(L.accent);
      if (!ex.has('accentText')) L.accentText = ensureContrast(darken(L.accent, 0.04), darkerOf(L.bg, L.surface), 4.6);
      if (!ex.has('logoAccent')) L.logoAccent = L.accent;
    }
    if (ex.has('info') && !ex.has('onInfo')) L.onInfo = readableOn(L.info);
  }

  function deriveDark(D, L, exD, exL) {
    const primaryChanged = exL.has('primary') || exD.has('primary');
    if (primaryChanged) {
      const [h, s0] = hexToHsl(exD.has('primary') ? D.primary : L.primary);
      const s = clamp(s0, 0.25, 0.6);
      if (!exD.has('bg')) D.bg = hslToHex(h, s * 0.8, 0.085);
      if (!exD.has('surface')) D.surface = hslToHex(h, s * 0.72, 0.135);
      if (!exD.has('surfaceAlt')) D.surfaceAlt = hslToHex(h, s * 0.68, 0.18);
      if (!exD.has('line')) D.line = hslToHex(h, s * 0.5, 0.26);
      if (!exD.has('primary')) D.primary = hslToHex(h, s * 0.9, 0.24);
      if (!exD.has('heroFrom')) D.heroFrom = hslToHex(h, s, 0.27);
      if (!exD.has('heroTo')) D.heroTo = hslToHex(h, s, 0.17);
      if (!exD.has('onPrimary')) D.onPrimary = readableOnAll([D.primary, D.heroFrom, D.heroTo]);
    }
    const accentChanged = exL.has('accent') || exD.has('accent');
    if (accentChanged) {
      if (!exD.has('accent')) D.accent = mix(L.accent, '#FFFFFF', 0.1);
      if (!exD.has('onAccent')) D.onAccent = readableOn(D.accent);
      if (!exD.has('accentText')) D.accentText = ensureContrast(D.accent, lighterOf(D.bg, D.surface), 4.6);
      if (!exD.has('logoAccent')) D.logoAccent = D.accent;
      if (!exD.has('action')) D.action = D.accent;
      if (!exD.has('onAction')) D.onAction = readableOn(D.action);
    }
    if (exL.has('info') || exD.has('info')) {
      if (!exD.has('info')) D.info = ensureContrast(mix(L.info, '#FFFFFF', 0.35), D.bg, 4.6);
      if (!exD.has('onInfo')) D.onInfo = readableOn(D.info);
    }
  }

  /* ------------------------------------------------------------------ validation */
  /** Status text sits on a 14% tint of itself over the card (the Paid / Due / Overdue chips). */
  const STATUS_TOKENS = [['positive', 'Paid chip text'], ['warning', 'Due chip text'], ['danger', 'Overdue chip text']];
  const TINT = 0.14;
  /** Every pair that must pass for one mode, with the background resolved to a colour. */
  function pairsFor(T) {
    const out = [];
    CHECKS.forEach(([fg, bg, min, label, blocking]) => { if (T[fg] && T[bg]) out.push({ fg, bg, bgHex: T[bg], min, label, blocking }); });
    STATUS_TOKENS.forEach(([s, label]) => { if (T[s] && T.surface) out.push({ fg: s, bg: s + 'Tint', bgHex: mix(T.surface, T[s], TINT), min: 4.5, label, blocking: true }); });
    return out;
  }
  function validate(tokens) {
    const out = [];
    MODES.forEach((mode) => {
      pairsFor(tokens[mode]).forEach((p) => {
        const ratio = contrast(tokens[mode][p.fg], p.bgHex);
        out.push({ mode, fg: p.fg, bg: p.bg, bgHex: p.bgHex, min: p.min, label: p.label, blocking: p.blocking, ratio: Math.round(ratio * 100) / 100, pass: ratio >= p.min });
      });
    });
    return out;
  }
  /* Neutral text tokens that are snapped back to the brand ink/ivory pair when they are far from readable;
     semantic colours (positive, warning, danger, info, accent text) keep their hue and are only nudged. */
  const NEUTRAL_FG = new Set(['ink', 'inkMuted', 'onPrimary', 'onAction', 'onAccent', 'onInfo', 'logoInk']);
  function fixForeground(name, fg, bg, min) {
    const nudged = ensureContrast(fg, bg, min + 0.05);
    if (!NEUTRAL_FG.has(name)) return nudged;
    if (Math.abs(hexToHsl(nudged)[2] - hexToHsl(fg)[2]) <= 0.3) return nudged;
    const base = readableOn(bg);
    return name === 'inkMuted' ? ensureContrast(mix(base, bg, 0.3), bg, min + 0.05) : base;
  }
  /** Status colours tint their own chip background, so darken/lighten until the chip text passes. */
  function fixStatus(T, name, min) {
    const [h, s, l] = hexToHsl(T[name]);
    const dir = luminance(T.surface) > 0.4 ? -1 : 1;
    for (let i = 0; i <= 60; i++) {
      const c = hslToHex(h, s, clamp(l + dir * i * 0.01, 0, 1));
      if (contrast(c, mix(T.surface, c, TINT)) >= min + 0.05) return c;
    }
    return T[name];
  }
  /** Fix every failing pair by moving the foreground token. Returns { tokens, changed }. */
  function autoFix(tokens) {
    const fixed = JSON.parse(JSON.stringify(tokens));
    const changed = [];
    MODES.forEach((mode) => {
      const T = fixed[mode];
      for (let pass = 0; pass < 3; pass++) {
        pairsFor(T).forEach((p) => {
          if (contrast(T[p.fg], p.bgHex) >= p.min) return;
          const before = T[p.fg];
          T[p.fg] = p.bg.endsWith('Tint') ? fixStatus(T, p.fg, p.min) : fixForeground(p.fg, T[p.fg], p.bgHex, p.min);
          if (T[p.fg] !== before) changed.push({ mode, token: p.fg, from: before, to: T[p.fg] });
        });
      }
    });
    return { tokens: fixed, changed };
  }

  /* ------------------------------------------------------------------ resolver */
  const KIND_ORDER = { base: 0, country: 1, event: 2 };

  /**
   * Resolve the live theme for a context.
   * opts: { themes, country, planId, tenantId, tenantAccent, now, timeZone, hijriOffsetDays, safeguard }
   * returns { tokens:{light,dark}, copy, motifs, applied:[{id,kind,name}], allowTenantAccent, validUntil, repaired }
   */
  function resolve(opts) {
    const now = opts.now || new Date();
    const country = opts.country || null;
    const meta = (country && COUNTRIES[country]) || {};
    const timeZone = opts.timeZone || meta.tz || 'UTC';
    const hijriOffsetDays = opts.hijriOffsetDays != null ? opts.hijriOffsetDays : meta.hijriOffsetDays || 0;
    const ctx = { country, planId: opts.planId, tenantId: opts.tenantId || null, timeZone, hijriOffsetDays };

    const live = (opts.themes || []).filter((t) =>
      (opts.includeDrafts ? t.status !== 'archived' : t.status === 'published') && !t.deletedAt &&
      (t.kind === 'base' || inScope(t, ctx)) && (opts.ignoreSchedule && opts.ignoreSchedule.includes(t.id) ? true : isActive(t, now, ctx)));
    live.sort((a, b) =>
      (KIND_ORDER[a.kind] - KIND_ORDER[b.kind]) || ((a.priority || 0) - (b.priority || 0)) ||
      String(a.publishedAt || '').localeCompare(String(b.publishedAt || '')));
    const base = live.find((t) => t.kind === 'base');
    if (!base) throw new Error('Qistas theme resolver: no active base theme');

    const tokens = { light: Object.assign({}, base.tokens.light), dark: Object.assign({}, base.tokens.dark) };
    const explicit = { light: new Set(), dark: new Set() };
    const copy = {};
    Object.entries(base.copy || {}).forEach(([k, byLocale]) => { copy[k] = Object.assign({}, byLocale); });
    const motifs = [];
    const applied = [{ id: base.id, kind: base.kind, name: base.name }];
    let allowTenantAccent = base.allowTenantAccent !== false;

    const layers = live.filter((t) => t !== base);
    layers.forEach((t) => {
      applied.push({ id: t.id, kind: t.kind, name: t.name });
      MODES.forEach((m) => Object.entries((t.tokens && t.tokens[m]) || {}).forEach(([k, v]) => { tokens[m][k] = v; explicit[m].add(k); }));
      Object.entries(t.copy || {}).forEach(([k, byLocale]) => { copy[k] = Object.assign(copy[k] || {}, byLocale); });
      (t.motifs || []).forEach((mo) => { if (!motifs.includes(mo)) motifs.push(mo); });
      if (t.allowTenantAccent === false) allowTenantAccent = false;
    });

    let tenantApplied = false;
    if (opts.tenantAccent && allowTenantAccent) {
      tokens.light.accent = opts.tenantAccent; explicit.light.add('accent');
      tenantApplied = true;
    }

    deriveLight(tokens.light, explicit.light);
    deriveDark(tokens.dark, tokens.light, explicit.dark, explicit.light);

    let repaired = [];
    let finalTokens = tokens;
    if (opts.safeguard !== false) {
      const r = autoFix(tokens);
      finalTokens = r.tokens;
      repaired = r.changed;
    }
    return {
      tokens: finalTokens,
      copy,
      motifs,
      applied,
      tenantAccentApplied: tenantApplied,
      allowTenantAccent,
      validUntil: nextBoundary((opts.themes || []).filter((x) => x.status === 'published' && !x.deletedAt && (x.kind === 'base' || inScope(x, ctx))), now, timeZone),
      repaired,
      context: { country, timeZone, hijriOffsetDays, now: new Date(now).toISOString() }
    };
  }

  /** Resolve the base plus ONE theme (ignoring its schedule) — used to validate/preview a draft. */
  function resolveSingle(themes, themeId, extra) {
    const t = themes.find((x) => x.id === themeId);
    const list = themes.map((x) => (x.id === themeId ? Object.assign({}, x, { status: 'published', scope: { countries: ['*'], plans: ['*'], tenants: ['*'] }, schedule: { always: true } }) : x));
    const onlyBase = list.filter((x) => x.kind === 'base' || x.id === themeId);
    return resolve(Object.assign({ themes: onlyBase, now: new Date(), safeguard: false }, extra || {}, { includeDrafts: true }));
  }

  /* ------------------------------------------------------------------ DOM + formatting */
  const kebab = (s) => s.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
  function cssVars(modeTokens) {
    const o = {};
    Object.entries(modeTokens).forEach(([k, v]) => { o['--q-' + kebab(k)] = v; });
    return o;
  }
  function applyToDOM(el, tokens, mode) {
    const vars = cssVars(tokens[mode]);
    Object.entries(vars).forEach(([k, v]) => el.style.setProperty(k, v));
    el.setAttribute('data-q-mode', mode);
  }
  /** Localised copy lookup with fallbacks: exact locale -> en -> fallback text. */
  function t(copy, key, lang, fallback) {
    const m = copy && copy[key];
    return (m && (m[lang] || m.en)) || fallback || '';
  }

  return {
    // colour
    hexToRgb, rgbToHex, hexToHsl, hslToHex, mix, luminance, contrast, lighten, darken, readableOn, ensureContrast,
    // model
    MODES, TOKEN_NAMES, TOKEN_META, CHECKS, COUNTRIES,
    // calendar
    localDate, hijriOf, addDays, isActive, nextBoundary, inScope,
    // engine
    resolve, resolveSingle, validate, autoFix,
    // DOM
    cssVars, applyToDOM, t
  };
});
