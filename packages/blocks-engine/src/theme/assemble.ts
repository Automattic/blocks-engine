import { rewriteHtmlImageSrcs, type StaticImgRef } from './assets-static.js';
import type { ChromeSlugs } from './chrome-signature.js';
import {
  appendCarriedSourceCss,
  hasCarriedSourceCss,
  type ThemeAssemblySourceCssInput,
} from './source-css-carry.js';
import { planTemplates, type TemplatePlan } from './template-plan.js';
import type {
  AssetFile,
  FoundationTokens,
  SectionBlocks,
  SiteModel,
  ThemeMeta,
  ThemeModel,
} from './types.js';

type ThemeAssemblyParts = ThemeAssemblySourceCssInput & {
  site: SiteModel;
  tokens: FoundationTokens;
  pages: Record<string, SectionBlocks[]>;
  meta: ThemeMeta;
  assets?: AssetFile[];
  fontCss?: string;
  imgRefsByPage?: Record<string, StaticImgRef[]>;
  chromeParts?: Record<string, string>;
  chromeSlugsByPage?: Record<string, ChromeSlugs>;
  layoutOffsetWrapperClass?: string;
  styleBlocks?: Record<string, Record<string, unknown>>;
};

type PaletteEntry = {
  name: string;
  slug: string;
  color: string;
};

type FontFamilyEntry = {
  fontFamily: string;
  name: string;
  slug: string;
};

export function assemble(parts: ThemeAssemblyParts): ThemeModel {
  const themeSlug = slugify(parts.meta.slug || parts.meta.name) || 'blocks-engine-theme';
  const palette = buildPalette(parts.tokens);
  const fontFamilies = buildFontFamilies(parts.tokens);
  const styleCss = buildStyleCss(parts.meta, themeSlug);
  const carriedSourceCss = hasCarriedSourceCss(parts.sourceCss);
  const templatePlan = planTemplates(parts.site);

  return {
    styleCss: appendCarriedSourceCss(appendFontCss(styleCss, parts.fontCss), parts.sourceCss),
    themeJson: buildThemeJson(parts.tokens, palette, fontFamilies, {
      omitStyles: carriedSourceCss,
    }),
    templates: {
      'front-page.html': buildFrontPageTemplate(
        parts.site,
        parts.pages,
        parts.imgRefsByPage ?? {},
        themeSlug,
        parts.chromeParts ?? {},
        parts.chromeSlugsByPage,
        templatePlan,
        parts.layoutOffsetWrapperClass
      ),
      'index.html': buildGenericQueriedContentTemplate(parts.chromeParts ?? {}, parts.layoutOffsetWrapperClass),
      'page.html': buildGenericQueriedContentTemplate(parts.chromeParts ?? {}, parts.layoutOffsetWrapperClass),
    },
    parts: { ...(parts.chromeParts ?? {}) },
    patterns: {},
    ...(parts.styleBlocks ? { styleBlocks: parts.styleBlocks } : {}),
    assets: collectAssets(parts),
  };
}

function appendFontCss(styleCss: string, fontCss: string | undefined): string {
  return fontCss ? `${styleCss}${fontCss}` : styleCss;
}

function buildStyleCss(meta: ThemeMeta, themeSlug: string): string {
  return buildThemeHeader([
    ['Theme Name', meta.name],
    ['Author', meta.author ?? 'Automattic'],
    ['Description', `${meta.name} block theme assembled by @automattic/blocks-engine.`],
    ['Version', '0.1.0'],
    ['Requires at least', '6.5'],
    ['Requires PHP', '8.0'],
    ['License', 'GPL-3.0-or-later'],
    ['Text Domain', themeSlug],
    ['Tags', 'block-theme, full-site-editing'],
  ]);
}

function buildThemeHeader(fields: Array<[string, string]>): string {
  const body = fields.map(([key, value]) => `${key}: ${value}`).join('\n');
  return `/*\n${body}\n*/\n`;
}

