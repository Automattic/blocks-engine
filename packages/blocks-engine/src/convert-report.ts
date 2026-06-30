import { performance } from 'node:perf_hooks';

import { compose } from './compose.js';
import { createWorker } from './pool/pool.js';
import { buildReport } from './report/findings.js';
import type { ConvertReport } from './report/schema.js';
import type { ConvertOptions } from './convert.js';
import type { ConversionContext } from './types.js';

function normalizeContext(ctx?: Partial<ConversionContext>): ConversionContext {
  return ctx?.mediaMap === undefined
    ? { url: ctx?.url ?? '' }
    : { url: ctx?.url ?? '', mediaMap: ctx.mediaMap };
}

export async function convertReport(
  html: string,
  ctx?: Partial<ConversionContext>,
  opts?: ConvertOptions,
): Promise<ConvertReport> {
  const startedAt = performance.now();
  const fullCtx = normalizeContext(ctx);
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
    const transformDurationMs = performance.now() - startedAt;

    return buildReport({
      inputHtml: html,
      blockMarkup: fixed.html,
      fixResult: fixed,
      transformDurationMs,
    });
  } finally {
    if (ownsPool) {
      await pool.stop();
    }
  }
}
