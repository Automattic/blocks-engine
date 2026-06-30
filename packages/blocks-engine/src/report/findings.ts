import { sanitize } from '../sanitize.js';
import type { FixResult } from '../pool/types.js';
import {
  CONVERT_REPORT_SCHEMA,
  FALLBACK_INVENTORY_CAP,
  type ConversionFinding,
  type ConvertReport,
} from './schema.js';

const MAX_SNIPPET_CHARS = 2_000;

export interface BuildReportInput {
  inputHtml: string;
  blockMarkup: string;
  fixResult: FixResult;
  transformDurationMs: number;
}

function truncateSnippet(html: string): string {
  return Array.from(sanitize(html)).slice(0, MAX_SNIPPET_CHARS).join('');
}

function hasWarningOrError(findings: ConversionFinding[]): boolean {
  return findings.some(
    (finding) => finding.severity === 'warning' || finding.severity === 'error',
  );
}

export function buildReport({
  inputHtml,
  blockMarkup,
  fixResult,
  transformDurationMs,
}: BuildReportInput): ConvertReport {
  const keptIslands = fixResult.htmlIslands.slice(0, FALLBACK_INVENTORY_CAP);
  const fallbacks: ConversionFinding[] = keptIslands.map((island) => ({
    code: 'unconverted_html',
    severity: 'warning',
    message: 'Unconverted HTML fallback preserved.',
    selector: `core/html[${island.index}]`,
    snippet: truncateSnippet(island.html),
  }));

  const diagnostics: ConversionFinding[] = fixResult.fixedIssues.map((issue) => ({
    code: 'normalized_markup',
    severity: 'info',
    message: issue,
  }));

  if (fixResult.degraded) {
    diagnostics.push({
      code: 'conversion_degraded',
      severity: 'warning',
      message: 'Conversion completed with degraded worker results.',
    });
  }

  const kept = keptIslands.length;
  const total = fixResult.htmlIslandCount;
  if (total > kept) {
    diagnostics.push({
      code: 'fallback_inventory_truncated',
      severity: 'info',
      message: 'Fallback inventory truncated.',
      total,
      kept,
    });
  }

  return {
    schema: CONVERT_REPORT_SCHEMA,
    status:
      total > 0 || hasWarningOrError(diagnostics) ? 'success_with_warnings' : 'success',
    blockMarkup,
    fallbacks,
    diagnostics,
    metrics: {
      inputBytes: Buffer.byteLength(inputHtml, 'utf8'),
      outputBytes: Buffer.byteLength(blockMarkup, 'utf8'),
      blockCount: fixResult.blockCount,
      fallbackCount: total,
      diagnosticCount: diagnostics.length,
      transformDurationMs,
    },
  };
}
