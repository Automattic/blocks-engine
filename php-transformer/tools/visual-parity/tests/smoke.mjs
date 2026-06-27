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

await writeFile(domFixture, '<!doctype html><html><body><main data-figma-node-id="12:34" data-figma-node-name="Hero">Hello world</main></body></html>');
const server = createServer(async (request, response) => {
  if (request.url === '/dom-box.html') {
    response.writeHead(200, { 'content-type': 'text/html' });
    response.end(await readFile(domFixture));
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
  assert(domReport.entrypoints[0].elements[0].text_sample === 'Hello world', 'DOM provider captures text sample');
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
