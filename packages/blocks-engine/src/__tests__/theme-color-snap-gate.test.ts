import { describe, expect, it } from 'vitest';

import { ctaButton, emptyNativeRenderOut } from '../theme/native-block-builders.js';
import { COLOR_SNAP_GATE, nearestToken, type PaletteToken } from '../theme/native-color.js';
import { opaqueHex, opaqueLiteralBgHex, opaqueTintHex } from '../theme/native-layout.js';
import { renderCellGrid } from '../theme/native-renderers-grid.js';
import type { NativeRenderCtx } from '../theme/native-reconstruct-types.js';
import type { SectionSpec } from '../theme/section-spec.js';

const paletteTokens: PaletteToken[] = [
  { slug: 'text-default', hex: '#102030' },
  { slug: 'text-inverse', hex: '#f8fafc' },
  { slug: 'accent-primary', hex: '#008060' },
  { slug: 'surface-raised', hex: '#e8eff1' },
];

function nativeCtx(): NativeRenderCtx {
  return {
    mediaTextIndex: 0,
    iconCounter: 0,
    paletteTokens,
    fontFamilies: [],
  };
}

function baseSection(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 2,
    interactionModel: 'color-block-grid',
    top: 0,
    height: 420,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 255,
    backgroundColor: 'rgb(255, 255, 255)',
    gradient: null,
    gradientSource: null,
    motionProfile: { motionClass: 'none', signals: [], animatedElements: 0 },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 1100,
      padding: '0',
      childLayout: 'grid',
      columnCount: 1,
      gap: '24px',
    },
    ...overrides,
  };
}

describe('native color snap gate', () => {
  it('snaps on-brand colors, gates off-brand colors, and keeps the two-arg form ungated', () => {
    expect(COLOR_SNAP_GATE).toBe(24);
    expect(nearestToken('#00815f', paletteTokens, COLOR_SNAP_GATE)).toBe('accent-primary');
    expect(nearestToken('#806000', paletteTokens, COLOR_SNAP_GATE)).toBeNull();
    expect(nearestToken('#806000', paletteTokens)).toBe('text-default');
  });

  it('shares opaque hex parsing while only section tints reject near-white', () => {
    expect(opaqueHex('rgb(232, 239, 241)', { rejectNearWhite: true })).toBe('#e8eff1');
    expect(opaqueTintHex('rgb(249, 249, 249)')).toBeNull();
    expect(opaqueLiteralBgHex('rgb(249, 249, 249)')).toBe('#f9f9f9');
    expect(opaqueLiteralBgHex('rgba(192, 74, 157, 0.59)')).toBeNull();
  });
});

describe('ctaButton gated literal background', () => {
  it('keeps on-brand button backgrounds as slugs', () => {
    const out = emptyNativeRenderOut();
    const markup = ctaButton(out, nativeCtx(), {
      label: 'Buy now',
      href: '/buy',
      background: '#00815f',
    });

    expect(markup).toContain('"backgroundColor":"accent-primary"');
    expect(markup).toContain('has-accent-primary-background-color');
    expect(markup).not.toContain('"color":{"background"');
  });

  it('emits an off-brand opaque button background as a literal custom color', () => {
    const out = emptyNativeRenderOut();
    const markup = ctaButton(out, nativeCtx(), {
      label: 'Start',
      href: '/start',
      background: '#c04a9d',
      color: '#d6ff00',
    });

    expect(markup).toContain('"style":{"color":{"background":"#c04a9d"}}');
    expect(markup).toContain('background-color:#c04a9d');
    expect(markup).toContain('has-background');
    expect(markup).not.toContain('"backgroundColor":"accent-primary"');
    expect(markup).not.toContain('has-accent-primary-background-color');
    expect(markup).toContain('"textColor":"text-inverse"');
  });

  it('falls back to accent-primary when the button background is unusable', () => {
    const out = emptyNativeRenderOut();
    const markup = ctaButton(out, nativeCtx(), {
      label: 'Ghost',
      background: 'rgba(192, 74, 157, 0.59)',
    });

    expect(markup).toContain('"backgroundColor":"accent-primary"');
    expect(markup).toContain('has-accent-primary-background-color');
  });
});

describe('cell grid gated literal card background', () => {
  it('keeps on-brand card backgrounds as slugs', () => {
    const out = renderCellGrid(
      baseSection({
        cells: [
          {
            heading: 'Support',
            body: ['Covered every day.'],
            image: null,
            icon: null,
            button: null,
            background: '#e8eff0',
            radius: 12,
          },
        ],
      }),
      nativeCtx(),
    );

    expect(out.markup).toContain('"backgroundColor":"surface-raised"');
    expect(out.markup).toContain('has-surface-raised-background-color');
    expect(out.markup).not.toContain('"color":{"background"');
  });

  it('emits an off-brand opaque card background as a literal custom color', () => {
    const out = renderCellGrid(
      baseSection({
        cells: [
          {
            heading: 'Studio',
            body: ['A distinct captured card.'],
            image: null,
            icon: null,
            button: null,
            background: '#c04a9d',
            radius: 18,
            padding: { top: 18, right: 22, bottom: 24, left: 22 },
          },
        ],
      }),
      nativeCtx(),
    );

    expect(out.markup).toContain('"color":{"background":"#c04a9d"}');
    expect(out.markup).toContain('background-color:#c04a9d');
    expect(out.markup).toContain('is-replica-card');
    expect(out.markup).not.toContain('has-accent-primary-background-color');
  });

  it('drops unusable card backgrounds instead of emitting a card group', () => {
    const out = renderCellGrid(
      baseSection({
        cells: [
          {
            heading: 'Transparent',
            body: ['No durable background.'],
            image: null,
            icon: null,
            button: null,
            background: 'rgba(192, 74, 157, 0.59)',
          },
        ],
      }),
      nativeCtx(),
    );

    expect(out.markup).not.toContain('is-replica-card');
    expect(out.markup).not.toContain('"color":{"background"');
  });
});
