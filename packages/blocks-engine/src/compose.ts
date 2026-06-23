import type { ConversionContext, Converter, HtmlFallback } from './types';

export function compose(
  html: string,
  ctx: ConversionContext,
  opts?: { converters?: Converter[]; htmlFallback?: HtmlFallback },
): string {
  void ctx;
  void opts;
  return html;
}
