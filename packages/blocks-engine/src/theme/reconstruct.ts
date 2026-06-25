import { convert } from '../convert.js';
import { escapeHtmlAttr, escapeHtmlText } from '../escape.js';
import type { WorkerPool } from '../pool/types.js';
import { buildHtmlFallbackBlock, selectIslandSource, type HtmlFallbackOpts } from './html-fallback.js';
import { hasUnmigratedRemoteAsset, scanForInjection } from './injection-scan.js';
import { captureSectionContent, measureConvertedCoverage } from './section-coverage.js';
import type { SectionSpec, SectionSpecButton, SectionSpecImage } from './section-spec.js';
import type { SectionBlocks, SiteToThemeHooks, StageCtx } from './types.js';
import { rewriteMediaUrls } from './url-rewrite.js';
import type { InternalLinkMap } from './url-rewrite.js';

const BLOCK_COMMENT_RE = /<!--\s+wp:/;
const HTML_ISLAND_RE = /<!--\s+wp:(?:core\/)?html(?:\s|-->|{)/;
const UNRESOLVED_PLACEHOLDER_RE = /\{\{[\w -]+\}\}/;

type RewriteCtx = StageCtx & {
  mediaUrlMap?: Map<string, string>;
  linkMap?: InternalLinkMap;
};

function nonEmpty(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

function imageHtml(image: SectionSpecImage): string | null {
  const src = nonEmpty(image.url) ?? nonEmpty(image.sourceUrl);
  if (!src) return null;

  const alt = escapeHtmlAttr(image.alt);
  return `<figure><img src="${escapeHtmlAttr(src)}" alt="${alt}"></figure>`;
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

function sectionInputHtml(spec: SectionSpec): string {
  return spec.sectionHtml?.trim() ? spec.sectionHtml : semanticFallbackHtml(spec);
}

function skeletonCoverage(blocks: string): number {
  if (HTML_ISLAND_RE.test(blocks)) return 0;
  return BLOCK_COMMENT_RE.test(blocks) ? 1 : 0;
}

function fallbackOptions(ctx: StageCtx): HtmlFallbackOpts {
  const rewriteCtx = ctx as RewriteCtx;
  const opts: HtmlFallbackOpts = {};
  if (rewriteCtx.mediaUrlMap) opts.mediaUrlMap = rewriteCtx.mediaUrlMap;
  if (rewriteCtx.linkMap) opts.linkMap = rewriteCtx.linkMap;
  return opts;
}

function rewriteConvertedMedia(blocks: string, ctx: StageCtx): string {
  const mediaUrlMap = (ctx as RewriteCtx).mediaUrlMap;
  return mediaUrlMap && mediaUrlMap.size > 0 ? rewriteMediaUrls(blocks, mediaUrlMap) : blocks;
}

function convertedOutputLost(spec: SectionSpec, blocks: string): boolean {
  return (
    measureConvertedCoverage(captureSectionContent(spec), blocks).lost ||
    scanForInjection(blocks).length > 0 ||
    hasUnmigratedRemoteAsset(blocks) ||
    UNRESOLVED_PLACEHOLDER_RE.test(blocks)
  );
}

function buildLossFallback(spec: SectionSpec, ctx: StageCtx): string {
  const { source } = selectIslandSource(spec);
  return buildHtmlFallbackBlock(source, fallbackOptions(ctx));
}

export async function reconstruct(
  specs: SectionSpec[],
  ctx: StageCtx,
  pool: WorkerPool,
  hooks: SiteToThemeHooks,
  coverageFloor: number
): Promise<SectionBlocks[]> {
  const sections: SectionBlocks[] = [];

  for (const spec of specs) {
    let blocks = await convert(sectionInputHtml(spec), { url: '' }, { pool });
    if (!HTML_ISLAND_RE.test(blocks)) {
      blocks = rewriteConvertedMedia(blocks, ctx);
      if (convertedOutputLost(spec, blocks)) {
        blocks = buildLossFallback(spec, ctx);
      }
    }

    const section: SectionBlocks = {
      spec,
      blocks,
      coverage: skeletonCoverage(blocks),
    };

    sections.push(
      section.coverage <= coverageFloor && hooks.onSection
        ? await hooks.onSection(section, ctx)
        : section,
    );
  }

  return sections;
}
