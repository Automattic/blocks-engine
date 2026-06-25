import { emptyNativeRenderOut } from './native-block-builders.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpec, SectionSpecImage } from './section-spec.js';

export interface GalleryBlockOptions {
  sectionHeight?: number;
}

export function renderTextBand(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  void section;
  void ctx;
  return emptyNativeRenderOut();
}

export function renderCover(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  void section;
  void ctx;
  return emptyNativeRenderOut();
}

export function renderMediaText(section: SectionSpec, flip: boolean, ctx: NativeRenderCtx): NativeRenderOut {
  void section;
  void flip;
  void ctx;
  return emptyNativeRenderOut();
}

export function galleryBlock(
  images: SectionSpecImage[],
  out: NativeRenderOut,
  opts?: GalleryBlockOptions,
): string {
  void images;
  void out;
  void opts;
  return '';
}
