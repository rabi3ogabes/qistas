#!/usr/bin/env node
/**
 * Smoke test for a running Qistas site (local vercel-sim server or production).
 *
 *   node scripts/smoke.cjs [baseUrl] [--deployed]
 *
 * --deployed additionally asserts that files excluded by .vercelignore are NOT reachable and that
 * Strict-Transport-Security is present (only meaningful on the real deployment).
 */
const args = process.argv.slice(2);
const deployed = args.includes('--deployed');
const base = (args.find((a) => !a.startsWith('--')) || process.env.BASE_URL || 'http://127.0.0.1:5274').replace(/\/$/, '');
const waitArg = args.find((a) => a.startsWith('--wait=')); const waitSeconds = waitArg ? Number(waitArg.split('=')[1]) : 0;

let failed = 0, passed = 0;
const ok = (name) => { passed++; console.log('  ok   ' + name); };
const bad = (name, why) => { failed++; console.log('  FAIL ' + name + '\n       ' + why); };
async function check(name, fn) { try { const r = await fn(); if (r === true || r === undefined) ok(name); else bad(name, String(r)); } catch (e) { bad(name, e.message || String(e)); } }
const get = (p, opts) => fetch(base + p, Object.assign({ redirect: 'manual' }, opts || {}));
const must = (cond, msg) => { if (!cond) throw new Error(msg); };
/** Follow redirects like a browser (Vercel may normalise the trailing slash before applying a redirect rule). */
async function follow(p) {
  let url = base + p, hops = 0;
  for (;;) {
    const r = await fetch(url, { redirect: 'manual' });
    if ([301, 302, 307, 308].includes(r.status) && hops < 4) { url = new URL(r.headers.get('location'), url).href; hops++; continue; }
    return { status: r.status, path: new URL(url).pathname, hops };
  }
}

(async () => {
  console.log('\nSmoke test: ' + base + (deployed ? '  (deployed checks on)' : '') + '\n');

  console.log('Pages');
  const pages = [['/', 'Test Qistas'], ['/brand/', 'Brand system'], ['/brand/intro/', 'Replay intro'], ['/brand/theme-studio/', 'Theme Studio']];
  for (const [p, marker] of pages) {
    await check(`GET ${p} -> 200 HTML containing "${marker}"`, async () => {
      const r = await get(p); must(r.status === 200, 'status ' + r.status);
      must((r.headers.get('content-type') || '').includes('text/html'), 'content-type ' + r.headers.get('content-type'));
      must((await r.text()).includes(marker), 'marker not found');
    });
  }

  console.log('\nRedirects and trailing slashes');
  const redirs = [['/intro', '/brand/intro/'], ['/studio', '/brand/theme-studio/'], ['/theme-studio', '/brand/theme-studio/'], ['/brand', '/brand/'], ['/brand/intro', '/brand/intro/'], ['/brand/theme-studio', '/brand/theme-studio/']];
  for (const [from, to] of redirs) {
    await check(`${from} -> ${to}`, async () => {
      const r = await follow(from);
      must(r.hops >= 1 && r.hops <= 2, 'expected 1-2 redirects, got ' + r.hops);
      must(r.path === to && r.status === 200, `ended at ${r.path} (${r.status})`);
    });
  }

  console.log('\nIcons, manifest, robots');
  for (const [p, type] of [['/favicon.ico', /image\//], ['/favicon.svg', /svg/], ['/apple-touch-icon.png', /png/], ['/brand/logo/qistas-og-card.png', /png/]]) {
    await check(`GET ${p} -> 200 ${type}`, async () => { const r = await get(p); must(r.status === 200, 'status ' + r.status); must(type.test(r.headers.get('content-type') || ''), 'type ' + r.headers.get('content-type')); });
  }
  await check('robots.txt disallows everything while this is a test deployment', async () => { const r = await get('/robots.txt'); must(r.status === 200, 'status ' + r.status); must(/Disallow:\s*\//.test(await r.text()), 'no Disallow'); });
  await check('manifest is valid and every icon resolves', async () => {
    const r = await get('/brand/logo/web/site.webmanifest'); must(r.status === 200, 'status ' + r.status);
    const m = JSON.parse(await r.text()); must(m.name === 'Qistas' && m.start_url === '/', 'name/start_url'); must(m.icons.length >= 3, 'icons');
    for (const i of m.icons) { const ir = await get(i.src); must(ir.status === 200, i.src + ' -> ' + ir.status); }
  });

  console.log('\nNot found');
  await check('unknown path -> 404 with the branded page', async () => { const r = await get('/definitely-missing/'); must(r.status === 404, 'status ' + r.status); must((await r.text()).includes('out of balance'), 'not the branded 404'); });

  console.log('\nSecurity and caching headers');
  const h = (await get('/')).headers;
  await check('Content-Security-Policy restricts scripts to self', () => { const c = h.get('content-security-policy') || ''; must(c.includes("default-src 'self'") && c.includes("script-src 'self'") && c.includes("frame-ancestors 'none'") && c.includes("object-src 'none'"), c || 'missing'); });
  await check('CSP does not allow unsafe-inline scripts or eval', () => { const c = h.get('content-security-policy') || ''; const scriptSrc = (c.match(/script-src[^;]*/) || [''])[0]; must(!/unsafe-/.test(scriptSrc), scriptSrc); });
  for (const [k, re] of [['x-content-type-options', /nosniff/], ['x-frame-options', /DENY/i], ['referrer-policy', /strict-origin/], ['permissions-policy', /camera=\(\)/], ['cross-origin-opener-policy', /same-origin/], ['x-robots-tag', /noindex/]]) {
    await check(`${k} present`, () => must(re.test(h.get(k) || ''), h.get(k) || 'missing'));
  }
  await check('logo assets are cacheable (1 h + stale-while-revalidate)', async () => { const r = await get('/brand/logo/favicon.svg'); const c = r.headers.get('cache-control') || ''; must(/max-age=3600/.test(c), c || 'missing'); });
  if (deployed) {
    await check('Strict-Transport-Security present', () => must(/max-age=\d+/.test(h.get('strict-transport-security') || ''), h.get('strict-transport-security') || 'missing'));
    console.log('\nFiles that must NOT be public');
    for (const p of ['/supabase/migrations/20261007000100_theme_engine.sql', '/docs/THEME_ENGINE.md', '/brand/tools/build_logo.py', '/scripts/smoke.cjs', '/package.json', '/.git/config', '/.vercelignore']) {
      await check(`${p} -> 404`, async () => { const r = await follow(p); must(r.status === 404, 'status ' + r.status + ' at ' + r.path); });
    }
  }

  console.log(`\n${passed} passed, ${failed} failed\n`);
  process.exit(failed ? 1 : 0);
})();
