#!/usr/bin/env node
/** One command: start the vercel-sim server, run the smoke test and the rendered-DOM link check, stop the server. */
const { spawn } = require('node:child_process');
const path = require('node:path');
const { server } = require('./serve-vercel.cjs');

const PORT = Number(process.env.PORT || 5276);
const base = 'http://127.0.0.1:' + PORT;
const run = (script) => new Promise((resolve) => {
  const p = spawn(process.execPath, [path.join(__dirname, script), base], { stdio: 'inherit' });
  p.on('close', (code) => resolve(code));
});

server.listen(PORT, '127.0.0.1', async () => {
  const smoke = await run('smoke.cjs');
  const links = await run('check-links.cjs');
  server.close();
  process.exit(smoke || links ? 1 : 0);
});
