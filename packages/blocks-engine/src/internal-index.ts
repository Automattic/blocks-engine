export { compose } from './compose';
export { createWorker } from './pool/pool';
export { isRawConvertible, UNWRAP_SELECTOR } from './raw-convertible';
export { escapeHtml, escapeHtmlAttr, escapeHtmlText } from './escape';
export { buildEmbedBlock, guessEmbedProvider } from './embed';
export { sanitize } from './sanitize';
export { blockMarkupRoundtrips } from './validate';
export { verifyComposedOutput } from './output-verify';
export { heuristicBlocks } from './heuristics';
export { genericHtmlToBlocks } from './catalog';
export { composeFromRecipes } from './recipe-table';

export type * from './types';
export type { VerifyResult } from './output-verify';
export type { HeuristicResult } from './heuristics';
export type {
  CreateWorker,
  FixResult,
  RawConvertResult,
  WorkerPool,
  WorkerPoolOptions,
} from './pool/types';
export type { PoolEvent } from './pool/events';
