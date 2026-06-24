import { join } from 'node:path';

import { createWorker } from '../pool/pool.js';
import { assemble } from './assemble.js';
import { foundation } from './foundation.js';
import { ingest } from './ingest.js';
import { reconstruct } from './reconstruct.js';
import { sectionExtract } from './section-extract.js';
import { writeTheme } from './write-theme.js';
import type {
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
  void options?.fetchImpl;

  try {
    const baseTokens = foundation(site, options?.foundationAggregates);
    const tokens = hooks.onFoundation ? await hooks.onFoundation(baseTokens, ctx) : baseTokens;
    const pages: Record<string, SectionBlocks[]> = {};

    for (const page of site.pages) {
      const specs = options?.sections?.[page.slug] ?? sectionExtract(page);
      pages[page.slug] = await reconstruct(specs, ctx, pool, hooks, coverageFloor);
    }

    const assembled = assemble({ site, tokens, pages, meta: themeMeta });
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

function normalizeThemeMeta(srcDir: string, meta: Partial<ThemeMeta> | undefined): ThemeMeta {
  const fallback = srcDir.split(/[\\/]+/).filter(Boolean).at(-1) ?? 'blocks-engine-theme';
  const slug = slugify(meta?.slug ?? fallback);
  return {
    name: meta?.name ?? titleFromSlug(slug),
    slug,
    ...(meta?.author ? { author: meta.author } : {}),
  };
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
