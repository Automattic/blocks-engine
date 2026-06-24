import { BlocksEngineError } from './errors.js';
import type { WorkerPool } from './pool/types.js';
import type { ConversionContext, Converter, HtmlFallback } from './types.js';

export interface ConvertOptions {
  pool?: WorkerPool;
  converters?: Converter[];
  htmlFallback?: HtmlFallback;
}

export async function convert(
  html: string,
  ctx?: Partial<ConversionContext>,
  opts?: ConvertOptions,
): Promise<string> {
  void html;
  void ctx;
  void opts;
  throw new BlocksEngineError('Main-entry convert is not wired yet.', {
    code: 'CONVERT_NOT_IMPLEMENTED',
    hint: 'Use the /wp entry until the async main-entry convert implementation is wired.',
  });
}
