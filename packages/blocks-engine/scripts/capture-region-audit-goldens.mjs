#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const TSX_BOOTSTRAP_ENV = 'BLOCKS_ENGINE_REGION_AUDIT_GOLDENS_TSX';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(__dirname, '..');
const corpusUrl = pathToFileURL(path.join(packageRoot, 'src/__fixtures__/region-audit-corpus.ts')).href;
const outputPath = path.join(packageRoot, 'src/__fixtures__/region-audit-reference-goldens.json');

function hasTsxLoader() {
  return process.execArgv.some((arg, index, argv) => {
    if (arg === '--import') return argv[index + 1]?.includes('tsx');
    return arg.startsWith('--import=') && arg.includes('tsx');
  });
}

if (!process.env[TSX_BOOTSTRAP_ENV] && !hasTsxLoader()) {
  const result = spawnSync(process.execPath, ['--import', 'tsx', fileURLToPath(import.meta.url), ...process.argv.slice(2)], {
    cwd: packageRoot,
    env: { ...process.env, [TSX_BOOTSTRAP_ENV]: '1' },
    stdio: 'inherit',
  });
  if (result.error) throw result.error;
  process.exit(result.status ?? 1);
}

const {
  REFERENCE_REGION_AUDIT_BLOB,
  REFERENCE_REGION_AUDIT_COMMIT,
  REFERENCE_REGION_AUDIT_PATH,
  REFERENCE_REGION_CENSUS_BLOB,
  REFERENCE_REGION_CENSUS_PATH,
  REFERENCE_SECTION_SELECTOR_BLOB,
  REFERENCE_SECTION_SELECTOR_PATH,
  runRegionAuditParity,
} = await import(corpusUrl);

const args = new Set(process.argv.slice(2));
const shouldWrite = args.has('--write');
const shouldCheck = args.has('--check');
const shouldStdout = args.has('--stdout') || (!shouldWrite && !shouldCheck);
const referenceRoot = process.env.REFERENCE_ROOT;
if (!referenceRoot) {
  throw new Error('Set REFERENCE_ROOT to a checkout of the reference implementation at the pinned commit.');
}

const explicitModeCount = [shouldWrite, shouldCheck, args.has('--stdout')].filter(Boolean).length;
if (explicitModeCount > 1) {
  throw new Error('Use only one mode: --write, --check, or --stdout.');
}

function git(gitArgs) {
  return execFileSync('git', ['-C', referenceRoot, ...gitArgs], { encoding: 'utf8' }).trim();
}

function stableJson(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function verifyBlob(pathName, expectedBlob) {
  const headBlob = git(['rev-parse', `HEAD:${pathName}`]);
  if (headBlob !== expectedBlob) {
    throw new Error(`reference ${pathName} HEAD blob must be ${expectedBlob}; got ${headBlob}`);
  }

  const liveBlob = git(['hash-object', pathName]);
  if (liveBlob !== expectedBlob) {
    throw new Error(`reference ${pathName} working tree blob must be ${expectedBlob}; got ${liveBlob}`);
  }
}

function verifyReferenceOracle() {
  const currentCommit = git(['rev-parse', 'HEAD']);
  if (currentCommit !== REFERENCE_REGION_AUDIT_COMMIT) {
    throw new Error(`reference checkout must be at ${REFERENCE_REGION_AUDIT_COMMIT}; got ${currentCommit}`);
  }

  verifyBlob(REFERENCE_REGION_AUDIT_PATH, REFERENCE_REGION_AUDIT_BLOB);
  verifyBlob(REFERENCE_REGION_CENSUS_PATH, REFERENCE_REGION_CENSUS_BLOB);
  verifyBlob(REFERENCE_SECTION_SELECTOR_PATH, REFERENCE_SECTION_SELECTOR_BLOB);

  const requiredStatus = git([
    'status',
    '--porcelain',
    '--',
    REFERENCE_REGION_AUDIT_PATH,
    REFERENCE_REGION_CENSUS_PATH,
    REFERENCE_SECTION_SELECTOR_PATH,
  ]);
  if (requiredStatus) {
    throw new Error(`reference required region-audit sources must be clean:\n${requiredStatus}`);
  }
}

function report(mode, parityFile) {
  process.stderr.write(
    `region-audit goldens ${mode}: reference commit=${REFERENCE_REGION_AUDIT_COMMIT} ` +
      `regionAudit=${REFERENCE_REGION_AUDIT_BLOB} regionCensus=${REFERENCE_REGION_CENSUS_BLOB} ` +
      `sectionSelector=${REFERENCE_SECTION_SELECTOR_BLOB} cases=${parityFile.cases.length}\n`
  );
}

verifyReferenceOracle();

const referenceAudit = await import(pathToFileURL(path.join(referenceRoot, REFERENCE_REGION_AUDIT_PATH)).href);
const referenceCensus = await import(pathToFileURL(path.join(referenceRoot, REFERENCE_REGION_CENSUS_PATH)).href);
const parityFile = runRegionAuditParity({
  reconcileRegions: referenceAudit.reconcileRegions,
  extractSourceLandmarksFromHtml: referenceCensus.extractSourceLandmarksFromHtml,
  selectorForHtmlRoot: referenceCensus.selectorForHtmlRoot,
  landmarkRoleForHtmlRoot: referenceCensus.landmarkRoleForHtmlRoot,
});
const json = stableJson(parityFile);

if (shouldCheck) {
  const existing = readFileSync(outputPath, 'utf8');
  if (existing !== json) {
    throw new Error(`${outputPath} is out of date with reference region-audit blobs`);
  }
  report('check', parityFile);
}

if (shouldWrite) {
  writeFileSync(outputPath, json);
  report('write', parityFile);
}

if (shouldStdout) {
  process.stdout.write(json);
  report('stdout', parityFile);
}
