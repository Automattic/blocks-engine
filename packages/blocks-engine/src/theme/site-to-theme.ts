import { basename, extname, join, resolve } from 'node:path';

import { createWorker } from '../pool/pool.js';
import { assemble } from './assemble.js';
import { assets as runAssetsStage } from './assets.js';
import { chrome } from './chrome.js';
import { foundation } from './foundation.js';
import { ingest } from './ingest.js';
import { detectLayoutOffsetWrapper } from './layout-offset-wrapper.js';
import { reconstruct } from './reconstruct.js';
import { sectionExtract } from './section-extract.js';
import { collectSourceAssets, type MediaAsset } from './source-assets.js';
import { shouldCarrySourceCss } from './source-css-carry.js';
import { applyHoistSwaps, hoistVariations, type HoistedVariation } from './variation-hoist.js';
import { writeTheme } from './write-theme.js';
import type {
  AssetFile,
  AssetInventory,
  AssetVerdicts,
  SectionBlocks,
  SiteToThemeOptions,
  StageCtx,
  ThemeBuildResult,
  ThemeMeta,
} from './types.js';

export async function siteToTheme(
  srcDir: string,
  options?: SiteToThemeOptions
): Promise<ThemeBuildResult> {
  const site = ingest(srcDir);
  const warnings: string[] = [];
  const themeMeta = normalizeThemeMeta(srcDir, options?.themeMeta);
  const ctx: StageCtx = {
    srcDir,
    site,
    themeMeta,
    warn(message: string) {
      warnings.push(message);
    },
  };
  const ownsPool = options?.pool === undefined;
  const pool = options?.pool ?? createWorker();
  const outDir = options?.outDir ?? join(srcDir, 'theme');
  const coverageFloor = options?.coverageFloor ?? 0;
  const hooks = options?.hooks ?? {};

  try {
    const baseTokens = foundation(site, options?.foundationAggregates);
    const tokens = hooks.onFoundation ? await hooks.onFoundation(baseTokens, ctx) : baseTokens;
    const chromeRes = await chrome(ctx, pool);
    const pages: Record<string, SectionBlocks[]> = {};

    for (const page of site.pages) {
      const specs =
        options?.sections?.[page.slug] ??
        sectionExtract({ ...page, html: chromeRes.mainHtmlByPage[page.slug] ?? page.html });
      pages[page.slug] = await reconstruct(
        specs,
        ctx,
        pool,
        hooks,
        coverageFloor,
        options?.renderOptions?.[page.slug]
      );
    }

    const styleBlocks =
      options?.variationHoist === false ? undefined : hoistPageStyleBlocks(site, pages, warnings);
    const assetStage = await runAssetsStage(ctx, { fetchImpl: options?.fetchImpl });
    const sourceAssets = collectSourceAssets(
      site.root,
      site.pages.map((page) => ({ relPath: page.relPath, html: page.html }))
    );
    const layoutOffsetWrapperClass = detectLayoutOffsetWrapper(
      homePage(site)?.html ?? '',
      sourceAssets.css
    );
    const carrySourceCss = shouldCarrySourceCss(sourceAssets.css, options);
    const inventory = hooks.onAssets
      ? filterDecorativeAssets(
          assetStage.inventory,
          await hooks.onAssets(assetStage.inventory, ctx)
        )
      : assetStage.inventory;
    const sourceCssCarry = carrySourceCss
      ? prepareSourceCssCarry(sourceAssets.css, sourceAssets.mediaAssets, inventory.assets)
      : undefined;
    const assembled = assemble({
      site,
      tokens,
      pages,
      meta: themeMeta,
      assets: sourceCssCarry?.assets ?? inventory.assets,
      fontCss: assetStage.fontCss,
      imgRefsByPage: assetStage.imgRefsByPage,
      chromeParts: chromeRes.parts,
      chromeSlugsByPage: chromeRes.slugsByPage,
      layoutOffsetWrapperClass,
      styleBlocks,
      sourceCss: sourceCssCarry?.css,
    });
    const model = hooks.onRefine ? await hooks.onRefine(assembled, ctx) : assembled;
    const written = await writeTheme(model, outDir);

    return {
      outDir,
      model,
      written,
      tallies: {
        pages: site.pages.length,
        sections: Object.values(pages).reduce((sum, sections) => sum + sections.length, 0),
        templates: Object.keys(model.templates).length,
        parts: Object.keys(model.parts).length,
        patterns: Object.keys(model.patterns).length,
        assets: model.assets.length,
        warnings: warnings.length,
      },
      warnings,
    };
  } finally {
    if (ownsPool) {
      await pool.stop();
    }
  }
}

function hoistPageStyleBlocks(
  site: { pages: Array<{ slug: string }> },
  pages: Record<string, SectionBlocks[]>,
  warnings: string[]
): Record<string, Record<string, unknown>> | undefined {
  const hoistPages = site.pages
    .map((page) => ({
      slug: page.slug,
      markup: joinHoistSections(pages[page.slug] ?? []),
    }))
    .filter((page) => page.markup);

  if (hoistPages.length === 0) return undefined;

  try {
    const hoisted = hoistVariations(hoistPages);
    if (hoisted.variations.length === 0) return undefined;

    for (const sections of Object.values(pages)) {
      for (const section of sections) {
        section.blocks = applyHoistSwaps(section.blocks, hoisted.variations);
      }
    }

    return styleBlocksFromVariations(hoisted.variations);
  } catch (error) {
    warnings.push(
      `variation hoist failed (continuing un-hoisted): ${
        error instanceof Error ? error.message : String(error)
      }`
    );
    return undefined;
  }
}

