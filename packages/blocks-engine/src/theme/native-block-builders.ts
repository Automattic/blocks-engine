import type { NativeRenderCtx, NativeRenderOut } from './native-reconstruct-types.js';
import type { SectionSpec, SectionSpecIcon, SectionSpecImage } from './section-spec.js';

export interface TypographyFragments {
  attr: string;
  inline: string;
}

export interface HeadingBlockOptions {
  level?: number;
  center?: boolean;
  muted?: boolean;
  inverse?: boolean;
  sizePx?: number;
  fontFamily?: string | null;
  lineHeight?: number;
}

export interface ParagraphBlockOptions {
  center?: boolean;
  muted?: boolean;
  size?: string;
  inverse?: boolean;
  sizePx?: number;
  fontFamily?: string | null;
  lineHeight?: number;
}

export interface ImageBlockOptions {
  rounded?: boolean;
  align?: 'center' | null;
}

export interface NativeButtonInput {
  label: string;
  href?: string;
  background?: string | null;
  color?: string | null;
  icon?: SectionSpecIcon | null;
  iconAfter?: boolean;
}

export interface CtaButtonOptions {
  align?: 'left' | 'center';
}

export interface WrapSectionOptions {
  constrained?: string;
  wide?: string;
  center?: boolean;
  raised?: boolean;
  inverse?: boolean;
  bgColor?: string;
  padTopPx?: number;
  padBottomPx?: number;
  fullBleed?: boolean;
}

export function visibleText(html: string): string {
  void html;
  throw new Error('native-block-builders visibleText is not implemented');
}

export function emptyNativeRenderOut(): NativeRenderOut {
  throw new Error('native-block-builders emptyNativeRenderOut is not implemented');
}

export function typographyStyle(fontCss: string, lineHeight?: number): TypographyFragments {
  void fontCss;
  void lineHeight;
  throw new Error('native-block-builders typographyStyle is not implemented');
}

export function imageBlock(
  image: SectionSpecImage | undefined,
  out: NativeRenderOut,
  context: string,
  opts?: ImageBlockOptions,
): string {
  void image;
  void out;
  void context;
  void opts;
  throw new Error('native-block-builders imageBlock is not implemented');
}

export function headingBlock(text: string, out: NativeRenderOut, opts?: HeadingBlockOptions): string {
  void text;
  void out;
  void opts;
  throw new Error('native-block-builders headingBlock is not implemented');
}

export function paragraphBlock(text: string, out: NativeRenderOut, opts?: ParagraphBlockOptions): string {
  void text;
  void out;
  void opts;
  throw new Error('native-block-builders paragraphBlock is not implemented');
}

export function buttonBlock(
  label: string,
  out: NativeRenderOut,
  opts?: { align?: 'left' | 'center' | 'right' },
): string {
  void label;
  void out;
  void opts;
  throw new Error('native-block-builders buttonBlock is not implemented');
}

export function ctaButton(
  out: NativeRenderOut,
  ctx: NativeRenderCtx,
  button: NativeButtonInput,
  opts?: CtaButtonOptions,
): string {
  void out;
  void ctx;
  void button;
  void opts;
  throw new Error('native-block-builders ctaButton is not implemented');
}

export function sectionButtons(section: SectionSpec, out: NativeRenderOut, ctx: NativeRenderCtx): string[] {
  void section;
  void out;
  void ctx;
  throw new Error('native-block-builders sectionButtons is not implemented');
}

export function column(parts: string[], width?: string): string {
  void parts;
  void width;
  throw new Error('native-block-builders column is not implemented');
}

export function columns(cols: string[], opts?: { fullBleed?: boolean }): string {
  void cols;
  void opts;
  throw new Error('native-block-builders columns is not implemented');
}

export function wrapSection(parts: string[], opts: WrapSectionOptions): string {
  void parts;
  void opts;
  throw new Error('native-block-builders wrapSection is not implemented');
}
