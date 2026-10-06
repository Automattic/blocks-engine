import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const evidence = await fs.mkdtemp(path.join(process.env.BLOCKS_ENGINE_ARTIFACT_ROOT ?? process.env.TMPDIR ?? '/tmp', 'declared-document-body-'));
const build = path.join(evidence, 'build');
const server = http.createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    const file = path.resolve(build, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(build + path.sep)) throw new Error('Outside evidence root');
    response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'text/html');
    response.end(await fs.readFile(file));
  } catch { response.statusCode = 404; response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
try {
  const args = [path.join(root, 'tools/benchmarks/body-scope-evidence.php'), '--neutral', `--output=${build}`, `--theme-uri=${origin}/theme`];
  if (process.env.WP_CORE_PARSER_DIR) args.push(`--wp-parser=${process.env.WP_CORE_PARSER_DIR}`);
  execFileSync('php', args, { stdio: 'pipe' });
  const result = JSON.parse(await fs.readFile(path.join(build, 'result.json'), 'utf8'));
  const collect = (blocks, found = []) => { for (const block of blocks) { if (block.blockName?.endsWith('/authored-button') && block.attrs?.tagName === 'div') found.push(block); collect(block.innerBlocks ?? [], found); } return found; };
  const roleBlocks = collect(result.blocks);
  assert.equal(roleBlocks.length, 4);
  const definition = result.source_reports.companion_plugin_payload.blocks.find(block => block.block_json?.name?.endsWith('/authored-button'));
  let save;
  const RawHTML = function RawHTML() {};
  vm.runInNewContext(definition.assets['index.js'], { window: { wp: {
    blocks: { registerBlockType: (name, settings) => { save = settings.save; } },
    blockEditor: { InspectorControls() {} },
    components: { PanelBody() {}, TextControl() {}, SelectControl() {}, ToggleControl() {} },
    element: { RawHTML, Fragment: 'Fragment', createElement: (type, props, ...children) => type === RawHTML ? children[0] : { type, props, children } },
  } } });
  for (const block of roleBlocks) assert.equal(save({ attributes: block.attrs }), block.innerHTML, 'Real companion save() equals canonical PHP source-control markup');
  let generated = await fs.readFile(path.join(build, 'frontend-0.html'), 'utf8');
  for (const block of roleBlocks) {
    const reloaded = JSON.parse(JSON.stringify({ ...block.attrs, ariaLabel: 'Edited navigation label' }));
    const saved = save({ attributes: reloaded });
    assert.ok(saved.includes('aria-label="Edited navigation label"'));
    assert.ok(saved.includes('role="button"') && saved.includes('tabindex="0"'));
    generated = generated.replace(block.innerHTML, saved);
  }
  await fs.writeFile(path.join(build, 'editor-saved.html'), generated);
  browser = await chromium.launch({ headless: true });
  const observations = [];
  for (const profile of ['default', 'compact', 'slate', 'wide']) {
    const states = {};
    for (const [kind, url] of [['source', `${origin}/source/index.html?profile=${profile}`], ['generated', `${origin}/frontend-0.html?profile=${profile}`], ['editorSaved', `${origin}/editor-saved.html?profile=${profile}`]]) {
      const page = await browser.newPage();
      await page.route('https://example.test/**', route => route.fulfill({ contentType: 'text/html', body: '<p>Independent frame</p>' }));
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.goto(url, { waitUntil: 'load' });
      const state = await page.evaluate(profile => {
        const scope = document.querySelector(`[data-dla-device-document="${profile}"]`);
        const trigger = document.getElementById(`toggle-${profile}`);
        const rect = trigger.getBoundingClientRect();
        return { scopes: document.querySelectorAll('[data-dla-device-document]').length, selected: document.documentElement.dataset.selectedProfile, classes: scope.className.split(/\s+/).filter(name => !name.startsWith('blocks-engine-')), bars: trigger.querySelectorAll('.bar').length, width: rect.width, height: rect.height, gap: getComputedStyle(scope.querySelector('.copy')).marginTop, viewports: document.querySelectorAll('meta[name=viewport]').length, media: [...document.querySelectorAll('style[data-dla-document-scope]')].map(style => ({ profile: style.dataset.dlaDocumentScope, media: style.media, authored: style.dataset.dlaSourceMedia })) };
      }, profile);
      assert.equal(state.scopes, 4); assert.equal(state.selected, profile); assert.equal(state.bars, 3); assert.equal(state.viewports, 1);
      const trigger = page.locator(`#toggle-${profile}`);
      const panel = page.locator(`#panel-${profile}`);
      await trigger.click();
      assert.equal(await panel.evaluate(node => node.hidden), false);
      await panel.locator('button').click();
      assert.equal(await panel.evaluate(node => node.hidden), true);
      assert.equal(await page.evaluate(() => document.activeElement?.id), `toggle-${profile}`);
      await trigger.press('Enter'); assert.equal(await panel.evaluate(node => node.hidden), false);
      await page.keyboard.press('Escape'); assert.equal(await panel.evaluate(node => node.hidden), true);
      await trigger.press('Space'); assert.equal(await panel.evaluate(node => node.hidden), false);
      await page.keyboard.press('Escape'); assert.equal(await panel.evaluate(node => node.hidden), true);
      assert.equal(await page.evaluate(() => document.activeElement?.id), `toggle-${profile}`);
      assert.deepEqual(errors, []);
      states[kind] = state;
      await page.close();
    }
    assert.deepEqual(states.generated, states.source, `${profile}: source and generated root geometry/media/state match`);
    assert.deepEqual(states.editorSaved, states.source, `${profile}: companion save/reload retains geometry, media and runtime bindings`);
    observations.push({ profile, ...states });
  }
  const proof = { gate: 'declared-document-role-button', status: 'pass', observations, boundary: 'Canonical frontend/source and real companion save() proof; parent owns full destination WordPress rendering/editor acceptance.' };
  await fs.writeFile(path.join(evidence, 'browser-proof.json'), JSON.stringify(proof, null, 2) + '\n');
  console.log(JSON.stringify({ gate: proof.gate, status: proof.status, evidence, profiles: observations.map(row => ({ profile: row.profile, bars: row.generated.bars, width: row.generated.width, height: row.generated.height, gap: row.generated.gap })) }, null, 2));
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
