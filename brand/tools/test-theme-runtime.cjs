#!/usr/bin/env node
/* Tests for brand/shared/qistas-theme.js  —  run:  node brand/tools/test-theme-runtime.cjs */
const assert = require('node:assert/strict');
const path = require('node:path');
const Q = require(path.join(__dirname, '..', 'shared', 'qistas-theme.js'));
const seed = require(path.join(__dirname, '..', 'tokens', 'themes.seed.json'));
const themes = seed.themes;

let passed = 0, failed = 0;
function test(name, fn) {
  try { fn(); passed++; console.log('  ok   ' + name); }
  catch (e) { failed++; console.log('  FAIL ' + name + '\n       ' + (e.message || e).split('\n').join('\n       ')); }
}
const at = (iso) => new Date(iso);
const ids = (r) => r.applied.map((a) => a.id);

console.log('\nContrast & palette');
test('base theme passes every contrast check in light and dark', () => {
  const base = themes.find((t) => t.kind === 'base');
  const bad = Q.validate(base.tokens).filter((c) => !c.pass);
  assert.deepEqual(bad.map((c) => `${c.mode}:${c.fg}/${c.bg}=${c.ratio}<${c.min}`), []);
});
test('every seed theme, resolved over the base, passes contrast WITHOUT the safeguard', () => {
  const problems = [];
  themes.filter((t) => t.kind !== 'base').forEach((t) => {
    const r = Q.resolveSingle(themes, t.id);
    Q.validate(r.tokens).filter((c) => !c.pass && c.blocking).forEach((c) =>
      problems.push(`${t.id} ${c.mode} ${c.fg}/${c.bg} = ${c.ratio} (< ${c.min})`));
  });
  assert.deepEqual(problems, []);
});
test('status chip text (Paid / Due / Overdue) is checked on its own 14% tint and meets AA in every seed theme', () => {
  themes.forEach((th) => {
    const r = Q.resolveSingle(themes, th.id);
    ['light', 'dark'].forEach((m) => {
      ['positive', 'warning', 'danger'].forEach((s) => {
        const T = r.tokens[m], bg = Q.mix(T.surface, T[s], 0.14);
        assert.ok(Q.contrast(T[s], bg) >= 4.5, `${th.id} ${m} ${s} chip = ${Q.contrast(T[s], bg).toFixed(2)}`);
      });
    });
  });
  assert.ok(Q.validate(themes[0].tokens).some((c) => c.bg === 'positiveTint'), 'validate() includes the chip pairs');
});
test('a too-pale status colour is caught by the gate and repaired by auto-fix', () => {
  const t = JSON.parse(JSON.stringify(themes[0].tokens));
  t.light.positive = '#3FBF8F';
  const bad = Q.validate(t).filter((c) => !c.pass && c.fg === 'positive');
  assert.ok(bad.length > 0, 'expected a failing chip pair');
  const fixed = Q.autoFix(t).tokens;
  assert.deepEqual(Q.validate(fixed).filter((c) => !c.pass && c.mode === 'light'), []);
  assert.ok(Math.abs(Q.hexToHsl(fixed.light.positive)[0] - Q.hexToHsl('#3FBF8F')[0]) <= 3, 'hue is preserved (within rounding)');
});
test('ensureContrast reaches the requested ratio', () => {
  const c = Q.ensureContrast('#C9A25B', '#F7F3EA', 4.5);
  assert.ok(Q.contrast(c, '#F7F3EA') >= 4.5, c);
});
test('safeguard repairs a hostile palette', () => {
  const bad = JSON.parse(JSON.stringify(themes));
  bad.push({ id: 'evil', kind: 'event', name: { en: 'evil' }, status: 'published', priority: 99,
    scope: { countries: ['*'] }, schedule: { always: true },
    tokens: { light: { ink: '#F7F3EA', inkMuted: '#F3EFE5', accentText: '#F9F4E6' }, dark: {} } });
  const r = Q.resolve({ themes: bad, country: 'FR', now: at('2026-10-07T10:00:00Z') });
  assert.ok(r.repaired.length > 0, 'expected repairs');
  assert.deepEqual(Q.validate(r.tokens).filter((c) => !c.pass && c.blocking), []);
  assert.equal(r.tokens.light.ink, '#0B1F44', 'unreadable neutral text snaps back to brand navy, not an off-hue brown');
  assert.ok(Q.contrast(r.tokens.light.inkMuted, r.tokens.light.bg) >= 4.5);
});

