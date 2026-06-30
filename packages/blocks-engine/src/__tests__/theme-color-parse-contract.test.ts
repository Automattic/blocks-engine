import { describe, expect, expectTypeOf, it } from 'vitest';

import { parseColor, parseHex, type RGBA } from '../theme/native-color.js';
import { opaqueTintHex } from '../theme/native-layout.js';

// DIVERGENCE: Blocks Engine intentionally parses modern CSS color syntax beyond the frozen DLA low-level color helpers.

describe('native color parsing contract', () => {
  it('freezes the shared color parser type surface', () => {
    expectTypeOf<RGBA>().toEqualTypeOf<{ r: number; g: number; b: number; a: number }>();
    expectTypeOf(parseColor).toEqualTypeOf<(input: string) => RGBA | null>();
    expectTypeOf(parseHex).toEqualTypeOf<(color: string) => [number, number, number] | null>();
    expectTypeOf(opaqueTintHex).toEqualTypeOf<(color: string | null | undefined) => string | null>();
  });

  it.each([
    ['rgb comma integer', 'rgb(16, 32, 48)', { r: 16, g: 32, b: 48, a: 1 }],
    ['rgb comma alpha synonym', 'rgb(20,20,20,0.9)', { r: 20, g: 20, b: 20, a: 0.9 }],
    ['rgba comma alpha', 'rgba(16, 32, 48, 0.25)', { r: 16, g: 32, b: 48, a: 0.25 }],
    ['rgb space integer', 'rgb(16 32 48)', { r: 16, g: 32, b: 48, a: 1 }],
    ['rgb space slash alpha', 'rgb(16 32 48 / 0.25)', { r: 16, g: 32, b: 48, a: 0.25 }],
    ['rgb space slash percent alpha', 'rgb(1 2 3 / 50%)', { r: 1, g: 2, b: 3, a: 0.5 }],
    ['rgb percentage channels', 'rgb(100% 50% 0%)', { r: 255, g: 128, b: 0, a: 1 }],
    ['short hex', '#0f8', { r: 0, g: 255, b: 136, a: 1 }],
    ['short hex alpha', '#0f8c', { r: 0, g: 255, b: 136, a: 0.8 }],
    ['long hex', '#102030', { r: 16, g: 32, b: 48, a: 1 }],
    ['long hex alpha', '#10203040', { r: 16, g: 32, b: 48, a: 64 / 255 }],
    ['hsl space', 'hsl(210 50% 40%)', { r: 51, g: 102, b: 153, a: 1 }],
    ['hsl comma', 'hsl(210, 50%, 40%)', { r: 51, g: 102, b: 153, a: 1 }],
    ['hsla comma alpha', 'hsla(210, 50%, 40%, 0.25)', { r: 51, g: 102, b: 153, a: 0.25 }],
    ['hsl space slash alpha', 'hsl(210 50% 40% / 0.25)', { r: 51, g: 102, b: 153, a: 0.25 }],
    ['hsl space slash percent alpha', 'hsl(120 50% 50% / 50%)', { r: 64, g: 191, b: 64, a: 0.5 }],
  ])('parses %s', (_label, input, expected) => {
    expect(parseColor(input)).toEqual(expected);
  });

  it.each(['transparent', 'currentColor', 'inherit', 'rebeccapurple', 'not-a-color', 'rgb(256, 2, 3)', 'rgb(1 2 3 / 150%)'])(
    'returns null for unsupported color %s',
    (input) => {
      expect(parseColor(input)).toBeNull();
    },
  );

  it.each([
    ['rgb comma', 'rgb(246, 248, 250)', [246, 248, 250]],
    ['rgba comma', 'rgba(232, 239, 241, 0.4)', [232, 239, 241]],
    ['short hex', '#0f8', [0, 255, 136]],
    ['short hex without hash', '0f8', [0, 255, 136]],
    ['long hex', '#102030', [16, 32, 48]],
    ['long hex without hash', '102030', [16, 32, 48]],
  ])('keeps parseHex byte-identical for old input %s', (_label, input, expected) => {
    expect(parseHex(input)).toEqual(expected);
  });

  it('drops parsed alpha when parseHex receives alpha-capable colors', () => {
    expect(parseHex('#10203040')).toEqual([16, 32, 48]);
    expect(parseHex('rgb(20,20,20,0.9)')).toEqual([20, 20, 20]);
    expect(parseHex('rgb(16 32 48 / 0.25)')).toEqual([16, 32, 48]);
  });

  it.each([
    ['no color', undefined],
    ['faint rgba', 'rgba(232, 239, 241, 0.4)'],
    ['faint hex alpha', '#e8eff166'],
    ['near white', '#ffffff'],
    ['low-saturation near white', '#eeeeec'],
  ])('keeps opaqueTintHex guard for %s', (_label, input) => {
    expect(opaqueTintHex(input)).toBeNull();
  });

  it.each([
    ['rgb tint', 'rgb(232, 239, 241)', '#e8eff1'],
    ['long hex tint', '#008060', '#008060'],
    ['space rgb tint', 'rgb(232 239 241)', '#e8eff1'],
  ])('serializes opaque tint %s', (_label, input, expected) => {
    expect(opaqueTintHex(input)).toBe(expected);
  });
});