function joinHoistSections(sections: SectionBlocks[]): string {
  return sections
    .map((section) => section.blocks.trim())
    .filter(Boolean)
    .join('\n\n');
}

function styleBlocksFromVariations(
  variations: HoistedVariation[]
): Record<string, Record<string, unknown>> {
  return Object.fromEntries(
    variations.map((variation) => [
      `${variation.slug}.json`,
      {
        version: 3,
        slug: variation.slug,
        title: variation.title,
        blockTypes: variation.blockTypes,
        styles: variation.styles,
      },
    ])
  );
}

function prepareSourceCssCarry(
  css: string,
  mediaAssets: MediaAsset[],
  inventoryAssets: AssetFile[]
): { css: string; assets: AssetFile[] } {
  if (mediaAssets.length === 0) {
    return { css: rebaseSourceCssMediaUrls(css, []), assets: inventoryAssets };
  }

  const occupiedByRel = new Map<string, AssetFile>();
  for (const asset of inventoryAssets) {
    occupiedByRel.set(asset.relPath, asset);
  }

  const carriedAssets: AssetFile[] = [];
  const rewrites: Array<{ from: string; to: string }> = [];

  for (const mediaAsset of mediaAssets) {
    const targetRel = availableSourceCssMediaRel(mediaAsset, occupiedByRel);
    rewrites.push({
      from: `media/${basename(mediaAsset.themeRel)}`,
      to: targetRel,
    });

    const existing = occupiedByRel.get(targetRel);
    if (!existing || !sameSourceAsset(existing, mediaAsset.srcAbs)) {
      const asset = { relPath: targetRel, sourcePath: mediaAsset.srcAbs };
      occupiedByRel.set(targetRel, asset);
      carriedAssets.push(asset);
    }
  }

  return {
    css: rebaseSourceCssMediaUrls(css, rewrites),
    assets: sortAssetFiles([...inventoryAssets, ...carriedAssets]),
  };
}

function availableSourceCssMediaRel(
  mediaAsset: MediaAsset,
  occupiedByRel: Map<string, AssetFile>
): string {
  const existing = occupiedByRel.get(mediaAsset.themeRel);
  if (!existing || sameSourceAsset(existing, mediaAsset.srcAbs)) return mediaAsset.themeRel;

  const ext = extname(mediaAsset.themeRel);
  const base = ext ? mediaAsset.themeRel.slice(0, -ext.length) : mediaAsset.themeRel;
  let suffix = 2;
  let candidate = `${base}-${suffix}${ext}`;

  while (
    occupiedByRel.has(candidate) &&
    !sameSourceAsset(occupiedByRel.get(candidate), mediaAsset.srcAbs)
  ) {
    suffix += 1;
    candidate = `${base}-${suffix}${ext}`;
  }

  return candidate;
}

function sameSourceAsset(asset: AssetFile | undefined, sourcePath: string): boolean {
  return Boolean(asset?.sourcePath && resolve(asset.sourcePath) === resolve(sourcePath));
}

function rebaseSourceCssMediaUrls(
  css: string,
  rewrites: Array<{ from: string; to: string }>
): string {
  let out = css;
  for (const rewrite of rewrites) {
    out = out.split(`url(${rewrite.from})`).join(`url(${rewrite.to})`);
  }
  return out;
}

function sortAssetFiles(files: AssetFile[]): AssetFile[] {
  return [...files].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return (a.sourcePath ?? '').localeCompare(b.sourcePath ?? '');
  });
}

function filterDecorativeAssets(
  inventory: AssetInventory,
  verdicts: AssetVerdicts
): AssetInventory {
  const decoration = new Set(verdicts.decoration);
  if (decoration.size === 0) return inventory;

  return {
    assets: inventory.assets.filter((asset) => !decoration.has(asset.relPath)),
  };
}

function normalizeThemeMeta(srcDir: string, meta: Partial<ThemeMeta> | undefined): ThemeMeta {
  const fallback = srcDir.split(/[\\/]+/).filter(Boolean).at(-1) ?? 'blocks-engine-theme';
  const slug = slugify(meta?.slug ?? fallback);
  return {
    name: meta?.name ?? titleFromSlug(slug),
    slug,
    ...(meta?.author ? { author: meta.author } : {}),
  };
}

function homePage(site: { pages: Array<{ slug: string; html: string }> }): { html: string } | undefined {
  return site.pages.find((page) => page.slug === 'home') ?? site.pages[0];
}

function titleFromSlug(slug: string): string {
  return slug
    .split('-')
    .filter(Boolean)
    .map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`)
    .join(' ') || 'Blocks Engine Theme';
}

function slugify(value: string): string {
  return (
    value
      .toLowerCase()
      .replace(/[^a-z0-9-]+/g, '-')
      .replace(/-{2,}/g, '-')
      .replace(/^-+|-+$/g, '') || 'blocks-engine-theme'
  );
}
