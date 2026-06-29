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
      '<!-- wp:group {"anchor":"hero-panel","tagName":"section","className":"feature shell"} -->\n' +
        '<section id="hero-panel" class="wp-block-group feature shell"><!-- wp:heading {"className":"eyebrow lib-i91a84cc172"} -->\n' +
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
      '<!-- wp:group {"anchor":"media-panel","tagName":"section","className":"media shell lib-i42aa6d9c6f"} -->\n' +
        '<section id="media-panel" class="wp-block-group media shell lib-i42aa6d9c6f"><!-- wp:image {"className":"photo lib-i0466783d98"} -->\n' +
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

  it('does not flatten deferred non-leaf wrappers through the leaf span/div path', () => {
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

    expect(aggregate.sectionMarkup).toEqual([
      '<!-- wp:group {"anchor":"nested-panel","tagName":"section","className":"shell"} -->\n' +
        '<section id="nested-panel" class="wp-block-group shell"></section>\n' +
        '<!-- /wp:group -->',
    ]);
    expect(aggregate.sections[0]?.coverage.lost).toBe(true);
    expect(aggregate.provenanceFlags).toEqual(['preserve-dom#4: skipped non-core elements']);
  });

  it('freezes lib-i hash parity for max-width declarations', () => {
    const sheet = new InstanceStyleSheet();
    const cls = sheet.classFor('max-width:46ch');

    console.info('P3-S2 lib-i frozen slug=lib-i91a84cc172');
    expect(cls).toBe('lib-i91a84cc172');
    expect(sheet.toCss()).toBe('.lib-i91a84cc172{max-width:46ch}');
  });
});
