import { PIPELINE_ISLAND_OPENER } from './block-policy';
import { genericHtmlToBlocks } from './catalog';
import { sanitize } from './sanitize';
import { scanForInjection } from './validate';
import type { ConversionContext, Converter, HtmlFallback } from './types';

type HtmlFallbackEmitter = (html: string) => string;

function defaultHtmlFallback(html: string): string {
  const inner = sanitize(html);
  const violations = scanForInjection(inner);
  if (violations.length > 0) {
    throw new Error(`html-fallback sanitization left injection vectors: ${violations.join('; ')}`);
  }
  return `${PIPELINE_ISLAND_OPENER}\n${inner.trim()}\n<!-- /wp:html -->`;
}

function normalizeHtmlFallback(htmlFallback: HtmlFallback | undefined): HtmlFallbackEmitter {
  return typeof htmlFallback === 'function' ? htmlFallback : defaultHtmlFallback;
}

export function compose(
  html: string,
  ctx: ConversionContext,
  opts?: { converters?: Converter[]; htmlFallback?: HtmlFallback },
): string {
  for (const converter of opts?.converters ?? []) {
    const out = converter(html, ctx);
    if (out != null && out.trim()) return out;
  }

  const htmlFallback = normalizeHtmlFallback(opts?.htmlFallback);
  const catalog = genericHtmlToBlocks(html, ctx, htmlFallback);
  if (catalog != null && catalog.trim()) return catalog;

  return htmlFallback(html);
}
