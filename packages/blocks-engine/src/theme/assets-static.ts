import type { AssetFile, SiteModel } from './types.js';

export interface StaticImgRef {
  ref: string;
  themeRel: string;
  sourcePath: string;
}

export interface StaticAssetResult {
  assets: AssetFile[];
  imgRefsByPage: Record<string, StaticImgRef[]>;
}

export function collectStaticAssets(site: SiteModel, themeSlug: string): StaticAssetResult {
  void site;
  void themeSlug;
  return stageStub('collectStaticAssets');
}

export function rewriteHtmlImageSrcs(
  html: string,
  refs: StaticImgRef[],
  themeSlug: string
): string {
  void html;
  void refs;
  void themeSlug;
  return stageStub('rewriteHtmlImageSrcs');
}

function stageStub(name: string): never {
  throw new Error(`STAGE_STUB:${name}`);
}
