#!/usr/bin/env node
/**
 * Local static server that applies vercel.json (redirects, rewrites, headers, trailingSlash) so the
 * deployment behaviour can be tested before it reaches Vercel.  Dependency-free.
 *
 *   node scripts/serve-vercel.cjs [port]        (default 5274)
 *
 * Supported vercel.json subset: trailingSlash, redirects, rewrites, headers, with sources using
 * literal paths, `(.*)`, `:name` and `:name*`.  Same precedence as Vercel: redirects -> filesystem -> rewrites.
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');
const PORT = Number(process.argv[2] || process.env.PORT || 5274);
const CONFIG = JSON.parse(fs.readFileSync(path.join(ROOT, 'vercel.json'), 'utf8'));
const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.cjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp',
  '.ico': 'image/x-icon', '.webmanifest': 'application/manifest+json', '.txt': 'text/plain; charset=utf-8', '.md': 'text/markdown; charset=utf-8', '.woff2': 'font/woff2'
};

function compile(source) {
  let re = '^';
  let i = 0;
  while (i < source.length) {
    const rest = source.slice(i);
    let m;
    if ((m = /^\(\.\*\)/.exec(rest))) { re += '(.*)'; i += m[0].length; }
    else if ((m = /^:([A-Za-z_]+)\*/.exec(rest))) { re += '(?<' + m[1] + '>.*)'; i += m[0].length; }
    else if ((m = /^:([A-Za-z_]+)/.exec(rest))) { re += '(?<' + m[1] + '>[^/]+)'; i += m[0].length; }
    else { re += source[i].replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); i++; }
  }
  return new RegExp(re + '$');
}
const redirects = (CONFIG.redirects || []).map((r) => ({ re: compile(r.source), r }));
const rewrites = (CONFIG.rewrites || []).map((r) => ({ re: compile(r.source), r }));
const headerRules = (CONFIG.headers || []).map((h) => ({ re: compile(h.source), h }));

function fill(dest, groups) { return dest.replace(/:([A-Za-z_]+)\*?/g, (_, n) => (groups && groups[n] != null ? groups[n] : '')); }
function fileFor(urlPath) {
  const p = path.normalize(path.join(ROOT, decodeURIComponent(urlPath)));
  if (!p.startsWith(ROOT)) return null;
  try {
    const st = fs.statSync(p);
    if (st.isDirectory()) { const idx = path.join(p, 'index.html'); return fs.existsSync(idx) ? idx : null; }
    return st.isFile() ? p : null;
  } catch (e) { return null; }
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  let pathname = url.pathname;
  const send = (status, body, extra) => {
    const headers = Object.assign({}, extra || {});
    headerRules.forEach(({ re, h }) => { if (re.test(pathname)) h.headers.forEach((x) => { headers[x.key] = x.value; }); });
    res.writeHead(status, headers); res.end(body);
  };

  // 1. redirects
  for (const { re, r } of redirects) {
    const m = re.exec(pathname);
    if (m) return send(r.permanent ? 308 : 307, '', { Location: fill(r.destination, m.groups) + url.search });
  }
  // 2. trailing slash for extension-less paths that are directories
  const hasExt = path.extname(pathname) !== '';
  if (CONFIG.trailingSlash === true && !hasExt && !pathname.endsWith('/') && fileFor(pathname)) {
    return send(308, '', { Location: pathname + '/' + url.search });
  }
  // 3. filesystem, then rewrites
  let file = fileFor(pathname);
  if (!file) for (const { re, r } of rewrites) { const m = re.exec(pathname); if (m) { file = fileFor(fill(r.destination, m.groups)); if (file) break; } }
  if (!file) {
    const nf = path.join(ROOT, '404.html');
    return send(404, fs.existsSync(nf) ? fs.readFileSync(nf) : 'Not found', { 'Content-Type': MIME['.html'] });
  }
  const ext = path.extname(file).toLowerCase();
  send(200, req.method === 'HEAD' ? '' : fs.readFileSync(file), { 'Content-Type': MIME[ext] || 'application/octet-stream' });
});

if (require.main === module) {
  server.listen(PORT, '127.0.0.1', () => console.log('Serving ' + ROOT + ' with vercel.json rules at http://127.0.0.1:' + PORT));
}
module.exports = { server, compile };
