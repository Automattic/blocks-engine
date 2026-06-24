import type { ParsedFontFace } from './font-faces.js';
import type { AssetFile } from './types.js';

export interface FontSelfHostResult {
  assets: AssetFile[];
  localizedCss: string;
  warnings: string[];
}

export function selfHostFonts(
  cssUrls: string[],
  parsed: ParsedFontFace[],
  opts: { themeSlug: string; fetchImpl?: typeof fetch }
): Promise<FontSelfHostResult> {
  void cssUrls;
  void parsed;
  void opts;
  throw new Error('STAGE_STUB');
}
