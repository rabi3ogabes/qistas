/* Brand system page — everything dynamic is generated from the same sources the product uses
 * (logo geometry, theme seed, intro copy), so the documentation cannot drift from the product. */
(function () {
  'use strict';
  const Q = window.QistasTheme, IC = window.QIcon, LG = window.QistasLogo, PV = window.QistasPreview, D = LG.data;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const NAVY = '#0B1F44', GOLD = '#C9A25B', IVORY = '#F7F3EA';

  /* ---------------------------------------------------------------- icons + logos */
  $$('[data-ic]').forEach((el) => { el.innerHTML = IC.svg(el.dataset.ic, { size: 18 }); el.style.display = 'inline-flex'; });
  $('#pillLogo').innerHTML = LG.svg('wordmark', { title: 'Qistas' });
  $('#footLogo').innerHTML = LG.svg('wordmark', { title: 'Qistas' });

  function dual(width, ink, accent) {
    const sLat = width / D.latin.viewBox[2];
    return LG.svg('wordmark', { width: width, ink: ink, accent: accent }) + LG.svg('arabic', { width: Math.round(D.arabic.viewBox[2] * 0.6 * sLat), ink: ink, accent: accent });
  }
  $('#heroLockup').innerHTML = dual(400, IVORY, GOLD);
  $('#lgDualRev').innerHTML = dual(360, IVORY, GOLD);
  $('#lgWord').innerHTML = LG.svg('wordmark', { ink: NAVY, accent: GOLD });
  $('#lgArabic').innerHTML = LG.svg('arabic', { ink: NAVY, accent: GOLD });
  $('#lgDualH').innerHTML = LG.svg('wordmark', { height: 64, ink: NAVY, accent: GOLD }) + '<i class="sep"></i>' + LG.svg('arabic', { height: 64 * 0.82 * 0.92, ink: NAVY, accent: GOLD });
  $('#lgSym').innerHTML = LG.svg('symbol', { height: 150, ink: NAVY, accent: GOLD, title: 'Qistas symbol' });
  $('#lgSymSmall').innerHTML = LG.svg('symbolSmall', { height: 72, ink: NAVY, accent: GOLD, title: 'Qistas symbol, small-size cut' });

  /* ---------------------------------------------------------------- construction diagram */
  (function () {
    const S = D.symbol, R = 70, t = 26, stemEnd = 2 * R + 36, bobY = stemEnd + 8, bobR = 22;
    const guide = 'stroke="#C9A25B" stroke-width="1" stroke-dasharray="4 4" fill="none"';
    const txt = 'font-family="Geist, sans-serif" font-size="9.5" fill="#4F5B76"';
    $('#construct').innerHTML = '<svg viewBox="-96 -34 340 262" role="img" aria-label="Construction of the symbol">' +
      '<path d="' + S.ink + '" fill="#0B1F44" opacity=".92"/><path d="' + S.gold + '" fill="#C9A25B"/>' +
      '<circle cx="70" cy="70" r="70" ' + guide + '/><circle cx="70" cy="70" r="' + (70 - t) + '" ' + guide + '/>' +
      '<path d="M70 -22V162M-22 70H162" stroke="#C9A25B" stroke-width=".8" stroke-dasharray="2 4"/>' +
      '<path d="M' + (2 * R - t) + ' -22V' + (stemEnd + 30) + 'M' + 2 * R + ' -22V' + (stemEnd + 30) + '" ' + guide + '/>' +
      '<path d="M-30 0H150M-30 140H150" ' + guide + '/>' +
      '<circle cx="' + (2 * R - t / 2) + '" cy="' + bobY + '" r="' + (bobR + 7) + '" ' + guide + '/>' +
      '<text x="-90" y="4" ' + txt + '>x-height</text><text x="-90" y="144" ' + txt + '>baseline of the ring</text>' +
      '<text x="-90" y="74" ' + txt + '>ring Ø 140</text><text x="-90" y="86" ' + txt + '>stroke 26 · 0.19 Ø</text>' +
      '<text x="164" y="56" ' + txt + '>plumb line</text><text x="164" y="68" ' + txt + '>width = stroke</text>' +
      '<text x="164" y="' + (bobY - 2) + '" ' + txt + '>bob r 22</text><text x="164" y="' + (bobY + 10) + '" ' + txt + '>1.7 × stroke</text></svg>';
  })();

  /* ---------------------------------------------------------------- clear space + minimum sizes */
  (function () {
    const L = D.latin, bobD = D.geom.bob.r * 2, x2 = L.width, y1 = -34, y2 = D.geom.bob.cy + D.geom.bob.r;
    const m = bobD, vbx = -m - 22, vby = y1 - m - 22, vbw = x2 + 2 * m + 44, vbh = (y2 - y1) + 2 * m + 44;
    const guide = 'stroke="#C9A25B" stroke-width="1.4" stroke-dasharray="6 5" fill="none"';
    $('#clear').innerHTML = '<svg viewBox="' + [vbx, vby, vbw, vbh].join(' ') + '" role="img" aria-label="Clear space equals one bob diameter">' +
      '<rect x="' + (-m) + '" y="' + (y1 - m) + '" width="' + (x2 + 2 * m) + '" height="' + (y2 - y1 + 2 * m) + '" rx="10" ' + guide + '/>' +
      '<rect x="0" y="' + y1 + '" width="' + x2 + '" height="' + (y2 - y1) + '" fill="rgb(11 31 68 / .04)"/>' +
      '<path d="' + L.ink + '" fill="#0B1F44"/><path d="' + L.gold + '" fill="#C9A25B"/>' +
      [[-m / 2, (y1 + y2) / 2], [x2 + m / 2, (y1 + y2) / 2], [x2 / 2, y1 - m / 2], [x2 / 2, y2 + m / 2]].map((p) => '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="' + D.geom.bob.r + '" ' + guide + '/>').join('') + '</svg>';
    $('#mins').innerHTML =
      '<div class="row"><div>' + LG.svg('wordmark', { height: 22, ink: NAVY, accent: GOLD }) + '<small>22 px · minimum</small></div><div>' + LG.svg('wordmark', { height: 34, ink: NAVY, accent: GOLD }) + '<small>34 px</small></div><div>' + LG.svg('wordmark', { height: 52, ink: NAVY, accent: GOLD }) + '<small>52 px</small></div></div>' +
      '<div class="row"><div>' + LG.svg('symbolSmall', { height: 16, ink: NAVY, accent: GOLD }) + '<small>16 px · minimum</small></div><div>' + LG.svg('symbolSmall', { height: 24, ink: NAVY, accent: GOLD }) + '<small>24</small></div><div>' + LG.svg('symbolSmall', { height: 32, ink: NAVY, accent: GOLD }) + '<small>32 · small cut</small></div><div>' + LG.svg('symbol', { height: 56, ink: NAVY, accent: GOLD }) + '<small>56 · regular</small></div></div>';
  })();

  /* ---------------------------------------------------------------- colourways + misuse */
  $('#ways').innerHTML = [
    ['On ivory', IVORY, NAVY, GOLD, NAVY], ['On navy · reversed', NAVY, IVORY, GOLD, IVORY], ['On gold · one colour', GOLD, NAVY, NAVY, NAVY],
    ['On white · black', '#fff', '#000', '#000', '#000'], ['On photo · white', 'linear-gradient(135deg,#3d6f84,#1c2b4a)', '#fff', '#fff', '#fff']
  ].map((w) => '<div class="way" style="background:' + w[1] + ';color:' + w[4] + '">' + LG.svg('wordmark', { ink: w[2], accent: w[3] }) + '<span>' + w[0] + '</span></div>').join('');
  const mis = [
    ['Don\'t stretch', 'transform:scaleX(1.45)', NAVY, GOLD, '#fff'], ['Don\'t rotate', 'transform:rotate(-14deg)', NAVY, GOLD, '#fff'],
    ['Don\'t recolour', '', '#E0359C', '#2FD1A2', '#fff'], ['Don\'t outline', '', 'none', 'none', '#fff'],
    ['Don\'t add effects', 'filter:drop-shadow(5px 7px 3px rgb(0 0 0 / .5))', NAVY, GOLD, '#fff'], ['Don\'t use low contrast', '', NAVY, '#1B3A78', '#1B3A78']
  ];
  $('#misuse').innerHTML = mis.map((m) => {
    const outline = m[2] === 'none';
    const svg = LG.svg('wordmark', { ink: outline ? 'none' : m[2], accent: outline ? 'none' : m[3] });
    return '<div class="way x" style="background:' + m[4] + ';color:' + (m[4] === '#fff' ? '#4F5B76' : '#F7F3EA') + '"><div style="' + m[1] + ';width:80%;max-width:190px">' + (outline ? svg.replace('<svg ', '<svg style="--o:1" ').replace(/class="ink"/, 'class="ink" stroke="#0B1F44" stroke-width="1.6"').replace(/class="acc"/, 'class="acc" stroke="#C9A25B" stroke-width="1.6"') : svg) + '</div><span>' + m[0] + '</span></div>';
  }).join('');
  $$('#misuse svg').forEach((s) => { s.style.width = '100%'; });

  /* ---------------------------------------------------------------- palette */
  const cmyk = (hex) => { const [r, g, b] = Q.hexToRgb(hex).map((v) => v / 255), k = 1 - Math.max(r, g, b); if (k >= 1) return [0, 0, 0, 100]; return [(1 - r - k) / (1 - k), (1 - g - k) / (1 - k), (1 - b - k) / (1 - k), k].map((v) => Math.round(v * 100)); };
  const PAL = [
    ['Midnight Navy', NAVY, 'Brand ink, primary surfaces, app icon', '#F7F3EA'], ['Champagne Gold', GOLD, 'The plumb-bob, rings, hairlines', NAVY], ['Bronze', '#7F6126', 'Gold as small text on ivory', '#F7F3EA'], ['Ivory', IVORY, 'The canvas', NAVY],
    ['Sapphire', '#1C6BA4', 'Links and interactive states (from the reference UI)', '#fff'], ['Emerald', '#176E50', 'Paid, settled', '#fff'], ['Amber', '#9A5700', 'Due soon', '#fff'], ['Ruby', '#B3261E', 'Overdue, defaulted', '#fff']
  ];
  $('#palette').innerHTML = PAL.map((p, i) => {
    const rgb = Q.hexToRgb(p[1]).join(' · '), k = cmyk(p[1]).join(' · ');
    return '<article class="pc rv" style="--d:' + (i % 4) * 60 + 'ms"><div class="sw" style="background:' + p[1] + ';color:' + p[3] + '"><small>' + esc(p[2]) + '</small><b>' + p[0] + '</b></div><dl><dt>HEX</dt><dd>' + p[1] + '</dd><dt>RGB</dt><dd>' + rgb + '</dd><dt>CMYK≈</dt><dd>' + k + '</dd><dt>On ivory</dt><dd>' + Q.contrast(p[1], IVORY).toFixed(1) + ' : 1</dd></dl></article>';
  }).join('') + '<div class="tints rv" style="grid-column:1/-1">' + [['Sky', '#DDEDFA'], ['Blush', '#F6E4EA'], ['Sand', '#F8EBCB'], ['Mint', '#DCF2E8']].map((t) => '<div style="background:' + t[1] + '">' + t[0] + '<small>' + t[1] + '</small></div>').join('') + '</div>';

  const PAIRS = [['Navy on ivory · body text', NAVY, IVORY], ['Ivory on navy · hero cards', IVORY, NAVY], ['Navy on gold · gold buttons', NAVY, GOLD], ['Bronze on ivory · accent text', '#7F6126', IVORY], ['Sapphire on ivory · links', '#1C6BA4', IVORY], ['Emerald on white · Paid', '#176E50', '#fff'], ['Amber on white · Due', '#9A5700', '#fff'], ['Ruby on white · Overdue', '#B3261E', '#fff']];
  $('#pairs').innerHTML = PAIRS.map((p) => { const r = Q.contrast(p[1], p[2]); return '<div class="pr"><i style="background:' + p[2] + ';color:' + p[1] + '">Aa</i><span>' + esc(p[0]) + '</span><span><b>' + r.toFixed(1) + ':1</b> <span class="ok">' + (r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : '—') + '</span></span></div>'; }).join('');

  const seed = window.QISTAS_SEED.themes;
  $('#seedPalettes').innerHTML = seed.filter((t) => t.kind !== 'base').map((t) => {
    const r = Q.resolveSingle(seed, t.id).tokens.light;
    return '<div class="sp"><span class="dots"><i class="p" style="background:' + r.primary + '"></i><i class="a" style="background:' + r.accent + '"></i></span><span>' + esc(t.name.en.replace(/ \(.*\)/, '')) + '</span></div>';
  }).join('');

  /* ---------------------------------------------------------------- type scale */
  const SC = [['display-xl', 56, 'Cormorant 600', '“The just balance.”', 'var(--q-font-display)', 600], ['display', 40, 'Cormorant 600', 'Every instalment, in order.', 'var(--q-font-display)', 600], ['h1', 32, 'Geist 600', 'Total outstanding', 'var(--q-font-body)', 600], ['h2', 24, 'Geist 600', 'Due today', 'var(--q-font-body)', 600], ['body', 16, 'Geist 400', 'Customers, contracts and schedules in one calm place.', 'var(--q-font-body)', 400], ['small', 14, 'Geist 400', 'Next instalment · 5 Nov', 'var(--q-font-body)', 400], ['caption', 12, 'Geist 500', 'LAST SYNCED 2 MIN AGO', 'var(--q-font-body)', 500]];
  $('#scale').innerHTML = SC.map((s) => '<div class="r"><span>' + s[0] + ' · ' + s[1] + ' px</span><p style="margin:0;font-family:' + s[4] + ';font-size:' + Math.min(s[1], 44) + 'px;font-weight:' + s[5] + ';line-height:1.15;letter-spacing:' + (s[1] > 30 ? '-.015em' : '0') + '">' + esc(s[3]) + '</p><span>' + s[2] + '</span></div>').join('');

  /* ---------------------------------------------------------------- motion timelines */
  function timeline(el, total, items) {
    const pct = (ms) => (ms / total * 100).toFixed(2) + '%';
    el.innerHTML = items.map((it) => '<div class="row"><span>' + it[0] + '</span><div class="track"><i class="bar ' + (it[3] || '') + '" style="left:' + pct(it[1]) + ';width:' + pct(it[2]) + '"></i></div></div>').join('') +
      '<div class="axis"><span></span><div><span>0</span><span>1 s</span><span>2 s</span><span>' + (total >= 3000 ? '3 s' : '') + '</span></div></div>';
  }
  timeline($('#tlLatin'), 3300, [['Glow', 150, 1400, 'dim'], ['Ring is drawn', 150, 780], ['Bob drops, line pays out', 640, 950], ['Landing ripple', 923, 850], ['Wordmark forms', 1320, 720, 'ink'], ['Letters rise (×5)', 1490, 800, 'ink'], ['Gold hairline', 2000, 560], ['Greeting / tagline', 2080, 560], ['Hold', 2640, 660, 'dim']]);
  timeline($('#tlArabic'), 2940, [['Glow', 150, 1400, 'dim'], ['Wordmark wipes in', 200, 1100, 'ink'], ['Qaf dots drop (×2)', 1020, 1080], ['Coin ripples', 1303, 770], ['Gold hairline', 1800, 560], ['Greeting / tagline', 1880, 560], ['Hold', 2440, 500, 'dim']]);

  /* ---------------------------------------------------------------- theming demo */
  const dC = $('#dCountry'), dE = $('#dEvent'), dL = $('#dLang');
  const demo = { country: 'SA', lang: 'en', mode: 'light', date: new Date().toISOString().slice(0, 10), eventId: '' };
  const nowFor = (iso) => new Date(iso + 'T12:00:00Z');
  dC.innerHTML = Object.keys(Q.COUNTRIES).map((c) => '<option value="' + c + '">' + c + ' · ' + Q.COUNTRIES[c].name + '</option>').join('');
  function findDay(ev, country) {
    const m = Q.COUNTRIES[country], ctx = { timeZone: m.tz, hijriOffsetDays: m.hijriOffsetDays || 0 }, t0 = +nowFor(new Date().toISOString().slice(0, 10));
    for (let i = 0; i < 800; i++) if (Q.isActive(ev, new Date(t0 + i * 864e5), ctx)) return new Date(t0 + i * 864e5).toISOString().slice(0, 10);
    return null;
  }
  function fillEvents() {
    const evs = seed.filter((t) => t.kind === 'event' && t.status === 'published' && (t.scope.countries.includes('*') || t.scope.countries.includes(demo.country)));
    dE.innerHTML = '<option value="">Today (no event)</option>' + evs.map((e) => '<option value="' + e.id + '">' + esc(e.name.en) + '</option>').join('');
    dE.value = evs.some((e) => e.id === demo.eventId) ? demo.eventId : '';
    demo.eventId = dE.value;
  }
  function renderDemo() {
    const today = new Date().toISOString().slice(0, 10);
    demo.date = demo.eventId ? (findDay(seed.find((t) => t.id === demo.eventId), demo.country) || today) : today;
    const r = Q.resolve({ themes: seed, country: demo.country, now: nowFor(demo.date) });
    PV.render($('#dPhone'), { tokens: r.tokens, mode: demo.mode, lang: demo.lang, country: demo.country, copy: r.copy, motifs: r.motifs });
    const g = Q.t(r.copy, 'greeting', demo.lang, '');
    $('#dNote').innerHTML = '<b>' + r.applied.map((a) => esc(a.name.en)).join(' → ') + '</b><br>' + demo.date + (g ? ' · greeting “' + esc(g) + '”' : '');
  }
  dC.addEventListener('change', () => { demo.country = dC.value; fillEvents(); renderDemo(); });
  dE.addEventListener('change', () => { demo.eventId = dE.value; renderDemo(); });
  dL.addEventListener('change', () => { demo.lang = dL.value; renderDemo(); });
  $('#dMode').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; demo.mode = b.dataset.v; $$('#dMode button').forEach((x) => x.classList.toggle('on', x === b)); renderDemo(); });
  dC.value = demo.country; fillEvents(); renderDemo();

  /* ---------------------------------------------------------------- copy table */
  (function () {
    const C = window.QISTAS_INTRO_COPY, langs = [['en', 'English'], ['ar', 'العربية'], ['fr', 'Français'], ['es', 'Español'], ['ur', 'اردو']], base = seed.find((t) => t.kind === 'base');
    const cell = (l, txt) => '<td lang="' + l + '" dir="' + (l === 'ar' || l === 'ur' ? 'rtl' : 'ltr') + '"' + (l === 'ur' ? ' style="font-size:15px;line-height:2"' : '') + '>' + esc(txt) + '</td>';
    const rows = [['Tagline', (l) => base.copy.tagline[l]], ['Slide 1', (l) => C[l].slides[0][0].replace(/<br>/g, ' ') + ' — ' + C[l].slides[0][1]], ['Slide 2', (l) => C[l].slides[1][0].replace(/<br>/g, ' ') + ' — ' + C[l].slides[1][1]], ['Slide 3', (l) => C[l].slides[2][0].replace(/<br>/g, ' ') + ' — ' + C[l].slides[2][1]], ['Call to action', (l) => C[l].start], ['Demo note', (l) => C[l].demo]];
    $('#copyTable').innerHTML = '<table><thead><tr><th><span class="sr-only">Item</span></th>' + langs.map((l) => '<th>' + l[1] + '</th>').join('') + '</tr></thead><tbody>' + rows.map((r) => '<tr><td>' + r[0] + '</td>' + langs.map((l) => cell(l[0], r[1](l[0]))).join('') + '</tr>').join('') + '</tbody></table>';
  })();

  /* ---------------------------------------------------------------- files */
  const FILES = [
    ['Logo masters (SVG)', [['logo/qistas-wordmark-color.svg', 'Latin wordmark'], ['logo/qistas-wordmark-ar-color.svg', 'Arabic wordmark'], ['logo/qistas-lockup-dual-color.svg', 'Dual, stacked'], ['logo/qistas-lockup-dual-h-color.svg', 'Dual, horizontal'], ['logo/qistas-symbol-color.svg', 'Symbol'], ['logo/qistas-symbol-small-color.svg', 'Small-size cut']], 'Each also in -reversed, -black, -white, -mono.'],
    ['App & web icons', [['logo/qistas-app-icon-1024.svg', 'iOS 1024'], ['logo/qistas-app-icon-android-foreground.svg', 'Android foreground'], ['logo/qistas-app-icon-android-background.svg', 'Android background'], ['logo/qistas-app-icon-maskable.svg', 'PWA maskable'], ['logo/favicon.svg', 'favicon.svg (dark-aware)'], ['logo/web/favicon.ico', 'favicon.ico 16/32/48'], ['logo/web/site.webmanifest', 'Manifest'], ['logo/web/head-snippet.html', '&lt;head&gt; snippet'], ['logo/qistas-og-card.png', 'Social card']]],
    ['Tokens & runtime', [['tokens/design-tokens.json', 'Fixed tokens'], ['tokens/themes.seed.json', 'Theme seed (source)'], ['shared/qistas-theme.js', 'Theme resolver'], ['shared/qistas-preview.js', 'Dashboard preview'], ['shared/qistas-logo.js', 'Inline logos (generated)'], ['shared/qistas-base.css', 'Base styles']]],
    ['Prototypes', [['intro/index.html', 'Splash + onboarding'], ['theme-studio/index.html', 'Theme Studio (admin)']]],
    ['Backend & docs', [['../supabase/migrations/20261007000100_theme_engine.sql', 'Migration (unexecuted)'], ['../supabase/seed/theme_seed.sql', 'Seed (generated)'], ['../docs/THEME_ENGINE.md', 'Theme engine spec']]],
    ['Tools & history', [['tools/build_logo.py', 'Rebuild every logo'], ['tools/sync-brand-data.py', 'Regenerate JS / SQL'], ['tools/test-theme-runtime.cjs', '24 engine tests'], ['tools/arabic-wordmark.json', 'Arabic outlines'], ['concepts/concepts-colour.png', 'The three concepts']]]
  ];
  // Files that are in git but not part of the deployed site open on GitHub.
  const GH = 'https://github.com/rabi3ogabes/qistas/blob/main/';
  const href = (p) => (p.indexOf('../') === 0 ? GH + p.slice(3) : p.indexOf('tools/') === 0 ? GH + 'brand/' + p : p);
  const ext = (p) => (p.indexOf('../') === 0 || p.indexOf('tools/') === 0 ? ' target="_blank" rel="noopener"' : '');
  $('#files').innerHTML = FILES.map((g) => '<div class="fgroup"><h3>' + g[0] + '</h3>' + g[1].map((f) => '<a href="' + href(f[0]) + '"' + ext(f[0]) + '><b style="font-weight:500">' + f[1] + '</b><span>' + f[0].split('/').pop() + '</span></a>').join('') + (g[2] ? '<p class="small" style="margin:6px 0 0">' + g[2] + '</p>' : '') + '</div>').join('');

  /* ---------------------------------------------------------------- reveal, nav state, mobile menu */
  const rv = $$('.rv');
  rv.forEach((el) => { if (!el.style.getPropertyValue('--d')) { const sibs = Array.from(el.parentElement.children).filter((c) => c.classList.contains('rv')); el.style.setProperty('--d', Math.min(sibs.indexOf(el), 5) * 70 + 'ms'); } });
  const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
  rv.forEach((el) => io.observe(el));
  if (/[?&]static/.test(location.search)) { document.documentElement.style.scrollBehavior = 'auto'; rv.forEach((el) => el.classList.add('in')); }
  const links = $$('.pill-links a'), secs = links.map((a) => $(a.getAttribute('href')));
  const so = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { const i = secs.indexOf(e.target); links.forEach((a, k) => a.classList.toggle('on', k === i)); } }), { rootMargin: '-45% 0px -50% 0px' });
  secs.forEach((s) => s && so.observe(s));
  $('#pillMenu').addEventListener('click', () => { const o = $('#pill').classList.toggle('open'); $('#pillMenu').setAttribute('aria-expanded', String(o)); });
  links.forEach((a) => a.addEventListener('click', () => { $('#pill').classList.remove('open'); }));
})();
