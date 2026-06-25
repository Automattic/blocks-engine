import type { CoverageResult } from './section-coverage.js';
import type { SectionSpec } from './section-spec.js';

export type FallbackReasonCode = 'dropped_images' | 'text_coverage_below_floor' | 'decorative_asset_triaged';
export type FallbackRepairClass = 'recover_dropped_media' | 'restructure_section_blocks' | 'replace_with_structural_block';

export interface FallbackDiagnostic {
  id: string;
  page: string;
  sectionIndex: number;
  interactionModel: string;
  selector: string;
  severity: 'warning';
  reasonCode: FallbackReasonCode;
  /** 'none' = not an island record (decorative_asset_triaged removals). */
  islandKind: 'verbatim' | 'styled' | 'responsive' | 'none';
  droppedImages: string[];
  textCoverage: number;
  suggestedRepairClass: FallbackRepairClass;
  sourceHtmlPreview: string;
  emittedBlockPreview: string;
}

export interface AssetRemoval {
  url: string;
  sectionSelector: string;
  description: string;
}

export function buildFallbackDiagnostic(args: {
  page: string;
  slug: string;
  section: SectionSpec;
  coverage: CoverageResult;
  islandKind: 'verbatim' | 'styled' | 'responsive';
  islandMarkup: string;
}): FallbackDiagnostic {
  void args;
  throw new Error('buildFallbackDiagnostic implementation pending');
}

export function buildTriageRemovalDiagnostic(args: {
  page: string;
  slug: string;
  sectionIndex: number;
  interactionModel: string;
  removal: AssetRemoval;
  ordinal: number;
}): FallbackDiagnostic {
  void args;
  throw new Error('buildTriageRemovalDiagnostic implementation pending');
}
