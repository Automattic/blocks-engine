import type { WorkerPool } from '../pool/types.js';
import type { SectionSpec } from './section-spec.js';

export interface SiteModel {
  root: string;
  pages: SitePage[];
}

export interface SitePage {
  relPath: string;
  slug: string;
  html: string;
  title: string;
  bodyData?: Record<string, string>;
}

export interface FoundationTokens {
  palette: { name: string; color: string }[];
  typography: { body?: string; display?: string };
  breakpoints: { md?: string; lg?: string; xl?: string };
}

export interface FoundationAggregates {
  palette?: unknown;
  typography?: unknown;
  breakpoints?: unknown;
}

export interface AssetFile {
  relPath: string;
  bytes?: Uint8Array;
  sourcePath?: string;
}

export interface ThemeModel {
  styleCss: string;
  themeJson: Record<string, unknown>;
  templates: Record<string, string>;
  parts: Record<string, string>;
  patterns: Record<string, string>;
  assets: AssetFile[];
}

export interface SectionBlocks {
  spec: SectionSpec;
  blocks: string;
  coverage: number;
}

export interface AssetInventory {
  assets: AssetFile[];
}

export interface AssetVerdicts {
  keep: string[];
  decoration: string[];
}

export interface StageCtx {
  srcDir: string;
  site: SiteModel;
  themeMeta: ThemeMeta;
  warn(msg: string): void;
}

export interface ThemeMeta {
  name: string;
  slug: string;
  author?: string;
}

export interface SiteToThemeHooks {
  onFoundation?(tokens: FoundationTokens, ctx: StageCtx): Promise<FoundationTokens>;
  onSection?(section: SectionBlocks, ctx: StageCtx): Promise<SectionBlocks>;
  onAssets?(inventory: AssetInventory, ctx: StageCtx): Promise<AssetVerdicts>;
  onRefine?(theme: ThemeModel, ctx: StageCtx): Promise<ThemeModel>;
}

export interface SiteToThemeOptions {
  outDir?: string;
  pool?: WorkerPool;
  sections?: Record<string, SectionSpec[]>;
  foundationAggregates?: FoundationAggregates;
  hooks?: SiteToThemeHooks;
  fetchImpl?: typeof fetch;
  coverageFloor?: number;
  themeMeta?: Partial<ThemeMeta>;
}

export interface ThemeBuildResult {
  outDir: string;
  model: ThemeModel;
  written: string[];
  tallies: Record<string, number>;
  warnings: string[];
}
