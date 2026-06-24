import type { SectionSpec } from './section-spec.js';

export interface CapturedSectionContent {
  text: string[];
  images: string[];
}

export interface CoverageResult {
  textCoverage: number;
  missingImages: string[];
  lost: boolean;
}

export const TEXT_FLOOR = 0.5;

export function captureSectionContent(_spec: SectionSpec): CapturedSectionContent {
  return { text: [], images: [] };
}

export function measureSectionCoverage(
  _captured: CapturedSectionContent,
  _renderedMarkup: string
): CoverageResult {
  return { textCoverage: 1, missingImages: [], lost: false };
}
