/**
 * Run cases through one PHP worker and one browser.
 *
 * The next case's transform is requested while the current case renders,
 * so PHP and Chromium work in parallel.
 */
import { Renderer } from './render.mjs';
import { convertedDocument, TransformerWorker } from './transformer.mjs';

export class Harness {
  constructor({ viewports, php } = {}) {
    this.worker = new TransformerWorker({ php });
    this.renderer = new Renderer({ viewports });
  }

  async start() {
    await Promise.all([this.worker.start(), this.renderer.start()]);
  }

  async close() {
    await Promise.allSettled([this.worker.close(), this.renderer.close()]);
  }

  /** Run every case, calling `onResult(result, testCase)` as each finishes. */
  async run(cases, onResult = () => {}) {
    const results = [];
    const transformOf = (testCase) => this.worker.transform(testCase.id, testCase.html).then(
      (response) => ({ response }),
      (error) => ({ error }),
    );
    let next = cases.length ? transformOf(cases[0]) : null;
    for (let index = 0; index < cases.length; index++) {
      const testCase = cases[index];
      const transformed = await next;
      next = index + 1 < cases.length ? transformOf(cases[index + 1]) : null;
      const result = await this.evaluate(testCase, transformed);
      results.push(result);
      await onResult(result, testCase);
    }
    return results;
  }

  async evaluate(testCase, { response, error }) {
    const base = {
      id: testCase.id,
      family: testCase.family,
      families: testCase.families,
      tags: testCase.tags,
      detail_tags: testCase.detail_tags ?? [],
      element_count: testCase.element_count ?? null,
    };
    if (error || !response.ok) {
      // A transformer exception or crash is a case failure, not a harness error.
      const message = error ? error.message : response.error;
      return { ...base, pass: false, failing_features: failingFeatures(testCase, {}), error: message, diffs: [], missing: [], editability: null, converted_html: null };
    }
    const convertedHtml = convertedDocument(response, `${testCase.id} (converted)`);
    const comparison = await this.renderer.compare(testCase.html, convertedHtml, testCase.attribution ?? null);
    const matchCounts = {};
    for (const { match } of Object.values(comparison.matches)) matchCounts[match] = (matchCounts[match] ?? 0) + 1;
    const pass = comparison.diffs.length === 0 && comparison.missing.length === 0;
    return {
      ...base,
      pass,
      failing_features: pass ? [] : failingFeatures(testCase, comparison.features),
      transform_status: response.status,
      compared_elements: comparison.elements,
      matched_by: matchCounts,
      diffs: comparison.diffs,
      missing: comparison.missing,
      editability: response.editability,
      converted_html: convertedHtml,
    };
  }
}

/**
 * The `family:feature` tags behind a failure: those attributed to the
 * differing elements, or every feature tag of the case when nothing could be
 * attributed (transformer errors, pinned cases).
 */
function failingFeatures(testCase, features) {
  const attributed = new Set(Object.values(features).flat().filter((feature) => feature.includes(':')));
  if (attributed.size) return [...attributed].sort();
  return testCase.tags.filter((tag) => tag.includes(':') && !tag.startsWith('wrap:')).sort();
}
