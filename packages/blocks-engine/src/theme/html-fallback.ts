import { PIPELINE_ISLAND_OPENER } from '../block-policy.js';
import { escapeHtmlAttr, escapeHtmlText } from '../escape.js';
import type { SectionSpec } from './section-spec.js';
import type { SectionSpecButton, SectionSpecImage } from './section-spec.js';
import type { InternalLinkMap } from './url-rewrite.js';

export interface HtmlFallbackOpts {
  mediaUrlMap?: Map<string, string>;
  linkMap?: InternalLinkMap;
}

export type IslandTier = 'responsive' | 'styled' | 'verbatim';

export function sanitize(html: string): string {
  return html;
}

export function isWpLayoutMarkup(html: string): boolean {
  return html.length > 0 && false;
}

export function selectIslandSource(
  section: { sectionHtml?: string; styledHtml?: string },
): { source: string; tier: IslandTier } {
  return { source: section.sectionHtml ?? section.styledHtml ?? '', tier: 'verbatim' };
}

export function buildHtmlFallbackBlock(
  sectionHtml: string,
  opts: HtmlFallbackOpts = {},
): string {
  void opts;
  return `${PIPELINE_ISLAND_OPENER}\n${sectionHtml.trim()}\n<!-- /wp:html -->`;
}

function nonEmpty(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

function imageHtml(image: SectionSpecImage): string | null {
  const src = nonEmpty(image.url) ?? nonEmpty(image.sourceUrl);
  if (!src) return null;

  return `<figure><img src="${escapeHtmlAttr(src)}" alt="${escapeHtmlAttr(image.alt)}"></figure>`;
}

function buttonHtml(button: SectionSpecButton): string | null {
  const label = nonEmpty(button.label);
  if (!label) return null;

  const href = nonEmpty(button.href) ?? '#';
  return `<p><a href="${escapeHtmlAttr(href)}">${escapeHtmlText(label)}</a></p>`;
}

function semanticFallbackHtml(spec: SectionSpec): string {
  const fragments: string[] = [];

  for (const [index, heading] of spec.headings.entries()) {
    const text = nonEmpty(heading);
    if (!text) continue;

    const level = index === 0 ? 2 : 3;
    fragments.push(`<h${level}>${escapeHtmlText(text)}</h${level}>`);
  }

  for (const body of spec.bodyText) {
    const text = nonEmpty(body);
    if (text) fragments.push(`<p>${escapeHtmlText(text)}</p>`);
  }

  const structuredButtons =
    spec.buttons?.map(buttonHtml).filter((html): html is string => html !== null) ?? [];
  const labelButtons = spec.buttonLabels
    .map((label) => nonEmpty(label))
    .filter((label): label is string => label !== null)
    .map((label) => `<p><a href="#">${escapeHtmlText(label)}</a></p>`);
  const buttons = structuredButtons.length > 0 ? structuredButtons : labelButtons;
  fragments.push(...buttons);

  fragments.push(...spec.images.map(imageHtml).filter((html): html is string => html !== null));

  return fragments.length > 0 ? `<section>${fragments.join('')}</section>` : '<section></section>';
}

export function buildCoverageIsland(spec: SectionSpec): string {
  const body = spec.sectionHtml?.trim() ? spec.sectionHtml : semanticFallbackHtml(spec);
  return `${PIPELINE_ISLAND_OPENER}\n${body}\n<!-- /wp:html -->`;
}
