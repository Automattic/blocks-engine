//
// Semantic-section pre-conversion (async)
// =======================================
// The PRODUCING half of the convert-or-island hybrid (ported from
// data-liberation-agent's src/lib/replicate/convert-semantic-sections.ts). For a
// page's sections, send the SEMANTIC ones (isSemanticHtml) through rawConvert
// (rawHandler → canonical blocks) on the worker pool and return a
// Map<sectionIndex, {markup, wpHtmlResidue}> the sync reconstructor consumes as
// data — so reconstructNativeAggregate stays synchronous.
//
// The reconstructor's convertedDecision accepts an entry only when
// wpHtmlResidue === 0 (a clean canonical conversion); anything dirty or non-semantic
// is left out of the map and falls back to a verbatim wp:html island, where the
// source DOM + classes survive so the carried CSS binds 1:1.
//
// Sanitizes BEFORE the parser (scripts must never reach rawHandler).
//
import type { SectionSpec } from './section-spec.js';
import type { ConvertedSectionInput } from './native-reconstruct-types.js';
import type { RawConvertResult } from '../pool/types.js';
import { isSemanticHtml } from './semantic-html.js';
import { sanitize } from './html-fallback.js';

/** The subset of WorkerPool the producer needs — batch HTML→blocks conversion. */
export interface RawConverter {
  rawConvert(items: string[]): Promise<RawConvertResult[]>;
}

export async function convertSemanticSections(
  sections: SectionSpec[],
  client: RawConverter,
): Promise<Map<number, ConvertedSectionInput>> {
  const semantic = sections.filter((s) => s.sectionHtml && isSemanticHtml(s.sectionHtml));
  const map = new Map<number, ConvertedSectionInput>();
  if (semantic.length === 0) return map;

  const items = semantic.map((s) => sanitize(s.sectionHtml as string));
  const results = await client.rawConvert(items);
  semantic.forEach((s, i) => {
    const r = results[i] ?? { html: null, wpHtmlResidue: Infinity };
    map.set(s.sectionIndex, { markup: r.html, wpHtmlResidue: r.wpHtmlResidue });
  });
  return map;
}
