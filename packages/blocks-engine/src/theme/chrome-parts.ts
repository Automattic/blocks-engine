import type { WorkerPool } from '../pool/types.js';
import type { StageCtx } from './types.js';

export async function buildChromePart(
  html: string,
  ctx: StageCtx,
  pool: WorkerPool
): Promise<string> {
  void html;
  void ctx;
  void pool;
  return '';
}
