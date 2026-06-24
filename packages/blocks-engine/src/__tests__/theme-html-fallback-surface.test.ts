import { describe, expect, it } from 'vitest';

import {
  buildHtmlFallbackBlock,
  isWpLayoutMarkup,
  rewriteInternalLinks,
  rewriteMediaUrls,
  sanitize,
  scanForInjection,
  selectIslandSource,
  type InternalLinkMap,
  type IslandTier,
} from '../theme/index.js';

describe('theme html fallback public surface', () => {
  it('freezes the additive A3 named exports', () => {
    const linkMap: InternalLinkMap = new Map([['/about', '/about/']]);
    const tier: IslandTier = selectIslandSource({ sectionHtml: '<section></section>' }).tier;

    expect(typeof sanitize).toBe('function');
    expect(typeof isWpLayoutMarkup).toBe('function');
    expect(typeof selectIslandSource).toBe('function');
    expect(typeof buildHtmlFallbackBlock).toBe('function');
    expect(typeof rewriteMediaUrls).toBe('function');
    expect(typeof rewriteInternalLinks).toBe('function');
    expect(typeof scanForInjection).toBe('function');
    expect(linkMap.get('/about')).toBe('/about/');
    expect(['responsive', 'styled', 'verbatim']).toContain(tier);
  });
});
