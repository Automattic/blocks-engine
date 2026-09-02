import type { SectionSpec } from './section-spec.js';

/** Registered theme fontFamily token ({slug, family}); used to map a captured
 *  computed font-family to the nearest registered token (the gate wants a token,
 *  not a raw family, and the file must be self-hosted). */
export interface FontFamilyToken {
  slug: string;
  family: string;
}

/** Collapse whitespace; drop zero-width + soft-hyphen noise. Keeps copy verbatim. */
export function normalizeCopy(s: string): string {
  return s
    .replace(/­/g, '')
    .replace(/[​-‍﻿]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Neutralize a value interpolated into the PHP pattern doc-comment header so a
 * crafted source-derived title/slug cannot break OUT of the doc-comment and
 * inject executable PHP. A title shaped like a comment-close + code + comment-open
 * would, in PHP, close the doc-comment early, run the code, then re-open a comment
 * that swallows the rest through the real header close — a comment-breakout RCE.
 * (`validate_artifacts` also defends this via a tempered header match, but the
 * renderer must never EMIT such a header in the first place.) The header is
 * metadata only, never rendered copy, so stripping comment/PHP-tag delimiters and
 * collapsing to one line is lossless here.
 */
export function sanitizePatternHeaderField(s: string): string {
  return s
    .replace(/\*\//g, '') // cannot close the doc-comment early
    .replace(/\/\*/g, '') // cannot open a nested comment
    .replace(/<\?/g, '') // no PHP open tag
    .replace(/\?>/g, '') // no PHP close tag
    .replace(/[\r\n]+/g, ' ') // single line
    .trim();
}

/** Footer/nav chrome detection — the sitewide footer leaks into every page
 *  capture as trailing sections. We render page body only; header + footer come
 *  from the theme parts, so a captured footer section must be stripped or the
 *  page shows TWO footers (the reconstructed one + the theme footer part). */
function isChromeSection(s: SectionSpec): boolean {
  if (s.interactionModel === 'footer' || s.interactionModel === 'nav') return true;
  const heads = s.headings.map((h) => normalizeCopy(h).toLowerCase());
  const body = (s.bodyText ?? []).map((b) => normalizeCopy(b));
  const buttons = (s.buttonLabels ?? []).map((b) => normalizeCopy(b));
  const allText = [...heads, ...body, ...buttons];
  // GENERIC footer signal: a copyright / attribution line. Only footers carry
  // "© <year>", "all rights reserved", "website by", "powered by" — and
  // stripChrome only removes TRAILING sections, so a stray mid-page mention
  // can't be falsely stripped. (This is what swiftlumber's footer carries:
  // "© 2026 Website by Tokuda Technology".)
  const hasCopyright = allText.some((t) =>
    /(?:©|\(c\)\s|copyright\b|all rights reserved|website by|powered by)/i.test(t),
  );
  // getsnooz's footer nav + newsletter (kept for back-compat).
  const hasFooterNav = heads.includes('shop') && heads.includes('support') && heads.includes('company');
  const hasNewsletter = body.some((b) => /get some good snooz/i.test(b));
  return hasCopyright || hasFooterNav || hasNewsletter;
}

/** Leading site-header chrome: a SHORT top-of-page band dominated by nav links
 *  (+ logo), with no real prose. When the section detector captures the whole
 *  page as <section> tiles (flat Wix pages), the header tile arrives as a
 *  `static` section rather than model `nav`, so the nav-model check alone misses
 *  it and the page renders the menu/contact block above its content. The theme
 *  supplies its own header part, so a leading header band is always redundant.
 *  Guarded by height so a tall hero or content band can never match. */
function isHeaderChrome(s: SectionSpec): boolean {
  if (s.interactionModel === 'nav') return true;
  if (s.height > 200) return false; // headers are thin; heroes/content are tall
  const body = (s.bodyText ?? []).map((b) => normalizeCopy(b)).filter(Boolean);
  const heads = s.headings.map((h) => normalizeCopy(h)).filter(Boolean);
  const shortLinkish = body.filter((b) => b.length <= 30).length;
  const hasLongProse = [...body, ...heads].some((t) => t.length > 80);
  return shortLinkish >= 3 && !hasLongProse;
}

/**
 * Drop trailing sitewide chrome (footer + newsletter) and leading header/nav.
 * Only strips from the ends — a dark-bg content band in the page middle (e.g.
 * the "100 Night Happiness Guarantee" block) is preserved.
 */
export function stripChrome(sections: SectionSpec[]): SectionSpec[] {
  let start = 0;
  let end = sections.length;
  while (start < end && isHeaderChrome(sections[start])) start++;
  while (end > start && isChromeSection(sections[end - 1])) end--;
  return sections.slice(start, end);
}

/**
 * Sanitize a source-captured inline SVG before it's written as a theme asset and
 * referenced from a `core/image`. Loading SVG via `<img src>` already prevents
 * script execution in browsers, but strip active content defensively (the SVG is
 * source-derived = attacker-controlled per the project trust boundary): no
 * <script>, <foreignObject>, event-handler attributes, or javascript: URLs.
 */
export function sanitizeSvgAsset(svg: string): string {
  const sanitized = (
    svg
      .replace(/<script[\s\S]*?<\/script\s*>/gi, '')
      .replace(/<foreignObject[\s\S]*?<\/foreignObject\s*>/gi, '')
      // SMIL animation elements can set event-handler attributes at runtime
      // (e.g. <set attributeName="onload" to="…">) — a direct-navigation XSS
      // vector that the on*= attribute strip below misses. A static icon glyph
      // never animates, so drop these wholesale (both self-closing and paired).
      .replace(/<(set|animate|animateTransform|animateMotion)\b[\s\S]*?(?:\/>|<\/\1\s*>)/gi, '')
      // External / script href on <a>/<use>/<image> (tracking + SSRF-ish on
      // direct navigation). Keep local #fragment refs and inline data:image.
      .replace(
        /\s(?:xlink:)?href\s*=\s*["']\s*(?:https?:|\/\/|data:(?!image\/))[^"']*["']/gi,
        '',
      )
      .replace(/\son[a-z]+\s*=\s*"[^"]*"/gi, '')
      .replace(/\son[a-z]+\s*=\s*'[^']*'/gi, '')
      .replace(/javascript:/gi, '')
      .trim()
  );
  const root = svgRootStartTag(sanitized);
  if (!root) return sanitized;
  const namespaces = root.attributes.filter((attribute) => attribute.name === 'xmlns');
  if (namespaces.length === 1 && namespaces[0].value === 'http://www.w3.org/2000/svg') return sanitized;

  // Theme icon assets are loaded through <img>, which requires the root SVG
  // namespace even when the source browser accepted the inline markup without it.
  if (namespaces.length) {
    const [namespace, ...duplicates] = namespaces;
    let normalized = sanitized;
    for (const duplicate of duplicates.reverse()) {
      normalized = `${normalized.slice(0, duplicate.leadingStart)}${normalized.slice(duplicate.end)}`;
    }
    return `${normalized.slice(0, namespace.start)}xmlns="http://www.w3.org/2000/svg"${normalized.slice(namespace.end)}`;
  }
  return `${sanitized.slice(0, root.nameEnd)} xmlns="http://www.w3.org/2000/svg"${sanitized.slice(root.nameEnd)}`;
}

interface SvgRootAttribute {
  name: string;
  leadingStart: number;
  start: number;
  end: number;
  value: string | null;
}

function svgRootStartTag(svg: string): { nameEnd: number; attributes: SvgRootAttribute[] } | null {
  let cursor = 0;
  while (cursor < svg.length) {
    while (/\s/.test(svg[cursor] ?? '')) cursor++;
    if (svg.startsWith('<?', cursor)) {
      cursor = endOfPreamble(svg, cursor, '?>');
      if (cursor < 0) return null;
      continue;
    }
    if (svg.startsWith('<!--', cursor)) {
      const end = svg.indexOf('-->', cursor + 4);
      if (end < 0) return null;
      cursor = end + 3;
      continue;
    }
    if (svg.startsWith('<!DOCTYPE', cursor)) {
      cursor = endOfDoctype(svg, cursor);
      if (cursor < 0) return null;
      continue;
    }
    break;
  }
  if (!svg.startsWith('<svg', cursor) || !/[\s/>]/.test(svg[cursor + 4] ?? '')) return null;

  const attributes: SvgRootAttribute[] = [];
  const nameEnd = cursor + 4;
  cursor = nameEnd;
  while (cursor < svg.length) {
    const leadingStart = cursor;
    while (/\s/.test(svg[cursor] ?? '')) cursor++;
    if (svg[cursor] === '>') return { nameEnd, attributes };
    if (svg[cursor] === '/' && svg[cursor + 1] === '>') return { nameEnd, attributes };
    if (!svg[cursor]) return null;

    const start = cursor;
    while (cursor < svg.length && !/[\s=/>]/.test(svg[cursor])) cursor++;
    const name = svg.slice(start, cursor);
    if (!name) return null;
    while (/\s/.test(svg[cursor] ?? '')) cursor++;

    let value: string | null = null;
    if (svg[cursor] === '=') {
      cursor++;
      while (/\s/.test(svg[cursor] ?? '')) cursor++;
      const quote = svg[cursor];
      if (quote === '"' || quote === "'") {
        const valueStart = ++cursor;
        while (cursor < svg.length && svg[cursor] !== quote) cursor++;
        if (cursor === svg.length) return null;
        value = svg.slice(valueStart, cursor++);
      } else {
        const valueStart = cursor;
        while (cursor < svg.length && !/[\s>]/.test(svg[cursor])) cursor++;
        value = svg.slice(valueStart, cursor);
      }
    }
    attributes.push({ name, leadingStart, start, end: cursor, value });
  }
  return null;
}

function endOfPreamble(svg: string, start: number, terminator: string): number {
  let quote = '';
  for (let cursor = start + 2; cursor < svg.length; cursor++) {
    const char = svg[cursor];
    if (quote) {
      if (char === quote) quote = '';
      continue;
    }
    if (char === '"' || char === "'") {
      quote = char;
      continue;
    }
    if (svg.startsWith(terminator, cursor)) return cursor + terminator.length;
  }
  return -1;
}

function endOfDoctype(svg: string, start: number): number {
  let quote = '';
  let subsetDepth = 0;
  for (let cursor = start + '<!DOCTYPE'.length; cursor < svg.length; cursor++) {
    const char = svg[cursor];
    if (quote) {
      if (char === quote) quote = '';
      continue;
    }
    if (char === '"' || char === "'") {
      quote = char;
      continue;
    }
    if (svg.startsWith('<!--', cursor)) {
      const end = svg.indexOf('-->', cursor + 4);
      if (end < 0) return -1;
      cursor = end + 2;
      continue;
    }
    if (svg.startsWith('<?', cursor)) {
      const end = endOfPreamble(svg, cursor, '?>');
      if (end < 0) return -1;
      cursor = end - 1;
      continue;
    }
    if (char === '[') {
      subsetDepth++;
      continue;
    }
    if (char === ']') {
      subsetDepth = Math.max(0, subsetDepth - 1);
      continue;
    }
    if (char === '>' && subsetDepth === 0) return cursor + 1;
  }
  return -1;
}
