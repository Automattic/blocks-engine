#!/usr/bin/env node
/**
 * Differential CSS corpus runner.
 *
 *   node differential/run.mjs [--seed 1] [--count 200] [--families a,b]
 *     [--viewports 390,1440] [--compose-rate 0.35] [--case id,id]
 *     [--pinned] [--report tmp/differential-report.json] [--out tmp/differential-cases]
 *
 * Exits 0 whatever the cases find; exits non-zero only when the harness
 * itself fails (bad options, PHP or Chromium unavailable).
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { DEFAULT_GENERATION, familyNames, generateCases } from './catalog.mjs';
import { Harness } from './harness.mjs';
import { PINNED_CASES } from './pinned.mjs';
import { DEFAULT_VIEWPORTS } from './render.mjs';
import { buildReport, writeFailingCases } from './report.mjs';

const HELP = `Usage: node differential/run.mjs [options]

  --seed <n>           Generation seed (default ${DEFAULT_GENERATION.seed})
  --count <n>          Number of generated cases (default ${DEFAULT_GENERATION.count})
  --families <list>    Comma-separated families (default ${familyNames.join(',')})
  --viewports <list>   Comma-separated viewport widths (default ${DEFAULT_VIEWPORTS.join(',')})
  --compose-rate <p>   Probability a case composes two families (default ${DEFAULT_GENERATION.composeRate})
  --case <ids>         Only run these case ids (comma-separated)
  --pinned             Also run the pinned regression cases
  --pinned-only        Run only the pinned regression cases
  --report <path>      Report path (default tmp/differential-report.json)
  --out <dir>          Write each failing case as standalone HTML into <dir>
  --quiet              No per-case progress on stderr
`;

function parseArguments(argv) {
  const options = {
    seed: DEFAULT_GENERATION.seed,
    count: DEFAULT_GENERATION.count,
    families: familyNames,
    viewports: DEFAULT_VIEWPORTS,
    composeRate: DEFAULT_GENERATION.composeRate,
    cases: null,
    pinned: false,
    pinnedOnly: false,
    report: 'tmp/differential-report.json',
    out: null,
    quiet: false,
  };
  const number = (flag, value) => {
    const parsed = Number(value);
    if (value === undefined || !Number.isFinite(parsed)) throw new Error(`${flag} needs a number`);
    return parsed;
  };
  const list = (flag, value) => {
    if (!value) throw new Error(`${flag} needs a comma-separated list`);
    return value.split(',').map((entry) => entry.trim()).filter(Boolean);
  };
  for (let index = 0; index < argv.length; index++) {
    const flag = argv[index];
    const value = argv[index + 1];
    switch (flag) {
      case '--seed':
        options.seed = number(flag, value);
        index++;
        break;
      case '--count':
        options.count = number(flag, value);
        index++;
        break;
      case '--families':
        options.families = list(flag, value);
        index++;
        break;
      case '--viewports':
        options.viewports = list(flag, value).map((width) => number(flag, width));
        index++;
        break;
      case '--compose-rate':
        options.composeRate = number(flag, value);
        index++;
        break;
      case '--case':
        options.cases = list(flag, value);
        index++;
        break;
      case '--pinned':
        options.pinned = true;
        break;
      case '--pinned-only':
        options.pinnedOnly = true;
        break;
      case '--report':
        options.report = value;
        index++;
        break;
      case '--out':
        options.out = value;
        index++;
        break;
      case '--quiet':
        options.quiet = true;
        break;
      case '--help':
      case '-h':
        process.stdout.write(HELP);
        process.exit(0);
        break;
      default:
        throw new Error(`Unknown option ${flag}\n\n${HELP}`);
    }
  }
  const unknown = options.families.filter((name) => !familyNames.includes(name));
  if (unknown.length) throw new Error(`Unknown families: ${unknown.join(', ')} (known: ${familyNames.join(', ')})`);
  return options;
}

function selectCases(options) {
  let cases = options.pinnedOnly ? [] : generateCases({ seed: options.seed, count: options.count, families: options.families, composeRate: options.composeRate });
  if (options.pinned || options.pinnedOnly) cases = [...cases, ...PINNED_CASES];
  if (options.cases) {
    const wanted = new Set(options.cases);
    cases = cases.filter((testCase) => wanted.has(testCase.id));
    const found = new Set(cases.map((testCase) => testCase.id));
    const absent = options.cases.filter((id) => !found.has(id));
    if (absent.length) throw new Error(`No such case ids: ${absent.join(', ')}`);
  }
  return cases;
}

function describe(result) {
  if (result.pass) return 'pass';
  if (result.error) return `FAIL error: ${result.error.slice(0, 120)}`;
  const properties = [...new Set(result.diffs.map((diff) => diff.property))];
  return `FAIL ${result.diffs.length} diffs (${properties.slice(0, 5).join(', ')}${properties.length > 5 ? ', ...' : ''})${result.missing.length ? `, ${result.missing.length} missing` : ''}`;
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const cases = selectCases(options);
  const started = Date.now();
  const harness = new Harness({ viewports: options.viewports });
  let results;
  try {
    await harness.start();
    results = await harness.run(cases, (result) => {
      if (!options.quiet) process.stderr.write(`[${cases.findIndex((testCase) => testCase.id === result.id) + 1}/${cases.length}] ${result.id} ${describe(result)}\n`);
    });
  } finally {
    await harness.close();
  }

  const reportOptions = {
    seed: options.seed,
    count: options.count,
    families: options.families,
    viewports: options.viewports,
    compose_rate: options.composeRate,
    max_elements: DEFAULT_GENERATION.maxElements,
    cases: options.cases,
    pinned: options.pinned || options.pinnedOnly,
    pinned_only: options.pinnedOnly,
  };
  const report = buildReport({ options: reportOptions, results, cases });
  const reportPath = resolve(options.report);
  await mkdir(dirname(reportPath), { recursive: true });
  await writeFile(reportPath, `${JSON.stringify(report, null, 2)}\n`);
  const written = options.out ? await writeFailingCases(resolve(options.out), results, cases, options.seed) : 0;

  const summary = {
    seed: options.seed,
    ...report.summary,
    duration_seconds: Math.round((Date.now() - started) / 100) / 10,
    report: reportPath,
    failing_cases_written: options.out ? { directory: resolve(options.out), count: written } : null,
    top_failing_tag_sets: report.failures_by_tag_set.slice(0, 8).map(({ tags, count, minimal_case: minimalCase }) => ({ tags: tags.join(' '), count, minimal_case: minimalCase })),
  };
  delete summary.cases_by_differing_property;
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
}

main().catch((error) => {
  process.stderr.write(`differential harness error: ${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
  process.exit(1);
});
