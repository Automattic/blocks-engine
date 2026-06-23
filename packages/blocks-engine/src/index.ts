export { compose } from './compose.js';
export { createWorker } from './pool/pool.js';
export { isRawConvertible, UNWRAP_SELECTOR } from './raw-convertible.js';
export { escapeHtml, escapeHtmlAttr, escapeHtmlText } from './escape.js';
export { serializeBlockAttrs } from './serialize.js';
export { buildEmbedBlock, guessEmbedProvider } from './embed.js';
export { sanitize } from './sanitize.js';
export { blockMarkupRoundtrips, scanForInjection } from './validate.js';
export { verifyComposedOutput } from './output-verify.js';
export { heuristicBlocks } from './heuristics.js';
export { genericHtmlToBlocks } from './catalog.js';
export { composeFromRecipes } from './recipe-table.js';
export { PIPELINE_ISLAND_OPENER, PIPELINE_ISLAND_NAME } from './block-policy.js';

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
