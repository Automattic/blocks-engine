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

  return {
    styleCss: buildStyleCss(parts.meta, themeSlug),
    themeJson: buildThemeJson(parts.tokens, palette, fontFamilies),
    templates: {
      'index.html': buildIndexTemplate(parts.site, parts.pages),
    },
    parts: {},
    patterns: {},
    assets: collectAssets(parts),
  };
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

function buildIndexTemplate(site: SiteModel, pages: Record<string, SectionBlocks[]>): string {
  const pageBlocks = orderedPageBlocks(site, pages);
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

function orderedPageBlocks(site: SiteModel, pages: Record<string, SectionBlocks[]>): string[] {
  const out: string[] = [];
  const seen = new Set<string>();

  for (const page of site.pages) {
    const blocks = blocksForPage(page.slug, pages);
    if (!blocks) continue;
    out.push(blocks);
    seen.add(page.slug);
  }

  for (const [slug, sections] of Object.entries(pages).sort(([a], [b]) => a.localeCompare(b))) {
    if (seen.has(slug)) continue;
    const blocks = joinSectionBlocks(sections);
    if (blocks) out.push(blocks);
  }

  return out;
}

function blocksForPage(slug: string, pages: Record<string, SectionBlocks[]>): string | null {
  return joinSectionBlocks(pages[slug] ?? []);
}

function joinSectionBlocks(sections: SectionBlocks[]): string | null {
  const blocks = sections.map((section) => section.blocks.trim()).filter(Boolean);
  return blocks.length > 0 ? blocks.join('\n\n') : null;
}

function collectAssets(parts: ThemeAssemblyParts): AssetFile[] {
  return [
    ...assetFilesFromUnknown(parts.site),
    ...assetFilesFromUnknown(parts.tokens),
    ...assetFilesFromUnknown(parts.meta),
  ];
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
