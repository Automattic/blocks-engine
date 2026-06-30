import type { SectionSpec } from './section-spec.js';
import { parseColor } from './native-color.js';

export interface SectionPadding {
  padTopPx?: number;
  padBottomPx?: number;
}

/**
 * A responsive font size that equals the captured px at a 1440px viewport and
 * scales down on mobile.
 */
export function responsiveFontSize(px: number | undefined): string {
  if (!px || px <= 0) return '';
  const floor = Math.min(px, Math.max(16, Math.round(px * 0.5)));
  const vw = (px / 14.4).toFixed(1);
  return `clamp(${floor}px, ${vw}vw, ${px}px)`;
}

export function responsiveSpace(px: number): string {
  const p = Math.max(0, Math.round(px));
  if (p < 24) return `${p}px`;
  const floor = Math.max(16, Math.round(p * 0.45));
  const vw = (p / 14.4).toFixed(2);
  return `clamp(${floor}px, ${vw}vw, ${p}px)`;
}

export function sectionPad(section: SectionSpec): SectionPadding {
  const t = section.layout?.padTopPx;
  const b = section.layout?.padBottomPx;
  return {
    ...(typeof t === 'number' ? { padTopPx: t } : {}),
    ...(typeof b === 'number' ? { padBottomPx: b } : {}),
  };
}

export function centerOf(section: SectionSpec): boolean {
  return section.textAlign == null ? true : section.textAlign === 'center';
}

export function buttonJustify(section: SectionSpec): 'left' | 'center' {
  return centerOf(section) ? 'center' : 'left';
}

export function isTintedSection(section: SectionSpec): boolean {
  const b = section.backgroundBrightness;
  if (b >= 245 || b < 100) return false;
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(section.backgroundColor || '');
  if (!m) return false;
  const sat = Math.max(+m[1], +m[2], +m[3]) - Math.min(+m[1], +m[2], +m[3]);
  return sat >= 25;
}

export function opaqueHex(color: string | null | undefined, opts: { rejectNearWhite: boolean }): string | null {
  if (!color) return null;
  const parsed = parseColor(color);
  if (!parsed) return null;
  if (parsed.a < 0.6) return null;
  const { r, g, b } = parsed;
  const bright = (r + g + b) / 3;
  const spread = Math.max(r, g, b) - Math.min(r, g, b);
  if (opts.rejectNearWhite) {
    if (bright >= 248) return null;
    if (spread <= 6 && bright >= 230) return null;
  }
  return '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('');
}

export function opaqueLiteralBgHex(color: string | null | undefined): string | null {
  return opaqueHex(color, { rejectNearWhite: false });
}

export function opaqueTintHex(color: string | null | undefined): string | null {
  return opaqueHex(color, { rejectNearWhite: true });
}

export function isDarkSection(section: SectionSpec): boolean {
  return section.backgroundBrightness < 100;
}
