export { compose } from './compose.js';
export { createWorker } from './pool/pool.js';
export { isRawConvertible, UNWRAP_SELECTOR } from './raw-convertible.js';
export { escapeHtml, escapeHtmlAttr, escapeHtmlText } from './escape.js';
export { buildEmbedBlock, guessEmbedProvider } from './embed.js';
export { sanitize } from './sanitize.js';
export { blockMarkupRoundtrips } from './validate.js';
export { verifyComposedOutput } from './output-verify.js';
export { heuristicBlocks } from './heuristics.js';
export { genericHtmlToBlocks } from './catalog.js';
export { composeFromRecipes } from './recipe-table.js';

export type * from './types.js';
export type { VerifyResult } from './output-verify.js';
export type { HeuristicResult } from './heuristics.js';
export type {
  CreateWorker,
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './pool/types.js';
export type { PoolEvent } from './pool/events.js';
