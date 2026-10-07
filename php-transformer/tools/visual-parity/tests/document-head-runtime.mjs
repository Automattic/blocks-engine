import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

// Execute the compiled head's real local assets in a browser parser. This is
// deliberately a head contract gate; the parent runs the full WP import gate.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const output = await fs.mkdtemp(path.join(process.env.BLOCKS_ENGINE_ARTIFACT_ROOT ?? process.env.TMPDIR ?? '/tmp', 'blocks-engine-head-'));
const build = path.join(output, 'build');
const server = http.createServer(async (request, response) => {
  try {
    const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
    const file = path.resolve(build, '.' + pathname);
    if (!file.startsWith(build + path.sep)) throw new Error('Outside evidence root');
    const body = await fs.readFile(file);
    response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'text/html');
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
  execFileSync('php', [path.join(root, 'tools/document-head-runtime/build.php'), `--output=${build}`, `--theme-uri=${origin}/theme`], { stdio: 'pipe' });
  browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`${origin}/parser-contract-0.html`, { waitUntil: 'load' });
  const state = await page.evaluate(() => ({
    viewports: document.querySelectorAll('meta[name=viewport]').length,
    viewport: document.querySelector('meta[data-selected-viewport]')?.content,
    first: window.firstHeadState,
    second: window.secondHeadState,
    secondViewport: window.viewportAtSecondScript,
    tags: [...document.head.children].map(element => element.tagName.toLowerCase()),
    scope: document.querySelector('style[data-document-scope]')?.dataset.documentScope,
    styleMedia: document.querySelector('style[data-document-scope]')?.media,
    linkMedia: document.querySelector('link[data-source-media]')?.media,
    authoredMedia: document.querySelector('link[data-source-media]')?.dataset.sourceMedia,
  }));
  assert.deepEqual(errors, []);
  assert.equal(state.viewports, 1);
  assert.equal(state.viewport, 'width=320, user-scalable=yes');
  assert.equal(state.secondViewport, state.viewport);
  assert.deepEqual(state.first, { bodyAbsent: true, styleAbsent: true });
  assert.deepEqual(state.second, { bodyAbsent: true, stylePresent: true, linkPresent: true });
  assert.deepEqual(state.tags, ['meta', 'meta', 'script', 'style', 'style', 'link', 'script']);
  assert.equal(state.scope, 'phone');
  assert.equal(state.styleMedia, 'not all');
  assert.equal(state.linkMedia, 'not all');
  assert.equal(state.authoredMedia, 'screen');
  console.log(JSON.stringify({ gate: 'document-head-browser-parser', status: 'pass', state, evidence: output }, null, 2));
} finally {
  await browser?.close();
  await new Promise(resolve => server.close(resolve));
}
