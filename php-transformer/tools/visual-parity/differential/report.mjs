/**
 * The differential report (`blocks-engine/php-transformer/differential-report/v1`)
 * and the standalone HTML files written for failing cases.
 *
 * The report holds no timings or paths, so the same seed and options give a
 * byte-identical report.
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

export const REPORT_SCHEMA = 'blocks-engine/php-transformer/differential-report/v1';

/** Smaller cases (fewer elements, then fewer diffs) make better issue reproductions. */
function smallestFirst(left, right) {
  return (left.element_count ?? 99) - (right.element_count ?? 99)
    || left.diffs.length + left.missing.length - (right.diffs.length + right.missing.length)
    || left.id.localeCompare(right.id);
}

export function groupFailuresByTagSet(results) {
  const groups = new Map();
  for (const result of results.filter((entry) => !entry.pass)) {
    const key = result.tags.join(' ');
    if (!groups.has(key)) groups.set(key, { tags: result.tags, count: 0, cases: [] });
    const group = groups.get(key);
    group.count++;
    group.cases.push(result);
  }
  return [...groups.values()]
    .map((group) => ({
      tags: group.tags,
      count: group.count,
      minimal_case: [...group.cases].sort(smallestFirst)[0].id,
      cases: group.cases.map((result) => result.id),
    }))
    .sort((left, right) => right.count - left.count || left.tags.join(' ').localeCompare(right.tags.join(' ')));
}

/**
 * Failures grouped by the features attributed to their differing elements:
 * a case mixing several features lands in the group of the ones that broke.
 */
export function groupFailuresByFeatureSet(results) {
  const groups = new Map();
  for (const result of results.filter((entry) => !entry.pass)) {
    const features = result.failing_features ?? [];
    const key = features.join(' ');
    if (!groups.has(key)) groups.set(key, { features, count: 0, cases: [] });
    const group = groups.get(key);
    group.count++;
    group.cases.push(result);
  }
  return [...groups.values()]
    .map((group) => ({
      features: group.features,
      count: group.count,
      minimal_case: [...group.cases].sort(smallestFirst)[0].id,
      cases: group.cases.map((result) => result.id),
    }))
    .sort((left, right) => right.count - left.count || left.features.join(' ').localeCompare(right.features.join(' ')));
}

/** Failure rate per individual coarse tag: the per-feature view of the same data. */
export function failuresByTag(results) {
  const tags = new Map();
  for (const result of results) {
    for (const tag of result.tags) {
      if (!tags.has(tag)) tags.set(tag, { tag, total: 0, failed: 0, failing_cases: [] });
      const entry = tags.get(tag);
      entry.total++;
      if (!result.pass) {
        entry.failed++;
        entry.failing_cases.push(result);
      }
    }
  }
  return [...tags.values()]
    .map((entry) => ({
      tag: entry.tag,
      total: entry.total,
      failed: entry.failed,
      minimal_case: entry.failing_cases.length ? [...entry.failing_cases].sort(smallestFirst)[0].id : null,
    }))
    .sort((left, right) => right.failed - left.failed || left.tag.localeCompare(right.tag));
}

/** Which properties differ most often, across all failing cases. */
function propertyTotals(results) {
  const totals = {};
  for (const result of results) {
    const seen = new Set(result.diffs.map((diff) => diff.property));
    for (const property of seen) totals[property] = (totals[property] ?? 0) + 1;
  }
  return Object.fromEntries(Object.entries(totals).sort((left, right) => right[1] - left[1] || left[0].localeCompare(right[0])));
}

export function buildReport({ options, results, cases }) {
  const sources = new Map(cases.map((testCase) => [testCase.id, testCase.html]));
  const failed = results.filter((result) => !result.pass);
  return {
    schema: REPORT_SCHEMA,
    seed: options.seed,
    options,
    summary: {
      cases: results.length,
      passed: results.length - failed.length,
      failed: failed.length,
      errored: results.filter((result) => result.error).length,
      cases_with_missing_elements: results.filter((result) => result.missing.length).length,
      cases_by_differing_property: propertyTotals(failed),
    },
    cases: results.map((result) => {
      const entry = { ...result };
      delete entry.converted_html;
      if (!result.pass) entry.source_html = sources.get(result.id);
      return entry;
    }),
    failures_by_tag_set: groupFailuresByTagSet(results),
    failures_by_feature_set: groupFailuresByFeatureSet(results),
    failures_by_tag: failuresByTag(results),
  };
}

function htmlComment(text) {
  return text.replace(/--/g, '- -');
}

/** Write `<id>.html` (the source, ready to promote) and `<id>.converted.html` per failing case. */
export async function writeFailingCases(directory, results, cases, seed) {
  const sources = new Map(cases.map((testCase) => [testCase.id, testCase.html]));
  await mkdir(directory, { recursive: true });
  let written = 0;
  for (const result of results.filter((entry) => !entry.pass)) {
    const diffLines = result.error
      ? [`error: ${result.error}`]
      : [
          ...result.missing.map((entry) => `missing #${entry.element} <${entry.tag}> "${entry.text}"`),
          ...result.diffs.slice(0, 40).map((diff) => `@${diff.viewport} #${diff.element} ${diff.property}: ${diff.source} -> ${diff.output}`),
          ...(result.diffs.length > 40 ? [`... ${result.diffs.length - 40} more diffs`] : []),
        ];
    const header = [
      '<!--',
      `Differential case ${result.id} (seed ${seed})`,
      `tags: ${result.tags.join(' ')}`,
      ...diffLines.map((line) => `  ${htmlComment(line)}`),
      '-->',
    ].join('\n');
    const source = sources.get(result.id);
    const annotated = /^<!doctype html>\n/i.test(source) ? source.replace(/^<!doctype html>\n/i, (doctype) => `${doctype}${header}\n`) : `${header}\n${source}`;
    await writeFile(join(directory, `${result.id}.html`), annotated);
    if (result.converted_html) await writeFile(join(directory, `${result.id}.converted.html`), result.converted_html);
    written++;
  }
  return written;
}
