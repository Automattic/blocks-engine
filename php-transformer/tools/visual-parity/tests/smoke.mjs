import { copyFile, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const outputDir = path.join(root, 'tmp');
const output = path.join(outputDir, 'visual-parity-smoke.json');
const domFixture = path.join(outputDir, 'dom-box.html');
const domFixtureGeneric = path.join(outputDir, 'dom-box-generic.html');
const screenshotDir = path.join(outputDir, 'screenshots');
const missingPlaywrightDir = path.join(tmpdir(), `blocks-engine-dom-provider-missing-playwright-${process.pid}`);
const missingPlaywrightProvider = path.join(missingPlaywrightDir, 'dom-box-provider.mjs');

await mkdir(outputDir, { recursive: true });
await mkdir(missingPlaywrightDir, { recursive: true });

await run(process.execPath, [
  path.join(root, 'bin/visual-parity.mjs'),
  '--source', path.join(root, 'tests/fixtures/source.html'),
  '--target', path.join(root, 'tests/fixtures/target.html'),
  '--viewport', 'smoke=390x844',
  '--selector', 'primary=.primary-link',
  '--output', output,
], root);

const report = JSON.parse(await readFile(output, 'utf8'));
assert(report.schema === 'blocks-engine.visual-parity.probes.v1', 'report schema is stable');
assert(report.source.snapshots[0].probes.length === 1, 'custom selector overrides default probes');
assert(report.source.snapshots[0].probes[0].matches[0].text === 'Get Started', 'extracts link text');
assert(report.source.snapshots[0].probes[0].matches[0].href === '/start', 'extracts link href');
assert(report.source.snapshots[0].probes[0].matches[0].display === 'inline-block', 'extracts computed display');
assert(report.source.snapshots[0].probes[0].matches[0].padding.top === '10px', 'extracts computed padding');
assert(report.comparison[0].probes[0].count_delta === 0, 'compares source and target counts');

await writeFile(domFixture, `<!doctype html><html><head><style>
  body { margin: 0; }
  .hero {
    color: rgb(12, 34, 56);
    background-color: rgb(240, 241, 242);
    background-image: linear-gradient(rgb(255, 0, 0), rgb(0, 0, 255));
    border: 2px solid rgb(1, 2, 3);
    border-radius: 8px 9px 10px 11px;
    box-shadow: rgb(0, 0, 0) 1px 2px 3px;
    font-family: Arial, sans-serif;
    font-size: 20px;
    font-weight: 700;
    line-height: 24px;
    letter-spacing: 1px;
    opacity: 0.75;
    overflow: hidden;
    position: relative;
    text-align: center;
    transform: translateX(4px);
    white-space: normal;
    width: 120px;
    height: 24px;
  }
</style></head><body><main class="hero" data-figma-node-id="12:34" data-figma-node-name="Hero" data-source-node-type="FRAME" data-source-visual-width="120" data-source-visual-height="24">Hello world <img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='2' height='3'%3E%3C/svg%3E"></main></body></html>`);
await writeFile(domFixtureGeneric, `<!doctype html><html><head><style>
  body { margin: 0; }
  .hero { color: rgb(12, 34, 56); font-size: 20px; width: 120px; height: 24px; }
</style></head><body><main class="hero" data-node-id="n-1" data-node-name="Generic Hero">Hello generic</main></body></html>`);
const server = createServer(async (request, response) => {
  if (request.url === '/dom-box.html') {
    response.writeHead(200, { 'content-type': 'text/html' });
    response.end(await readFile(domFixture));
    return;
  }
  if (request.url === '/dom-box-generic.html') {
    response.writeHead(200, { 'content-type': 'text/html' });
    response.end(await readFile(domFixtureGeneric));
    return;
  }
  if (request.url === '/fluid-layout-2095.html') {
    response.writeHead(200, { 'content-type': 'text/html' });
    response.end(await readFile(path.join(root, 'tests/fixtures/fluid-layout-2095.html')));
    return;
  }
  response.writeHead(404, { 'content-type': 'application/json' });
  response.end('{"error":"not_found"}');
});
await new Promise((resolve, reject) => {
  server.on('error', reject);
  server.listen(0, '127.0.0.1', resolve);
});
try {
  const address = server.address();
  const domReport = await runJson(process.execPath, [path.join(root, 'bin/dom-box-provider.mjs')], root, {
    ...process.env,
    HOMEBOY_DOM_BOX_BASE_URL: `http://127.0.0.1:${address.port}`,
    HOMEBOY_DOM_BOX_PAGE_PATHS_JSON: JSON.stringify(['/dom-box.html']),
    HOMEBOY_DOM_BOX_TEXT_SAMPLE_LIMIT: '20',
  });
  assert(domReport.entrypoints.length === 1, 'DOM provider captures one entrypoint');
  assert(domReport.entrypoints[0].dom_css_loaded === true, 'DOM provider reports CSS loaded when reset is applied');
  assert(domReport.entrypoints[0].dom_capture_valid === true, 'DOM provider reports capture valid when CSS reset is applied');
  assert(domReport.entrypoints[0].stylesheet_status.body_margin === '0px', 'DOM provider captures body margin reset status');
  assert(domReport.entrypoints[0].elements[0].node_id === '12:34', 'DOM provider captures node id');
  assert(domReport.entrypoints[0].elements[0].node_name === 'Hero', 'DOM provider captures node name');
  assert(domReport.entrypoints[0].elements[0].source.node_type === 'FRAME', 'DOM provider preserves source node type');
  assert(domReport.entrypoints[0].elements[0].source.visual_dimensions.width === 120, 'DOM provider preserves source visual width');
  assert(domReport.entrypoints[0].elements[0].source.visual_dimensions.height === 24, 'DOM provider preserves source visual height');
  assert(domReport.entrypoints[0].elements[0].selector === 'main[data-figma-node-id="12:34"]', 'DOM provider keys selector off default figma attribute');
  assert(domReport.entrypoints[0].elements[0].text_sample === 'Hello world', 'DOM provider captures text sample');
  assert(domReport.entrypoints[0].elements[0].page_path === '/dom-box.html', 'DOM provider adds page path to elements');
  assert(domReport.entrypoints[0].elements[0].computed_style['font-size'] === '20px', 'DOM provider captures computed font size');
  assert(domReport.entrypoints[0].elements[0].computed_style['font-weight'] === '700', 'DOM provider captures computed font weight');
  assert(domReport.entrypoints[0].elements[0].computed_style.color === 'rgb(12, 34, 56)', 'DOM provider captures computed color');
  assert(domReport.entrypoints[0].elements[0].computed_style['background-image'].includes('linear-gradient'), 'DOM provider captures background image');
  assert(domReport.entrypoints[0].elements[0].computed_style.transform !== 'none', 'DOM provider captures transform');
  assert(domReport.entrypoints[0].elements[0].text_metrics.text_length === 11, 'DOM provider captures full normalized text length');
  assert(domReport.entrypoints[0].elements[0].text_metrics.client_width > 0, 'DOM provider captures client dimensions');
  assert(domReport.entrypoints[0].elements[0].text_metrics.line_count_estimate >= 1, 'DOM provider estimates line count');
  assert(domReport.entrypoints[0].elements[0].asset_state.background_image_present === true, 'DOM provider flags background images');
  assert(domReport.entrypoints[0].elements[0].asset_state.descendants[0].tag === 'img', 'DOM provider captures image descendants');
  assert(domReport.entrypoints[0].elements[0].asset_state.descendants[0].complete === true, 'DOM provider captures image complete state');
  assert(domReport.entrypoints[0].elements[0].visibility.visible === true, 'DOM provider captures visible state');
  assert(domReport.entrypoints[0].elements[0].visibility.clipped === true, 'DOM provider captures clipped-ish overflow state');
  assert(domReport.entrypoints[0].unidentified_elements.some((element) => element.tag === 'img'), 'DOM provider reports visible elements without node ids');

  const genericEnvReport = await runJson(process.execPath, [path.join(root, 'bin/dom-box-provider.mjs')], root, {
    ...process.env,
    HOMEBOY_DOM_BOX_BASE_URL: `http://127.0.0.1:${address.port}`,
    HOMEBOY_DOM_BOX_PAGE_PATHS_JSON: JSON.stringify(['/dom-box-generic.html']),
    HOMEBOY_DOM_BOX_NODE_ID_ATTR: 'data-node-id',
    HOMEBOY_DOM_BOX_NODE_NAME_ATTR: 'data-node-name',
  });
  assert(genericEnvReport.entrypoints[0].elements.length === 1, 'DOM provider enumerates by configured generic attribute (env)');
  assert(genericEnvReport.entrypoints[0].elements[0].node_id === 'n-1', 'DOM provider reads node id from configured generic attribute (env)');
  assert(genericEnvReport.entrypoints[0].elements[0].node_name === 'Generic Hero', 'DOM provider reads node name from configured generic attribute (env)');
  assert(genericEnvReport.entrypoints[0].elements[0].selector === 'main[data-node-id="n-1"]', 'DOM provider keys selector off configured generic attribute (env)');

  const genericFlagReport = await runJson(process.execPath, [
    path.join(root, 'bin/dom-box-provider.mjs'),
    '--node-id-attr=data-node-id',
    '--node-name-attr=data-node-name',
  ], root, {
    ...process.env,
    HOMEBOY_DOM_BOX_BASE_URL: `http://127.0.0.1:${address.port}`,
    HOMEBOY_DOM_BOX_PAGE_PATHS_JSON: JSON.stringify(['/dom-box-generic.html']),
  });
  assert(genericFlagReport.entrypoints[0].elements[0].node_id === 'n-1', 'DOM provider reads node id from configured generic attribute (flag)');
  assert(genericFlagReport.entrypoints[0].elements[0].node_name === 'Generic Hero', 'DOM provider reads node name from configured generic attribute (flag)');
  assert(genericFlagReport.entrypoints[0].elements[0].selector === 'main[data-node-id="n-1"]', 'DOM provider keys selector off configured generic attribute (flag)');

  const fluidReport = await runJson(process.execPath, [path.join(root, 'bin/dom-box-provider.mjs')], root, {
    ...process.env,
    HOMEBOY_DOM_BOX_BASE_URL: `http://127.0.0.1:${address.port}`,
    HOMEBOY_DOM_BOX_PAGE_PATHS_JSON: JSON.stringify(['/fluid-layout-2095.html']),
    HOMEBOY_DOM_BOX_CAPTURE_TARGETS_JSON: JSON.stringify([
      { page_path: '/fluid-layout-2095.html', viewport: { width: 2095, height: 900 }, source_frame: { id: 'fluid:2095', width: 2095 }, comparison_role: 'source_layout' },
      { page_path: '/fluid-layout-2095.html', viewport: { width: 1440, height: 900 }, source_frame: { id: 'fluid:1440', width: 1440 }, comparison_role: 'responsive_evidence' },
    ]),
  });
  assert(fluidReport.entrypoints.length === 2, 'DOM provider preserves native and responsive captures separately');
  assert(fluidReport.entrypoints[0].viewport.width === 2095, 'DOM provider captures native source width');
  assert(fluidReport.entrypoints[0].source_frame.id === 'fluid:2095', 'DOM provider persists native source frame identity');
  assert(fluidReport.entrypoints[1].viewport.width === 1440, 'DOM provider captures responsive evidence width');
  assert(fluidReport.entrypoints[1].comparison_role === 'responsive_evidence', 'DOM provider labels responsive evidence separately');
  const nativeSecondary = fluidReport.entrypoints[0].elements.find((element) => element.node_id === 'fluid:secondary');
  const responsiveSecondary = fluidReport.entrypoints[1].elements.find((element) => element.node_id === 'fluid:secondary');
  assert(nativeSecondary.boundingClientRect.y < responsiveSecondary.boundingClientRect.y, 'reduced fixture reflows two columns at the responsive width');

  const screenshotReport = await runJson(process.execPath, [
    path.join(root, 'bin/screenshot-provider.mjs'),
    '--base-url', `http://127.0.0.1:${address.port}`,
    '--page-path', '/dom-box.html',
    '--output-dir', screenshotDir,
    '--viewport', '390x844',
  ], root, process.env);
  assert(screenshotReport.schema === 'blocks-engine.visual-parity.screenshots.v1', 'screenshot provider emits stable schema');
  assert(screenshotReport.screenshots.length === 1, 'screenshot provider captures one screenshot');
  assert(screenshotReport.screenshots[0].filename === 'dom-box.html.png', 'screenshot provider derives deterministic filename');
  assert(screenshotReport.screenshots[0].exists === true, 'screenshot provider records written PNG');
  const screenshotBytes = await readFile(screenshotReport.screenshots[0].path);
  assert(screenshotBytes[0] === 0x89 && screenshotBytes[1] === 0x50 && screenshotBytes[2] === 0x4e && screenshotBytes[3] === 0x47, 'screenshot provider writes a PNG file');
} finally {
  await new Promise((resolve) => server.close(resolve));
}

await copyFile(path.join(root, 'bin/dom-box-provider.mjs'), missingPlaywrightProvider);
const missingPlaywright = await runFailure(process.execPath, [missingPlaywrightProvider], missingPlaywrightDir, {
  ...process.env,
  HOMEBOY_DOM_BOX_BASE_URL: 'http://127.0.0.1:9',
  HOMEBOY_DOM_BOX_PAGE_PATHS_JSON: JSON.stringify(['/dom-box.html']),
});
assert(missingPlaywright.stderr.includes('Playwright is required for DOM box capture but is not installed.'), 'DOM provider explains missing Playwright dependency');
assert(missingPlaywright.stderr.includes('npm ci --prefix php-transformer/tools/visual-parity'), 'DOM provider suggests npm ci');
assert(missingPlaywright.stderr.includes('install:browsers'), 'DOM provider suggests browser install script');

await rm(output, { force: true });
await rm(domFixture, { force: true });
await rm(domFixtureGeneric, { force: true });
await rm(screenshotDir, { recursive: true, force: true });
await rm(missingPlaywrightDir, { recursive: true, force: true });
console.log('Visual parity smoke test passed.');

function run(command, args, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd, stdio: 'inherit' });
    child.on('error', reject);
    child.on('exit', (code) => {
      if (code === 0) {
        resolve();
        return;
      }
      reject(new Error(`${command} exited with ${code}`));
    });
  });
}

function runFailure(command, args, cwd, env) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd, env, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => {
      stdout += chunk;
    });
    child.stderr.on('data', (chunk) => {
      stderr += chunk;
    });
    child.on('error', reject);
    child.on('exit', (code) => {
      if (code === 0) {
        reject(new Error(`${command} unexpectedly exited with 0`));
        return;
      }
      resolve({ code, stdout, stderr });
    });
  });
}

function runJson(command, args, cwd, env) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd, env, stdio: ['ignore', 'pipe', 'inherit'] });
    let stdout = '';
    child.stdout.on('data', (chunk) => {
      stdout += chunk;
    });
    child.on('error', reject);
    child.on('exit', (code) => {
      if (code !== 0) {
        reject(new Error(`${command} exited with ${code}`));
        return;
      }
      try {
        resolve(JSON.parse(stdout));
      } catch (error) {
        reject(error);
      }
    });
  });
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}
