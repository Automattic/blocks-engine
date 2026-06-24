import { convert as convertMainEntry } from './convert.js';
import type { WorkerPool } from './pool/types.js';
import type { ConversionContext, Converter, HtmlFallback } from './types.js';

export async function convert(
  html: string,
  ctx?: Partial<ConversionContext>,
  opts?: { pool?: WorkerPool; converters?: Converter[]; htmlFallback?: HtmlFallback },
): Promise<string> {
  return convertMainEntry(html, ctx, opts);
}

export { compose } from './compose.js';
export { BlocksEngineError } from './errors.js';
export { createWorker } from './pool/pool.js';
export { isRawConvertible, UNWRAP_SELECTOR } from './raw-convertible.js';
export { escapeHtml, escapeHtmlAttr, escapeHtmlText } from './escape.js';
export { serializeBlockAttrs } from './serialize.js';
export { buildEmbedBlock, guessEmbedProvider } from './embed.js';
export { sanitize } from './sanitize.js';
export { blockMarkupRoundtrips, scanForInjection } from './validate.js';
export { validateBlockMarkup } from './validate-block-markup.js';
export { walkBlocks } from './block-tree.js';
export { validateBlockContract } from './block-contract.js';
export { verifyComposedOutput } from './output-verify.js';
export { heuristicBlocks } from './heuristics.js';
export { genericHtmlToBlocks } from './catalog.js';
export { composeFromRecipes } from './recipe-table.js';
export { PIPELINE_ISLAND_OPENER, PIPELINE_ISLAND_NAME } from './block-policy.js';

export type { BlocksEngineErrorOptions } from './errors.js';
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
export type { ParsedBlock } from './block-tree.js';
export type { BlockContractIssue } from './block-contract.js';
