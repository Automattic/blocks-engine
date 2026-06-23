import { compose } from '../compose';
import type { ConversionContext } from '../types';
import { canonicalize } from './canonicalize';
import { rawConvert } from './raw-convert';

export { bootstrap } from './bootstrap';
export { canonicalize } from './canonicalize';
export { rawConvert } from './raw-convert';

export type ConvertContext = Partial<ConversionContext>;

export function convert(html: string, ctx?: ConvertContext): string {
  const raw = rawConvert(html);
  const conversionCtx: ConversionContext = {
    url: ctx?.url ?? '',
    ...(ctx?.mediaMap ? { mediaMap: ctx.mediaMap } : {}),
  };
  const blockMarkup =
    raw.html !== null && raw.wpHtmlResidue === 0
      ? raw.html
      : compose(html, conversionCtx, {});
  return canonicalize(blockMarkup).html;
}
