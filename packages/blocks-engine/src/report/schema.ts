export const CONVERT_REPORT_SCHEMA = 'blocks-engine/convert-report/v1' as const;

export const CONVERT_REPORT_STATUSES = [
  'success',
  'success_with_warnings',
  'failed',
] as const;

export type ConvertReportStatus = (typeof CONVERT_REPORT_STATUSES)[number];

export const CONVERSION_FINDING_SEVERITIES = [
  'info',
  'warning',
  'error',
] as const;

export type ConversionFindingSeverity =
  (typeof CONVERSION_FINDING_SEVERITIES)[number];

export const CONVERSION_FINDING_CODES = [
  'unconverted_html',
  'normalized_markup',
  'conversion_degraded',
  'fallback_inventory_truncated',
  'content_dropped',
] as const;

export type ConversionFindingCode = (typeof CONVERSION_FINDING_CODES)[number];

export const FALLBACK_INVENTORY_CAP = 100;

export interface ConversionFinding {
  code: string;
  severity: ConversionFindingSeverity;
  message?: string;
  selector?: string;
  snippet?: string;
  [extra: string]: unknown;
}

export interface ConversionMetrics {
  inputBytes: number;
  outputBytes: number;
  blockCount: number;
  fallbackCount: number;
  diagnosticCount: number;
  transformDurationMs: number;
}

export interface ConvertReport {
  schema: typeof CONVERT_REPORT_SCHEMA;
  status: ConvertReportStatus;
  blockMarkup: string;
  fallbacks: ConversionFinding[];
  diagnostics: ConversionFinding[];
  metrics: ConversionMetrics;
}
