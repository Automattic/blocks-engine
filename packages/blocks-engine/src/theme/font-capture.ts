import { parseFontFaces as parseAllFontFaces } from './font-faces.js';
import type { ParsedFontFace } from './font-faces.js';

export type { ParsedFontFace } from './font-faces.js';

/** A captured font with a resolved LOCAL asset path inside the theme. */
export interface LocalFontFace extends ParsedFontFace {
  /** Theme-relative path the font file was written to, e.g. "assets/fonts/Larsseit-Regular.woff". */
  localPath: string;
}

/** Substrings in a font URL that mark third-party widget fonts (not the site's own). */
const THIRD_PARTY_FONT_HOST_HINTS = ['klaviyo.com', 'gstatic.com', 'typekit.net', 'use.typekit'];

export function parseFontFaces(...cssOrHtml: string[]): ParsedFontFace[] {
  return parseAllFontFaces(...cssOrHtml).filter(
    (face) => !THIRD_PARTY_FONT_HOST_HINTS.some((host) => face.src.toLowerCase().includes(host)),
  );
}

export function absolutizeFontUrl(src: string, baseUrl?: string): string {
  const trimmed = src.trim();
  if (trimmed.startsWith('//')) return `https:${trimmed}`;
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  if (baseUrl) {
    try {
      return new URL(trimmed, baseUrl).toString();
    } catch {
      return trimmed;
    }
  }
  return trimmed;
}

export function fontFilename(face: ParsedFontFace): string {
  const clean = face.src.split('?')[0].split('#')[0];
  const seg = clean.slice(clean.lastIndexOf('/') + 1);
  if (seg && fontExtension(seg)) {
    return seg.replace(/[^A-Za-z0-9._-]/g, '_');
  }
  const familySlug = face.family.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const italic = face.style === 'italic' ? '-italic' : '';
  return `${familySlug}-${face.weight}${italic}.${face.format}`;
}

function fontExtension(url: string): string | null {
  const clean = url.split('?')[0].split('#')[0];
  const dot = clean.lastIndexOf('.');
  if (dot < 0) return null;
  const ext = clean.slice(dot + 1).toLowerCase();
  return ['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg'].includes(ext) ? ext : null;
}

export function buildFontFaceCss(faces: LocalFontFace[]): string {
  if (faces.length === 0) return '';
  const rules = faces.map((f) => {
    const fmt = cssFormat(f.format);
    return `@font-face {
\tfont-family: '${f.family}';
\tsrc: url('${f.localPath}')${fmt ? ` format('${fmt}')` : ''};
\tfont-weight: ${f.weight};
\tfont-style: ${f.style};
\tfont-display: swap;
}`;
  });
  return `\n/*\n * Self-hosted source fonts. Captured from the source site's @font-face\n * declarations and downloaded into assets/fonts/ so headings + body render in\n * the real typeface rather than a system fallback.\n */\n${rules.join('\n')}\n`;
}

function cssFormat(ext: string): string {
  switch (ext) {
    case 'woff2': return 'woff2';
    case 'woff': return 'woff';
    case 'ttf': return 'truetype';
    case 'otf': return 'opentype';
    case 'eot': return 'embedded-opentype';
    case 'svg': return 'svg';
    default: return '';
  }
}

export interface ThemeFontFamily {
  fontFamily: string;
  name: string;
  slug: string;
  fontFace?: Array<{
    fontFamily: string;
    fontWeight: string;
    fontStyle: string;
    src: string[];
  }>;
}

export function baseFamilyName(family: string): string {
  return family
    .replace(/[-_\s]+(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique)$/i, '')
    .replace(/[-_\s]+(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique)\b/gi, '')
    .replace(/[-_\s]+$/g, '')
    .trim() || family;
}

function weightFromFamilyName(family: string, declared: string): string {
  const m = /(thin|extralight|ultralight|light|regular|book|normal|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy)/i.exec(family);
  if (!m) return declared;
  const map: Record<string, string> = {
    thin: '100', extralight: '200', ultralight: '200', light: '300',
    regular: '400', book: '400', normal: '400', medium: '500',
    semibold: '600', demibold: '600', bold: '700', extrabold: '800',
    ultrabold: '800', black: '900', heavy: '900',
  };
  return map[m[1].toLowerCase()] ?? declared;
}

export function consolidateFontFaces<T extends ParsedFontFace>(faces: T[]): T[] {
  const byKey = new Map<string, T>();
  for (const f of faces) {
    const base = baseFamilyName(f.family);
    const weight = weightFromFamilyName(f.family, f.weight);
    const key = `${base.toLowerCase()}|${weight}|${f.style}`;
    const merged = { ...f, family: base, weight } as T;
    const existing = byKey.get(key);
    if (!existing) {
      byKey.set(key, merged);
      continue;
    }
    // Prefer woff2, then the URL without a hash-style suffix (cleaner filename).
    const score = (x: ParsedFontFace): number =>
      (x.format === 'woff2' ? 0 : 2) + (/_[0-9a-f]{8,}\./i.test(x.src) ? 1 : 0);
    if (score(merged) < score(existing)) byKey.set(key, merged);
  }
  return [...byKey.values()];
}

function normalizeFamilyBase(name: string): string {
  return name
    .toLowerCase()
    .replace(/^["']|["']$/g, '')
    .replace(/[0-9]{3,}/g, '') // drop builder hashes (e.g. ...light1475496)
    .replace(
      /[-_ ]+(thin|extra-?light|ultra-?light|light|book|regular|normal|roman|text|medium|semi-?bold|demi-?bold|demi|bold|extra-?bold|ultra-?bold|black|heavy|italic|oblique)\b/g,
      '',
    )
    .replace(/[-_ ]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .trim();
}

export function matchCapturedFamily(
  requestedStack: string | null | undefined,
  faces: ParsedFontFace[],
): string | null {
  if (!requestedStack) return null;
  const first = requestedStack.split(',')[0].trim().replace(/^["']|["']$/g, '').toLowerCase();
  if (!first) return null;
  for (const f of faces) {
    if (f.family.toLowerCase() === first) return f.family;
  }
  // Suffix-tolerant fallback: match on the normalized base name.
  const firstBase = normalizeFamilyBase(first);
  if (firstBase.length >= 4) {
    for (const f of faces) {
      if (normalizeFamilyBase(f.family) === firstBase) return f.family;
    }
  }
  return null;
}