console.log('\nLayering');
test('no country, no event => base only', () => {
  const r = Q.resolve({ themes, country: null, now: at('2026-10-07T10:00:00Z') });
  assert.deepEqual(ids(r), ['base']);
  assert.equal(r.tokens.light.primary, '#0B1F44');
});
test('country theme overrides base primary; dependent tokens are derived', () => {
  const r = Q.resolve({ themes, country: 'SA', now: at('2026-10-07T10:00:00Z') });
  assert.deepEqual(ids(r), ['base', 'country-sa']);
  assert.equal(r.tokens.light.primary, '#0E3B43');
  assert.equal(r.tokens.light.heroFrom, '#0E3B43');
  assert.equal(r.tokens.light.logoInk, '#0E3B43');
  assert.ok(Q.contrast(r.tokens.light.onPrimary, r.tokens.light.primary) >= 4.5);
});
test('dark mode is derived from the new primary hue (not left navy)', () => {
  const r = Q.resolve({ themes, country: 'SA', now: at('2026-10-07T10:00:00Z') });
  const [h] = Q.hexToHsl(r.tokens.dark.bg), [h0] = Q.hexToHsl(themes[0].tokens.dark.bg);
  assert.notEqual(Math.round(h), Math.round(h0));
});
test('scope: a Saudi-only theme never reaches France', () => {
  const r = Q.resolve({ themes, country: 'FR', now: at('2026-09-23T10:00:00Z') });
  assert.ok(!ids(r).includes('event-sa-national-day'));
});
test('event wins over country (AE during Ramadan)', () => {
  const day = findHijriDay(2027, 9, 10);
  const r = Q.resolve({ themes, country: 'AE', now: noonUTC(day, 'Asia/Dubai') });
  assert.deepEqual(ids(r), ['base', 'country-ae', 'event-ramadan']);
  assert.equal(r.tokens.light.primary, '#1B1750');
  assert.deepEqual(r.motifs, ['crescent']);
});
test('copy is merged per locale and falls back to English', () => {
  const day = findHijriDay(2027, 9, 10);
  const r = Q.resolve({ themes, country: 'SA', now: noonUTC(day, 'Asia/Riyadh') });
  assert.equal(Q.t(r.copy, 'greeting', 'ar'), 'رمضان كريم');
  assert.equal(Q.t(r.copy, 'greeting', 'xx', 'x'), 'Ramadan Kareem');
  assert.equal(Q.t(r.copy, 'tagline', 'fr'), 'La juste balance.');
});

console.log('\nTenant accent');
test('tenant accent applies on a normal day', () => {
  const r = Q.resolve({ themes, country: 'SA', tenantAccent: '#9A2E5B', now: at('2026-10-07T10:00:00Z') });
  assert.equal(r.tokens.light.accent, '#9A2E5B');
  assert.equal(r.tenantAccentApplied, true);
});
test('a locked campaign (National Day) ignores the tenant accent', () => {
  const r = Q.resolve({ themes, country: 'SA', tenantAccent: '#9A2E5B', now: at('2026-09-23T10:00:00Z') });
  assert.ok(ids(r).includes('event-sa-national-day'));
  assert.equal(r.tenantAccentApplied, false);
  assert.equal(r.tokens.light.accent, '#E3C77F');
});

