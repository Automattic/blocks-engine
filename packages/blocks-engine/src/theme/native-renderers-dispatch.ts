import { emptyNativeRenderOut } from './native-block-builders.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { InteractionModel, SectionSpec } from './section-spec.js';

export const NON_CELL_GRID_MODELS: ReadonlySet<InteractionModel> = new Set([
  'product-card-row',
  'project-card-grid',
  'blog-card-grid',
  'review-grid',
  'testimonial',
]);

export const FLATTEN_PRONE_MODELS: ReadonlySet<InteractionModel> = new Set([
  'static',
  'cta',
  'price-list',
  'app-download',
  'horizontal-showcase',
]);

export const MEDIA_LAYOUT_DENY: ReadonlySet<InteractionModel> = new Set([
  'gallery',
  'logo-strip',
  'color-block-grid',
  'marquee-strip',
  'product-card-row',
  'project-card-grid',
  'blog-card-grid',
  'review-grid',
  'testimonial',
]);

export function renderSection(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  void section;
  void ctx;
  return emptyNativeRenderOut();
}
