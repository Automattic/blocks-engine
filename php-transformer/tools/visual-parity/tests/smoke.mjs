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
</style></head><body><main class="hero" data-figma-node-id="12:34" data-figma-node-name="Hero">Hello world <img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='2' height='3'%3E%3C/svg%3E"></main></body></html>`);
await writeFile(domFixtureGeneric, `<!doctype html><html><head><style>
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
  assert(domReport.entrypoints[0].elements[0].node_id === '12:34', 'DOM provider captures node id');
  assert(domReport.entrypoints[0].elements[0].node_name === 'Hero', 'DOM provider captures node name');
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