console.log('\nSchedules');
test('Gregorian window: 22-23 Sep active, 21 and 24 not', () => {
  const on = (d) => ids(Q.resolve({ themes, country: 'SA', now: at(d) })).includes('event-sa-national-day');
  assert.equal(on('2026-09-21T10:00:00Z'), false);
  assert.equal(on('2026-09-22T10:00:00Z'), true);
  assert.equal(on('2026-09-23T10:00:00Z'), true);
  assert.equal(on('2026-09-24T10:00:00Z'), false);
});
test('window that crosses the year boundary (29 Dec + 5 days)', () => {
  const on = (d) => ids(Q.resolve({ themes, country: 'FR', now: at(d) })).includes('event-new-year');
  assert.equal(on('2026-12-28T12:00:00Z'), false);
  assert.equal(on('2026-12-29T12:00:00Z'), true);
  assert.equal(on('2027-01-02T12:00:00Z'), true);
  assert.equal(on('2027-01-03T12:00:00Z'), false);
});
test('Hijri: Ramadan active for its whole month in Saudi Arabia, not the day before', () => {
  const first = findHijriDay(2027, 9, 1);
  const on = (c) => ids(Q.resolve({ themes, country: 'SA', now: noonUTC(c, 'Asia/Riyadh') })).includes('event-ramadan');
  assert.equal(on(Q.addDays(first, -1)), false);
  assert.equal(on(first), true);
  assert.equal(on(Q.addDays(first, 28)), true);
});
test('Hijri: Eid al-Fitr lasts exactly 3 days', () => {
  const first = findHijriDay(2027, 10, 1);
  const on = (c) => ids(Q.resolve({ themes, country: 'EG', now: noonUTC(c, 'Africa/Cairo') })).includes('event-eid-fitr');
  assert.deepEqual([-1, 0, 1, 2, 3].map((k) => on(Q.addDays(first, k))), [false, true, true, true, false]);
});
test('Hijri offset: a country that sights the moon a day later starts Ramadan a day later', () => {
  const first = findHijriDay(2027, 9, 1);
  const on = (c) => ids(Q.resolve({ themes, country: 'MA', hijriOffsetDays: 1, now: noonUTC(c, 'Africa/Casablanca') })).includes('event-ramadan');
  assert.equal(on(first), false);
  assert.equal(on(Q.addDays(first, 1)), true);
});
test('one-off window (startsAt / endsAt) gates a theme', () => {
  const t = JSON.parse(JSON.stringify(themes));
  t.push({ id: 'promo', kind: 'event', name: { en: 'Promo' }, status: 'published', priority: 50, scope: { countries: ['*'] },
    schedule: { startsAt: '2026-11-27T00:00:00Z', endsAt: '2026-11-30T00:00:00Z' }, tokens: { light: { accent: '#E0A025' }, dark: {} } });
  const on = (d) => ids(Q.resolve({ themes: t, country: 'FR', now: at(d) })).includes('promo');
  assert.equal(on('2026-11-26T23:59:00Z'), false);
  assert.equal(on('2026-11-28T12:00:00Z'), true);
  assert.equal(on('2026-11-30T00:00:00Z'), false);
});
test('draft and archived themes never resolve', () => {
  const t = JSON.parse(JSON.stringify(themes));
  t.find((x) => x.id === 'country-sa').status = 'draft';
  assert.deepEqual(ids(Q.resolve({ themes: t, country: 'SA', now: at('2026-10-07T10:00:00Z') })), ['base']);
});
test('validUntil is the next local midnight (Riyadh = UTC+3)', () => {
  const r = Q.resolve({ themes, country: 'SA', now: at('2026-10-07T10:00:00Z') });
  assert.equal(r.validUntil, '2026-10-07T21:00:00.000Z');
});
test('validUntil is pulled forward by a one-off boundary', () => {
  const t = JSON.parse(JSON.stringify(themes));
  t.push({ id: 'soon', kind: 'event', name: { en: 'Soon' }, status: 'published', priority: 50, scope: { countries: ['*'] },
    schedule: { startsAt: '2026-10-07T12:00:00Z' }, tokens: { light: {}, dark: {} } });
  const r = Q.resolve({ themes: t, country: 'SA', now: at('2026-10-07T10:00:00Z') });
  assert.equal(r.validUntil, '2026-10-07T12:00:00.000Z');
});

console.log('\nCSS variables');
test('cssVars maps camelCase tokens to --q-kebab', () => {
  const v = Q.cssVars({ onPrimary: '#fff', heroFrom: '#000' });
  assert.deepEqual(v, { '--q-on-primary': '#fff', '--q-hero-from': '#000' });
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);

/* ---------- helpers ---------- */
/** First civil day (UTC calendar) in Gregorian year `y` whose Umm al-Qura date is month m, day d. */
function findHijriDay(y, m, d) {
  for (let k = 0; k < 366; k++) {
    const c = Q.addDays({ y, m: 1, d: 1 }, k);
    const h = Q.hijriOf(c);
    if (h.m === m && h.d === d) return c;
  }
  throw new Error('no such hijri day in ' + y);
}
/** An instant that is noon local time on a civil day in the given zone (UTC noon is safe for +/-12h zones). */
function noonUTC(c) { return new Date(Date.UTC(c.y, c.m - 1, c.d, 9, 0, 0)); }
