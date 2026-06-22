import { canonicalize } from './canonicalize';
import { rawConvert } from './raw-convert';

export { bootstrap } from './bootstrap';
export { canonicalize } from './canonicalize';
export { rawConvert } from './raw-convert';

export type ConvertContext = { url?: string };

export function convert(html: string, ctx?: ConvertContext): string {
  void ctx;
  const raw = rawConvert(html);
  // D6: insert compose() between rawConvert and canonicalize
  return canonicalize(raw.html ?? '').html;
}
