import { describe, expect, expectTypeOf, it } from 'vitest';

import {
  assignChromeVariants,
  chromeSignature,
  type ChromeSlugs,
} from '../theme/index.js';

describe('chrome signature contract', () => {
  it('freezes the public signatures exported from the theme entrypoint', () => {
    expect(typeof chromeSignature).toBe('function');
    expect(typeof assignChromeVariants).toBe('function');

    expectTypeOf(chromeSignature).toEqualTypeOf<
      (headerHtml: string, footerHtml: string) => string
    >();
    expectTypeOf(assignChromeVariants).toEqualTypeOf<
      (
        pages: Array<{ slug: string; headerHtml: string; footerHtml: string }>
      ) => {
        slugsByPage: Record<string, ChromeSlugs>;
        canonical: Record<string, { headerHtml: string; footerHtml: string }>;
      }
    >();
  });
});
