import { describe, expect, it } from 'vitest';

import { preserveDomStrategy, reconstructNativeAggregate } from '../../index.js';
import type { SectionSpec } from '../../section-spec.js';
import { InstanceStyleSheet } from '../instance-styles.js';

function sectionSpec(overrides: Partial<SectionSpec> = {}): SectionSpec {
  return {
    sectionIndex: 0,
    interactionModel: 'static',
    top: 0,
    height: 0,
    headings: [],
    bodyText: [],
    buttonLabels: [],
    images: [],
    icons: [],
    backgroundBrightness: 1,
    backgroundColor: 'transparent',
    gradient: null,
    gradientSource: null,
    motionProfile: {
      motionClass: 'none',
      signals: [],
      animatedElements: 0,
    },
    dividerAbove: null,
    dividerBelow: null,
    layout: {
      containerWidth: 0,
      padding: '0',
      childLayout: 'stack',
      columnCount: 1,
      gap: '0',
    },
    ...overrides,
  };
}

describe('preserveDomStrategy', () => {
  it('emits lib-i core heading markup and drains deduped css rules', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 2,
          headings: ['Build fast'],
          bodyText: ['Copy link'],
          sectionHtml:
            '<section id="hero-panel" class="feature shell"><h2 class="eyebrow" style="max-width:46ch">Build <span class="accent">fast</span></h2><p class="lede">Copy <a href="/go">link</a></p></section>',
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    expect(aggregate.sectionMarkup).toEqual([
      '<!-- wp:group {"anchor":"hero-panel","tagName":"section","align":"full","className":"feature shell"} -->\n' +
        '<section id="hero-panel" class="wp-block-group alignfull feature shell"><!-- wp:heading {"className":"eyebrow lib-i91a84cc172"} -->\n' +
        '<h2 class="wp-block-heading eyebrow lib-i91a84cc172">Build <span class="accent">fast</span></h2>\n' +
        '<!-- /wp:heading -->\n' +
        '<!-- wp:paragraph {"className":"lede"} -->\n' +
        '<p class="lede">Copy <a href="/go">link</a></p>\n' +
        '<!-- /wp:paragraph --></section>\n' +
        '<!-- /wp:group -->',
    ]);
    expect(aggregate.sectionMarkup[0]).not.toContain('style=');
    expect(aggregate.dedup).toEqual({ cssRules: ['.lib-i91a84cc172{max-width:46ch}'] });
  });

  it('emits byte-stable wrapper, direct image, and leaf span lib-i core paths', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 3,
          bodyText: ['Caption'],
          sectionHtml:
            '<section id="media-panel" class="media shell" style="padding:2rem"><img class="photo" style="width:100%" src="/photo.jpg" alt="Photo"><span class="caption" style="font-weight:700">Caption</span></section>',
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    expect(aggregate.sectionMarkup).toEqual([
      '<!-- wp:group {"anchor":"media-panel","tagName":"section","align":"full","className":"media shell lib-i42aa6d9c6f"} -->\n' +
        '<section id="media-panel" class="wp-block-group alignfull media shell lib-i42aa6d9c6f"><!-- wp:image {"className":"photo lib-i0466783d98"} -->\n' +
        '<figure class="wp-block-image photo lib-i0466783d98"><img src="/photo.jpg" alt="Photo"/></figure>\n' +
        '<!-- /wp:image -->\n' +
        '<!-- wp:paragraph {"className":"caption lib-ie3ec02ace9"} -->\n' +
        '<p class="caption lib-ie3ec02ace9">Caption</p>\n' +
        '<!-- /wp:paragraph --></section>\n' +
        '<!-- /wp:group -->',
    ]);
    expect(aggregate.dedup).toEqual({
      cssRules: [
        '.lib-i0466783d98{width:100%}',
        '.lib-i42aa6d9c6f{padding:2rem}',
        '.lib-ie3ec02ace9{font-weight:700}',
      ],
    });
  });

  it('P3-S3: recurses nested non-leaf wrappers, preserving their source id/class', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 4,
          bodyText: ['Nested'],
          sectionHtml:
            '<section id="nested-panel" class="shell"><div id="card" class="card"><span class="label">Nested</span></div></section>',
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    // The nested .card wrapper is preserved as a nested wp:group (was previously dropped),
    // and the inner .label class survives onto the emitted paragraph.
    expect(aggregate.sectionMarkup).toEqual([
      '<!-- wp:group {"anchor":"nested-panel","tagName":"section","align":"full","className":"shell"} -->\n' +
        '<section id="nested-panel" class="wp-block-group alignfull shell"><!-- wp:group {"anchor":"card","className":"card"} -->\n' +
        '<div id="card" class="wp-block-group card"><!-- wp:paragraph {"className":"label"} -->\n' +
        '<p class="label">Nested</p>\n' +
        '<!-- /wp:paragraph --></div>\n' +
        '<!-- /wp:group --></section>\n' +
        '<!-- /wp:group -->',
    ]);
    expect(aggregate.sections[0]?.coverage.lost).toBe(false);
    expect(aggregate.provenanceFlags).toEqual([]);
  });

  it('P3-S3: preserves a real designed grid (.menu-grid > .menu-card > h3 + p) with inner classes', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 5,
          headings: ['Latte'],
          bodyText: ['Oat milk'],
          sectionHtml:
            '<section class="menu"><div class="menu-grid">' +
            '<div class="menu-card"><h3 class="menu-card__name">Latte</h3><p class="menu-card__desc">Oat milk</p></div>' +
            '</div></section>',
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    const markup = aggregate.sectionMarkup[0] ?? '';
    // Every source layout class survives so carried CSS targeting them still applies.
    expect(markup).toContain('"className":"menu-grid"');
    expect(markup).toContain('"className":"menu-card"');
    expect(markup).toContain('class="wp-block-heading menu-card__name"');
    expect(markup).toContain('"className":"menu-card__desc"');
    expect(markup).toContain('>Latte<');
    expect(markup).toContain('>Oat milk<');
    // The whole nested subtree emitted cleanly — nothing dropped.
    expect(aggregate.sections[0]?.coverage.lost).toBe(false);
    expect(aggregate.provenanceFlags).toEqual([]);
  });

  it('freezes lib-i hash parity for max-width declarations', () => {
    const sheet = new InstanceStyleSheet();
    const cls = sheet.classFor('max-width:46ch');

    console.info('P3-S2 lib-i frozen slug=lib-i91a84cc172');
    expect(cls).toBe('lib-i91a84cc172');
    expect(sheet.toCss()).toBe('.lib-i91a84cc172{max-width:46ch}');
  });

  it('sanitizes out-of-flow and oversized fallback layout declarations without changing route or assets', () => {
    const aggregate = reconstructNativeAggregate(
      [
        sectionSpec({
          sectionIndex: 6,
          headings: ['Pinned panel'],
          images: [
            { url: '/hero.jpg', sourceUrl: '/hero.jpg', alt: 'Hero', kind: 'img', width: 1200, height: 800 },
          ],
          sectionHtml:
            '<section id="pinned-panel" class="hero shell" style="position:absolute;top:4800px;left:-9999px;height:6000px;width:100%;color:red;max-width:46ch">' +
            '<h2>Pinned <span style="position:absolute;top:9999px;min-height:4000px;color:blue;max-width:20ch">panel</span></h2>' +
            '<img src="/hero.jpg" alt="Hero"></section>',
        }),
      ],
      { strategy: preserveDomStrategy },
    );

    expect(aggregate.sections[0]?.decision).toBe('native');
    expect(aggregate.sections[0]?.coverage.lost).toBe(false);
    expect(aggregate.sections[0]?.coverage.missingImages).toEqual([]);
    expect(aggregate.sections[0]?.expectedAssets).toEqual(['/hero.jpg']);
    expect(aggregate.sectionMarkup[0]).toContain('className":"hero shell lib-i1e1b353447"');
    expect(aggregate.sectionMarkup[0]).toContain('<span class="lib-i641b5be562">panel</span>');
    expect(aggregate.dedup).toEqual({
      cssRules: [
        '.lib-i1e1b353447{width:100%;color:red;max-width:46ch}',
        '.lib-i641b5be562{color:blue;max-width:20ch}',
      ],
    });
    expect((aggregate.dedup?.cssRules ?? []).join('\n')).not.toMatch(/position|top:|left:|height:6000px/);
  });
});
