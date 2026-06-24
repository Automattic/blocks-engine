import { readFileSync, statSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { collectStaticAssets, type StaticImgRef } from './assets-static.js';
import { parseFontFaces, type ParsedFontFace } from './font-faces.js';
import type { AssetInventory, SitePage, StageCtx } from './types.js';

export interface AssetStageResult {
  inventory: AssetInventory;
  imgRefsByPage: Record<string, StaticImgRef[]>;
  fontCss: string;
  warnings: string[];
}

export function assets(
  ctx: StageCtx,
  opts: { fetchImpl?: typeof fetch }
): Promise<AssetStageResult> {
  void opts.fetchImpl;

  const { assets, imgRefsByPage } = collectStaticAssets(ctx.site, ctx.themeMeta.slug);
  const parsedFontFaces: ParsedFontFace[] = parseFontFaces(...collectLinkedStylesheetCss(ctx));
  void parsedFontFaces;

  return Promise.resolve({
    inventory: { assets },
    imgRefsByPage,
    fontCss: '',
    warnings: [],
  });
}

function collectLinkedStylesheetCss(ctx: StageCtx): string[] {
  const root = resolve(ctx.srcDir);
  const cssByPath = new Map<string, string>();

  for (const page of sortedPages(ctx.site.pages)) {
    for (const tag of page.html.matchAll(/<link\b[^>]*>/gi)) {
      const attrs = attributesFromTag(tag[0]);
      const rel = (attrs.get('rel') ?? '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rel.includes('stylesheet')) continue;

      const cssPath = resolveLocalStylesheet(root, page, attrs.get('href'));
      if (!cssPath || cssByPath.has(cssPath)) continue;

      try {
        cssByPath.set(cssPath, readFileSync(cssPath, 'utf8'));
      } catch {
        // P1-1 treats linked CSS as inventory readiness only.
      }
    }
  }

  return Array.from(cssByPath.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([, css]) => css);
}

function resolveLocalStylesheet(
  root: string,
  page: SitePage,
  href: string | undefined
): string | null {
  const cleanHref = cleanLocalRef(href);
  if (!cleanHref) return null;

  const path = cleanHref.startsWith('/')
    ? resolve(root, `.${cleanHref}`)
    : resolve(root, dirname(page.relPath), cleanHref);

  if (!isInside(root, path) || !isFile(path)) return null;
  return path;
}

function cleanLocalRef(ref: string | undefined): string | null {
  const trimmed = ref?.trim();
  if (!trimmed || trimmed.startsWith('#')) return null;
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(trimmed)) return null;

  const withoutHash = trimmed.split('#', 1)[0] ?? '';
  const withoutQuery = withoutHash.split('?', 1)[0] ?? '';
  if (!withoutQuery) return null;

  let decoded: string;
  try {
    decoded = decodeURIComponent(withoutQuery);
  } catch {
    return null;
  }

  const clean = decoded.trim();
  if (!clean || clean.startsWith('#') || clean.includes('\0')) return null;
  if (/^(?:[a-z][a-z\d+.-]*:|\/\/)/i.test(clean)) return null;
  return clean;
}

function isInside(root: string, file: string): boolean {
  const rel = relative(root, file);
  return rel === '' || (!rel.startsWith('..') && !rel.startsWith('/'));
}

function isFile(path: string): boolean {
  try {
    return statSync(path).isFile();
  } catch {
    return false;
  }
}

function attributesFromTag(tag: string): Map<string, string> {
  const attrs = new Map<string, string>();
  const attrPattern = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;

  for (const match of tag.matchAll(attrPattern)) {
    const key = match[1]?.toLowerCase();
    if (!key || key === 'link') continue;
    attrs.set(key, match[2] ?? match[3] ?? match[4] ?? '');
  }

  return attrs;
}

function sortedPages(pages: SitePage[]): SitePage[] {
  return [...pages].sort((a, b) => {
    const rel = a.relPath.localeCompare(b.relPath);
    if (rel !== 0) return rel;
    return a.slug.localeCompare(b.slug);
  });
}
