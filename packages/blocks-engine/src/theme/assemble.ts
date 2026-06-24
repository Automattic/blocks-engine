import { escapeHtmlAttr } from '../escape.js';
import { rewriteHtmlImageSrcs, type StaticImgRef } from './assets-static.js';
import type {
  AssetFile,
  FoundationTokens,
  SectionBlocks,
  SiteModel,
  ThemeMeta,
  ThemeModel,
} from './types.js';

type ThemeAssemblyParts = {
  site: SiteModel;
  tokens: FoundationTokens;
  pages: Record<string, SectionBlocks[]>;
  meta: ThemeMeta;
  assets?: AssetFile[];
  fontCss?: string;
  imgRefsByPage?: Record<string, StaticImgRef[]>;
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

  return {
    styleCss: appendFontCss(styleCss, parts.fontCss),
    themeJson: buildThemeJson(parts.tokens, palette, fontFamilies),
    templates: {
      'index.html': buildIndexTemplate(
        parts.site,
        parts.pages,
        parts.imgRefsByPage ?? {},
        themeSlug
      ),
    },
    parts: {},
    patterns: {},
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
  fontFamilies: FontFamilyEntry[]
): Record<string, unknown> {
  const bodyFontSlug = fontFamilies[0]?.slug;

  return {
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
    styles: {
      color: {
        background: colorValueFor(palette, ['surface-base', 'background', 'white'], '#ffffff'),
        text: colorValueFor(palette, ['text-default', 'foreground', 'black'], '#111111'),
      },
      ...(bodyFontSlug
        ? { typography: { fontFamily: `var(--wp--preset--font-family--${bodyFontSlug})` } }
        : {}),
    },
  };
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

function buildIndexTemplate(
  site: SiteModel,
  pages: Record<string, SectionBlocks[]>,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): string {
  const pageBlocks = orderedPageBlocks(site, pages, imgRefsByPage, themeSlug);
  const blocks = pageBlocks.length > 0
    ? pageBlocks.join('\n\n')
    : '<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->';

  return `<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">
${blocks}
</main>
<!-- /wp:group -->
`;
}

function orderedPageBlocks(
  site: SiteModel,
  pages: Record<string, SectionBlocks[]>,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): string[] {
  const out: string[] = [];
  const seen = new Set<string>();

  for (const page of site.pages) {
    const blocks = blocksForPage(page.slug, pages, imgRefsByPage, themeSlug);
    if (!blocks) continue;
    out.push(blocks);
    seen.add(page.slug);
  }

  for (const [slug, sections] of Object.entries(pages).sort(([a], [b]) => a.localeCompare(b))) {
    if (seen.has(slug)) continue;
    const blocks = rewritePageBlocks(
      slug,
      joinSectionBlocks(sections, imgRefsByPage[slug] ?? []),
      imgRefsByPage,
      themeSlug
    );
    if (blocks) out.push(blocks);
  }

  return out;
}

function blocksForPage(
  slug: string,
  pages: Record<string, SectionBlocks[]>,
  imgRefsByPage: Record<string, StaticImgRef[]>,
  themeSlug: string
): string | null {
  return rewritePageBlocks(
    slug,
    joinSectionBlocks(pages[slug] ?? [], imgRefsByPage[slug] ?? []),
    imgRefsByPage,
    themeSlug
  );
}

function joinSectionBlocks(sections: SectionBlocks[], refs: StaticImgRef[]): string | null {
  const blocks = sections
    .map((section) => sectionBlocksWithMissingImages(section, refs).trim())
    .filter(Boolean);
  return blocks.length > 0 ? blocks.join('\n\n') : null;
}

function sectionBlocksWithMissingImages(section: SectionBlocks, refs: StaticImgRef[]): string {
  const blocks = section.blocks.trim();
  const missingImages = missingImageBlocks(section, refs, blocks);
  if (missingImages.length === 0) return blocks;
  return [blocks, ...missingImages].filter(Boolean).join('\n\n');
}

function missingImageBlocks(
  section: SectionBlocks,
  refs: StaticImgRef[],
  blocks: string
): string[] {
  const out: string[] = [];
  const emitted = new Set<string>();

  for (const image of section.spec.images) {
    const ref = refs.find(
      (candidate) => candidate.ref === image.url || candidate.ref === image.sourceUrl
    );
    if (!ref || emitted.has(ref.ref) || blocks.includes(ref.ref)) continue;

    emitted.add(ref.ref);
    out.push(imageBlock(ref.ref, image.alt));
  }

  return out;
}

function imageBlock(src: string, alt: string): string {
  return [
    '<!-- wp:image -->',
    `<figure class="wp-block-image"><img src="${escapeHtmlAttr(src)}" alt="${escapeHtmlAttr(alt)}"/></figure>`,
    '<!-- /wp:image -->',
  ].join('\n');
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
