import { performance } from 'node:perf_hooks';

import { buildReport } from '../report/findings.js';
import type { WorkerPool } from '../pool/types.js';
import type {
  SectionBlocks,
  ThemeConversionDiagnostics,
  ThemeConversionPageDiagnostic,
} from './types.js';

export interface ThemeConversionDiagnosticsInputPage {
  slug: string;
  inputHtml?: string;
  sections: SectionBlocks[];
}

export async function deriveThemeConversionDiagnostics(
  pages: Record<string, SectionBlocks[]>,
  pool: WorkerPool,
): Promise<ThemeConversionDiagnostics> {
  return buildThemeConversionDiagnostics(
    Object.entries(pages).map(([slug, sections]) => ({ slug, sections })),
    pool,
  );
}

export async function buildThemeConversionDiagnostics(
  pages: ThemeConversionDiagnosticsInputPage[],
  pool: WorkerPool,
): Promise<ThemeConversionDiagnostics> {
  if (pages.length === 0) {
    return emptyConversionDiagnostics();
  }

  const pageBlockMarkup = pages.map((page) => joinSectionBlocks(page.sections));
  const startedAt = performance.now();
  const fixResults = await pool.canonicalize(pageBlockMarkup);
  const transformDurationMs = (performance.now() - startedAt) / pages.length;

  const diagnostics = pages.map((page, index): ThemeConversionPageDiagnostic => {
    const blockMarkup = pageBlockMarkup[index] ?? '';
    const fixResult = fixResults[index];
    if (!fixResult) {
      throw new Error(`Missing canonicalize result for page "${page.slug}"`);
    }

    const report = buildReport({
      inputHtml: page.inputHtml ?? blockMarkup,
      blockMarkup: fixResult.html,
      fixResult,
      transformDurationMs,
    });

    const degraded = report.diagnostics.some(
      (finding) => finding.code === 'conversion_degraded',
    );

    return {
      slug: page.slug,
      status: report.status,
      fallbackCount: report.metrics.fallbackCount,
      degraded,
    };
  });

  return {
    pages: diagnostics,
    totalFallbacks: diagnostics.reduce((total, page) => total + page.fallbackCount, 0),
    pagesWithFallbacks: diagnostics.filter((page) => page.fallbackCount > 0).length,
    degradedPages: diagnostics.filter((page) => page.degraded).length,
  };
}

function emptyConversionDiagnostics(): ThemeConversionDiagnostics {
  return {
    pages: [],
    totalFallbacks: 0,
    pagesWithFallbacks: 0,
    degradedPages: 0,
  };
}

function joinSectionBlocks(sections: SectionBlocks[]): string {
  return sections
    .map((section) => section.blocks.trim())
    .filter(Boolean)
    .join('\n\n');
}
