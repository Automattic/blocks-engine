import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { serveSourceRoot } from '../bin/source-root-server.mjs';

// A captured source root whose shared chrome is an artifact include must be
// measured as the resolved document compilation sees, never as raw comments.
const fixture = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures/source-root-includes');
const read = name => fs.readFile(path.join(fixture, name), 'utf8');
const raw = await read('index.html');
const expected = raw.replace('<!--#include virtual="/parts/footer-shell.html" -->', (await read('parts/footer-shell.html')).replace('<!--#include virtual="/parts/footer-row.html" -->', await read('parts/footer-row.html')));
assert.notEqual(expected, raw);

const invalid = await fs.mkdtemp(path.join(process.env.BLOCKS_ENGINE_ARTIFACT_ROOT ?? os.tmpdir(), 'source-root-includes-'));
await fs.mkdir(path.join(invalid, 'parts'));
await fs.writeFile(path.join(invalid, 'missing.html'), '<body><!--#include virtual="/parts/absent.html" --></body>');
await fs.writeFile(path.join(invalid, 'cycle.html'), '<body><!--#include virtual="/parts/loop-a.html" --></body>');
await fs.writeFile(path.join(invalid, 'parts/loop-a.html'), '<!--#include virtual="/parts/loop-b.html" -->');
await fs.writeFile(path.join(invalid, 'parts/loop-b.html'), '<!--#include virtual="/parts/loop-a.html" -->');
await fs.writeFile(path.join(invalid, 'malformed.html'), '<body><!--#include file="parts/loop-a.html" --></body>');

const server = await serveSourceRoot(fixture);
const rejecting = await serveSourceRoot(invalid);
const browser = await chromium.launch({ headless: true });
try {
  const served = await fetch(`${server.origin}/`);
  assert.equal(served.status, 200);
  assert.equal(await served.text(), expected, 'Only include comments change; nested fragments resolve byte-for-byte.');
  assert.deepEqual(server.failures, []);

  const page = await browser.newPage({ viewport: { width: 600, height: 400 } });
  const measure = () => page.evaluate(() => ({ documentWidth: document.documentElement.scrollWidth, footers: document.querySelectorAll('footer.shell .item').length }));
  await page.setContent(raw);
  assert.deepEqual(await measure(), { documentWidth: 600, footers: 0 }, 'Unresolved source omits included chrome and its overflow.');
  await page.goto(`${server.origin}/`);
  assert.deepEqual(await measure(), { documentWidth: 850, footers: 1 }, 'Resolved source keeps the positioned fragment that extends document width.');

  for (const [name, reason] of [['missing', 'html_include_missing_path'], ['cycle', 'html_include_cycle'], ['malformed', 'html_include_invalid_directive']]) {
    const response = await fetch(`${rejecting.origin}/${name}.html`);
    assert.equal(response.status, 500, `${name} include graph rejects`);
    assert.match(rejecting.failures.at(-1).error, new RegExp(reason));
  }
  assert.equal(rejecting.failures.length, 3);
  console.log('source-root-includes: resolved source document width 850 (raw 600); invalid include graphs reject');
} finally {
  await browser.close();
  await server.close();
  await rejecting.close();
  await fs.rm(invalid, { recursive: true, force: true });
}
