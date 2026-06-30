import { describe, expect, it } from 'vitest';

import { parseColor } from '../theme/native-color.js';
import { opaqueTintHex } from '../theme/native-layout.js';

describe('native color alpha precision', () => {
  it('preserves hex alpha precision before opaque tint guard checks', () => {
    expect(parseColor('#00000098')).toEqual({ r: 0, g: 0, b: 0, a: 152 / 255 });
    expect(opaqueTintHex('#00000098')).toBeNull();
    expect(opaqueTintHex('#00000099')).toBe('#000000');
  });
});
