#!/usr/bin/env node
/**
 * Rendered-DOM link checker.  Loads each page in headless Chrome (so links created by JavaScript are
 * included), collects every href/src, and verifies that
 *   - internal URLs answer 200 (after redirects),
 *   - in-page anchors (#id) point at an element that exists,
 *   - external URLs are listed (not fetched) so they can be reviewed.
 *
 *   node scripts/check-links.cjs [baseUrl]
 * Chrome is found via CHROME_BIN or the usual install locations (Windows, macOS, Linux/CI).
 */
const fs = require('node:fs');
const { spawn } = require('node:child_process');

const base = (process.argv[2] || process.env.BASE_URL || 'http://127.0.0.1:5274').replace(/\/$/, '');
const PAGES = ['/', '/brand/', '/brand/intro/', '/brand/theme-studio/', '/definitely-missing/'];

function chrome() {
  const c = [process.env.CHROME_BIN, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
  return c.find((p) => p && fs.existsSync(p));
}
function dumpDom(bin, url) {
  return new Promise((resolve, reject) => {
    const p = spawn(bin, ['--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars', '--virtual-time-budget=7000', '--dump-dom', url], { stdio: ['ignore', 'pipe', 'ignore'] });
    let out = ''; p.stdout.on('data', (d) => { out += d; });
    p.on('error', reject); p.on('close', () => resolve(out));
  });
}
function attrs(html) {
  const links = [], ids = new Set();
  for (const m of html.matchAll(/\b(?:href|src|poster)=["']([^"']+)["']/gi)) links.push(m[1]);
  for (const m of html.matchAll(/\bid=["']([^"']+)["']/gi)) ids.add(m[1]);
  return { links, ids };
}
async function status(url) {
  let u = url;
  for (let i = 0; i < 4; i++) {
    const r = await fetch(u, { redirect: 'manual', method: 'GET' });
    if ([301, 302, 307, 308].includes(r.status)) { u = new URL(r.headers.get('location'), u).href; continue; }
    return r.status;
  }
  return 'redirect loop';
}

(async () => {
  const bin = chrome();
  if (!bin) { console.error('No Chrome/Chromium found. Set CHROME_BIN.'); process.exit(2); }
  console.log('\nLink check: ' + base + '  (renderer: ' + bin.split(/[\\/]/).pop() + ')\n');
  const seen = new Map(), externals = new Set(), problems = [];
  let pageCount = 0;
  for (const page of PAGES) {
    const url = base + page, html = await dumpDom(bin, url);
    if (html.length < 500) { problems.push(`${page}: rendered DOM is empty (${html.length} bytes)`); continue; }
    pageCount++;
    const { links, ids } = attrs(html);
    for (const raw of links) {
      if (/^(data:|mailto:|tel:|javascript:|blob:)/i.test(raw)) continue;
      if (raw.startsWith('#')) { if (raw.length > 1 && !ids.has(raw.slice(1))) problems.push(`${page}: anchor ${raw} has no target`); continue; }
      const abs = new URL(raw, url);
      if (abs.origin !== new URL(base).origin) { externals.add(abs.href.split('#')[0]); continue; }
      const key = abs.origin + abs.pathname;
      if (!seen.has(key)) seen.set(key, status(abs.href.split('#')[0]));
      const st = await seen.get(key);
      if (st !== 200 && !(page === '/definitely-missing/' && false)) problems.push(`${page}: ${abs.pathname} -> ${st}`);
    }
    console.log(`  ${String(links.length).padStart(3)} references  ${page}`);
  }
  console.log(`\n${seen.size} internal URLs checked across ${pageCount} pages; ${externals.size} external URLs not fetched:`);
  [...externals].sort().forEach((e) => console.log('    ' + e));
  if (problems.length) { console.log('\nProblems:'); problems.forEach((p) => console.log('  FAIL ' + p)); console.log(''); process.exit(1); }
  console.log('\nAll internal links resolve.\n');
})();
