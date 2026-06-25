import type { SectionSpec } from './section-spec.js';

export interface SectionPadding {
  padTopPx?: number;
  padBottomPx?: number;
}

export function responsiveFontSize(px: number | undefined): string {
  void px;
  throw new Error('native-layout responsiveFontSize is not implemented');
}

export function responsiveSpace(px: number): string {
  void px;
  throw new Error('native-layout responsiveSpace is not implemented');
}

export function sectionPad(section: SectionSpec): SectionPadding {
  void section;
  throw new Error('native-layout sectionPad is not implemented');
}

export function centerOf(section: SectionSpec): boolean {
  void section;
  throw new Error('native-layout centerOf is not implemented');
}

export function buttonJustify(section: SectionSpec): 'left' | 'center' {
  void section;
  throw new Error('native-layout buttonJustify is not implemented');
}

export function isTintedSection(section: SectionSpec): boolean {
  void section;
  throw new Error('native-layout isTintedSection is not implemented');
}

export function opaqueTintHex(color: string | null | undefined): string | null {
  void color;
  throw new Error('native-layout opaqueTintHex is not implemented');
}

export function isDarkSection(section: SectionSpec): boolean {
  void section;
  throw new Error('native-layout isDarkSection is not implemented');
}
