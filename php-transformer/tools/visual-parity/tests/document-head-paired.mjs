import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

// Source-vs-emitted head parser proof for a supplied portable artifact. The
// observation script is injected into both HTTP responses at the end of head;
// it observes actual blocking execution before either document's body parses.
const input = process.argv[2];
const evidenceRoot = process.argv[3];
assert.ok(input && evidenceRoot, 'Usage: node document-head-paired.mjs <input.json> <fresh evidence directory>');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const checkpoint = `<script>window.__headCheckpoint={bodyAbsent:document.body===null,viewports:[...document.querySelectorAll('meta[name="viewport"]')].map(e=>({content:e.getAttribute('content'),id:e.id})),selectedDocument:document.documentElement.getAttribute('data-dla-selected-document'),styles:[...document.head.querySelectorAll('style,link[rel="stylesheet"]')].map(e=>({tag:e.tagName.toLowerCase(),media:e.getAttribute('media'),attributes:Object.fromEntries([...e.attributes].filter(a=>a.name.startsWith('data-')).map(a=>[a.name,a.value]))}))};</script>`;
const server = http.createServer(async (request, response) => {
  try {
    const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
    const file = path.resolve(evidenceRoot, '.' + pathname);
    if (!file.startsWith(path.resolve(evidenceRoot) + path.sep)) throw new Error('Outside evidence root');
    let body = await fs.readFile(file);
    if (file.endsWith('.html')) body = Buffer.from(body.toString().replace('</head>', checkpoint + '</head>'));
    response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : file.endsWith('.html') ? 'text/html' : 'application/octet-stream');
    response.end(body);
  } catch {
    response.statusCode = 404;
    response.end();
  }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
  execFileSync('php', [path.join(root, 'tools/document-head-runtime/build.php'), `--input=${input}`, `--output=${evidenceRoot}`, `--theme-uri=${origin}/theme`], { stdio: 'pipe' });
  const artifact = JSON.parse(await fs.readFile(path.join(evidenceRoot, 'input.json'), 'utf8'));
  browser = await chromium.launch({ headless: true });
  const results = [];
  const userAgents = [
    ['desktop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36'],
    ['phone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'],
    ['tablet', 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1'],
  ];
  for (const [device, userAgent] of userAgents) {
    const context = await browser.newContext({ userAgent });
    const observations = {};
    for (const [kind, url] of [['source', `${origin}/source/${artifact.entrypoint}`], ['emitted', `${origin}/parser-contract-0.html`]]) {
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(url, { waitUntil: 'load' });
      const state = await page.evaluate(() => window.__headCheckpoint);
      assert.ok(state?.bodyAbsent, `${device}/${kind}: blocking head checkpoint precedes body`);
      observations[kind] = { state, errors };
      await page.close();
    }
    assert.deepEqual(observations.emitted.state, observations.source.state, `${device}: actual source and emitted head state agree`);
    assert.equal(observations.emitted.state.viewports.length, 1, `${device}: unique viewport`);
    results.push({ device, ...observations });
    await context.close();
  }
  const evidence = { gate: 'paired-source-emitted-head-parser', status: 'pass', results, boundary: 'Head parser contract only; real WordPress import, body/layout, interactivity and complete-site parity remain parent gates.' };
  await fs.writeFile(path.join(evidenceRoot, 'browser-head-proof.json'), JSON.stringify(evidence, null, 2) + '\n');
  console.log(JSON.stringify({ gate: evidence.gate, status: evidence.status, evidence: path.join(evidenceRoot, 'browser-head-proof.json'), devices: results.map(result => ({ device: result.device, viewport: result.emitted.state.viewports, selectedDocument: result.emitted.state.selectedDocument, bodyAbsent: result.emitted.state.bodyAbsent, stylesheetCount: result.emitted.state.styles.length, sourceErrors: result.source.errors, emittedErrors: result.emitted.errors })) }, null, 2));
} finally {
  await browser?.close();
  await new Promise(resolve => server.close(resolve));
}