function buildThemeJson(
  tokens: FoundationTokens,
  palette: PaletteEntry[],
  fontFamilies: FontFamilyEntry[],
  options?: { omitStyles?: boolean }
): Record<string, unknown> {
  const bodyFontSlug = fontFamilies[0]?.slug;
  const themeJson: Record<string, unknown> = {
    $schema: 'https://schemas.wp.org/trunk/theme.json',
    version: 3,
    settings: {
      appearanceTools: true,
      color: {
        custom: false,
        defaultPalette: false,
        palette,
      },
      typography: {
        customFontSize: false,
        defaultFontSizes: false,
        fluid: false,
        fontFamilies,
      },
      layout: {
        contentSize: tokens.breakpoints.lg ?? tokens.breakpoints.md ?? '900px',
        wideSize: tokens.breakpoints.xl ?? tokens.breakpoints.lg ?? '1280px',
      },
      spacing: {
        units: ['px', 'em', 'rem', '%', 'vh', 'vw'],
      },
    },
  };

  if (!options?.omitStyles) {
    themeJson.styles = {
      color: {
        background: colorValueFor(palette, ['surface-base', 'background', 'white'], '#ffffff'),
        text: colorValueFor(palette, ['text-default', 'foreground', 'black'], '#111111'),
      },
      ...(bodyFontSlug
        ? { typography: { fontFamily: `var(--wp--preset--font-family--${bodyFontSlug})` } }
        : {}),
    };
  }

  return themeJson;
}

function buildPalette(tokens: FoundationTokens): PaletteEntry[] {
  const used = new Set<string>();
  const palette: PaletteEntry[] = [];

  for (const token of tokens.palette) {
    const name = token.name.trim();
    const color = token.color.trim();
    if (!name || !color) continue;

    const baseSlug = slugify(name) || 'color';
    const slug = uniqueSlug(baseSlug, used);
    palette.push({ name, slug, color });
  }

  return palette;
}

function buildFontFamilies(tokens: FoundationTokens): FontFamilyEntry[] {
  const out: FontFamilyEntry[] = [];
  const used = new Set<string>();
  addFontFamily(out, used, 'Body', tokens.typography.body);
  addFontFamily(out, used, 'Display', tokens.typography.display);
  return out;
}

function addFontFamily(
  out: FontFamilyEntry[],
  used: Set<string>,
  name: string,
  fontFamily: string | undefined
): void {
  const family = fontFamily?.trim();
  if (!family) return;

  const baseSlug = slugify(name) || 'font';
  const slug = uniqueSlug(baseSlug, used);
  out.push({ fontFamily: family, name, slug });
}

function colorValueFor(palette: PaletteEntry[], preferredSlugs: string[], fallback: string): string {
  for (const slug of preferredSlugs) {
    const match = palette.find((entry) => entry.slug === slug);
    if (match) return `var(--wp--preset--color--${match.slug})`;
  }
  return fallback;
}

function buildFrontPageTemplate(
  site: SiteModel,
  pages: Record<string, SectionBlocks[]>,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string,
  chromeParts: Record<string, string>,
  chromeSlugsByPage: Record<string, ChromeSlugs> | undefined,
  templatePlan: TemplatePlan,
  layoutOffsetWrapperClass: string | undefined
): string {
  const homeSlug = homeSlugFromPlan(site, templatePlan);
  const blocks =
    blocksForPage(homeSlug, pages, imgRefsByPage, themeSlug) ??
    '<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->';

  const mainTemplate = `<!-- wp:group ${mainGroupAttrs(layoutOffsetWrapperClass, { type: 'constrained' })} -->
<main class="${mainGroupClass(layoutOffsetWrapperClass)}">
${blocks}
</main>
<!-- /wp:group -->
`;
  if (!chromeSlugsByPage) return mainTemplate;

  const homeSlugs = chromeSlugsByPage[homeSlug] ?? {
    header: 'header',
    footer: 'footer',
  };
  if (!hasChromePart(chromeParts, homeSlugs.header) || !hasChromePart(chromeParts, homeSlugs.footer)) {
    return mainTemplate;
  }

  return [
    templatePartRef(homeSlugs.header, 'header'),
    mainTemplate.trimEnd(),
    templatePartRef(homeSlugs.footer, 'footer'),
    '',
  ].join('\n');
}

