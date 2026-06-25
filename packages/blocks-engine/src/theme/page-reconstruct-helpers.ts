import type { SectionSpec } from './section-spec.js';

export interface FontFamilyToken {
  slug: string;
  family: string;
}

export function normalizeCopy(s: string): string {
  return s;
}

export function sanitizePatternHeaderField(s: string): string {
  return s;
}

export function stripChrome(sections: SectionSpec[]): SectionSpec[] {
  return sections;
}

export function sanitizeSvgAsset(svg: string): string {
  return svg;
}
