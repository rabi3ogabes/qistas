/* Qistas intro — splash + onboarding.
 *
 * Motion brief (see brand/index.html#motion):
 *   Frequency: rare (first launch / cold start)  ->  the delight budget lives here.
 *   Purpose:   delight + explanation. The plumb-bob falls, the line pays out, the level settles.
 *   Tool:      Web Animations API (programmatic, interruptible, off the main thread for transform/opacity).
 *   Properties: transform + opacity only (stroke-dashoffset for the line draw, mask-position for the Arabic wipe).
 *   Curves:    ease-out cubic-bezier(.23,1,.32,1) · ease-in-out cubic-bezier(.77,0,.175,1)
 *   Spring:    mass 1, stiffness 90, damping 11  (≈10% overshoot, settles in ~0.9 s)
 *   Reduced motion: final frame fades in; no movement.
 */
(function () {
  'use strict';
  const Q = window.QistasTheme, IC = window.QIcon, LG = window.QistasLogo, D = LG.data;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const ic = (n, s, st) => IC.svg(n, { size: s || 18, stroke: st });
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)', EASE_IO = 'cubic-bezier(0.77, 0, 0.175, 1)';

  const LANGS = [['en', 'English', 'EN'], ['ar', 'العربية', 'AR'], ['fr', 'Français', 'FR'], ['es', 'Español', 'ES'], ['ur', 'اردو', 'UR']];
  const RTL = { ar: true, ur: true };

  /* ---------------------------------------------------------------- copy (5 launch languages) */
  const COPY = window.QISTAS_INTRO_COPY;

  /* ---------------------------------------------------------------- state */
  const params = new URLSearchParams(location.search);
  const state = { lang: 'en', country: 'SA', date: new Date().toISOString().slice(0, 10), mode: 'light', motion: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'reduced' : 'full', slide: 0, res: null };
  (function detect() {
    const nav = ((navigator.languages && navigator.languages[0]) || navigator.language || 'en').split('-');
    state.lang = COPY[nav[0]] ? nav[0] : 'en';
    const region = (nav[1] || '').toUpperCase();
    state.country = Q.COUNTRIES[region] ? region : ({ ar: 'SA', ur: 'PK', fr: 'FR', es: 'ES', en: 'US' })[state.lang];
    ['lang', 'country', 'date', 'mode', 'motion'].forEach((k) => { if (params.get(k)) state[k] = params.get(k); });
    if (!COPY[state.lang]) state.lang = 'en';
  })();

  const screen = $('#screen'), splash = $('#splash'), slidesEl = $('#slides');
  let anims = [], splashToken = 0;

  /* ---------------------------------------------------------------- theme */
  const nowFor = (iso) => new Date(iso + 'T12:00:00Z');
  function resolveTheme() { return Q.resolve({ themes: window.QISTAS_SEED.themes, country: state.country, now: nowFor(state.date) }); }
  function applyTheme() {
    const r = state.res = resolveTheme();
    Q.applyToDOM(screen, r.tokens, state.mode);
    const kinds = { base: 'Base', country: 'Country', event: 'Event' };
    const greeting = Q.t(r.copy, 'greeting', state.lang, '');
    const t = r.tokens[state.mode];
    $('#themeNow').innerHTML = '<div class="lbl">Resolved for ' + state.country + ' · ' + state.date + '</div>' +
      '<div class="row">' + r.applied.map((a) => '<span><b>' + esc(a.name.en) + '</b> <small>' + kinds[a.kind] + '</small></span>').join(' <span>→</span> ') + '</div>' +
      '<div class="row"><span class="sw" style="background:' + t.primary + '"></span><span class="sw" style="background:' + t.accent + '"></span><span class="sw" style="background:' + t.info + '"></span>' +
      (greeting ? '<span>Greeting: <b>' + esc(greeting) + '</b></span>' : '<span>No seasonal greeting</span>') + '</div>';
  }
  function findWindow(theme, country, fromISO) {
    const m = Q.COUNTRIES[country] || {}, ctx = { timeZone: m.tz || 'UTC', hijriOffsetDays: m.hijriOffsetDays || 0 }, t0 = +nowFor(fromISO);
    for (let i = 0; i < 800; i++) if (Q.isActive(theme, new Date(t0 + i * 864e5), ctx)) return new Date(t0 + i * 864e5);
    return null;
  }

  /* ---------------------------------------------------------------- formatting */
  function money(n) {
    const m = Q.COUNTRIES[state.country] || { currency: 'USD' };
    try { return new Intl.NumberFormat(state.lang + '-u-nu-latn', { style: 'currency', currency: m.currency, maximumFractionDigits: 0 }).format(n); } catch (e) { return String(n); }
  }
  function shortDate(offsetMonths) {
    const d = nowFor(state.date); d.setUTCMonth(d.getUTCMonth() + offsetMonths, 5);
    return new Intl.DateTimeFormat(state.lang + '-u-nu-latn', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(d);
  }
  const fill = (s) => s.replace('{amt}', money(450));

  /* ---------------------------------------------------------------- splash */
  /** Damped spring, normalised 0 -> 1 (with overshoot). Returns samples + the time of the first crossing. */
  function spring(k, c, ms, steps) {
    const w0 = Math.sqrt(k), z = c / (2 * w0), wd = w0 * Math.sqrt(1 - z * z), out = [];
    for (let i = 0; i <= steps; i++) {
      const t = (i / steps) * (ms / 1000);
      out.push(1 - Math.exp(-z * w0 * t) * (Math.cos(wd * t) + (z * w0 / wd) * Math.sin(wd * t)));
    }
    return { p: out, firstCross: ((Math.PI - Math.atan2(wd, z * w0)) / wd) * 1000 };
  }
  const SPRING = spring(90, 11, 950, 56);

  function latinMarkup() {
    const L = D.latin, g = D.geom, w = g.stroke, rx = g.qRing.r - w / 2, ry = g.qRing.r + g.overshoot - w / 2;
    const rest = L.letters.slice(1).map((l) => '<path class="ink l" d="' + l.ink + '"/>').join('');
    const bob = g.bob;
    return '<svg viewBox="-12 -46 450 206" role="img" aria-label="Qistas"><g id="wm"><g class="qg">' +
      '<path class="stroke-ink q-ring" d="M48 ' + (48 - ry) + 'A' + rx + ' ' + ry + ' 0 1 1 48 ' + (48 + ry) + 'A' + rx + ' ' + ry + ' 0 1 1 48 ' + (48 - ry) + 'Z" stroke-width="' + w + '" pathLength="1"/>' +
      '<rect class="ink q-stem" x="' + g.qStem.x + '" y="0" width="' + w + '" height="' + g.qStem.y2 + '"/>' +
      '<circle class="ripple r1" cx="' + bob.cx + '" cy="' + bob.cy + '" r="' + bob.r + '"/><circle class="ripple r2" cx="' + bob.cx + '" cy="' + bob.cy + '" r="' + bob.r + '"/>' +
      '<circle class="acc bob" cx="' + bob.cx + '" cy="' + bob.cy + '" r="' + bob.r + '"/></g><g class="rest">' + rest + '</g></g></svg>';
  }
  function arabicMarkup() {
    const A = D.arabic, vb = A.viewBox.join(' ');
    return '<div class="arabic-stack"><svg class="mask" viewBox="' + vb + '" role="img" aria-label="قسطاس"><path class="ink" d="' + A.ink + '"/></svg>' +
      '<svg viewBox="' + vb + '" aria-hidden="true" style="overflow:visible">' + A.dots.map((d) => '<circle class="ripple" cx="' + d.cx + '" cy="' + d.cy + '" r="' + d.r + '"/><circle class="acc dot" cx="' + d.cx + '" cy="' + d.cy + '" r="' + d.r + '"/>').join('') + '</svg></div>';
  }
  const SPLASH_CSS = '.splash-logo .q-ring{stroke-dasharray:1;stroke-dashoffset:1}.splash-logo .q-stem{transform-box:fill-box;transform-origin:50% 0;transform:scaleY(0)}' +
    '.splash-logo .bob{opacity:0}.splash-logo .rest .l{opacity:0}.splash-logo .ripple{transform-box:fill-box;transform-origin:center}.splash-logo .dot{opacity:0}' +
    '.splash-logo .arabic-stack .mask{-webkit-mask-position:0% 0;mask-position:0% 0}.splash-logo #wm{transform:translateX(var(--shift,165px))}' +
    '.splash-logo.final .q-ring{stroke-dashoffset:0!important}.splash-logo.final .q-stem{transform:none!important}.splash-logo.final .bob,.splash-logo.final .dot,.splash-logo.final .rest .l{opacity:1!important}' +
    '.splash-logo.final #wm{transform:none!important}.splash-logo.final .arabic-stack .mask{-webkit-mask-position:100% 0!important;mask-position:100% 0!important}.splash.final-tag .splash-tag{opacity:1!important}.splash.final-tag .splash-rule{transform:none!important}.splash.final-tag .glow{opacity:1!important}';
  (function () { const s = document.createElement('style'); s.textContent = SPLASH_CSS; document.head.appendChild(s); })();

  function splashLine() {
    const g = Q.t(state.res.copy, 'greeting', state.lang, '');
    return g || Q.t(state.res.copy, 'tagline', state.lang, 'The just balance.');
  }
  function buildSplash() {
    const arabic = !!RTL[state.lang];
    $('#splashLogo').innerHTML = arabic ? arabicMarkup() : latinMarkup();
    $('#splashLogo').style.setProperty('--shift', (D.latin.width / 2 - 48) + 'px');
    $('#splashLogo').style.width = arabic ? '286px' : '312px';
    const tag = $('#splashTag');
    tag.textContent = splashLine(); tag.setAttribute('lang', state.lang); tag.dir = RTL[state.lang] ? 'rtl' : 'ltr';
    tag.classList.toggle('q-nastaliq', state.lang === 'ur');
    $('#splashSkip').textContent = ({ en: 'Skip', ar: 'تخطّي', fr: 'Passer', es: 'Omitir', ur: 'چھوڑیں' })[state.lang];
    $('#splashLogo').classList.remove('final'); splash.classList.remove('final-tag', 'done');
  }
  function stopAnims() { anims.forEach((a) => { try { a.cancel(); } catch (e) { /* ignore */ } }); anims = []; }
  function showFinal() { $('#splashLogo').classList.add('final'); splash.classList.add('final-tag'); }

  async function playSplash() {
    const token = ++splashToken;
    stopAnims(); buildSplash();
    screen.classList.add('on-splash'); splash.classList.remove('done');
    const reduced = state.motion === 'reduced' || screen.classList.contains('reduced');
    if (reduced) {
      const logo = $('#splashLogo');
      showFinal(); logo.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 240, easing: 'linear' });
      await wait(1100); if (token === splashToken) finishSplash();
      return;
    }
    const arabic = !!RTL[state.lang];
    const add = (el, kf, opt) => { const a = el.animate(kf, Object.assign({ fill: 'both' }, opt)); anims.push(a); return a; };
    const tag = $('#splashTag'), rule = $('#splashRule'), glow = $('.glow', splash);
    const rise = [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }];
    let endAt;
    add(glow, [{ opacity: 0 }, { opacity: 1 }], { duration: 1400, delay: 150, easing: EASE_OUT });

    if (!arabic) {
      const g = D.geom, bobY = g.bob.cy, drop = 640, land = drop + SPRING.firstCross;
      add($('.q-ring'), [{ strokeDashoffset: 1 }, { strokeDashoffset: 0 }], { duration: 780, delay: 150, easing: EASE_OUT });
      add($('.q-stem'), SPRING.p.map((p) => ({ transform: 'scaleY(' + Math.min(1, Math.max(0, p)) + ')' })), { duration: 950, delay: drop, easing: 'linear' });
      add($('.bob'), SPRING.p.map((p, i) => ({ transform: 'translateY(' + ((p - 1) * bobY).toFixed(2) + 'px)', opacity: Math.min(1, i / 4) })), { duration: 950, delay: drop, easing: 'linear' });
      add($('.r1'), [{ opacity: 0.6, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(3.6)' }], { duration: 850, delay: land, easing: EASE_OUT });
      add($('.r2'), [{ opacity: 0.4, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(2.5)' }], { duration: 750, delay: land + 140, easing: EASE_OUT });
      const reveal = 1320;
      add($('#wm'), [{ transform: 'translateX(' + (D.latin.width / 2 - 48) + 'px)' }, { transform: 'translateX(0px)' }], { duration: 720, delay: reveal, easing: EASE_IO });
      $$('.rest .l').forEach((l, i) => add(l, [{ opacity: 0, transform: 'translateY(12px)' }, { opacity: 1, transform: 'none' }], { duration: 520, delay: reveal + 170 + i * 60, easing: EASE_OUT }));
      add(rule, [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: 560, delay: 2000, easing: EASE_OUT });
      add(tag, rise, { duration: 560, delay: 2080, easing: EASE_OUT });
      endAt = 2080 + 560 + 950;
    } else {
      const wipe = [{ maskPosition: '0% 0', webkitMaskPosition: '0% 0' }, { maskPosition: '100% 0', webkitMaskPosition: '100% 0' }];
      add($('.arabic-stack .mask'), wipe, { duration: 1100, delay: 200, easing: EASE_IO });
      $$('.arabic-stack .dot').forEach((d, i) => {
        const start = 1020 + i * 130, rp = $$('.arabic-stack .ripple')[i];
        add(d, SPRING.p.map((p, k) => ({ transform: 'translateY(' + ((p - 1) * 96).toFixed(2) + 'px)', opacity: Math.min(1, k / 4) })), { duration: 950, delay: start, easing: 'linear' });
        add(rp, [{ opacity: 0.55, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(3.4)' }], { duration: 760, delay: start + SPRING.firstCross, easing: EASE_OUT });
      });
      add(rule, [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: 560, delay: 1800, easing: EASE_OUT });
      add(tag, rise, { duration: 560, delay: 1880, easing: EASE_OUT });
      endAt = 1880 + 560 + 950;
    }
    const freeze = params.get('freeze');
    if (freeze != null) { anims.forEach((a) => { a.pause(); a.currentTime = +freeze; }); return; }
    await wait(endAt);
    if (token === splashToken) finishSplash();
  }
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  function finishSplash() {
    stopAnims(); showFinal();
    splash.classList.add('done'); screen.classList.remove('on-splash');
    setActive(state.slide, true);
  }

  /* ---------------------------------------------------------------- slides */
  function ring(pct, label) {
    return '<div class="mring"><svg viewBox="0 0 48 48"><circle class="bg" cx="24" cy="24" r="20"/><circle class="fg" cx="24" cy="24" r="20" pathLength="100" stroke-dasharray="' + pct + ' 100" transform="rotate(-90 24 24)"/></svg><b class="tnum">' + label + '</b></div>';
  }
  function art1(t) {
    const rows = [[-2, 'paid'], [-1, 'paid'], [0, 'due']];
    return '<div class="art art1"><div class="back2 fx" style="--i:0"></div><div class="back fx" style="--i:1"></div>' +
      '<article class="front card-s fx" style="--i:2"><div class="head"><span class="ico" style="--tint:var(--q-tint-sand)">' + ic('fileText', 20) + '</span><div><b>' + esc(t.a1.item) + '</b><small class="tnum">' + esc(fill(t.a1.terms)) + '</small></div>' + ring(58, '7/12') + '</div>' +
      '<ul>' + rows.map((r) => '<li><span class="tnum">' + esc(shortDate(r[0])) + '</span><b class="tnum">' + esc(money(450)) + '</b><em class="chip-s ' + r[1] + '">' + ic(r[1] === 'paid' ? 'check' : 'clock', 11, 2) + esc(r[1] === 'paid' ? t.a1.paid : t.a1.due) + '</em></li>').join('') + '</ul></article>' +
      '<div class="toast fx" style="--i:4">' + ic('check', 16, 2) + '<span>' + esc(t.a1.received) + ' · <span class="tnum">' + esc(money(450)) + '</span></span></div></div>';
  }
  function art2(t) {
    return '<div class="art art2"><div class="panel fx" style="--i:0"></div>' +
      '<div class="bubble fx" style="--i:1"><span class="who">' + esc(t.a2.who) + '</span><p>' + esc(fill(t.a2.msg)) + '</p><small class="tnum">09:30 ' + ic('check', 12, 2) + '</small></div>' +
      '<div class="risk card-s fx" style="--i:2">' + ring(82, '82') + '<div><b class="t">' + esc(t.a2.risk) + '</b><dl>' + t.a2.reasons.map((r, i) => '<div><dt>' + esc(r) + '</dt><dd class="tnum">' + esc(t.a2.vals[i]) + '</dd></div>').join('') + '</dl></div></div>' +
      '<span class="bellf fx" style="--i:3">' + ic('bell', 22) + '</span></div>';
  }
  function art3(t) {
    const icons = ['cloud', 'sheet', 'database'], tints = ['sky', 'mint', 'sand'];
    return '<div class="art art3">' + t.a3.opts.map((o, i) => '<div class="opt card-s fx ' + (i === 0 ? 'on' : '') + '" style="--i:' + i + '"><span class="ico" style="--tint:var(--q-tint-' + tints[i] + ')">' + ic(icons[i], 20) + '</span><div><b>' + esc(o) + '</b><small>' + esc(t.a3.sub[i]) + '</small></div><span class="tick">' + (i === 0 ? ic('check', 14, 2.4) : '') + '</span></div>').join('') +
      '<div class="backup fx" style="--i:3">' + ic('shield', 22) + '<div><b>' + esc(t.a3.backup) + '</b><small>' + esc(t.a3.ok) + '</small></div><i class="live"></i></div></div>';
  }
  function buildSlides() {
    const t = COPY[state.lang], arts = [art1, art2, art3];
    screen.dir = RTL[state.lang] ? 'rtl' : 'ltr'; screen.lang = state.lang;
    slidesEl.innerHTML = t.slides.map((s, i) => '<section class="slide" data-i="' + i + '" aria-roledescription="slide" aria-label="' + (i + 1) + ' / 3">' + arts[i](t) + '<div class="copy"><h2' + (state.lang === 'ur' ? ' class="q-nastaliq"' : '') + '>' + s[0] + '</h2><p>' + esc(s[1]) + '</p>' + (i === 2 ? '<span class="note-pill">' + ic('sparkles', 15) + esc(t.demo) + '</span>' : '') + '</div></section>').join('');
    $('#dots').innerHTML = [0, 1, 2].map((i) => '<button role="tab" aria-label="' + (i + 1) + '" data-i="' + i + '" aria-selected="false"></button>').join('');
    $('#langCode').textContent = LANGS.find((l) => l[0] === state.lang)[2];
    $('#langBtn').setAttribute('aria-label', ({ en: 'Language', ar: 'اللغة', fr: 'Langue', es: 'Idioma', ur: 'زبان' })[state.lang]);
    observe(); setActive(state.slide, false);
    requestAnimationFrame(() => goTo(state.slide, true));
  }
  let io = null;
  function observe() {
    if (io) io.disconnect();
    io = new IntersectionObserver((entries) => { entries.forEach((e) => { if (e.isIntersecting && e.intersectionRatio > 0.6) { state.slide = +e.target.dataset.i; setActive(state.slide, !splash.classList.contains('done') ? false : true); } }); }, { root: slidesEl, threshold: [0.6] });
    $$('.slide').forEach((s) => io.observe(s));
  }
  function setActive(i, animate) {
    const t = COPY[state.lang], last = i === 2;
    $$('.slide').forEach((s) => s.classList.toggle('on', animate && +s.dataset.i === i));
    $$('#dots button').forEach((b) => b.setAttribute('aria-selected', String(+b.dataset.i === i)));
    const fa = $('#footActions'); fa.className = 'foot-actions' + (last ? ' last' : '');
    fa.innerHTML = last
      ? '<button class="btn-next" data-go="start"><span>' + esc(t.start) + '</span><span class="arrow">' + ic('arrowRight', 18, 1.8) + '</span></button><button class="link-btn" data-go="login">' + esc(t.have) + '</button>'
      : '<button class="btn-ghost" data-go="skip">' + esc(t.skip) + '</button><button class="btn-next" data-go="next"><span>' + esc(t.next) + '</span><span class="arrow">' + ic('arrowRight', 18, 1.8) + '</span></button>';
  }
  function goTo(i, instant) {
    const s = $$('.slide')[i]; if (!s) return;
    const smooth = !instant && state.motion !== 'reduced';
    slidesEl.scrollTo({ left: s.offsetLeft - slidesEl.offsetLeft, behavior: smooth ? 'smooth' : 'auto' });
  }

  /* ---------------------------------------------------------------- language menu */
  function renderLangMenu() {
    $('#langMenu').innerHTML = LANGS.map((l) => '<li role="option" data-lang="' + l[0] + '" aria-selected="' + (l[0] === state.lang) + '" lang="' + l[0] + '"><span>' + l[1] + '</span>' + (l[0] === state.lang ? ic('check', 16, 2) : '') + '</li>').join('');
  }
  function setLang(l) {
    state.lang = l; applyTheme(); renderLangMenu(); renderControls(); buildSlides();
    if (!splash.classList.contains('done')) buildSplash();
    $('#langMenu').hidden = true; $('#langBtn').setAttribute('aria-expanded', 'false');
  }

  /* ---------------------------------------------------------------- controls */
  function renderControls() {
    $('#ctlLang').innerHTML = LANGS.map((l) => '<button aria-pressed="' + (l[0] === state.lang) + '" data-lang="' + l[0] + '" lang="' + l[0] + '">' + l[1] + '</button>').join('');
    $('#ctlCountry').innerHTML = Object.keys(Q.COUNTRIES).map((c) => '<option value="' + c + '"' + (c === state.country ? ' selected' : '') + '>' + c + ' · ' + Q.COUNTRIES[c].name + '</option>').join('');
    $('#ctlDate').value = state.date;
    const events = window.QISTAS_SEED.themes.filter((t) => t.kind === 'event' && t.status === 'published' && (t.scope.countries.includes('*') || t.scope.countries.includes(state.country)));
    const active = new Set(state.res.applied.map((a) => a.id));
    $('#ctlJump').innerHTML = '<button data-jump="today" aria-pressed="false">Today</button>' + events.map((e) => '<button data-jump="' + e.id + '" aria-pressed="' + active.has(e.id) + '">' + esc(e.name.en.replace(/ \(.*\)/, '')) + '</button>').join('');
    $$('#ctlMode button').forEach((b) => b.classList.toggle('on', b.dataset.v === state.mode));
    $$('#ctlMotion button').forEach((b) => b.classList.toggle('on', b.dataset.v === state.motion));
  }
  function refreshAll() { applyTheme(); renderControls(); const wasSplash = !splash.classList.contains('done'); buildSlides(); if (wasSplash) buildSplash(); }

  document.addEventListener('click', (e) => {
    const t = e.target;
    const go = t.closest('[data-go]');
    if (go) {
      const a = go.dataset.go;
      if (a === 'next') goTo(Math.min(2, state.slide + 1));
      else if (a === 'skip') goTo(2);
      else { go.animate([{ transform: 'scale(1)' }, { transform: 'scale(.97)' }, { transform: 'scale(1)' }], { duration: 220, easing: EASE_OUT }); }
      return;
    }
    const dot = t.closest('#dots button'); if (dot) { goTo(+dot.dataset.i); return; }
    if (t.closest('#langBtn')) { const m = $('#langMenu'); m.hidden = !m.hidden; $('#langBtn').setAttribute('aria-expanded', String(!m.hidden)); return; }
    const li = t.closest('#langMenu li'); if (li) { setLang(li.dataset.lang); return; }
    if (!t.closest('#langMenu')) { $('#langMenu').hidden = true; $('#langBtn').setAttribute('aria-expanded', 'false'); }
    const cl = t.closest('#ctlLang button'); if (cl) { setLang(cl.dataset.lang); return; }
    const jump = t.closest('#ctlJump button');
    if (jump) {
      if (jump.dataset.jump === 'today') state.date = new Date().toISOString().slice(0, 10);
      else { const ev = window.QISTAS_SEED.themes.find((x) => x.id === jump.dataset.jump), d = findWindow(ev, state.country, new Date().toISOString().slice(0, 10)); if (d) state.date = d.toISOString().slice(0, 10); }
      refreshAll(); return;
    }
    const mode = t.closest('#ctlMode button'); if (mode) { state.mode = mode.dataset.v; applyTheme(); renderControls(); return; }
    const mot = t.closest('#ctlMotion button'); if (mot) { state.motion = mot.dataset.v; screen.classList.toggle('reduced', state.motion === 'reduced'); renderControls(); return; }
    if (t.closest('#replay')) { replay(); return; }
    if (t.closest('#splashSkip')) { splashToken++; finishSplash(); }
  });
  $('#ctlCountry').addEventListener('change', (e) => { state.country = e.target.value; refreshAll(); });
  $('#ctlDate').addEventListener('change', (e) => { if (e.target.value) { state.date = e.target.value; refreshAll(); } });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $('#langMenu').hidden = true; $('#langBtn').setAttribute('aria-expanded', 'false'); } });

  function replay() { state.slide = 0; goTo(0, true); setActive(0, false); playSplash(); }

  /* ---------------------------------------------------------------- page chrome */
  function fit() {
    const dev = $('#device'), wrap = $('.device-wrap');
    const vw = window.innerWidth, h = window.innerHeight - 96, w = (vw < 1000 ? vw - 32 : (wrap.clientWidth || vw)) - 8;
    dev.style.setProperty('--fit', String(Math.max(0.5, Math.min(1, h / 840, w / 414))));
  }
  window.addEventListener('resize', fit);

  function boot() {
    $('#brandLink').innerHTML = LG.svg('wordmark', { title: 'Qistas' });
    $('#miniLogo').innerHTML = LG.svg('wordmark', { title: 'Qistas' });
    $$('[data-ic]').forEach((el) => { el.innerHTML = ic(el.dataset.ic, 16); el.style.display = 'inline-flex'; });
    screen.classList.toggle('reduced', state.motion === 'reduced');
    applyTheme(); renderLangMenu(); renderControls(); buildSlides(); fit();
    if (params.get('slide')) { state.slide = +params.get('slide'); }
    if (params.get('splash') === '0') { buildSplash(); splash.classList.add('done'); screen.classList.remove('on-splash'); showFinal(); requestAnimationFrame(() => { goTo(state.slide, true); setActive(state.slide, true); }); }
    else playSplash();
  }
  boot();
  window.__intro = { state, replay, playSplash };
})();