function buildGenericQueriedContentTemplate(
  chromeParts: Record<string, string>,
  layoutOffsetWrapperClass: string | undefined
): string {
  const mainTemplate =
    `<!-- wp:group ${mainGroupAttrs(layoutOffsetWrapperClass)} --><main class="${mainGroupClass(layoutOffsetWrapperClass)}"><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main><!-- /wp:group -->`;

  if (!hasChromePart(chromeParts, 'header') || !hasChromePart(chromeParts, 'footer')) {
    return `${mainTemplate}\n`;
  }

  return [
    templatePartRef('header', 'header'),
    mainTemplate,
    templatePartRef('footer', 'footer'),
    '',
  ].join('\n');
}

function hasChromePart(chromeParts: Record<string, string>, slug: string): boolean {
  return Object.prototype.hasOwnProperty.call(chromeParts, `${slug}.html`);
}

function mainGroupAttrs(
  layoutOffsetWrapperClass: string | undefined,
  layout?: Record<string, unknown>
): string {
  return JSON.stringify({
    tagName: 'main',
    ...(layoutOffsetWrapperClass ? { className: layoutOffsetWrapperClass } : {}),
    ...(layout ? { layout } : {}),
  });
}

function mainGroupClass(layoutOffsetWrapperClass: string | undefined): string {
  return ['wp-block-group', layoutOffsetWrapperClass]
    .filter((value): value is string => Boolean(value))
    .map(escapeHtmlAttr)
    .join(' ');
}

function homeSlugFromPlan(site: SiteModel, templatePlan: TemplatePlan): string {
  const plannedHome = Object.entries(templatePlan.templatesByPage).find(
    ([, template]) => template === 'front-page'
  )?.[0];

  return (
    plannedHome ??
    site.pages[0]?.slug ??
    'home'
  );
}

function templatePartRef(slug: string, tagName: 'header' | 'footer'): string {
  return `<!-- wp:template-part ${JSON.stringify({ slug, tagName })} /-->`;
}

function blocksForPage(
  slug: string,
  pages: Record<string, SectionBlocks[]>,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): string | null {
  return rewritePageBlocks(
    slug,
    joinSectionBlocks(pages[slug] ?? []),
    imgRefsByPage,
    themeSlug
  );
}

function joinSectionBlocks(sections: SectionBlocks[]): string | null {
  const blocks = sections
    .map((section) => section.blocks.trim())
    .filter(Boolean);
  return blocks.length > 0 ? blocks.join('\n\n') : null;
}

function rewritePageBlocks(
  slug: string,
  blocks: string | null,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): string | null {
  if (!blocks) return null;
  return rewriteHtmlImageSrcs(blocks, imgRefsByPage[slug] ?? [], themeSlug);
}

function collectAssets(parts: ThemeAssemblyParts): AssetFile[] {
  const assetsByRelPath = new Map<string, AssetFile>();

  for (const asset of [
    ...assetFilesFromUnknown(parts.site),
    ...assetFilesFromUnknown(parts.tokens),
    ...assetFilesFromUnknown(parts.meta),
  ]) {
    assetsByRelPath.set(asset.relPath, asset);
  }

  for (const asset of parts.assets ?? []) {
    assetsByRelPath.set(asset.relPath, asset);
  }

  return [...assetsByRelPath.values()];
}

function assetFilesFromUnknown(value: unknown): AssetFile[] {
  if (!value || typeof value !== 'object' || !('assets' in value)) return [];
  const assets = (value as { assets?: unknown }).assets;
  if (!Array.isArray(assets)) return [];

  return assets.filter(isAssetFile);
}

function isAssetFile(value: unknown): value is AssetFile {
  return Boolean(
    value &&
      typeof value === 'object' &&
      typeof (value as AssetFile).relPath === 'string' &&
      (value as AssetFile).relPath.trim()
  );
}

function uniqueSlug(baseSlug: string, used: Set<string>): string {
  let slug = baseSlug;
  let suffix = 2;
  while (used.has(slug)) {
    slug = `${baseSlug}-${suffix}`;
    suffix += 1;
  }
  used.add(slug);
  return slug;
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function escapeHtmlAttr(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}
