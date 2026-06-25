import {
  emptyNativeRenderOut,
} from './native-block-builders.js';
import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpec } from './section-spec.js';

export interface CardGroupPadding {
  top: number;
  right: number;
  bottom: number;
  left: number;
}

export function renderCardGrid(section: SectionSpec, withButtons: boolean): NativeRenderOut {
  void section;
  void withButtons;
  return emptyNativeRenderOut();
}

export function renderFaq(section: SectionSpec): NativeRenderOut {
  void section;
  return emptyNativeRenderOut();
}

export function renderCellGrid(section: SectionSpec, ctx: NativeRenderCtx): NativeRenderOut {
  void section;
  void ctx;
  return emptyNativeRenderOut();
}

export function cardGroup(
  parts: string[],
  bgToken: string,
  dark: boolean,
  radius: number,
  padding: CardGroupPadding | null,
): string {
  void parts;
  void bgToken;
  void dark;
  void radius;
  void padding;
  return '';
}
