/**
 * Differential corpus: generator determinism and bounds, the pinned
 * regression cases through the real PHP transformer and Chromium, the
 * report shape, and the CLI exit-code contract.
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdir, readdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEFAULT_GENERATION, familyNames, generateCases } from '../differential/catalog.mjs';
import { Harness } from '../differential/harness.mjs';
import { PINNED_CASES } from '../differential/pinned.mjs';
import { REPORT_SCHEMA, buildReport, writeFailingCases } from '../differential/report.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const outputDir = path.join(root, 'tmp', 'differential-corpus-test');

// Generation is a pure function of the seed and bounded per case.
const corpus = generateCases({ seed: 1, count: DEFAULT_GENERATION.count });
assert.deepEqual(generateCases({ seed: 1, count: DEFAULT_GENERATION.count }), corpus, 'same seed must generate the same corpus');
assert.notDeepEqual(generateCases({ seed: 2, count: 20 }), corpus.slice(0, 20), 'a different seed must generate a different corpus');
assert.equal(new Set(corpus.map((testCase) => testCase.id)).size, corpus.length, 'case ids must be unique');
assert.equal(new Set(corpus.map((testCase) => testCase.html)).size, corpus.length, 'cases must not repeat');
for (const testCase of corpus) {
  assert.ok(testCase.element_count <= DEFAULT_GENERATION.maxElements, `${testCase.id} has ${testCase.element_count} elements`);
  assert.ok(testCase.markers.length > 0, `${testCase.id} has no stable markers`);
  for (const family of testCase.families) assert.ok(testCase.tags.includes(family), `${testCase.id} lacks its ${family} tag`);
  for (const marker of testCase.markers) assert.ok(testCase.html.includes(`id="${marker}"`), `${testCase.id} lost marker ${marker}`);
}
for (const family of familyNames) {
  assert.ok(corpus.some((testCase) => testCase.families.includes(family)), `no ${family} case in the default corpus`);
}
const composed = corpus.filter((testCase) => testCase.families.length === 2);
assert.ok(composed.length > corpus.length / 5, 'too few composed cases');
for (const testCase of composed) assert.ok(testCase.tags.includes('composed'), `${testCase.id} is composed but untagged`);
const css = corpus.map((testCase) => testCase.html).join('\n');
for (const pattern of [/@media/, /@layer/, /--[a-z]+[A-Z]/, /var\(--[\w-]+,/, /:where\(/, /!important/, /display: grid/]) {
  assert.match(css, pattern, `default corpus never generates ${pattern}`);
}

// Pinned cases pass through the real transformer and Chromium on trunk.
await rm(outputDir, { recursive: true, force: true });
await mkdir(outputDir, { recursive: true });
const sample = generateCases({ seed: 1, count: 6 });
const harness = new Harness({});
let results;
try {
  await harness.start();
  results = await harness.run([...PINNED_CASES, ...sample]);
} finally {
  await harness.close();
}
for (const pinned of PINNED_CASES) {
  const result = results.find((entry) => entry.id === pinned.id);
  assert.ok(result.pass, `${pinned.id} (#${pinned.issue}) regressed: ${JSON.stringify(result.error ?? result.diffs.slice(0, 4))}`);
  assert.deepEqual(result.missing, [], `${pinned.id} lost elements`);
}
for (const result of results) {
  assert.equal(typeof result.editability.raw_html_block_count, 'number', `${result.id} lacks editability metrics`);
}

// The report keeps the v1 shape and every failure lands in a tag-set group.
const report = buildReport({ options: { seed: 1, count: 6 }, results, cases: [...PINNED_CASES, ...sample] });
assert.equal(report.schema, REPORT_SCHEMA);
assert.equal(report.seed, 1);
assert.equal(report.cases.length, results.length);
for (const entry of report.cases) {
  for (const key of ['id', 'family', 'tags', 'pass', 'diffs', 'missing', 'editability']) assert.ok(key in entry, `report case lacks ${key}`);
}
const failing = results.filter((result) => !result.pass);
assert.equal(report.failures_by_tag_set.reduce((total, group) => total + group.count, 0), failing.length);
const written = await writeFailingCases(outputDir, results, [...PINNED_CASES, ...sample], 1);
assert.equal(written, failing.length);
for (const result of failing) {
  const html = await readFile(path.join(outputDir, `${result.id}.html`), 'utf8');
  assert.match(html, /^<!doctype html>/i, `${result.id}.html is not standalone`);
}

// Case failures never fail the CLI; harness errors do.
const cli = (...args) => spawnSync(process.execPath, [path.join(root, 'differential/run.mjs'), '--quiet', ...args], { cwd: root, encoding: 'utf8' });
const run = cli('--count', '3', '--report', path.join(outputDir, 'cli-report.json'), '--out', path.join(outputDir, 'cli-cases'));
assert.equal(run.status, 0, run.stderr);
assert.equal(JSON.parse(run.stdout).cases, 3);
const failed = JSON.parse(run.stdout).failed;
assert.equal((await readdir(path.join(outputDir, 'cli-cases'), { recursive: true }).catch(() => [])).filter((name) => name.endsWith('.converted.html')).length, failed);
assert.notEqual(cli('--families', 'nonexistent').status, 0, 'a bad option must fail the harness');

console.log(`differential corpus ok: ${corpus.length} generated cases, ${PINNED_CASES.length} pinned cases pass`);
