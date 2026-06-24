import type { WorkerPool } from '../pool/types.js';
import type { ChromeSlugs } from './chrome-signature.js';
import type { StageCtx } from './types.js';

export interface ChromeResult {
  parts: Record<string, string>;
  slugsByPage: Record<string, ChromeSlugs>;
  mainHtmlByPage: Record<string, string>;
  warnings: string[];
}

export async function chrome(ctx: StageCtx, pool: WorkerPool): Promise<ChromeResult> {
  void ctx;
  void pool;
  return {
    parts: {},
    slugsByPage: {},
    mainHtmlByPage: {},
    warnings: [],
  };
}
