/**
 * Benchmark corpus loader.
 *
 * A "fixture" is one corpus case: an `id` (`<producer>/<case>`) and the
 * `SectionSpec[]` that drives reconstruct. "Producer" is the corpus family — the
 * first path segment of the id — which gives attribution its cross-producer
 * signal. v1 draws from the section-renderer case groups (≥2 producers).
 */
import { nativeSectionRendererCaseGroups } from '../../src/__fixtures__/native-section-renderer-cases.js';
import type { SectionSpec } from '../../src/theme/section-spec.js';

export interface BenchFixture {
  id: string;
  producer: string;
  specs: SectionSpec[];
}

export function loadFixtures(): BenchFixture[] {
  const fixtures: BenchFixture[] = [];
  const groups = nativeSectionRendererCaseGroups();
  for (const [producer, cases] of Object.entries(groups)) {
    for (const testCase of cases) {
      fixtures.push({ id: `${producer}/${testCase.id}`, producer, specs: [testCase.section] });
    }
  }
  return fixtures.sort((a, b) => a.id.localeCompare(b.id));
}
