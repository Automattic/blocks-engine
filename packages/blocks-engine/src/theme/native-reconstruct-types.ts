import type { FallbackDiagnostic } from './fallback-diagnostic.js';
import type { FontFamilyToken } from './page-reconstruct-helpers.js';
import type { CoverageResult } from './section-coverage.js';
import type { SectionSpec, SectionSpecForm } from './section-spec.js';

export interface FormRemainder {
  forms: SectionSpecForm[];
}

export interface NativeRenderOut {
  markup: string;
  expectedText: string[];
  bodyText: string[];
  assets: string[];
  flags: string[];
  iconAssets: Array<{ path: string; svg: string }>;
  remainder?: FormRemainder;
}

export interface NativeRenderCtx {
  mediaTextIndex: number;
  iconCounter: number;
  paletteTokens: Array<{ slug: string; hex: string }>;
  fontFamilies: FontFamilyToken[];
}

export interface NativeSectionResult {
  spec: SectionSpec;
  blocks: string;
  coverage: CoverageResult;
  expectedText: string[];
  bodyText: string[];
  expectedAssets: string[];
  provenanceFlags: string[];
  fallbackDiagnostics: FallbackDiagnostic[];
  iconAssets: Array<{ path: string; svg: string }>;
  heroIsCover?: boolean;
  remainder?: FormRemainder;
}

export type NativeSectionDecisionKind = 'converted' | 'native' | 'fallback';

export interface NativeSectionDecision extends NativeSectionResult {
  decision: NativeSectionDecisionKind;
}

export interface NativeReconstructAggregate {
  sections: NativeSectionDecision[];
  sectionMarkup: string[];
  expectedText: string[];
  bodyText: string[];
  expectedAssets: string[];
  provenanceFlags: string[];
  fallbackDiagnostics: FallbackDiagnostic[];
  iconAssets: Array<{ path: string; svg: string }>;
  heroIsCover: boolean;
}

export interface ConvertedSectionInput {
  markup: string | null;
  wpHtmlResidue: number;
}

export interface SectionRenderOptions {
  mediaUrlMap?: Map<string, string>;
  convertedSections?: Map<number, ConvertedSectionInput>;
  paletteTokens?: Array<{ slug: string; hex: string }>;
  fontFamilies?: FontFamilyToken[];
  sourceUrl?: string;
  slug?: string;
}
