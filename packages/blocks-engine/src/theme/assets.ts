import type { StaticImgRef } from './assets-static.js';
import type { AssetInventory, StageCtx } from './types.js';

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
  void ctx;
  void opts;
  return stageStub('assets');
}

function stageStub(name: string): never {
  throw new Error(`STAGE_STUB:${name}`);
}
