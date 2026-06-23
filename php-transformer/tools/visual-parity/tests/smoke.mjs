import { mkdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const outputDir = path.join(root, 'tmp');
const output = path.join(outputDir, 'visual-parity-smoke.json');

await mkdir(outputDir, { recursive: true });

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

await rm(output, { force: true });
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

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}
