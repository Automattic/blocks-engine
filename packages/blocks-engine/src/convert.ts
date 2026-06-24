import { compose } from './compose.js';
import { createWorker } from './pool/pool.js';
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
  const fullCtx: ConversionContext =
    ctx?.mediaMap === undefined
      ? { url: ctx?.url ?? '' }
      : { url: ctx?.url ?? '', mediaMap: ctx.mediaMap };
  const ownsPool = opts?.pool === undefined;
  const pool = opts?.pool ?? createWorker();

  try {
    const [raw] = await pool.rawConvert([html]);
    const blockMarkup =
      raw.html !== null && raw.wpHtmlResidue === 0
        ? raw.html
        : compose(html, fullCtx, {
            converters: opts?.converters,
            htmlFallback: opts?.htmlFallback,
          });
    const [fixed] = await pool.canonicalize([blockMarkup]);
    return fixed.html;
  } finally {
    if (ownsPool) {
      await pool.stop();
    }
  }
}
