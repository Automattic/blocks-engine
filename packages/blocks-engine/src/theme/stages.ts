import { BlocksEngineError } from '../errors.js';
import type { WorkerPool } from '../pool/types.js';
import type { SectionSpec } from './section-spec.js';
import type {
  FoundationAggregates,
  FoundationTokens,
  SectionBlocks,
  SiteModel,
  SitePage,
  SiteToThemeHooks,
  SiteToThemeOptions,
  StageCtx,
  ThemeBuildResult,
  ThemeMeta,
  ThemeModel,
} from './types.js';

export function siteToTheme(
  srcDir: string,
  options?: SiteToThemeOptions
): Promise<ThemeBuildResult> {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function writeTheme(model: ThemeModel, outDir: string): Promise<string[]> {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function ingest(srcDir: string): SiteModel {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function sectionExtract(page: SitePage): SectionSpec[] {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function foundation(
  site: SiteModel,
  aggregates?: FoundationAggregates
): FoundationTokens {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function reconstruct(
  specs: SectionSpec[],
  ctx: StageCtx,
  pool: WorkerPool,
  hooks: SiteToThemeHooks,
  coverageFloor: number
): Promise<SectionBlocks[]> {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}

export function assemble(parts: {
  site: SiteModel;
  tokens: FoundationTokens;
  pages: Record<string, SectionBlocks[]>;
  meta: ThemeMeta;
}): ThemeModel {
  throw new BlocksEngineError('not implemented', {
    code: 'STAGE_STUB',
    hint: 'Phase 0 skeleton',
  });
}
