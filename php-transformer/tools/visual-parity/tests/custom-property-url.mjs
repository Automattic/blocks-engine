import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const { writes, expected, markup } = JSON.parse(execFileSync('php', [root + '/tests/unit/custom-property-url.php', '--json'], { encoding: 'utf8' }));
const files = new Map(writes.map(write => ['/themes/neutral/' + write.target_path, write]));
const css = writes.filter(write => write.target_path.endsWith('.css')).map(write => `<link rel="stylesheet" href="/themes/neutral/${write.target_path}">`).join('');
const server = createServer((req, res) => {
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html'); res.end(`<!doctype html>${css}${markup}`); return; }
  const write = files.get(req.url);
  if (!write) { res.writeHead(404); res.end(); return; }
  res.setHeader('Content-Type', write.target_path.endsWith('.css') ? 'text/css' : 'image/svg+xml');
  res.end(Buffer.from(write.payload.data, write.payload.encoding === 'base64' ? 'base64' : 'utf8'));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage();
  await page.goto(origin);
  const mask = await page.locator('.a i').evaluate(node => getComputedStyle(node).maskImage);
  console.log(`computed mask-image: ${mask}`);
  assert.equal(mask, `url("${origin}${expected}")`);
  assert.equal((await page.request.get(origin + expected)).status(), 200);
} finally {
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}
