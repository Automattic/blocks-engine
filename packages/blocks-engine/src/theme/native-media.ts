import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpecIcon, SectionSpecImage } from './section-spec.js';

export const MISSING_IMAGE_PLACEHOLDER = '[image unavailable — not captured]';

export interface ResolvedNativeImage {
  url: string;
  alt: string;
  usable: boolean;
}

export interface IconImageBlockOptions {
  sizePx?: number;
  fill?: string;
  align?: 'left' | 'center' | 'right';
}

export function pickLeadImage(images: SectionSpecImage[]): SectionSpecImage | undefined {
  void images;
  throw new Error('native-media pickLeadImage is not implemented');
}

export function isWpMediaUrl(url: string): boolean {
  void url;
  throw new Error('native-media isWpMediaUrl is not implemented');
}

export function recolorSvg(svg: string, hex: string): string {
  void svg;
  void hex;
  throw new Error('native-media recolorSvg is not implemented');
}

export function resolveImage(
  image: SectionSpecImage | undefined,
  out: NativeRenderOut,
  context: string,
): ResolvedNativeImage {
  void image;
  void out;
  void context;
  throw new Error('native-media resolveImage is not implemented');
}

export function iconImageBlock(
  icon: SectionSpecIcon,
  out: NativeRenderOut,
  ctx: NativeRenderCtx,
  opts?: IconImageBlockOptions,
): string {
  void icon;
  void out;
  void ctx;
  void opts;
  throw new Error('native-media iconImageBlock is not implemented');
}
