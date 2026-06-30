import { sanitize } from '../sanitize.js';
import type { FixResult } from '../pool/types.js';
import {
  CONVERT_REPORT_SCHEMA,
  FALLBACK_INVENTORY_CAP,
  type ConversionFinding,
  type ConvertReport,
} from './schema.js';
import { HTML_FINDING_CHAR_CAP } from './limits.js';

export interface BuildReportInput {
  inputHtml: string;
  blockMarkup: string;
  fixResult: FixResult;
  transformDurationMs: number;
}

function truncateSnippet(html: string): string {
  return Array.from(sanitize(html)).slice(0, HTML_FINDING_CHAR_CAP).join('');
}

function hasWarningOrError(findings: ConversionFinding[]): boolean {
  return findings.some(
    (finding) => finding.severity === 'warning' || finding.severity === 'error',
  );
}

function hasRealContent(html: string): boolean {
  return /\S/u.test(html);
}

export function buildReport({
  inputHtml,
  blockMarkup,
  fixResult,
  transformDurationMs,
}: BuildReportInput): ConvertReport {
  const keptIslands = fixResult.htmlIslands.slice(0, FALLBACK_INVENTORY_CAP);
  const outputBytes = Buffer.byteLength(blockMarkup, 'utf8');
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

  if (hasRealContent(inputHtml) && (fixResult.blockCount === 0 || outputBytes === 0)) {
    diagnostics.push({
      code: 'content_dropped',
      severity: 'warning',
      message: 'Input HTML contained content, but conversion produced an empty block result.',
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
      outputBytes,
      blockCount: fixResult.blockCount,
      fallbackCount: total,
      diagnosticCount: diagnostics.length,
      transformDurationMs,
    },
  };
}
